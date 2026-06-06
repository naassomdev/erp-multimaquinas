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
use App\Repositories\ConfiguracaoRepository;

final class EmpresaController
{
    /** Caminho relativo a BASE_PATH onde a logo final é salva */
    private const LOGO_DEST    = '/public/img/logo.png';

    /** Tamanho máximo do arquivo de logo (2 MB) */
    private const LOGO_MAX_BYTES = 2_097_152;

    /** MIME types aceitos para logo */
    private const LOGO_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /** Extensões aceitas para logo */
    private const LOGO_EXTS = ['png', 'jpg', 'jpeg', 'webp'];

    /** Chaves empresa_* gerenciadas nesta tela */
    private const CAMPOS_EMPRESA = [
        'empresa_nome',
        'empresa_razao_social',
        'empresa_cnpj',
        'empresa_endereco',
        'empresa_numero',
        'empresa_complemento',
        'empresa_bairro',
        'empresa_cidade',
        'empresa_uf',
        'empresa_cep',
        'empresa_telefone',
        'empresa_email',
        'empresa_site',
    ];

    public function __construct(
        private readonly ConfiguracaoRepository $configRepo = new ConfiguracaoRepository(),
    ) {}

    public function index(Request $request): Response
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }

        $cfgEmp  = $this->configRepo->listarPorPrefixo('empresa_');
        $cfgNfse = $this->configRepo->listarPorPrefixo('nfse_prestador_');
        $logoExiste = is_file(BASE_PATH . self::LOGO_DEST);

        return Response::html(View::render('admin/empresa', [
            'titulo'      => 'Dados da Empresa',
            'activeMenu'  => 'admin_empresa',
            'usuario'     => Auth::user(),
            'cfg'         => $cfgEmp,
            'nfse'        => $cfgNfse,
            'logoExiste'  => $logoExiste,
            'csrf_token'  => Csrf::token(),
        ]));
    }

    public function salvar(Request $request): Response
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }

        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Token de segurança inválido. Tente novamente.');
            return Response::redirect('/admin/empresa');
        }

        // ── Coletar e sanitizar campos de texto ───────────────────────────────
        $dados = [];
        foreach (self::CAMPOS_EMPRESA as $campo) {
            $valor = trim((string) $request->input($campo, ''));

            $valor = match ($campo) {
                'empresa_uf'   => strtoupper(
                    substr(preg_replace('/[^a-zA-Z]/', '', $valor) ?? '', 0, 2)
                ),
                'empresa_cnpj' => preg_replace('/\D/', '', $valor) ?? '',
                'empresa_cep'  => preg_replace('/\D/', '', $valor) ?? '',
                default        => $valor,
            };

            $dados[$campo] = $valor;
        }

        // ── Validação de e-mail (se preenchido) ───────────────────────────────
        if ($dados['empresa_email'] !== '' &&
            !filter_var($dados['empresa_email'], FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'E-mail inválido. Verifique e tente novamente.');
            return Response::redirect('/admin/empresa');
        }

        // ── Upload de logo (opcional) ─────────────────────────────────────────
        $logoMensagem = null;
        $logoOk       = false;
        $arquivo      = $request->file('logo');

        if ($arquivo !== null &&
            (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$logoOk, $logoErro, $logoDados] = $this->processarUploadLogo($arquivo);
            if ($logoOk) {
                $dados['empresa_logo']               = $logoDados['path'];
                $dados['empresa_logo_atualizado_em'] = date('Y-m-d H:i:s');
                $logoMensagem = 'Logo atualizada com sucesso.';
            } else {
                $logoMensagem = 'Logo não atualizada: ' . $logoErro;
            }
        }

        // ── Persistir no banco ────────────────────────────────────────────────
        $this->configRepo->salvarMuitos($dados);

        if (!$logoOk && $logoMensagem !== null) {
            // Dados textuais salvos, mas logo falhou
            Flash::set('error', 'Dados salvos. ' . $logoMensagem);
        } else {
            Flash::set('success', 'Dados da empresa salvos com sucesso.' .
                ($logoMensagem !== null ? ' ' . $logoMensagem : ''));
        }

        return Response::redirect('/admin/empresa');
    }

    // ── Helpers de upload ─────────────────────────────────────────────────────

    /**
     * Valida e move o arquivo de logo.
     *
     * @param  array<string,mixed> $arquivo  Entrada de $_FILES normalizada pelo Request
     * @return array{0:bool, 1:string|null, 2:array<string,string>}
     *         [$ok, $erro, $dados]
     */
    private function processarUploadLogo(array $arquivo): array
    {
        if ((int) $arquivo['error'] !== UPLOAD_ERR_OK) {
            return [false, 'Falha no upload (código ' . $arquivo['error'] . ').', []];
        }

        $tamanho = (int) ($arquivo['size'] ?? 0);
        if ($tamanho === 0 || $tamanho > self::LOGO_MAX_BYTES) {
            return [false, 'Arquivo muito grande. Máximo permitido: 1 MB.', []];
        }

        $tmpPath = (string) ($arquivo['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return [false, 'Arquivo de upload inválido.', []];
        }

        // Validar MIME real (não apenas extensão)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmpPath);
        if (!in_array($mime, self::LOGO_MIMES, true)) {
            return [false, 'Tipo de arquivo não permitido. Use PNG, JPG ou WEBP.', []];
        }

        // Validar extensão do nome original
        $ext = strtolower(pathinfo((string) ($arquivo['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, self::LOGO_EXTS, true)) {
            return [false, 'Extensão não permitida. Use PNG, JPG ou WEBP.', []];
        }

        $destFinal = BASE_PATH . self::LOGO_DEST;

        // Backup da logo anterior
        if (is_file($destFinal)) {
            $backup = BASE_PATH . '/public/img/logo_backup_' . date('Ymd_His') . '.png';
            @copy($destFinal, $backup);
        }

        if (!@move_uploaded_file($tmpPath, $destFinal)) {
            return [false, 'Não foi possível salvar o arquivo no servidor.', []];
        }

        return [true, null, ['path' => '/img/logo.png']];
    }
}
