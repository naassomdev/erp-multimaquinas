<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ProdutoRepository;
use App\Services\EstoqueService;

final class EstoqueApiController
{
    public function __construct(
        private readonly ProdutoRepository $repo    = new ProdutoRepository(),
        private readonly EstoqueService    $service = new EstoqueService(),
    ) {}

    /**
     * GET /api/estoque/busca?q=...
     * Autocomplete de produtos para formulários (OS, Orçamento).
     */
    public function busca(Request $request): Response
    {
        $termo = trim((string) $request->input('q', ''));
        if (mb_strlen($termo) < 2) {
            return Response::json(['ok' => true, 'produtos' => []]);
        }

        $produtos = $this->repo->buscarPorTermo($termo, 15);

        return Response::json([
            'ok'       => true,
            'produtos' => $produtos,
        ]);
    }

    /**
     * GET /api/estoque/calcular-preco?custo=X&margem=Y
     * Cálculo de preço de venda em tempo real.
     */
    public function calcularPreco(Request $request): Response
    {
        $custo  = (float) $request->input('custo', 0);
        $margem = (float) $request->input('margem', 0);
        $venda  = (float) $request->input('venda', 0);

        if ($custo > 0 && $margem > 0) {
            $vendaCalc = $this->service->calcularPrecoVenda($custo, $margem);
            return Response::json([
                'ok'     => true,
                'venda'  => $vendaCalc,
                'margem' => $margem,
            ]);
        }

        if ($custo > 0 && $venda > 0) {
            $margemCalc = $this->service->calcularMargem($custo, $venda);
            return Response::json([
                'ok'     => true,
                'venda'  => $venda,
                'margem' => $margemCalc,
            ]);
        }

        return Response::json(['ok' => false, 'error' => 'Informe custo e margem ou custo e venda'], 400);
    }
}
