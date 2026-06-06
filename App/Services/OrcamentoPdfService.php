<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\View;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\OrcamentoRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

final class OrcamentoPdfService
{
    public function __construct(
        private readonly OrcamentoRepository    $repo       = new OrcamentoRepository(),
        private readonly ConfiguracaoRepository $configRepo = new ConfiguracaoRepository(),
    ) {}

    /**
     * Renderiza o orçamento como PDF e retorna os bytes brutos.
     * Lança \InvalidArgumentException se o orçamento não existir.
     */
    public function gerarPdfBytes(int $orcId): string
    {
        $dados = $this->repo->buscarParaDocumento($orcId);
        if ($dados === null) {
            throw new \InvalidArgumentException("Orçamento #{$orcId} não encontrado.");
        }

        $itens = $this->repo->listarItens($orcId);

        $cfgEmp  = $this->configRepo->listarPorPrefixo('empresa_');
        $cfgNfse = $this->configRepo->listarPorPrefixo('nfse_prestador_');

        $empEndereco = $cfgEmp['empresa_endereco'] ?? '';
        if ($empEndereco === '') {
            $logradouro = trim((string) ($cfgNfse['nfse_prestador_logradouro'] ?? ''));
            $numero     = trim((string) ($cfgNfse['nfse_prestador_numero'] ?? ''));
            $empEndereco = $logradouro . ($numero !== '' ? ', ' . $numero : '');
        }

        $empresa = [
            'nome'     => $cfgEmp['empresa_nome'] ?? 'Multimáquinas Assistência Técnica',
            'cnpj'     => $cfgEmp['empresa_cnpj'] ?? ($cfgNfse['nfse_prestador_cnpj'] ?? ''),
            'endereco' => $empEndereco,
            'bairro'   => $cfgNfse['nfse_prestador_bairro'] ?? '',
            'cidade'   => $cfgEmp['empresa_cidade'] ?? '',
            'cep'      => $cfgNfse['nfse_prestador_cep'] ?? '',
            'telefone' => $cfgEmp['empresa_telefone'] ?? ($cfgNfse['nfse_prestador_telefone'] ?? ''),
            'email'    => $cfgNfse['nfse_prestador_email'] ?? '',
        ];

        // Logo como data URI — evita problemas de resolução de path no dompdf
        $logoDataUri = null;
        $logoPath    = BASE_PATH . '/public/img/logo.png';
        if (is_file($logoPath)) {
            $raw = file_get_contents($logoPath);
            if ($raw !== false) {
                $logoDataUri = 'data:image/png;base64,' . base64_encode($raw);
            }
        }

        $html = View::render('orcamento/pdf', [
            'titulo'        => "Orçamento #{$orcId}",
            'dados'         => $dados,
            'itens'         => $itens,
            'empresa'       => $empresa,
            'auto_print'    => false,
            'isPdfDownload' => true,
            'logoDataUri'   => $logoDataUri,
        ], '');

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Arial');
        $options->set('isPhpEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
