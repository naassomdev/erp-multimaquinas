<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AuditoriaService;
use App\Services\Financeiro\FaturamentoService;
use PDO;
use Throwable;

/**
 * Faturamento B2B — interface administrativa para agrupar OSs faturadas
 * em relatórios de cobrança vinculados a uma PO/pedido do cliente.
 *
 * Toda a lógica de negócio vive em FaturamentoService; este controller
 * só orquestra requests/respostas e renderiza as views.
 */
final class FaturamentoController
{
    public function __construct(
        private readonly FaturamentoService $service = new FaturamentoService(),
        private readonly AuditoriaService   $audit   = new AuditoriaService(),
    ) {}

    /**
     * GET /financeiro/faturamento
     * Lista relatórios existentes (filtro por status opcional).
     */
    public function index(Request $request): Response
    {
        $status = trim((string) $request->input('status', ''));
        $statusFiltro = in_array($status, ['rascunho', 'finalizado'], true) ? $status : null;

        $relatorios = $this->service->listarRelatorios($statusFiltro, 200);

        return Response::html(View::render('financeiro/faturamento/index', [
            'titulo'     => 'Faturamento B2B',
            'activeMenu' => 'financeiro',
            'relatorios' => $relatorios,
            'statusFiltro' => $status,
        ]));
    }

    /**
     * GET /financeiro/faturamento/novo
     *   - sem ?cliente_id: mostra a lista de clientes que possuem OSs aguardando fatura
     *   - com ?cliente_id=N: lista as OSs pendentes do cliente para seleção
     */
    public function criarForm(Request $request): Response
    {
        $clienteId = (int) $request->input('cliente_id', 0);

        if ($clienteId > 0) {
            $cliente = $this->buscarCliente($clienteId);
            if ($cliente === null) {
                Flash::set('error', "Cliente #{$clienteId} não encontrado.");
                return Response::redirect('/financeiro/faturamento/novo');
            }
            $pendentes = $this->service->listarPendentesPorCliente($clienteId);

            return Response::html(View::render('financeiro/faturamento/create', [
                'titulo'     => 'Novo relatório de faturamento',
                'activeMenu' => 'financeiro',
                'modo'       => 'selecionar_os',
                'cliente'    => $cliente,
                'pendentes'  => $pendentes,
                'csrf_token' => Csrf::token(),
            ]));
        }

        $clientesPendentes = $this->listarClientesComPendentes();

        return Response::html(View::render('financeiro/faturamento/create', [
            'titulo'            => 'Novo relatório de faturamento',
            'activeMenu'        => 'financeiro',
            'modo'              => 'selecionar_cliente',
            'clientesPendentes' => $clientesPendentes,
            'csrf_token'        => Csrf::token(),
        ]));
    }

