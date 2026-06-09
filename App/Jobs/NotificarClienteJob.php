<?php
declare(strict_types=1);

namespace App\Jobs;

use PDO;
use RuntimeException;

/**
 * Handler do job 'notificar_cliente'. Tenta enviar a mensagem via WhatsApp
 * (Evolution API ou compatível) e/ou SMTP. Se nenhum canal está configurado,
 * registra em storage/logs/notificacoes.log e devolve sucesso — assim a fila
 * não fica retentando para sempre num ambiente onde a notificação não foi
 * habilitada.
 *
 * Configuração via .env:
 *   WHATSAPP_API_URL=https://api.evolution.local
 *   WHATSAPP_API_KEY=...
 *   WHATSAPP_INSTANCE=multimaquinas
 *   MAIL_FROM=naoresponda@multimaquinas.site
 *   MAIL_FROM_NAME="Multimáquinas Assistência"
 */
final class NotificarClienteJob
{
    public function __construct(private readonly PDO $pdo) {}

    private function env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value !== false) {
            return (string)$value;
        }

        if (isset($_ENV[$key])) {
            return (string)$_ENV[$key];
        }

        return $default;
    }

    public function handle(array $payload): void
    {
        $telefone = (string)($payload['telefone'] ?? '');
        $email    = (string)($payload['email']    ?? '');
        $mensagem = (string)($payload['mensagem'] ?? '');
        $osId     = (string)($payload['os_id']    ?? '');
        $fotoUrl  = trim((string)($payload['foto_url'] ?? ''));

        if ($mensagem === '') {
            throw new RuntimeException('Payload sem mensagem.');
        }

        $enviou = false;

        if ($telefone !== '') {
            try {
                $enviadoWhatsapp = $fotoUrl !== ''
                    ? $this->enviarMidia($telefone, $mensagem, $fotoUrl)
                    : $this->enviarWhatsapp($telefone, $mensagem);

                if ($enviadoWhatsapp) {
                    $enviou = true;
                    $this->logar("[OS {$osId}] WhatsApp enviado para {$telefone}");
                }
            } catch (\Throwable $e) {
                $this->logar("[OS {$osId}] WhatsApp falhou ({$telefone}): " . $e->getMessage());
            }
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $assunto = "Sua OS #{$osId} foi aberta — Multimáquinas";
                if ($this->enviarEmail($email, $assunto, $mensagem)) {
                    $enviou = true;
                    $this->logar("[OS {$osId}] E-mail enviado para {$email}");
                }
            } catch (\Throwable $e) {
                $this->logar("[OS {$osId}] E-mail falhou ({$email}): " . $e->getMessage());
            }
        }

        if (!$enviou) {
            // Sem canal configurado/funcionando: log e fim. Não retentamos.
            $this->logar("[OS {$osId}] Nenhum canal disponível — payload registrado:\n" . $mensagem);
        }
    }

    private function enviarWhatsapp(string $telefone, string $mensagem): bool
    {
        // Safety gate: desligado até a Evolution API estar ativa e a VPS atualizada.
        $enabled = filter_var($this->env('WHATSAPP_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        $dryRun = filter_var($this->env('WHATSAPP_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN);
        $timeout = max(1, (int)$this->env('WHATSAPP_TIMEOUT', '3'));

        if (!$enabled) {
            error_log("[WhatsApp][DISABLED] Para: {$telefone} | Msg: " . substr($mensagem, 0, 80));
            return false;
        }

        // Guard de banco — admin pode desligar sem mexer no servidor.
        $stmt = $this->pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'whatsapp_enabled'");
        $stmt->execute();
        $dbEnabled = ($stmt->fetchColumn() ?: '0') === '1';
        if (!$dbEnabled) {
            error_log("[WhatsApp][DB-DISABLED] Para: {$telefone} | desligado via configurações do sistema.");
            return false;
        }

        if ($dryRun) {
            error_log("[WhatsApp][DRY-RUN] Para: {$telefone} | Msg: " . substr($mensagem, 0, 80));
            return true;
        }

        $url      = $this->env('WHATSAPP_API_URL');
        $apiKey   = $this->env('WHATSAPP_API_KEY');
        $instance = $this->env('WHATSAPP_INSTANCE');

        if ($url === '' || $apiKey === '' || $instance === '') {
            return false; // não configurado
        }

        $destino = $this->normalizarDestinoWhatsapp($telefone);
        if ($destino === null) return false;

        $endpoint = rtrim($url, '/') . '/message/sendText/' . rawurlencode($instance);
        $body = json_encode([
            'number' => $destino,
            'text'   => $mensagem,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apikey: ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false || $code >= 400) {
            throw new RuntimeException("HTTP {$code} {$err}");
        }

        error_log("[WhatsApp][SENT] Para: {$destino} | HTTP {$code}");
        return true;
    }

    private function enviarMidia(string $telefone, string $mensagem, string $fotoUrl): bool
    {
        $fotoLocal = $this->resolverFotoLocal($fotoUrl);
        if ($fotoLocal === null) {
            error_log("[WhatsApp][MEDIA-FALLBACK] Foto indisponivel ({$fotoUrl}); enviando texto.");
            return $this->enviarWhatsapp($telefone, $mensagem);
        }

        $enabled = filter_var($this->env('WHATSAPP_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        $dryRun = filter_var($this->env('WHATSAPP_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN);
        $timeout = max(1, (int)$this->env('WHATSAPP_TIMEOUT', '3'));

        if (!$enabled) {
            error_log("[WhatsApp][DISABLED-MEDIA] Para: {$telefone} | Foto: {$fotoUrl}");
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'whatsapp_enabled'");
        $stmt->execute();
        $dbEnabled = ($stmt->fetchColumn() ?: '0') === '1';
        if (!$dbEnabled) {
            error_log("[WhatsApp][DB-DISABLED-MEDIA] Para: {$telefone} | desligado via configurações do sistema.");
            return false;
        }

        if ($dryRun) {
            error_log("[WhatsApp][DRY-RUN-MEDIA] Para: {$telefone} | Foto: {$fotoUrl} | Msg: " . substr($mensagem, 0, 80));
            return true;
        }

        $url      = $this->env('WHATSAPP_API_URL');
        $apiKey   = $this->env('WHATSAPP_API_KEY');
        $instance = $this->env('WHATSAPP_INSTANCE');

        if ($url === '' || $apiKey === '' || $instance === '') {
            return false;
        }

        $destino = $this->normalizarDestinoWhatsapp($telefone);
        if ($destino === null) return false;

        $conteudo = file_get_contents($fotoLocal);
        if ($conteudo === false || $conteudo === '') {
            error_log("[WhatsApp][MEDIA-FALLBACK] Falha ao ler foto ({$fotoLocal}); enviando texto.");
            return $this->enviarWhatsapp($telefone, $mensagem);
        }

        $mime = mime_content_type($fotoLocal) ?: 'image/jpeg';
        $base64 = base64_encode($conteudo);
        $endpoint = rtrim($url, '/') . '/message/sendMedia/' . rawurlencode($instance);
        $body = json_encode([
            'number'    => $destino,
            'mediatype' => 'image',
            'mimetype'  => $mime,
            'caption'   => $mensagem,
            'media'     => $base64,
            'fileName'  => 'foto_recepcao.jpg',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apikey: ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false || $code >= 400) {
            error_log("[WhatsApp][ERROR-MEDIA] Para: {$destino} | HTTP {$code} {$err}");
            return false;
        }

        error_log("[WhatsApp][SENT-MEDIA] Para: {$destino} | HTTP {$code}");
        return true;
    }

    private function normalizarDestinoWhatsapp(string $telefone): ?string
    {
        $telefone = trim($telefone);
        if ($telefone === '') {
            return null;
        }

        if (str_contains($telefone, '@')) {
            return $telefone;
        }

        $numero = preg_replace('/\D/', '', $telefone) ?? '';
        if ($numero === '') {
            return null;
        }
        if (!str_starts_with($numero, '55')) {
            $numero = '55' . $numero;
        }
        return $numero;
    }

    private function resolverFotoLocal(string $fotoUrl): ?string
    {
        $fotoUrl = trim($fotoUrl);
        if ($fotoUrl === '' || preg_match('#^https?://#i', $fotoUrl)) {
            return null;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $candidatos = [];

        if (str_starts_with($fotoUrl, '/')) {
            $candidatos[] = $basePath . '/public' . $fotoUrl;
            $candidatos[] = $basePath . $fotoUrl;
        } else {
            $candidatos[] = $basePath . '/public/' . $fotoUrl;
            $candidatos[] = $basePath . '/' . $fotoUrl;
        }

        foreach (array_unique($candidatos) as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function enviarEmail(string $para, string $assunto, string $mensagem): bool
    {
        $from     = $this->env('MAIL_FROM');
        $fromName = $this->env('MAIL_FROM_NAME', 'Multimáquinas');

        if ($from === '') return false; // não configurado

        $headers = [
            'From: ' . sprintf('%s <%s>', $fromName, $from),
            'Reply-To: ' . $from,
            'Content-Type: text/plain; charset=UTF-8',
            'MIME-Version: 1.0',
        ];

        // mail() é o mínimo viável; em produção considere SMTP via lib dedicada.
        $ok = @mail($para, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $mensagem, implode("\r\n", $headers));
        if (!$ok) {
            throw new RuntimeException('mail() retornou falso (verifique sendmail/SMTP do servidor)');
        }
        return true;
    }

    private function logar(string $linha): void
    {
        $dir = (defined('BASE_PATH') ? BASE_PATH : __DIR__ . '/../..') . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $path = $dir . '/notificacoes.log';
        @file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] ' . $linha . "\n", FILE_APPEND);
    }
}
