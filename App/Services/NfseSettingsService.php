<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Repositories\ConfiguracaoRepository;
use App\Services\Fiscal\NfseService;

final class NfseSettingsService
{
    private const MAP = [
        'enabled'               => 'nfse_enabled',
        'ambiente'              => 'nfse_ambiente',
        'write_enabled'         => 'nfse_write_enabled',
        'admin_only'            => 'nfse_admin_only',
        'contador_aprova_total_os' => 'nfse_contador_aprova_total_os',
        'exigir_conferencia_manual' => 'nfse_exigir_conferencia_manual',
        'danfse_enabled'        => 'danfse_enabled',
        'danfse_shadow_mode'    => 'danfse_shadow_mode',
        'danfse_admin_only'     => 'danfse_admin_only',
        'danfse_external_download_enabled' => 'danfse_external_download_enabled',
        'send_whatsapp_enabled' => 'nfse_send_whatsapp_enabled',
        'send_email_enabled'    => 'nfse_send_email_enabled',
        'real_enabled'          => 'nfse_real_enabled',
        'cert_path'             => 'nfse_cert_path',
        'cert_password'         => 'nfse_cert_password',
        'prestador_cnpj'        => 'nfse_prestador_cnpj',
        'prestador_razao_social'=> 'nfse_prestador_razao_social',
        'prestador_inscricao_municipal' => 'nfse_prestador_inscricao_municipal',
        'prestador_codigo_municipio'    => 'nfse_prestador_codigo_municipio',
        'prestador_cep'         => 'nfse_prestador_cep',
        'prestador_logradouro'  => 'nfse_prestador_logradouro',
        'prestador_numero'      => 'nfse_prestador_numero',
        'prestador_complemento' => 'nfse_prestador_complemento',
        'prestador_bairro'      => 'nfse_prestador_bairro',
        'prestador_telefone'    => 'nfse_prestador_telefone',
        'prestador_email'       => 'nfse_prestador_email',
        'prestador_opcao_simples' => 'nfse_prestador_opcao_simples',
        'prestador_regime_apuracao_sn' => 'nfse_prestador_regime_apuracao_sn',
        'prestador_regime_especial' => 'nfse_prestador_regime_especial',
        'serie_dps'             => 'nfse_serie_dps',
        'codigo_trib_nacional'  => 'nfse_codigo_trib_nacional',
        'codigo_trib_municipal' => 'nfse_codigo_trib_municipal',
        'descricao_servico_padrao' => 'nfse_descricao_servico_padrao',
        'piscofins_cst'         => 'nfse_piscofins_cst',
        'endpoint_homologacao'  => 'nfse_endpoint_homologacao',
        'endpoint_producao'     => 'nfse_endpoint_producao',
    ];

    public function __construct(
        private readonly ConfiguracaoRepository $repo = new ConfiguracaoRepository(),
    ) {}

