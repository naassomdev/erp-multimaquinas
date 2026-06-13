<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class WhatsappService
{
    public function __construct(private readonly PDO $pdo) {}

    public function enviarTexto(string $telefone, string $mensagem, bool $dryRunContaComoEnviado = false): bool
    {
        return $this->enviarTextoComRetorno($telefone, $mensagem, $dryRunContaComoEnviado)['ok'];
    }

    /**
     * Igual a enviarTexto(), mas devolve o resultado detalhado em vez de apenas bool.
     *
     * Nunca lança: qualquer falha de rede/HTTP da Evolution vira ['ok' => false] com o
     * erro capturado em 'erro', para que um envio em lote não aborte no meio nem derrube
     * a tela. A resposta crua da API (decodificada) fica em 'response' para auditoria.
     *
     * @return array{ok:bool, http:int, response:mixed, erro:?string, modo:string}
     */
    public function enviarTextoComRetorno(string $telefone, string $mensagem, bool $dryRunContaComoEnviado = false): array
    {
        $telefone = trim($telefone);
        $mensagem = trim($mensagem);
        if ($telefone === '' || $mensagem === '') {
            return ['ok' => false, 'http' => 0, 'response' => null, 'erro' => 'Telefone ou mensagem vazio.', 'modo' => 'invalido'];
        }

        $enabled = filter_var($this->env('WHATSAPP_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        $dryRun = filter_var($this->env('WHATSAPP_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN);
        $timeout = max(1, (int) $this->env('WHATSAPP_TIMEOUT', '3'));

        if (!$enabled) {
            error_log("[WhatsApp][DISABLED] Para: {$telefone} | Msg: " . substr($mensagem, 0, 80));
            return ['ok' => false, 'http' => 0, 'response' => null, 'erro' => 'WhatsApp desabilitado (.env).', 'modo' => 'disabled'];
        }

        if (!$this->whatsappHabilitadoNoBanco()) {
            error_log("[WhatsApp][DB-DISABLED] Para: {$telefone} | desligado via configurações do sistema.");
            return ['ok' => false, 'http' => 0, 'response' => null, 'erro' => 'WhatsApp desabilitado (configurações).', 'modo' => 'db-disabled'];
        }

        if ($dryRun) {
            error_log("[WhatsApp][DRY-RUN] Para: {$telefone} | Msg: " . substr($mensagem, 0, 80));
            return ['ok' => $dryRunContaComoEnviado, 'http' => 0, 'response' => null, 'erro' => null, 'modo' => 'dry-run'];
        }

        $url = $this->env('WHATSAPP_API_URL');
        $apiKey = $this->env('WHATSAPP_API_KEY');
        $instance = $this->env('WHATSAPP_INSTANCE');
        if ($url === '' || $apiKey === '' || $instance === '') {
            return ['ok' => false, 'http' => 0, 'response' => null, 'erro' => 'Credenciais do WhatsApp ausentes.', 'modo' => 'sem-credencial'];
        }

        $destino = $this->normalizarDestinoWhatsapp($telefone);
        if ($destino === null) {
            return ['ok' => false, 'http' => 0, 'response' => null, 'erro' => 'Número inválido.', 'modo' => 'numero-invalido'];
        }

        $endpoint = rtrim($url, '/') . '/message/sendText/' . rawurlencode($instance);
        $body = json_encode([
            'number' => $destino,
            'text'   => $mensagem,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->postJson($endpoint, $apiKey, $body, $timeout);
            error_log("[WhatsApp][SENT] Para: {$destino} | HTTP {$response['code']}");
            $decoded = json_decode($response['response'], true);
            return [
                'ok'       => true,
                'http'     => $response['code'],
                'response' => $decoded ?? $response['response'],
                'erro'     => null,
                'modo'     => 'enviado',
            ];
        } catch (\Throwable $e) {
            error_log("[WhatsApp][FAIL] Para: {$destino} | " . $e->getMessage());
            return ['ok' => false, 'http' => 0, 'response' => null, 'erro' => $e->getMessage(), 'modo' => 'falha-api'];
        }
    }

    public function enviarMidia(string $telefone, string $mensagem, string $fotoUrl, bool $dryRunContaComoEnviado = false): bool
    {
        $fotoLocal = $this->resolverFotoLocal($fotoUrl);
        if ($fotoLocal === null) {
            error_log("[WhatsApp][MEDIA-FALLBACK] Foto indisponivel ({$fotoUrl}); enviando texto.");
            return $this->enviarTexto($telefone, $mensagem, $dryRunContaComoEnviado);
        }

        $telefone = trim($telefone);
        $mensagem = trim($mensagem);
        if ($telefone === '' || $mensagem === '') {
            return false;
        }

        $enabled = filter_var($this->env('WHATSAPP_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        $dryRun = filter_var($this->env('WHATSAPP_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN);
        $timeout = max(1, (int) $this->env('WHATSAPP_TIMEOUT', '3'));

        if (!$enabled) {
            error_log("[WhatsApp][DISABLED-MEDIA] Para: {$telefone} | Foto: {$fotoUrl}");
            return false;
        }

        if (!$this->whatsappHabilitadoNoBanco()) {
            error_log("[WhatsApp][DB-DISABLED-MEDIA] Para: {$telefone} | desligado via configurações do sistema.");
            return false;
        }

        if ($dryRun) {
            error_log("[WhatsApp][DRY-RUN-MEDIA] Para: {$telefone} | Foto: {$fotoUrl} | Msg: " . substr($mensagem, 0, 80));
            return $dryRunContaComoEnviado;
        }

        $url = $this->env('WHATSAPP_API_URL');
        $apiKey = $this->env('WHATSAPP_API_KEY');
        $instance = $this->env('WHATSAPP_INSTANCE');
        if ($url === '' || $apiKey === '' || $instance === '') {
            return false;
        }

        $destino = $this->normalizarDestinoWhatsapp($telefone);
        if ($destino === null) {
            return false;
        }

        $conteudo = file_get_contents($fotoLocal);
        if ($conteudo === false || $conteudo === '') {
            error_log("[WhatsApp][MEDIA-FALLBACK] Falha ao ler foto ({$fotoLocal}); enviando texto.");
            return $this->enviarTexto($telefone, $mensagem, $dryRunContaComoEnviado);
        }

        $mime = mime_content_type($fotoLocal) ?: 'image/jpeg';
        $endpoint = rtrim($url, '/') . '/message/sendMedia/' . rawurlencode($instance);
        $body = json_encode([
            'number'    => $destino,
            'mediatype' => 'image',
            'mimetype'  => $mime,
            'caption'   => $mensagem,
            'media'     => base64_encode($conteudo),
            'fileName'  => 'foto_recepcao.jpg',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->postJson($endpoint, $apiKey, $body, $timeout, false);
        error_log("[WhatsApp][SENT-MEDIA] Para: {$destino} | HTTP {$response['code']}");
        return true;
    }

    private function env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value !== false) {
            return (string) $value;
        }

        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        return $default;
    }

    private function whatsappHabilitadoNoBanco(): bool
    {
        $stmt = $this->pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'whatsapp_enabled'");
        $stmt->execute();
        return ($stmt->fetchColumn() ?: '0') === '1';
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

    /**
     * @return array{code:int,response:string}
     */
    private function postJson(string $endpoint, string $apiKey, string|false $body, int $timeout, bool $verifyPeer = true): array
    {
        if ($body === false) {
            throw new RuntimeException('Falha ao serializar payload do WhatsApp.');
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL para WhatsApp.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_SSL_VERIFYPEER => $verifyPeer,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apikey: ' . $apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false || $code >= 400) {
            throw new RuntimeException("HTTP {$code} {$err}");
        }

        return [
            'code' => $code,
            'response' => (string) $response,
        ];
    }
}
