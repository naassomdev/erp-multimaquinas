<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ProdutoRepository;

final class ProdutoApiController
{
    public function __construct(
        private readonly ProdutoRepository $repo = new ProdutoRepository(),
    ) {}

    public function busca(Request $request): Response
    {
        $q = trim((string) $request->input('q', ''));
        $mode = trim((string) $request->input('mode', ''));
        $context = trim((string) $request->input('context', ''));

        $limit = max(1, min(50, (int) $request->input('limit', 20)));

        if ($mode === 'codigo') {
            if (mb_strlen($q) < 2) return Response::json(['ok' => true, 'produtos' => []]);
            $produtos = $this->repo->buscarPorCodigoParcial($q, $limit);
        } elseif ($mode === 'descricao') {
            // Aceita 2+ chars: o repositório decide a estratégia por camada
            // (FTS exige 3/token, mas multi-termo pode ter tokens mistos)
            if (mb_strlen($q) < 2) return Response::json(['ok' => true, 'produtos' => []]);
            $produtos = $this->repo->buscarPorDescricaoParcial($q, $limit);
        } else {
            if (mb_strlen($q) < 2) return Response::json(['ok' => true, 'produtos' => []]);
            $produtos = $this->repo->buscarPorTermo($q, $limit);
        }

        // Oculta preços para o painel técnico (context=tecnico) ou para oficina.
        $hidePrices = ($context === 'tecnico') || !Auth::temNivel('admin', 'recepcao');

        $produtos = array_map(static function (array $p) use ($hidePrices): array {
            $valorVenda = (float) ($p['valor_venda_calculado'] ?? 0);
            if ($valorVenda <= 0) $valorVenda = (float) ($p['valor'] ?? 0);
            $base = [
                'id'               => (int) $p['id'],
                'codigo'           => (string) $p['codigo'],
                'descricao'        => (string) $p['descricao'],
                'marca'            => (string) ($p['marca'] ?? ''),
                'unidade'          => (string) ($p['unidade'] ?? 'un'),
                'estoque_qty'      => (float) ($p['estoque_qty'] ?? 0),
                'controla_estoque' => (int) ($p['controla_estoque'] ?? 1),
            ];
            if (!$hidePrices) {
                $base['valor_venda'] = round($valorVenda, 2);
            }
            // Fase 4: quando o item foi encontrado por um código ANTIGO/alternativo,
            // sinaliza para o front exibir o aviso (código digitado -> produto atual).
            if (!empty($p['via_codigo_alternativo'])) {
                $base['via_codigo_alternativo'] = (string) $p['via_codigo_alternativo'];
                $base['via_codigo_tipo']        = (string) ($p['via_codigo_tipo'] ?? 'antigo');
            }
            return $base;
        }, $produtos);

        return Response::json(['ok' => true, 'produtos' => $produtos]);
    }
}
