<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Pdv\PdvPaymentType;
use App\Services\Pdv\PdvSaleStatus;
use App\Services\Pdv\PdvService;
use RuntimeException;
use Throwable;

final class PdvController
{
    public function __construct(
        private readonly PdvService $service = new PdvService(),
    ) {}

    public function index(Request $request): Response
    {
        return Response::html(View::render('pdv/index', [
            'titulo' => 'PDV — Em preparação',
            'activeMenu' => 'pdv',
            'usuario' => Auth::user(),
            'pdv' => $this->service->status(),
            'vendaInicialId' => $this->positiveIdOrNull((string)$request->input('venda', '')),
        ]));
    }

    public function vendas(Request $request): Response
    {
        $page = max(1, (int)$request->input('page', 1));
        $limit = max(1, min(100, (int)$request->input('limit', 20)));
        $filtros = [
            'date_from' => (string)$request->input('date_from', ''),
            'date_to' => (string)$request->input('date_to', ''),
            'status_venda' => (string)$request->input('status_venda', ''),
            'forma_pagamento' => (string)$request->input('forma_pagamento', ''),
            'operador_id' => (string)$request->input('operador_id', ''),
            'q' => (string)$request->input('q', ''),
        ];

        try {
            $listagem = $this->service->listarVendas($filtros, $page, $limit);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Acesso negado. Listagem de vendas PDV disponível apenas para administrador.') {
                throw new HttpException(403, $e->getMessage(), $e);
            }
            throw new HttpException(409, $e->getMessage(), $e);
        } catch (Throwable $e) {
            throw new HttpException(500, 'Erro ao carregar listagem de vendas do PDV.', $e);
        }

        return Response::html(View::render('pdv/vendas', [
            'titulo' => 'Vendas PDV',
            'activeMenu' => 'pdv_vendas',
            'usuario' => Auth::user(),
            'pdv' => $this->service->status(),
            'listagem' => $listagem,
            'statusOptions' => PdvSaleStatus::all(),
            'paymentOptions' => PdvPaymentType::all(),
        ]));
    }

    public function recibo(Request $request, string $id): Response
    {
        $vendaId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($vendaId === false) {
            throw new HttpException(404, 'Venda PDV não encontrada.');
        }

        try {
            $recibo = $this->service->visualizarReciboNaoFiscal((int)$vendaId);
        } catch (RuntimeException $e) {
            throw new HttpException(409, $e->getMessage(), $e);
        } catch (Throwable $e) {
            throw new HttpException(500, 'Erro ao carregar recibo não fiscal do PDV.', $e);
        }

        return Response::html(View::render('pdv/recibo', [
            'titulo' => 'Recibo não fiscal PDV #' . $vendaId,
            'recibo' => $recibo,
            'auto_print' => (string)$request->input('print', '0') === '1',
        ], ''));
    }

    private function positiveIdOrNull(string $value): ?int
    {
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }
}
