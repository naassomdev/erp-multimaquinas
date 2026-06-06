<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\FinanceiroRepository;
use App\Services\AuditoriaService;
use App\Services\Financeiro\FinanceiroService;

final class FinanceiroController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly FinanceiroRepository $repo    = new FinanceiroRepository(),
        private readonly FinanceiroService    $service = new FinanceiroService(),
        private readonly AuditoriaService     $audit   = new AuditoriaService(),
    ) {}

    // ── Controle de acesso ─────────────────────────────────────────────────

    private function assertAdminOuRecepcao(): void
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            throw new HttpException(403, 'Acesso restrito a administradores e recepção.');
        }
    }

    /**
     * GET /financeiro — dashboard: resumo do mês + a vencer + vencidas.
     */
    public function index(Request $request): Response
    {
        $this->assertAdminOuRecepcao();
        $resumo     = $this->service->resumoMes();
        $aVencer    = $this->service->contasAVencer(30);
        $vencidas   = $this->service->contasVencidas(0);

        return Response::html(View::render('financeiro/index', [
            'titulo'     => 'Financeiro',
            'activeMenu' => 'financeiro',
            'resumo'     => $resumo,
            'aVencer'    => $aVencer,
            'vencidas'   => $vencidas,
        ]));
    }

    /**
     * GET /financeiro/receber — lista de contas a receber.
     */
    public function receber(Request $request): Response
    {
        $this->assertAdminOuRecepcao();
        return $this->listar($request, FinanceiroRepository::TIPO_RECEBER);
    }

    /**
     * GET /financeiro/pagar — lista de contas a pagar.
     */
    public function pagar(Request $request): Response
    {
        $this->assertAdminOuRecepcao();
        return $this->listar($request, FinanceiroRepository::TIPO_PAGAR);
    }

    /**
     * GET /financeiro/{tipo}/novo — formulário de novo lançamento.
     */
    public function novoForm(Request $request, string $tipo): Response
    {
        $this->assertAdminOuRecepcao();
        $this->validarTipo($tipo);

        return Response::html(View::render('financeiro/form', [
            'titulo'     => $tipo === 'receber' ? 'Nova Conta a Receber' : 'Nova Conta a Pagar',
            'activeMenu' => 'financeiro',
            'tipo'       => $tipo,
            'lancamento' => null,
            'modo'       => 'criar',
            'csrf_token' => Csrf::token(),
        ]));
    }

    /**
     * POST /financeiro/{tipo} — salvar novo lançamento.
     */
    public function salvar(Request $request, string $tipo): Response
    {
        $this->assertAdminOuRecepcao();
        $this->validarTipo($tipo);

        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect("/financeiro/{$tipo}/novo");
        }

        $dados = $this->extrairDados($request, $tipo);
        $erro  = $this->validar($dados);
        if ($erro !== null) {
            Flash::set('error', $erro);
            Flash::keepOld($dados);
            return Response::redirect("/financeiro/{$tipo}/novo");
        }

        $id = $this->repo->criar($tipo, $dados);
        $this->audit->registrar($this->repo->tabela($tipo), (string)$id, AuditoriaService::ACAO_INSERT, $dados);

        Flash::set('success', 'Lançamento criado com sucesso!');
        return Response::redirect("/financeiro/{$tipo}/{$id}");
    }

    /**
     * GET /financeiro/{tipo}/{id} — visualização do lançamento.
     */
    public function visualizar(Request $request, string $tipo, string $id): Response
    {
        $this->assertAdminOuRecepcao();
        $this->validarTipo($tipo);
        $lanc = $this->repo->buscarPorId($tipo, (int)$id);
        if ($lanc === null) {
            throw new HttpException(404, "Lançamento #{$id} não encontrado.");
        }

        return Response::html(View::render('financeiro/detalhe', [
            'titulo'     => ($tipo === 'receber' ? 'Recebimento' : 'Pagamento') . " #{$id}",
            'activeMenu' => 'financeiro',
            'tipo'       => $tipo,
            'lancamento' => $lanc,
            'csrf_token' => Csrf::token(),
        ]));
    }

    /**
     * GET /financeiro/{tipo}/{id}/editar — form de edição.
     */
    public function editar(Request $request, string $tipo, string $id): Response
    {
        $this->assertAdminOuRecepcao();
        $this->validarTipo($tipo);
        $lanc = $this->repo->buscarPorId($tipo, (int)$id);
        if ($lanc === null) {
            throw new HttpException(404, "Lançamento #{$id} não encontrado.");
        }
        if ($lanc['status'] !== 'aberto') {
            Flash::set('error', 'Apenas lançamentos em aberto podem ser editados.');
            return Response::redirect("/financeiro/{$tipo}/{$id}");
        }

        return Response::html(View::render('financeiro/form', [
            'titulo'     => 'Editar lançamento',
            'activeMenu' => 'financeiro',
            'tipo'       => $tipo,
            'lancamento' => $lanc,
            'modo'       => 'editar',
            'csrf_token' => Csrf::token(),
        ]));
    }

    /**
     * POST /financeiro/{tipo}/{id} — atualizar lançamento.
     */
    public function atualizar(Request $request, string $tipo, string $id): Response
    {
        $this->assertAdminOuRecepcao();
        $this->validarTipo($tipo);

        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect("/financeiro/{$tipo}/{$id}/editar");
        }

        $lanc = $this->repo->buscarPorId($tipo, (int)$id);
        if ($lanc === null) {
            throw new HttpException(404, "Lançamento #{$id} não encontrado.");
        }
        if ($lanc['status'] !== 'aberto') {
            Flash::set('error', 'Apenas lançamentos em aberto podem ser editados.');
            return Response::redirect("/financeiro/{$tipo}/{$id}");
        }

        $dados = $this->extrairDados($request, $tipo);
        $erro  = $this->validar($dados);
        if ($erro !== null) {
            Flash::set('error', $erro);
            Flash::keepOld($dados);
            return Response::redirect("/financeiro/{$tipo}/{$id}/editar");
        }

        $this->repo->atualizar($tipo, (int)$id, $dados);
        $this->audit->registrar($this->repo->tabela($tipo), $id, AuditoriaService::ACAO_UPDATE, $dados);

        Flash::set('success', 'Lançamento atualizado.');
        return Response::redirect("/financeiro/{$tipo}/{$id}");
    }

    /**
     * POST /financeiro/{tipo}/{id}/pagar — registra pagamento.
     */
    public function registrarPagamento(Request $request, string $tipo, string $id): Response
    {
        $this->assertAdminOuRecepcao();
        $this->validarTipo($tipo);

        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect("/financeiro/{$tipo}/{$id}");
        }

        $valor = (float)str_replace(',', '.', (string)$request->input('valor_pago', '0'));
        $data  = trim((string)$request->input('data_pagamento', date('Y-m-d')));

        if ($data === '' || strtotime($data) === false) {
            Flash::set('error', 'Data de pagamento inválida.');
            return Response::redirect("/financeiro/{$tipo}/{$id}");
        }

        try {
            $this->service->registrarPagamento($this->repo->tabela($tipo), (int)$id, $valor, $data);
            $this->audit->registrar(
                $this->repo->tabela($tipo),
                $id,
                'PAGAR',
                ['valor_pago' => $valor, 'data_pagamento' => $data],
            );
            Flash::set('success', 'Pagamento registrado com sucesso!');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        return Response::redirect("/financeiro/{$tipo}/{$id}");
    }

    /**
     * POST /financeiro/{tipo}/{id}/cancelar — cancela lançamento em aberto.
     */
    public function cancelar(Request $request, string $tipo, string $id): Response
    {
        $this->assertAdminOuRecepcao();
        $this->validarTipo($tipo);

        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect("/financeiro/{$tipo}/{$id}");
        }

        $lanc = $this->repo->buscarPorId($tipo, (int)$id);
        if ($lanc === null) {
            throw new HttpException(404, "Lançamento #{$id} não encontrado.");
        }
        if ($lanc['status'] !== 'aberto') {
            Flash::set('error', 'Apenas lançamentos em aberto podem ser cancelados.');
            return Response::redirect("/financeiro/{$tipo}/{$id}");
        }

        $this->repo->cancelar($tipo, (int)$id);
        $this->audit->registrar($this->repo->tabela($tipo), $id, 'CANCELAR', []);

        Flash::set('success', 'Lançamento cancelado.');
        return Response::redirect("/financeiro/{$tipo}");
    }

    /**
     * GET /financeiro/fluxo — relatório de fluxo de caixa por período.
     */
    public function fluxoCaixa(Request $request): Response
    {
        $this->assertAdminOuRecepcao();
        $de  = trim((string)$request->input('de', date('Y-m-01')));
        $ate = trim((string)$request->input('ate', date('Y-m-t')));

        $fluxo = $this->service->fluxoCaixa($de, $ate);

        return Response::html(View::render('financeiro/fluxo', [
            'titulo'     => 'Fluxo de Caixa',
            'activeMenu' => 'financeiro',
            'fluxo'      => $fluxo,
            'de'         => $de,
            'ate'        => $ate,
        ]));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function listar(Request $request, string $tipo): Response
    {
        $filtros = [
            'busca'     => trim((string)$request->input('q', '')),
            'status'    => trim((string)$request->input('status', '')),
            'de'        => trim((string)$request->input('de', '')),
            'ate'       => trim((string)$request->input('ate', '')),
            'vencidas'  => $request->input('vencidas') === '1' ? '1' : '',
            'os_id'     => trim((string)$request->input('os_id', '')),
            'forma_pag' => trim((string)$request->input('forma_pag', '')),
        ];

        $page  = max(1, (int)$request->input('p', 1));
        $total = $this->repo->contar($tipo, $filtros);
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        if ($page > $totalPages) $page = $totalPages;

        $lista   = $this->repo->listar($tipo, $filtros, $page, self::PER_PAGE);
        $resumo  = $this->repo->totaisPorStatus($tipo);

        return Response::html(View::render('financeiro/lista', [
            'titulo'     => $tipo === 'receber' ? 'Contas a Receber' : 'Contas a Pagar',
            'activeMenu' => 'financeiro',
            'tipo'       => $tipo,
            'lista'      => $lista,
            'filtros'    => $filtros,
            'resumo'     => $resumo,
            'paginacao'  => [
                'page'        => $page,
                'per_page'    => self::PER_PAGE,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ]));
    }

    private function validarTipo(string $tipo): void
    {
        if ($tipo !== 'receber' && $tipo !== 'pagar') {
            throw new HttpException(404, "Tipo de lançamento inválido: {$tipo}.");
        }
    }

    private function extrairDados(Request $request, string $tipo): array
    {
        $dados = [
            'descricao'  => trim((string)$request->input('descricao', '')),
            'valor'      => (float)str_replace(',', '.', (string)$request->input('valor', '0')),
            'vencimento' => trim((string)$request->input('vencimento', '')),
        ];

        if ($tipo === 'receber') {
            $cliente = (int)$request->input('cliente_id', 0);
            $dados['cliente_id'] = $cliente > 0 ? $cliente : null;
            $os = trim((string)$request->input('os_id', ''));
            $dados['os_id'] = $os !== '' ? (int)$os : null;
        } else {
            $forn = (int)$request->input('fornecedor_id', 0);
            $dados['fornecedor_id'] = $forn > 0 ? $forn : null;
            $chave = trim((string)$request->input('chave_nfe', ''));
            $dados['chave_nfe'] = $chave !== '' ? $chave : null;
        }

        return $dados;
    }

    private function validar(array $dados): ?string
    {
        if ($dados['descricao'] === '') return 'Descrição é obrigatória.';
        if ($dados['valor'] <= 0)       return 'Valor deve ser maior que zero.';
        if ($dados['vencimento'] === '' || strtotime($dados['vencimento']) === false) {
            return 'Data de vencimento inválida.';
        }
        return null;
    }
}
