<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class CertificateManager
{
    private ?array $pemCache = null;

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
     * Exporta o .pfx em PEM. Retorna ['cert' => '...', 'pkey' => '...'].
     */
    public function exportPem(): array
    {
        if (is_array($this->pemCache)) {
            return $this->pemCache;
        }

        $pfxContent = (string)file_get_contents($this->pfxPath);
        $certs      = [];

        if (openssl_pkcs12_read($pfxContent, $certs, $this->password)) {
            return $this->pemCache = [
                'cert' => $certs['cert'],
                'pkey' => $certs['pkey'],
            ];
        }

        return $this->pemCache = $this->exportPemWithLegacyOpenSsl();
    }

    /**
     * Validade do certificado (sem lançar). Para dashboard / alertas preventivos.
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

    public function rawPfx(): string
    {
        return (string)file_get_contents($this->pfxPath);
    }

    public function password(): string
    {
        return $this->password;
    }

    /**
     * Fallback para certificados PKCS#12 antigos (ex.: RC2-40-CBC) rejeitados
     * pelo OpenSSL 3 via openssl_pkcs12_read().
     *
     * @return array{cert:string,pkey:string}
     */
    private function exportPemWithLegacyOpenSsl(): array
    {
        $bin = $this->findOpenSslBinary();
        if ($bin === '') {
            throw new RuntimeException(
                'Falha ao ler o certificado .pfx no OpenSSL do PHP e o binário openssl não está disponível.'
            );
        }

        $base = escapeshellcmd($bin)
            . ' pkcs12 -legacy -in ' . escapeshellarg($this->pfxPath)
            . ' -passin pass:' . escapeshellarg($this->password);

        $cert = $this->runCommand($base . ' -clcerts -nokeys');
        $pkey = $this->runCommand($base . ' -nocerts -nodes');

        if (!is_string($cert) || trim($cert) === '' || !is_string($pkey) || trim($pkey) === '') {
            throw new RuntimeException(
                'Falha ao ler o certificado .pfx. Verifique se a senha está correta, se o arquivo está íntegro e se o OpenSSL do servidor suporta modo legado.'
            );
        }

        return [
            'cert' => $cert,
            'pkey' => $pkey,
        ];
    }

    private function findOpenSslBinary(): string
    {
        foreach (['/usr/bin/openssl', '/bin/openssl', '/usr/local/bin/openssl'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $output = [];
        $code = 1;
        if (\function_exists('exec')) {
            \exec('which openssl 2>/dev/null', $output, $code);
            if ($code === 0 && !empty($output[0])) {
                return trim((string)$output[0]);
            }
        }

        return '';
    }

    private function runCommand(string $command): string
    {
        if (\function_exists('exec')) {
            $output = [];
            $code = 1;
            \exec($command . ' 2>/dev/null', $output, $code);
            if ($code === 0) {
                return implode("\n", $output);
            }
        }

        if (\function_exists('proc_open')) {
            $descriptors = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = \proc_open($command, $descriptors, $pipes);
            if (\is_resource($proc)) {
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code === 0 && is_string($stdout)) {
                    return $stdout;
                }
            }
        }

        return '';
    }
}
