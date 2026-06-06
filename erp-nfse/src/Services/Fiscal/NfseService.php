<?php
declare(strict_types=1);
namespace App\Services\Fiscal;

use RuntimeException;

/**
 * Wrapper sobre a biblioteca nfse-nacional/nfse-php.
 *
 * A biblioteca suporta múltiplos provedores municipais (ABRASF, ISS.net, Curitiba, etc.).
 * Adapte o provedor correto no método emitir() conforme o município do emissor.
 *
 * Documentação: https://github.com/nfse-nacional/nfse-php
 */
final class NfseService
{
    public function __construct(private readonly CertificateManager $certManager) {}

    /**
     * Emite uma NFS-e para o serviço descrito em $dados.
     *
     * @param array{
     *   nota_id:      int,
     *   os_id:        int,
     *   nome_cliente: string,
     *   cpf_cnpj:     string,
     *   valor_servico:float,
     *   descricao:    string,
     *   ambiente:     string,
     *   codigo_servico?:string,
     *   aliquota_iss?: float,
     * } $dados
     *
     * @return array{status:string, numero:string|null, protocolo:string|null, xml:string|null}
     */
    public function emitir(array $dados): array
    {
        if (getenv('NFSE_LEGACY_TRANSMISSION_ENABLED') !== '1') {
            throw new RuntimeException('Integração fiscal antiga bloqueada. Use o fluxo seguro do ERP principal.');
        }

        $pem      = $this->certManager->exportPem();
        $ambiente = $dados['ambiente'] ?? 'homologacao';

        try {
            /*
             * ── INTEGRAÇÃO COM nfse-nacional/nfse-php ───────────────────────
             *
             * Exemplo para provedor ABRASF (padrão nacional mais comum):
             *
             *   $config = new \NfseNacional\Config\Config();
             *   $config->setCertificadoPem($pem['cert'], $pem['pkey']);
             *   $config->setAmbiente($ambiente);
             *
             *   $rps = new \NfseNacional\Rps\Rps();
             *   $rps->setNumero((string)$dados['nota_id']);
             *   $rps->setSerie('RPS');
             *   $rps->setTipo('1');
             *   $rps->setDataEmissao(date('Y-m-d'));
             *   $rps->setStatus('1');
             *   $rps->setNaturezaOperacao('1');
             *   $rps->setOptanteSimplesNacional('2');
             *   $rps->setIncentivadorCultural('2');
             *   $rps->setServico(
             *       (string)($dados['codigo_servico'] ?? '17.11'),  // conforme LC116
             *       (string)($dados['aliquota_iss']   ?? 3.0),
             *       (float)$dados['valor_servico'],
             *       $dados['descricao']
             *   );
             *   $rps->setTomador(
             *       cpf_cnpj: $dados['cpf_cnpj'],
             *       nome:     $dados['nome_cliente']
             *   );
             *
             *   $transmissor = new \NfseNacional\Transmissor\AbrasF($config);
             *   $retorno     = $transmissor->gerarNfse($rps);
             *
             *   return [
             *       'status'    => $retorno->isAutorizada() ? 'autorizada' : 'erro',
             *       'numero'    => $retorno->getNumeroNfse(),
             *       'protocolo' => $retorno->getProtocolo(),
             *       'xml'       => $retorno->getXml(),
             *   ];
             *
             * ────────────────────────────────────────────────────────────────
             * Simulação ativa enquanto a biblioteca não está configurada:
             */
            return $this->_simular($dados);

        } catch (\Throwable $e) {
            throw new RuntimeException('Falha na emissão NFS-e: ' . $e->getMessage(), 0, $e);
        }
    }

    private function _simular(array $dados): array
    {
        return [
            'status'    => 'autorizada',
            'numero'    => str_pad((string)$dados['nota_id'], 6, '0', STR_PAD_LEFT),
            'protocolo' => 'SIM-' . date('YmdHis') . '-' . $dados['nota_id'],
            'xml'       => null,
            'simulado'  => true,
        ];
    }
}
