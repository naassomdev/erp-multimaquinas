<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\UsuarioRepository;

final class UsuarioController
{
    private const NIVEIS_VALIDOS = ['admin', 'recepcao', 'oficina'];
    private const SENHA_MIN_LEN  = 8;

    public function __construct(
        private readonly UsuarioRepository $repo = new UsuarioRepository(),
    ) {}

    // ── Listagem ──────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }

        $filtros = [
            'q'      => trim((string) $request->input('q', '')),
            'nivel'  => (string) $request->input('nivel', ''),
            'status' => $request->input('status') !== null ? (string) $request->input('status') : '',
        ];

        $usuarios = $this->repo->listar($filtros);

        return Response::html(View::render('admin/usuarios/index', [
            'titulo'     => 'Usuários',
            'activeMenu' => 'admin_usuarios',
            'usuario'    => Auth::user(),
            'usuarios'   => $usuarios,
            'filtros'    => $filtros,
            'niveis'     => self::NIVEIS_VALIDOS,
        ]));
    }

    // ── Criar ─────────────────────────────────────────────────────────────────

    public function novo(Request $request): Response
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }

        return Response::html(View::render('admin/usuarios/form', [
            'titulo'     => 'Novo Usuário',
            'activeMenu' => 'admin_usuarios',
            'usuario'    => Auth::user(),
            'editando'   => null,
            'niveis'     => self::NIVEIS_VALIDOS,
            'csrf_token' => Csrf::token(),
            'old'        => [
                'nome'         => Flash::old('nome')         ?? '',
                'email'        => Flash::old('email')        ?? '',
                'nivel_acesso' => Flash::old('nivel_acesso') ?? '',
                'status'       => Flash::old('status')       ?? '1',
            ],
        ]));
    }

    public function criar(Request $request): Response
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }

        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Token inválido. Tente novamente.');
            return Response::redirect('/admin/usuarios/novo');
        }

        $dados = $this->extrairDados($request);
        $senha = (string) $request->input('senha', '');
        $conf  = (string) $request->input('senha_confirmar', '');

        $erro = $this->validar($dados, $senha, $conf, senhaObrigatoria: true);
        if ($erro !== null) {
            Flash::set('error', $erro);
            Flash::keepOld([
                'nome'         => $dados['nome'],
                'email'        => $dados['email'],
                'nivel_acesso' => $dados['nivel_acesso'],
                'status'       => (string) $dados['status'],
            ]);
            return Response::redirect('/admin/usuarios/novo');
        }

        if ($this->repo->emailExiste($dados['email'])) {
            Flash::set('error', 'Já existe um usuário com este e-mail.');
            Flash::keepOld([
                'nome'         => $dados['nome'],
                'email'        => $dados['email'],
                'nivel_acesso' => $dados['nivel_acesso'],
                'status'       => (string) $dados['status'],
            ]);
            return Response::redirect('/admin/usuarios/novo');
        }

        $dados['senha'] = password_hash($senha, PASSWORD_DEFAULT);
        $this->repo->criar($dados);

        Flash::set('success', "Usuário \"{$dados['nome']}\" criado com sucesso.");
        return Response::redirect('/admin/usuarios');
    }

    // ── Editar ────────────────────────────────────────────────────────────────

    public function editar(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }

        $editando = $this->repo->buscarPorId((int) $id);
        if ($editando === null) {
            throw new HttpException(404, "Usuário #{$id} não encontrado.");
        }

        return Response::html(View::render('admin/usuarios/form', [
            'titulo'     => "Editar — {$editando['nome']}",
            'activeMenu' => 'admin_usuarios',
            'usuario'    => Auth::user(),
            'editando'   => $editando,
            'niveis'     => self::NIVEIS_VALIDOS,
            'csrf_token' => Csrf::token(),
            'old'        => [],
        ]));
    }

    public function atualizar(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }

        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Token inválido. Tente novamente.');
            return Response::redirect("/admin/usuarios/{$id}/editar");
        }

        $editando = $this->repo->buscarPorId((int) $id);
        if ($editando === null) {
            throw new HttpException(404, "Usuário #{$id} não encontrado.");
        }

        $dados = $this->extrairDados($request);
        $senha = (string) $request->input('senha', '');
        $conf  = (string) $request->input('senha_confirmar', '');

        $erro = $this->validar($dados, $senha, $conf, senhaObrigatoria: false);
        if ($erro !== null) {
            Flash::set('error', $erro);
            return Response::redirect("/admin/usuarios/{$id}/editar");
        }

        // ── Proteção 1: admin não pode inativar a si próprio ─────────────────
        if ((int) $id === Auth::id() && (int) $dados['status'] === 0) {
            Flash::set('error', 'Você não pode inativar seu próprio usuário.');
            return Response::redirect("/admin/usuarios/{$id}/editar");
        }

        // ── Proteção 2: deve existir pelo menos um admin ativo ───────────────
        $eraSuperAdmin = (string) ($editando['nivel_acesso'] ?? '') === 'admin'
            && (int) ($editando['status'] ?? 0) === 1;

        if ($eraSuperAdmin) {
            $perdendoAdmin = $dados['nivel_acesso'] !== 'admin' || (int) $dados['status'] === 0;
            if ($perdendoAdmin && $this->repo->contarAdminsAtivos() <= 1) {
                Flash::set('error', 'O sistema precisa manter pelo menos um administrador ativo.');
                return Response::redirect("/admin/usuarios/{$id}/editar");
            }
        }

        // ── E-mail único ─────────────────────────────────────────────────────
        if ($this->repo->emailExiste($dados['email'], (int) $id)) {
            Flash::set('error', 'Já existe outro usuário com este e-mail.');
            return Response::redirect("/admin/usuarios/{$id}/editar");
        }

        $this->repo->atualizar((int) $id, [
            'nome'         => $dados['nome'],
            'email'        => $dados['email'],
            'nivel_acesso' => $dados['nivel_acesso'],
            'status'       => (int) $dados['status'],
        ]);

        if ($senha !== '') {
            $this->repo->atualizarSenha((int) $id, password_hash($senha, PASSWORD_DEFAULT));
        }

        Flash::set('success', "Usuário \"{$dados['nome']}\" atualizado com sucesso.");
        return Response::redirect('/admin/usuarios');
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function extrairDados(Request $request): array
    {
        return [
            'nome'         => trim((string) $request->input('nome', '')),
            'email'        => strtolower(trim((string) $request->input('email', ''))),
            'nivel_acesso' => (string) $request->input('nivel_acesso', ''),
            'status'       => $request->input('status') !== null ? (int) $request->input('status') : 1,
        ];
    }

    /**
     * Valida os dados do formulário.
     * Retorna a mensagem de erro, ou null se tudo estiver OK.
     */
    private function validar(
        array $dados,
        string $senha,
        string $confirmacao,
        bool $senhaObrigatoria
    ): ?string {
        if ($dados['nome'] === '') {
            return 'Nome é obrigatório.';
        }
        if ($dados['email'] === '' || !filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            return 'E-mail inválido ou não preenchido.';
        }
        if (!in_array($dados['nivel_acesso'], self::NIVEIS_VALIDOS, true)) {
            return 'Nível de acesso inválido.';
        }
        if (!in_array((int) $dados['status'], [0, 1], true)) {
            return 'Status inválido.';
        }
        if ($senhaObrigatoria && $senha === '') {
            return 'Senha é obrigatória para novos usuários.';
        }
        if ($senha !== '') {
            if (strlen($senha) < self::SENHA_MIN_LEN) {
                return 'A senha deve ter no mínimo ' . self::SENHA_MIN_LEN . ' caracteres.';
            }
            if ($senha !== $confirmacao) {
                return 'A confirmação de senha não confere.';
            }
        }
        return null;
    }
}
