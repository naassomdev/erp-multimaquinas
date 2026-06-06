<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use RuntimeException;

final class CertificateManager
{
    public function __construct(
        private readonly string $pfxPath,
        private readonly string $password
    ) {
        if (!is_readable($this->pfxPath)) {
            throw new RuntimeException(
                "Certificado não encontrado ou sem permissão de leitura: {$this->pfxPath}"
            );
        }
    }

    /**
     * Exporta o certificado em formato PEM para uso pela nfse-php.
     * Retorna ['cert' => '...pem...', 'pkey' => '...pem...']
     */
    public function exportPem(): array
    {
        $pfxContent = (string)file_get_contents($this->pfxPath);
        $certs      = [];

        if (!openssl_pkcs12_read($pfxContent, $certs, $this->password)) {
            throw new RuntimeException(
                'Falha ao ler o certificado .pfx. Verifique se a senha está correta ou se o arquivo não está corrompido.'
            );
        }

        return [
            'cert' => $certs['cert'],
            'pkey' => $certs['pkey'],
        ];
    }

    /**
     * Valida a validade do certificado sem lançar exceção.
     * Útil para dashboards de monitoramento e alertas preventivos.
     */
    public function validarValidade(): array
    {
        $pem      = $this->exportPem();
        $certInfo = openssl_x509_parse($pem['cert']);

        if ($certInfo === false) {
            throw new RuntimeException('Não foi possível parsear o certificado PEM.');
        }

        $validAte      = (int)($certInfo['validTo_time_t'] ?? 0);
        $diasRestantes = (int)(($validAte - time()) / 86400);

        return [
            'valido'         => $diasRestantes > 0,
            'dias_restantes' => $diasRestantes,
            'expira_em'      => date('d/m/Y', $validAte),
            'razao_social'   => $certInfo['subject']['CN'] ?? '',
            'serial'         => $certInfo['serialNumberHex'] ?? '',
        ];
    }

    /**
     * Retorna o conteúdo .pfx bruto (para libs que exigem o binário em vez do PEM).
     */
    public function rawPfx(): string
    {
        return (string)file_get_contents($this->pfxPath);
    }

    public function password(): string
    {
        return $this->password;
    }
}
