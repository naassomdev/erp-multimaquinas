<?php
declare(strict_types=1);

namespace App\Services\Pdv;

use App\Repositories\ConfiguracaoRepository;

final class PdvSettingsService
{
    private const DEFAULTS = [
        'pdv_enabled' => '0',
        'pdv_mode' => 'off',
        'pdv_fiscal_enabled' => '0',
        'pdv_recibo_enabled' => '1',
        'pdv_write_enabled' => '0',
        'pdv_write_admin_only' => '1',
    ];

    public function __construct(
        private readonly ConfiguracaoRepository $repo = new ConfiguracaoRepository(),
    ) {}

    /**
     * @return array<string, string>
     */
    public function obter(): array
    {
        $rows = $this->repo->listarPorPrefixo('pdv_');

        return [
            'pdv_enabled' => (string)($rows['pdv_enabled'] ?? self::DEFAULTS['pdv_enabled']),
            'pdv_mode' => (string)($rows['pdv_mode'] ?? self::DEFAULTS['pdv_mode']),
            'pdv_fiscal_enabled' => (string)($rows['pdv_fiscal_enabled'] ?? self::DEFAULTS['pdv_fiscal_enabled']),
            'pdv_recibo_enabled' => (string)($rows['pdv_recibo_enabled'] ?? self::DEFAULTS['pdv_recibo_enabled']),
            'pdv_write_enabled' => (string)($rows['pdv_write_enabled'] ?? self::DEFAULTS['pdv_write_enabled']),
            'pdv_write_admin_only' => (string)($rows['pdv_write_admin_only'] ?? self::DEFAULTS['pdv_write_admin_only']),
        ];
    }

    public function enabled(): bool
    {
        return $this->toBool($this->obter()['pdv_enabled']);
    }

    public function fiscalEnabled(): bool
    {
        return $this->toBool($this->obter()['pdv_fiscal_enabled']);
    }

    public function reciboEnabled(): bool
    {
        return $this->toBool($this->obter()['pdv_recibo_enabled']);
    }

    public function writeEnabled(): bool
    {
        return $this->toBool($this->obter()['pdv_write_enabled']);
    }

    public function writeAdminOnly(): bool
    {
        return $this->toBool($this->obter()['pdv_write_admin_only']);
    }

    public function mode(): string
    {
        $mode = strtolower(trim($this->obter()['pdv_mode']));
        return in_array($mode, ['off', 'shadow', 'live'], true) ? $mode : 'off';
    }

    private function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }
}