    /**
     * @return array<string, string>
     */
    public function obter(): array
    {
        $rows = $this->repo->listarPorPrefixo('nfse_');
        $empresa = $this->repo->listarPorPrefixo('empresa_');
        $envCertPath = (string)(Env::get('CERT_PATH', '') ?? '');
        $storedCertPath = (string)($rows['nfse_cert_path'] ?? '');
        $certPath = $this->resolveCertPath($storedCertPath, $envCertPath);

        $defaults = [
            'enabled'               => $rows['nfse_enabled'] ?? '0',
            'ambiente'              => $rows['nfse_ambiente'] ?? 'homologacao',
            'write_enabled'         => $rows['nfse_write_enabled'] ?? '0',
            'admin_only'            => $rows['nfse_admin_only'] ?? '1',
            'contador_aprova_total_os' => $rows['nfse_contador_aprova_total_os'] ?? '0',
            'exigir_conferencia_manual' => $rows['nfse_exigir_conferencia_manual'] ?? '1',
            'danfse_enabled'        => $rows['danfse_enabled'] ?? '0',
            'danfse_shadow_mode'    => $rows['danfse_shadow_mode'] ?? '1',
            'danfse_admin_only'     => $rows['danfse_admin_only'] ?? '1',
            'danfse_external_download_enabled' => $rows['danfse_external_download_enabled'] ?? '0',
            'send_whatsapp_enabled' => $rows['nfse_send_whatsapp_enabled'] ?? '0',
            'send_email_enabled'    => $rows['nfse_send_email_enabled'] ?? '0',
            'real_enabled'          => $rows['nfse_real_enabled'] ?? (Env::get('NFSE_REAL_ENABLED', 'false') ?? 'false'),
            'cert_path'             => $certPath,
            'cert_password'         => $rows['nfse_cert_password'] ?? (Env::get('CERT_PASSWORD', '') ?? ''),
            'prestador_cnpj'        => $rows['nfse_prestador_cnpj'] ?? ($empresa['empresa_cnpj'] ?? Env::get('NFSE_PRESTADOR_CNPJ', '') ?? ''),
            'prestador_razao_social'=> $rows['nfse_prestador_razao_social'] ?? ($empresa['empresa_razao_social'] ?? Env::get('NFSE_PRESTADOR_RAZAO_SOCIAL', '') ?? ''),
            'prestador_inscricao_municipal' => $rows['nfse_prestador_inscricao_municipal'] ?? (Env::get('NFSE_PRESTADOR_INSCRICAO_MUNICIPAL', '') ?? ''),
            'prestador_codigo_municipio'    => $rows['nfse_prestador_codigo_municipio'] ?? (Env::get('NFSE_PRESTADOR_CODIGO_MUNICIPIO', '') ?? ''),
            'prestador_cep'         => $rows['nfse_prestador_cep'] ?? (Env::get('NFSE_PRESTADOR_CEP', '') ?? ''),
            'prestador_logradouro'  => $rows['nfse_prestador_logradouro'] ?? ($empresa['empresa_endereco'] ?? Env::get('NFSE_PRESTADOR_LOGRADOURO', '') ?? ''),
            'prestador_numero'      => $rows['nfse_prestador_numero'] ?? (Env::get('NFSE_PRESTADOR_NUMERO', '') ?? ''),
            'prestador_complemento' => $rows['nfse_prestador_complemento'] ?? (Env::get('NFSE_PRESTADOR_COMPLEMENTO', '') ?? ''),
            'prestador_bairro'      => $rows['nfse_prestador_bairro'] ?? (Env::get('NFSE_PRESTADOR_BAIRRO', '') ?? ''),
            'prestador_telefone'    => $rows['nfse_prestador_telefone'] ?? ($empresa['empresa_telefone'] ?? Env::get('NFSE_PRESTADOR_TELEFONE', '') ?? ''),
            'prestador_email'       => $rows['nfse_prestador_email'] ?? ($empresa['empresa_email'] ?? Env::get('NFSE_PRESTADOR_EMAIL', '') ?? ''),
            'prestador_opcao_simples' => $rows['nfse_prestador_opcao_simples'] ?? (Env::get('NFSE_PRESTADOR_OPCAO_SIMPLES', '1') ?? '1'),
            'prestador_regime_apuracao_sn' => $rows['nfse_prestador_regime_apuracao_sn'] ?? (Env::get('NFSE_PRESTADOR_REGIME_APURACAO_SN', '') ?? ''),
            'prestador_regime_especial' => $rows['nfse_prestador_regime_especial'] ?? (Env::get('NFSE_PRESTADOR_REGIME_ESPECIAL', '0') ?? '0'),
            'serie_dps'             => $rows['nfse_serie_dps'] ?? (Env::get('NFSE_SERIE_DPS', '1') ?? '1'),
            'codigo_trib_nacional'  => $rows['nfse_codigo_trib_nacional'] ?? (Env::get('NFSE_CODIGO_TRIB_NACIONAL', '') ?? ''),
            'codigo_trib_municipal' => $rows['nfse_codigo_trib_municipal'] ?? (Env::get('NFSE_CODIGO_TRIB_MUNICIPAL', '') ?? ''),
            'descricao_servico_padrao' => $rows['nfse_descricao_servico_padrao'] ?? (Env::get('NFSE_DESCRICAO_SERVICO_PADRAO', 'Serviço prestado') ?? 'Serviço prestado'),
            'piscofins_cst'         => $rows['nfse_piscofins_cst'] ?? (Env::get('NFSE_PISCOFINS_CST', '08') ?? '08'),
            'endpoint_homologacao'  => $rows['nfse_endpoint_homologacao'] ?? (Env::get('NFSE_ENDPOINT_HOMOLOGACAO', '') ?? ''),
            'endpoint_producao'     => $rows['nfse_endpoint_producao'] ?? (Env::get('NFSE_ENDPOINT_PRODUCAO', '') ?? ''),
        ];

        foreach ($defaults as $key => $value) {
            $defaults[$key] = (string)$value;
        }

        return $defaults;
    }

