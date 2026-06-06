<?php
declare(strict_types=1);

namespace App\Services;

final class ClienteDocumentoLookupService
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly ClienteDocumentoSettingsService $settings = new ClienteDocumentoSettingsService(),
    ) {}

    /**
     * @return array<string, string>
     */
    public function consultarCpf(string $cpf): array
    {
        $config = $this->settings->obter();
        $apiKey = trim((string) ($config['cpfhub_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new ClienteDocumentoLookupException(
                'API Key do CPFHub não configurada. Acesse Clientes > Configuração CPF/CNPJ.',
                500
            );
        }

        $baseUrl = (string) ($config['cpfhub_base_url'] ?? 'https://api.cpfhub.io');
        $payload = $this->requestJson($this->buildCpfLookupUrl($baseUrl, $cpf), [
            'Accept: application/json',
            'x-api-key: ' . $apiKey,
            'User-Agent: Multimaquinas-ERP/1.0',
        ]);

        $data = isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : $payload;

        $nome = trim((string) ($data['name'] ?? $data['nome'] ?? ''));
        if ($nome === '') {
            throw new ClienteDocumentoLookupException(
                $this->extractErrorMessage($payload) ?: 'CPF não localizado na base da CPFHub.',
                404
            );
        }

        return [
            'tipo'            => 'cpf',
            'nome'            => $nome,
            'data_nascimento' => $this->normalizeDate((string) ($data['birthDate'] ?? $data['data_nascimento'] ?? $data['nascimento'] ?? '')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function consultarCnpj(string $cnpj): array
    {
        $config = $this->settings->obter();
        $baseUrl = rtrim((string) ($config['cnpj_base_url'] ?? 'https://brasilapi.com.br/api/cnpj/v1'), '/');
        if ($baseUrl === '') {
            throw new ClienteDocumentoLookupException(
                'URL da consulta de CNPJ não configurada. Acesse Clientes > Configuração CPF/CNPJ.',
                500
            );
        }

        $dados = $this->requestJson($baseUrl . '/' . $cnpj, [
            'Accept: application/json',
            'User-Agent: Multimaquinas-ERP/1.0',
        ]);

        $nome = trim((string) ($dados['razao_social'] ?? $dados['nome'] ?? ''));
        if ($nome === '') {
            throw new ClienteDocumentoLookupException(
                $this->extractErrorMessage($dados) ?: 'CNPJ não encontrado.',
                404
            );
        }

        $logradouro = trim(implode(' ', array_filter([
            $dados['descricao_tipo_de_logradouro'] ?? null,
            $dados['logradouro'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '')));

        return [
            'tipo'          => 'cnpj',
            'nome'          => $nome,
            'nome_fantasia' => trim((string) ($dados['nome_fantasia'] ?? '')),
            'telefone'      => trim((string) ($dados['ddd_telefone_1'] ?? '')),
            'email'         => trim((string) ($dados['email'] ?? '')),
            'cep'           => preg_replace('/\D/', '', (string) ($dados['cep'] ?? '')) ?? '',
            'endereco'      => $logradouro,
            'numero'        => trim((string) ($dados['numero'] ?? '')),
            'complemento'   => trim((string) ($dados['complemento'] ?? '')),
            'bairro'        => trim((string) ($dados['bairro'] ?? '')),
            'cidade'        => trim((string) ($dados['municipio'] ?? '')),
            'uf'            => trim((string) ($dados['uf'] ?? '')),
        ];
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    private function requestJson(string $url, array $headers): array
    {
        [$body, $status] = function_exists('curl_init')
            ? $this->requestJsonWithCurl($url, $headers)
            : $this->requestJsonWithStream($url, $headers);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new ClienteDocumentoLookupException('Resposta JSON inválida do provedor externo.', 502);
        }

        if ($status >= 400 || (($data['success'] ?? true) === false)) {
            throw new ClienteDocumentoLookupException(
                $this->mapErrorMessage($status, $data),
                $this->mapStatusCode($status)
            );
        }

        return $data;
    }

    /**
     * @param array<int, string> $headers
     * @return array{0:string,1:int}
     */
    private function requestJsonWithCurl(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($body === false) {
            throw new ClienteDocumentoLookupException(
                'Falha de comunicação com o provedor externo: ' . $error,
                502
            );
        }

        return [(string) $body, $status];
    }

    /**
     * @param array<int, string> $headers
     * @return array{0:string,1:int}
     */
    private function requestJsonWithStream(string $url, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'header' => implode("\r\n", $headers) . "\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new ClienteDocumentoLookupException(
                'Falha de comunicação com o provedor externo.',
                502
            );
        }

        $status = 200;
        foreach ($http_response_header ?? [] as $headerLine) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/i', $headerLine, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }

        return [(string) $body, $status];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapErrorMessage(int $status, array $data): string
    {
        if (in_array($status, [401, 403], true)) {
            return 'CPFHub rejeitou a chave API configurada. Revise a configuração do módulo.';
        }

        if ($status === 400) {
            return $this->extractErrorMessage($data) ?: 'CPF com formato inválido. Informe 11 dígitos.';
        }

        if ($status === 404) {
            return $this->extractErrorMessage($data) ?: 'Documento não encontrado.';
        }

        if ($status === 422) {
            return $this->extractErrorMessage($data) ?: 'CPF inválido. Verifique os dígitos informados.';
        }

        if ($status === 429) {
            return 'Limite de consultas atingido. Aguarde o intervalo da API ou revise a cota do seu plano.';
        }

        return $this->extractErrorMessage($data) ?: ('Consulta externa retornou HTTP ' . $status . '.');
    }

    private function mapStatusCode(int $status): int
    {
        return match ($status) {
            401, 403 => 502,
            400      => 400,
            404      => 404,
            422      => 422,
            429      => 429,
            default  => $status >= 400 ? 502 : 500,
        };
    }

    private function buildCpfLookupUrl(string $configuredBaseUrl, string $cpf): string
    {
        $baseUrl = trim($configuredBaseUrl);
        if ($baseUrl === '') {
            $baseUrl = 'https://api.cpfhub.io';
        }

        if (str_contains($baseUrl, '{cpf}')) {
            return str_replace('{cpf}', rawurlencode($cpf), $baseUrl);
        }

        $baseUrl = rtrim($baseUrl, '/');
        $baseUrl = (string) preg_replace('~/cpf$~i', '', $baseUrl);

        return rtrim($baseUrl, '/') . '/cpf/' . rawurlencode($cpf);
    }

    /**
     * Extrai a mensagem de erro da resposta do provedor externo.
     * Suporta tanto `error` como string quanto como objeto `{"message": "..."}` (padrão CPFHub).
     *
     * @param array<string, mixed> $data
     */
    private function extractErrorMessage(array $data): string
    {
        $candidates = [
            // CPFHub retorna: {"error": {"message": "..."}} — extrair o sub-campo
            is_array($data['error'] ?? null) ? ($data['error']['message'] ?? null) : null,
            // Fallback: error como string simples
            is_string($data['error'] ?? null) ? $data['error'] : null,
            $data['message'] ?? null,
            $data['details'] ?? null,
            is_array($data['data'] ?? null) ? ($data['data']['message'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            $message = trim((string) $candidate);
            if ($message !== '') {
                return $message;
            }
        }

        return '';
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) {
            return $value;
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }

        return $value;
    }
}