    /**
     * POST /financeiro/faturamento
     * Cria um novo relatório (status=rascunho) com as OSs selecionadas.
     */
    public function salvar(Request $request): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/financeiro/faturamento/novo');
        }

        $clienteId   = (int) $request->input('cliente_id', 0);
        $numeroPo    = trim((string) $request->input('numero_po', ''));
        $observacoes = trim((string) $request->input('observacoes', ''));
        $osIds       = (array) $request->input('os_ids', []);
        $criadoPor   = (int) (Auth::id() ?? 0);

        if ($clienteId <= 0) {
            Flash::set('error', 'Cliente inválido.');
            return Response::redirect('/financeiro/faturamento/novo');
        }

        try {
            $relatorioId = $this->service->gerarRelatorio(
                $clienteId,
                $numeroPo,
                $criadoPor,
                $osIds,
                $observacoes,
            );

            $this->audit->registrar(
                'relatorios_faturamento',
                (string) $relatorioId,
                AuditoriaService::ACAO_INSERT,
                [
                    'cliente_id' => $clienteId,
                    'numero_po'  => $numeroPo,
                    'qtd_os'     => count($osIds),
                ],
            );

            Flash::set('success', "Relatório #{$relatorioId} criado em rascunho. Confira e finalize quando estiver pronto.");
            return Response::redirect("/financeiro/faturamento/{$relatorioId}");
        } catch (Throwable $e) {
            Flash::set('error', $e->getMessage());
            Flash::keepOld([
                'numero_po'   => $numeroPo,
                'observacoes' => $observacoes,
            ]);
            return Response::redirect("/financeiro/faturamento/novo?cliente_id={$clienteId}");
        }
    }

    /**
     * GET /financeiro/faturamento/{id}
     * Visualização formal do relatório (cabeçalho + OSs + total) — pronta p/ impressão.
     */
    public function visualizar(Request $request, string $id): Response
    {
        $relatorioId = (int) $id;
        $relatorio   = $this->service->detalhar($relatorioId);
        if ($relatorio === null) {
            throw new HttpException(404, "Relatório #{$id} não encontrado.");
        }

        $cliente = $this->buscarCliente((int) $relatorio['cliente_id']);
        $empresa = $this->dadosEmpresa();

        return Response::html(View::render('financeiro/faturamento/show', [
            'titulo'     => "Relatório de Faturamento #{$relatorioId}",
            'activeMenu' => 'financeiro',
            'relatorio'  => $relatorio,
            'cliente'    => $cliente,
            'empresa'    => $empresa,
            'csrf_token' => Csrf::token(),
        ]));
    }

    /**
     * POST /financeiro/faturamento/{id}/finalizar
     * Marca o relatório como 'finalizado' e quita os lançamentos vinculados.
     */
    public function finalizar(Request $request, string $id): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect("/financeiro/faturamento/{$id}");
        }

        $relatorioId = (int) $id;

        try {
            $resultado = $this->service->finalizar($relatorioId);

            $this->audit->registrar(
                'relatorios_faturamento',
                (string) $relatorioId,
                'FINALIZAR',
                $resultado,
            );

            Flash::set('success', "Relatório #{$relatorioId} finalizado. {$resultado['os_quitadas']} lançamento(s) quitado(s).");
        } catch (Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        return Response::redirect("/financeiro/faturamento/{$relatorioId}");
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function buscarCliente(int $clienteId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, nome, nome_fantasia, cpf_cnpj, email, telefone, celular,
                    endereco, numero, complemento, bairro, cidade, uf, cep
               FROM clientes WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$clienteId]);
        $cli = $stmt->fetch(PDO::FETCH_ASSOC);
        return $cli !== false ? $cli : null;
    }

    /**
     * Lista clientes que possuem OSs aguardando fatura, com totais agregados.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listarClientesComPendentes(): array
    {
        $sql = "SELECT  c.id,
                        c.nome,
                        c.nome_fantasia,
                        c.cpf_cnpj,
                        COUNT(DISTINCT os.id) AS qtd_os,
                        COALESCE(SUM(lr.valor), 0) AS valor_total
                  FROM  clientes c
            INNER JOIN  ordem_servico        os ON os.cliente_id = c.id
            INNER JOIN  lancamentos_receber  lr ON lr.os_id = os.id
                 WHERE  lr.status = 'aguardando_fatura'
              GROUP BY  c.id, c.nome, c.nome_fantasia, c.cpf_cnpj
              ORDER BY  c.nome ASC";

        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Dados do emitente (cabeçalho do relatório). Lê da tabela `configuracoes`
     * (chave/valor); cai em defaults caso o sistema ainda não tenha sido configurado.
     *
     * @return array<string, string>
     */
    private function dadosEmpresa(): array
    {
        $defaults = [
            'razao_social' => 'MULTIMÁQUINAS ASSISTÊNCIA',
            'cnpj'         => '',
            'endereco'     => '',
            'telefone'     => '',
            'email'        => '',
        ];

        try {
            $rows = Database::pdo()
                ->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'empresa_%'")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable) {
            return $defaults;
        }

        return [
            'razao_social' => $rows['empresa_razao_social'] ?? $defaults['razao_social'],
            'cnpj'         => $rows['empresa_cnpj']         ?? $defaults['cnpj'],
            'endereco'     => $rows['empresa_endereco']     ?? $defaults['endereco'],
            'telefone'     => $rows['empresa_telefone']     ?? $defaults['telefone'],
            'email'        => $rows['empresa_email']        ?? $defaults['email'],
        ];
    }
}
