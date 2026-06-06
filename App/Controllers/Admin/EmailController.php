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
use App\Services\EmailService;
use PHPMailer\PHPMailer\Exception as MailerException;
use RuntimeException;

final class EmailController
{
    private const ENCRYPTION_VALIDAS = ['none', 'tls', 'ssl'];
    private const PORTA_MIN = 1;
    private const PORTA_MAX = 65535;

    public function __construct(
        private readonly ConfiguracaoRepository $repo = new ConfiguracaoRepository()
    ) {}

    // ── Formulário ────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }

        $cfg = $this->carregarCfg();

        return Response::html(View::render('admin/email', [
            'titulo'     => 'Configuração de E-mail',
            'activeMenu' => 'admin_email',
            'cfg'        => $cfg,
            'csrf_token' => Csrf::token(),
        ]));
    }

    // ── Salvar ────────────────────────────────────────────────────────────────

    public function salvar(Request $request): Response
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }

        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Token inválido. Tente novamente.');
            return Response::redirect('/admin/email');
        }

        $fromName    = trim((string) $request->input('email_from_name', ''));
        $fromAddress = strtolower(trim((string) $request->input('email_from_address', '')));
        $enabled     = $request->input('smtp_enabled') === '1' ? '1' : '0';
        $host        = trim((string) $request->input('smtp_host', ''));
        $portRaw     = trim((string) $request->input('smtp_port', '587'));
        $username    = trim((string) $request->input('smtp_username', ''));
        $password    = (string) $request->input('smtp_password', '');
        $encryption  = (string) $request->input('smtp_encryption', 'tls');

        // ── Validações ────────────────────────────────────────────────────────
        if ($fromName === '') {
            Flash::set('error', 'Nome do remetente é obrigatório.');
            return Response::redirect('/admin/email');
        }
        if ($fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'E-mail do remetente inválido ou não preenchido.');
            return Response::redirect('/admin/email');
        }
        if (!in_array($encryption, self::ENCRYPTION_VALIDAS, true)) {
            Flash::set('error', 'Tipo de criptografia inválido.');
            return Response::redirect('/admin/email');
        }
        $porta = (int) $portRaw;
        if ($portRaw === '' || $porta < self::PORTA_MIN || $porta > self::PORTA_MAX) {
            Flash::set('error', 'Porta SMTP inválida (1–65535).');
            return Response::redirect('/admin/email');
        }

        // ── Gravar ────────────────────────────────────────────────────────────
        $dados = [
            'email_from_name'    => $fromName,
            'email_from_address' => $fromAddress,
            'smtp_enabled'       => $enabled,
            'smtp_host'          => $host,
            'smtp_port'          => (string) $porta,
            'smtp_username'      => $username,
            'smtp_encryption'    => $encryption,
        ];

        // Senha: só atualiza se um novo valor foi fornecido
        if ($password !== '') {
            $dados['smtp_password'] = $password;
        }

        $this->repo->salvarMuitos($dados);

        Flash::set('success', 'Configurações de e-mail salvas com sucesso.');
        return Response::redirect('/admin/email');
    }

    // ── Teste de envio ────────────────────────────────────────────────────────

    public function testar(Request $request): Response
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }

        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Token inválido. Tente novamente.');
            return Response::redirect('/admin/email');
        }

        $destino = strtolower(trim((string) $request->input('email_destino_teste', '')));
        if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Informe um e-mail de destino válido para o teste.');
            return Response::redirect('/admin/email');
        }

        try {
            (new EmailService($this->repo))->testar($destino);
            Flash::set('success', "E-mail de teste enviado para {$destino} com sucesso.");
        } catch (MailerException) {
            // PHPMailer já logou internamente — não expomos detalhes SMTP/senha
            Flash::set('error', 'Falha no envio SMTP. Verifique host, porta, credenciais e criptografia.');
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        } catch (\Throwable) {
            Flash::set('error', 'Erro inesperado ao tentar enviar e-mail de teste.');
        }

        return Response::redirect('/admin/email');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function carregarCfg(): array
    {
        return $this->repo->listarPorPrefixo('email_')
             + $this->repo->listarPorPrefixo('smtp_');
    }
}