    private function resolveCertPath(string $storedPath, string $envPath): string
    {
        $storedPath = trim($storedPath);
        $envPath = trim($envPath);

        if ($storedPath !== '' && is_readable($storedPath)) {
            return $storedPath;
        }

        if ($envPath !== '' && is_readable($envPath)) {
            return $envPath;
        }

        return $storedPath !== '' ? $storedPath : $envPath;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function salvar(array $input): void
    {
        $normalized = $this->normalizar($input);
        $payload = [];
        foreach (self::MAP as $field => $key) {
            $payload[$key] = $normalized[$field];
        }

        $this->repo->salvarMuitos($payload);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function normalizar(array $input): array
    {
        $value = static fn (string $key, string $default = ''): string => trim((string)($input[$key] ?? $default));
        $flag = static fn (string $key): bool => in_array(strtolower(trim((string)($input[$key] ?? ''))), ['1', 'true', 'on', 'yes'], true);

        return [
            'enabled'               => $flag('enabled') ? '1' : '0',
            'ambiente'              => in_array($value('ambiente', 'homologacao'), ['homologacao', 'producao'], true) ? $value('ambiente', 'homologacao') : 'homologacao',
            'write_enabled'         => $flag('write_enabled') ? '1' : '0',
            'admin_only'            => $flag('admin_only') ? '1' : '0',
            'contador_aprova_total_os' => $flag('contador_aprova_total_os') ? '1' : '0',
            'exigir_conferencia_manual' => $flag('exigir_conferencia_manual') ? '1' : '0',
            'danfse_enabled'        => $flag('danfse_enabled') ? '1' : '0',
            'danfse_shadow_mode'    => $flag('danfse_shadow_mode') ? '1' : '0',
            'danfse_admin_only'     => $flag('danfse_admin_only') ? '1' : '0',
            'danfse_external_download_enabled' => $flag('danfse_external_download_enabled') ? '1' : '0',
            'send_whatsapp_enabled' => $flag('send_whatsapp_enabled') ? '1' : '0',
            'send_email_enabled'    => $flag('send_email_enabled') ? '1' : '0',
            'real_enabled'          => $flag('real_enabled') ? 'true' : 'false',
            'cert_path'             => $value('cert_path'),
            'cert_password'         => $value('cert_password'),
            'prestador_cnpj'        => preg_replace('/\D/', '', $value('prestador_cnpj')) ?? '',
            'prestador_razao_social'=> $value('prestador_razao_social'),
            'prestador_inscricao_municipal' => $value('prestador_inscricao_municipal'),
            'prestador_codigo_municipio'    => preg_replace('/\D/', '', $value('prestador_codigo_municipio')) ?? '',
            'prestador_cep'         => preg_replace('/\D/', '', $value('prestador_cep')) ?? '',
            'prestador_logradouro'  => $value('prestador_logradouro'),
            'prestador_numero'      => $value('prestador_numero'),
            'prestador_complemento' => $value('prestador_complemento'),
            'prestador_bairro'      => $value('prestador_bairro'),
            'prestador_telefone'    => preg_replace('/\D/', '', $value('prestador_telefone')) ?? '',
            'prestador_email'       => $value('prestador_email'),
            'prestador_opcao_simples' => $value('prestador_opcao_simples', '1'),
            'prestador_regime_apuracao_sn' => $value('prestador_regime_apuracao_sn'),
            'prestador_regime_especial' => $value('prestador_regime_especial', '0'),
            'serie_dps'             => $value('serie_dps', '1'),
            'codigo_trib_nacional'  => preg_replace('/\D/', '', $value('codigo_trib_nacional')) ?? '',
            'codigo_trib_municipal' => $value('codigo_trib_municipal'),
            'descricao_servico_padrao' => $value('descricao_servico_padrao', 'Serviço prestado'),
            'piscofins_cst'         => $value('piscofins_cst', '08'),
            'endpoint_homologacao'  => NfseService::normalizeEndpoint($value('endpoint_homologacao')),
            'endpoint_producao'     => NfseService::normalizeEndpoint($value('endpoint_producao')),
        ];
    }

    /**
     * @param array<string,string>|null $settings
     */
    public function canTransmitReal(?array $settings = null): bool
    {
        $settings ??= $this->obter();
        return ($settings['enabled'] ?? '0') === '1'
            && ($settings['write_enabled'] ?? '0') === '1'
            && filter_var((string)($settings['real_enabled'] ?? 'false'), FILTER_VALIDATE_BOOLEAN)
            && in_array(($settings['ambiente'] ?? 'homologacao'), ['homologacao', 'producao'], true);
    }

    /**
     * @param array<string,string>|null $settings
     */
    public function canRunFiscalWorker(?array $settings = null): bool
    {
        $settings ??= $this->obter();
        return $this->canTransmitReal($settings)
            && ($settings['exigir_conferencia_manual'] ?? '1') !== '1';
    }

    /**
     * @param array<string,string>|null $settings
     */
    public function canDownloadExternalDanfse(?array $settings = null): bool
    {
        $settings ??= $this->obter();
        return $this->canTransmitReal($settings)
            && ($settings['danfse_enabled'] ?? '0') === '1'
            && ($settings['danfse_shadow_mode'] ?? '1') === '0'
            && ($settings['danfse_external_download_enabled'] ?? '0') === '1';
    }

    /**
     * @param array<string,string>|null $settings
     */
    public function canCancelReal(?array $settings = null): bool
    {
        return $this->canTransmitReal($settings);
    }

    /**
     * @param array<string,string>|null $settings
     */
    public function canGenerateFinalDanfse(?array $settings = null): bool
    {
        $settings ??= $this->obter();
        return ($settings['danfse_enabled'] ?? '0') === '1'
            && ($settings['danfse_shadow_mode'] ?? '1') === '0';
    }

    /**
     * @param array<string,string>|null $settings
     * @return array<string,string>
     */
    public function fiscalFlagsSnapshot(?array $settings = null): array
    {
        $settings ??= $this->obter();
        $keys = [
            'enabled',
            'ambiente',
            'write_enabled',
            'real_enabled',
            'exigir_conferencia_manual',
            'danfse_enabled',
            'danfse_shadow_mode',
            'danfse_external_download_enabled',
        ];

        $snapshot = [];
        foreach ($keys as $key) {
            $snapshot[$key] = (string)($settings[$key] ?? '');
        }

        return $snapshot;
    }
}
