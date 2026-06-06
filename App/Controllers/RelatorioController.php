<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\GarantiaRepository;

/**
 * Relatórios operacionais — somente leitura.
 * Nenhuma ação deste controller altera dados.
 *
 * Etapa 9J-3.
 */
final class RelatorioController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly GarantiaRepository $garantiaRepo = new GarantiaRepository(),
    ) {}

    // ── Controle de acesso ──────────────────────────────────────────────────

    private function assertAdminOuRecepcao(): void
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            throw new HttpException(403, 'Acesso restrito a administradores e recepção.');
        }
    }

    // ── Rotas ───────────────────────────────────────────────────────────────

    /**
     * GET /relatorios/garantias-fabricante
     * Relatório de atendimentos em garantia de fabricante.
     */
    public function garantiasFabricante(Request $request): Response
    {
        $this->assertAdminOuRecepcao();

        $filtros = [
            'de'                => trim((string) $request->input('de',                '')),
            'ate'               => trim((string) $request->input('ate',               '')),
            'fabricante'        => trim((string) $request->input('fabricante',        '')),
            'status_equip'      => trim((string) $request->input('status_equip',      '')),
            'motivo_gratuidade' => trim((string) $request->input('motivo_gratuidade', '')),
            'autorizacao'       => trim((string) $request->input('autorizacao',       '')),
        ];

        $pagina = max(1, (int) $request->input('p', 1));

        $registros   = $this->garantiaRepo->listarGarantiasFabricante($filtros, $pagina);
        $total       = $this->garantiaRepo->contarGarantiasFabricante($filtros);
        $kpis        = $this->garantiaRepo->kpis($filtros);
        $fabricantes = $this->garantiaRepo->listarFabricantes();

        $totalPaginas = max(1, (int) ceil($total / self::PER_PAGE));
        $temFiltro    = array_filter($filtros, static fn($v) => $v !== '') !== [];

        return Response::html(View::render('relatorios/garantias_fabricante', [
            'titulo'       => 'Garantias de fabricante',
            'activeMenu'   => 'relatorios',
            'registros'    => $registros,
            'total'        => $total,
            'kpis'         => $kpis,
            'fabricantes'  => $fabricantes,
            'filtros'      => $filtros,
            'pagina'       => $pagina,
            'totalPaginas' => $totalPaginas,
            'temFiltro'    => $temFiltro,
        ]));
    }

    /**
     * GET /relatorios/garantias-fabricante/exportar
     * Exporta CSV com os mesmos filtros do relatório visual.
     */
    public function exportarGarantiasFabricante(Request $request): Response
    {
        $this->assertAdminOuRecepcao();

        $filtros = [
            'de'                => trim((string) $request->input('de',                '')),
            'ate'               => trim((string) $request->input('ate',               '')),
            'fabricante'        => trim((string) $request->input('fabricante',        '')),
            'status_equip'      => trim((string) $request->input('status_equip',      '')),
            'motivo_gratuidade' => trim((string) $request->input('motivo_gratuidade', '')),
            'autorizacao'       => trim((string) $request->input('autorizacao',       '')),
        ];

        $registros = $this->garantiaRepo->listarGarantiasFabricanteExport($filtros);

        $statusEquipLabels = [
            'aberta'     => 'Aberta',
            'andamento'  => 'Em andamento',
            'montagem'   => 'Montagem',
            'pronto'     => 'Pronto',
            'retirado'   => 'Retirado',
            'devolvido'  => 'Devolvido',
            'descartado' => 'Descartado',
            'cancelado'  => 'Cancelado',
        ];

        $orcStatusLabels = [
            'rascunho'  => 'Rascunho',
            'enviado'   => 'Enviado',
            'aprovado'  => 'Aprovado',
            'cancelado' => 'Cancelado',
        ];

        $motivoLabels = [
            'garantia_fabricante' => 'Garantia fabricante',
            'cortesia'            => 'Cortesia',
        ];

        $fmtData = static function (?string $d): string {
            if ($d === null || $d === '') return '';
            $ts = strtotime($d);
            return $ts !== false ? date('d/m/Y', $ts) : $d;
        };

        // Proteção contra fórmula CSV (Excel formula injection)
        $safe = static function (string $v): string {
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                return "'" . $v;
            }
            return $v;
        };

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel

        fputcsv($stream, [
            'OS',
            'Equipamento',
            'Indice',
            'Cliente',
            'Telefone',
            'Fabricante',
            'Autorizacao/RMA',
            'Status fisico',
            'Orcamento ID',
            'Status orcamento',
            'Total (R$)',
            'Motivo gratuidade',
            'Data aprovacao',
            'Data retirada',
            'Retirado por',
            'Status OS',
            'Data abertura OS',
        ], ';', '"', '\\');

        foreach ($registros as $r) {
            $se    = (string) ($r['status_equip'] ?? '');
            $orcSt = (string) ($r['orc_status']  ?? '');
            $motivo = (string) ($r['motivo_gratuidade'] ?? '');
            $total  = $r['orc_total'] !== null
                ? number_format((float) $r['orc_total'], 2, ',', '')
                : '';

            fputcsv($stream, [
                $safe((string) ($r['os_id']               ?? '')),
                $safe((string) ($r['equip_nome']          ?? '')),
                (int) ($r['equip_idx'] ?? 0),
                $safe((string) ($r['nome_cliente']        ?? '')),
                $safe((string) ($r['telefone']            ?? '')),
                $safe((string) ($r['fabricante']          ?? '')),
                $safe((string) ($r['garantia_autorizacao'] ?? '')),
                $statusEquipLabels[$se]   ?? $se,
                (string) ($r['orc_id']   ?? ''),
                $orcStatusLabels[$orcSt]  ?? $orcSt,
                $total,
                $motivoLabels[$motivo]   ?? '',
                $fmtData((string) ($r['data_aprovado']   ?? '')),
                $fmtData((string) ($r['data_retirada']   ?? '')),
                $safe((string) ($r['retirado_por']       ?? '')),
                $safe((string) ($r['status_os']          ?? '')),
                $fmtData((string) ($r['os_criado_em']    ?? '')),
            ], ';', '"', '\\');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = 'garantias_fabricante_' . date('Ymd_His') . '.csv';

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
        ]);
    }
}
