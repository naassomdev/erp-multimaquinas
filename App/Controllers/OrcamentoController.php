<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\NecessidadeCompraRepository;
use App\Repositories\OrcamentoRepository;
use App\Repositories\OrdemServicoRepository;
use App\Repositories\OsEquipamentoRepository;
use App\Repositories\ServicoTerceiroRepository;
use App\Services\OrcamentoPdfService;

final class OrcamentoController
{
    private const PENDENTES_LIMIT = 25;

    public function __construct(
        private readonly OrcamentoRepository         $orcRepo          = new OrcamentoRepository(),
        private readonly OrdemServicoRepository      $osRepo           = new OrdemServicoRepository(),
        private readonly OsEquipamentoRepository     $equipRepo        = new OsEquipamentoRepository(),
        private readonly NecessidadeCompraRepository $necessidadeRepo  = new NecessidadeCompraRepository(),
        private readonly ConfiguracaoRepository      $configRepo       = new ConfiguracaoRepository(),
        private readonly ServicoTerceiroRepository   $servicoTerceiroRepo = new ServicoTerceiroRepository(),
    ) {}

    public function index(Request $request): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            throw new HttpException(403, 'Acesso restrito à recepção e administradores.');
        }

        $busca = trim((string) $request->input('q', ''));
        $resultados = [];
        $tipoBusca = null;

        if ($busca !== '') {
            $os = $this->osRepo->buscarPorId($busca);
            if ($os !== null) {
                return Response::redirect('/orcamento/' . rawurlencode($busca));
            }

            $resultados = $this->osRepo->buscarComResumoPorTelefone($busca);
            $tipoBusca = preg_match('/^\d+$/', preg_replace('/\D/', '', $busca) ?? '') === 1
                ? 'telefone'
                : 'desconhecida';
        }

        $pendentes = $this->orcRepo->listarPendentes(self::PENDENTES_LIMIT);

        return Response::html(View::render('orcamento/index', [
            'titulo'      => 'Orçamentos',
            'activeMenu'  => 'orcamento',
            'usuario'     => Auth::user(),
            'busca'       => $busca,
            'resultados'  => $resultados,
            'tipo_busca'  => $tipoBusca,
            'pendentes'   => $pendentes,
        ]));
    }

    public function detalhe(Request $request, string $os_id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            throw new HttpException(403, 'Acesso restrito à recepção e administradores.');
        }

        $os = $this->osRepo->buscarPorId($os_id);
        if ($os === null) {
            throw new HttpException(404, "OS {$os_id} não encontrada");
        }

        $equipamentos = $this->equipRepo->listarPorOsParaOrcamento($os_id);
        if (empty($equipamentos)) {
            throw new HttpException(404, "OS {$os_id} sem equipamentos cadastrados");
        }

        $orcamentos = $this->orcRepo->listarPorOs($os_id);

        $orcamentoPorEquip = [];
        foreach ($orcamentos as $orc) {
            $orcamentoPorEquip[(int) $orc['equip_idx']] = $orc;
        }

        // Resumo de necessidades por equipamento — uma query, evita N+1
        $necessidadesPorEquip = $this->necessidadeRepo->listarResumoPorOs($os_id);

        return Response::html(View::render('orcamento/detalhe', [
            'titulo'                => "Orçamento — OS {$os_id}",
            'activeMenu'            => 'orcamento',
            'usuario'               => Auth::user(),
            'os'                    => $os,
            'equipamentos'          => $equipamentos,
            'orcamento_por_equip'   => $orcamentoPorEquip,
            'necessidades_por_equip' => $necessidadesPorEquip,
            'servicos_terceiros_por_equip' => $this->servicoTerceiroRepo->listarPorOsAgrupado($os_id),
            'csrf_token'            => Csrf::token(),
        ]));
    }

    /**
     * GET /orcamento/{id}/pdf
     * Renderiza o orçamento formal em HTML imprimível (sem layout).
     * Permissão: admin e recepção.
     */
    public function pdf(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            throw new HttpException(403, 'Acesso restrito à recepção e administradores.');
        }

        $orcId = (int) $id;
        $dados = $this->orcRepo->buscarParaDocumento($orcId);
        if ($dados === null) {
            throw new HttpException(404, "Orçamento #{$orcId} não encontrado.");
        }

        $itens = $this->orcRepo->listarItens($orcId);

        // Dados da empresa — prefere empresa_* (preenchido manualmente),
        // cai em nfse_prestador_* como fallback (dados do certificado fiscal).
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

        // ?download=1 → PDF binário via dompdf
        if ($request->input('download') === '1') {
            try {
                $bytes = (new OrcamentoPdfService())->gerarPdfBytes($orcId);
            } catch (\InvalidArgumentException $e) {
                throw new HttpException(404, $e->getMessage());
            }
            $osId     = (string) ($dados['os_id']    ?? $orcId);
            $equipIdx = (string) ($dados['equip_idx'] ?? '0');
            $filename = 'orcamento_OS' . $osId . '_equip' . $equipIdx . '.pdf';
            return new Response($bytes, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        $autoPrint = $request->input('print') === '1';

        // Renderiza sem layout (documento HTML completo autônomo, como /os/{id}/imprimir).
        return Response::html(View::render('orcamento/pdf', [
            'titulo'     => "Orçamento #{$orcId} — " . ($dados['equip_nome'] ?? ''),
            'dados'      => $dados,
            'itens'      => $itens,
            'empresa'    => $empresa,
            'auto_print' => $autoPrint,
        ], ''));
    }
}
