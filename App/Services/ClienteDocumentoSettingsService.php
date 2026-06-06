<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Repositories\ConfiguracaoRepository;

final class ClienteDocumentoSettingsService
{
    private const MAP = [
        'cpfhub_api_key'           => 'cliente_docs_cpfhub_api_key',
        'cpfhub_base_url'          => 'cliente_docs_cpfhub_base_url',
        'cpfhub_mcp_url'           => 'cliente_docs_cpfhub_mcp_url',
        'cnpj_base_url'            => 'cliente_docs_cnpj_base_url',
        'plan_name'                => 'cliente_docs_plan_name',
        'monthly_plan_limit'       => 'cliente_docs_monthly_plan_limit',
        'support_whatsapp_number'  => 'cliente_docs_support_whatsapp_number',
        'support_whatsapp_url'     => 'cliente_docs_support_whatsapp_url',
    ];

    private const DEFAULT_SUPPORT_NUMBER = '551132300861';
    private const DEFAULT_SUPPORT_URL = 'https://api.whatsapp.com/send/?app_absent=0&phone=551132300861&text=Ol%C3%A1%21+Gostaria+de+falar+com+a+CPFHub.&type=phone_number';

    public function __construct(
        private readonly ConfiguracaoRepository $repo = new ConfiguracaoRepository(),
    ) {}

    /**
     * @return array<string, string>
     */
    public function obter(): array
    {
        $rows = $this->repo->listarPorPrefixo('cliente_docs_');

        $settings = [
            'cpfhub_api_key'           => $rows['cliente_docs_cpfhub_api_key'] ?? (Env::get('CPFHUB_API_KEY', '') ?? ''),
            'cpfhub_base_url'          => $rows['cliente_docs_cpfhub_base_url'] ?? (Env::get('CPFHUB_BASE_URL', 'https://api.cpfhub.io') ?? 'https://api.cpfhub.io'),
            'cpfhub_mcp_url'           => $rows['cliente_docs_cpfhub_mcp_url'] ?? (Env::get('CPFHUB_MCP_URL', 'https://api.cpfhub.io/mcp') ?? 'https://api.cpfhub.io/mcp'),
            'cnpj_base_url'            => $rows['cliente_docs_cnpj_base_url'] ?? (Env::get('CLIENTES_CNPJ_BASE_URL', 'https://brasilapi.com.br/api/cnpj/v1') ?? 'https://brasilapi.com.br/api/cnpj/v1'),
            'plan_name'                => $rows['cliente_docs_plan_name'] ?? (Env::get('CPFHUB_PLAN_NAME', 'Grátis') ?? 'Grátis'),
            'monthly_plan_limit'       => $rows['cliente_docs_monthly_plan_limit'] ?? (Env::get('CPFHUB_MONTHLY_PLAN_LIMIT', '50') ?? '50'),
            'support_whatsapp_number'  => $rows['cliente_docs_support_whatsapp_number'] ?? (Env::get('CPFHUB_SUPPORT_WHATSAPP', self::DEFAULT_SUPPORT_NUMBER) ?? self::DEFAULT_SUPPORT_NUMBER),
            'support_whatsapp_url'     => $rows['cliente_docs_support_whatsapp_url'] ?? (Env::get('CPFHUB_SUPPORT_URL', self::DEFAULT_SUPPORT_URL) ?? self::DEFAULT_SUPPORT_URL),
            'rate_limit_hint'          => '1 requisição a cada 2 segundos',
            'quickstart_url'           => 'https://cpfhub.io/documentacao/quickstart/php',
            'mcp_doc_url'              => 'https://cpfhub.io/documentacao/mcp',
            'api_reference_url'        => 'https://cpfhub.io/documentacao/referencia/cpf',
            'pricing_url'              => 'https://cpfhub.io/',
        ];

        foreach ($settings as $key => $value) {
            $settings[$key] = (string) $value;
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function salvar(array $input): void
    {
        $normalized = $this->normalizar($input);
        $payload = [];

        foreach (self::MAP as $field => $configKey) {
            $payload[$configKey] = $normalized[$field];
        }

        $this->repo->salvarMuitos($payload);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function normalizar(array $input): array
    {
        $value = static fn (string $key, string $default = ''): string => trim((string) ($input[$key] ?? $default));
        $number = preg_replace('/\D/', '', $value('support_whatsapp_number', self::DEFAULT_SUPPORT_NUMBER)) ?? self::DEFAULT_SUPPORT_NUMBER;
        $limit = preg_replace('/\D/', '', $value('monthly_plan_limit', '50')) ?? '50';

        return [
            'cpfhub_api_key'          => $value('cpfhub_api_key'),
            'cpfhub_base_url'         => rtrim($value('cpfhub_base_url', 'https://api.cpfhub.io'), '/'),
            'cpfhub_mcp_url'          => rtrim($value('cpfhub_mcp_url', 'https://api.cpfhub.io/mcp'), '/'),
            'cnpj_base_url'           => rtrim($value('cnpj_base_url', 'https://brasilapi.com.br/api/cnpj/v1'), '/'),
            'plan_name'               => $value('plan_name', 'Grátis'),
            'monthly_plan_limit'      => $limit !== '' ? (string) max(0, (int) $limit) : '50',
            'support_whatsapp_number' => $number !== '' ? $number : self::DEFAULT_SUPPORT_NUMBER,
            'support_whatsapp_url'    => $value('support_whatsapp_url', self::DEFAULT_SUPPORT_URL),
        ];
    }
}
