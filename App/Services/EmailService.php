<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConfiguracaoRepository;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Serviço de envio de e-mail via SMTP (PHPMailer).
 *
 * Configuração carregada da tabela `configuracoes` (prefixos email_ / smtp_).
 * A assinatura de enviar() aceita anexos para preparar o caminho
 * da ETAPA 10C-3 (orçamento por e-mail com PDF).
 */
final class EmailService
{
    /** @var array<string, string> */
    private readonly array $cfg;

    public function __construct(
        private readonly ConfiguracaoRepository $repo = new ConfiguracaoRepository()
    ) {
        $this->cfg = $repo->listarPorPrefixo('email_')
                   + $repo->listarPorPrefixo('smtp_');
    }

    public function configurado(): bool
    {
        return ($this->cfg['smtp_enabled']       ?? '0') === '1'
            && ($this->cfg['smtp_host']          ?? '') !== ''
            && ($this->cfg['email_from_address'] ?? '') !== '';
    }

    /**
     * Envia e-mail. Lança RuntimeException ou MailerException em falha.
     *
     * Cada elemento de $anexos pode ser:
     *  - arquivo no disco: ['path' => '/caminho', 'name' => 'arquivo.ext']
     *  - bytes em memória: ['content' => '<bytes>', 'name' => 'arquivo.ext', 'mime' => 'application/pdf']
     *
     * @param array<array{path?:string, content?:string, name:string, mime?:string}> $anexos
     */
    public function enviar(
        string $para,
        string $assunto,
        string $corpo,
        array $anexos = []
    ): void {
        $mail = $this->criarMailer();
        $mail->addAddress($para);
        $mail->Subject = $assunto;
        $mail->isHTML(true);
        $mail->Body    = $corpo;
        $mail->AltBody = strip_tags($corpo);

        foreach ($anexos as $a) {
            if (isset($a['content'])) {
                $mail->addStringAttachment(
                    (string) $a['content'],
                    (string) $a['name'],
                    'base64',
                    (string) ($a['mime'] ?? 'application/octet-stream')
                );
            } else {
                $mail->addAttachment((string) $a['path'], (string) $a['name']);
            }
        }

        $mail->send();
    }

    /**
     * Envia e-mail de teste simples para validar as configurações SMTP.
     * Lança em falha — o controller captura e filtra a mensagem.
     */
    public function testar(string $paraDestino): void
    {
        $mail = $this->criarMailer();
        $mail->addAddress($paraDestino);
        $mail->Subject = 'Teste de e-mail — Multimáquinas';
        $mail->isHTML(false);
        $mail->Body    = "Configuração SMTP validada com sucesso.\n\n"
                       . 'Remetente configurado: ' . ($this->cfg['email_from_address'] ?? '—');
        $mail->send();
    }

    // ── privado ────────────────────────────────────────────────────────────────

    private function criarMailer(): PHPMailer
    {
        if (!$this->configurado()) {
            throw new RuntimeException('SMTP não está habilitado ou configurado.');
        }

        $mail = new PHPMailer(true); // true = lança exceções
        $mail->isSMTP();
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Host    = $this->cfg['smtp_host'];
        $mail->Port    = (int) ($this->cfg['smtp_port'] ?? 587);

        $enc = $this->cfg['smtp_encryption'] ?? 'tls';
        $mail->SMTPSecure = match ($enc) {
            'tls'   => PHPMailer::ENCRYPTION_STARTTLS,
            'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
            default => '',
        };

        $username = $this->cfg['smtp_username'] ?? '';
        $password = $this->cfg['smtp_password'] ?? '';
        $mail->SMTPAuth = ($username !== '');
        if ($username !== '') {
            $mail->Username = $username;
            $mail->Password = $password;
        }

        $fromAddr = $this->cfg['email_from_address'];
        $fromName = $this->cfg['email_from_name'] ?? 'Multimáquinas';
        $mail->setFrom($fromAddr, $fromName);

        return $mail;
    }
}
