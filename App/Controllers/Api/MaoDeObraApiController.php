<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\MaoDeObraRepository;

final class MaoDeObraApiController
{
    private MaoDeObraRepository $repo;

    public function __construct()
    {
        $this->repo = new MaoDeObraRepository();
    }

    /**
     * Retorna todo o catálogo de mão de obra para montar o dropdown/modal.
     * Acesso restrito a admin e recepção — técnico não deve ver valores.
     */
    public function catalogo(): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $agrupado = $this->repo->listarAgrupadoPorCategoria();
        return Response::json(['ok' => true, 'catalogo' => $agrupado]);
    }

    /**
     * Tenta adivinhar o preço baseado no nome do equipamento fornecido.
     * Usa correspondência por tokens inteiros — evita falsos positivos como
     * "5 CV" combinando com "25 CV".
     * Acesso restrito a admin e recepção — técnico não deve ver valores.
     */
    public function sugerir(): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $equipamento = trim((string) ($body['equipamento'] ?? ''));

        if ($equipamento === '') {
            return Response::json(['ok' => false, 'error' => 'Equipamento em branco']);
        }

        $item = $this->repo->sugerirPorNome($equipamento);

        if ($item === null) {
            return Response::json(['ok' => true, 'encontrado' => false]);
        }

        return Response::json([
            'ok'         => true,
            'encontrado' => true,
            'match'      => $item['nome'],
            'categoria'  => $item['categoria'],
            'valor'      => (float) $item['valor_padrao'],
        ]);
    }

    /**
     * GET /api/mao-obra?tipo=maquina
     * Retorna itens da tabela_mao_obra filtrados pelo tipo do orçamento.
     * Acesso restrito a admin e recepção — técnico não deve ver valores.
     *
     * @param  ?string $tipo Categoria para filtrar ('maquina', 'motor', 'bomba', 'servico').
     *                       Se omitido, retorna todos (exceto 'servico').
     */
    public function listarPorTipo(): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $tipo = trim((string) ($_GET['tipo'] ?? ''));
        $validos = ['maquina', 'motor', 'bomba', 'servico'];

        if ($tipo !== '' && !in_array($tipo, $validos, true)) {
            return Response::json(['ok' => false, 'error' => 'Tipo inválido. Use: maquina, motor, bomba.'], 400);
        }

        $todas = $this->repo->listarTudo();

        if ($tipo !== '') {
            $itens = array_values(array_filter($todas, fn($i) => $i['categoria'] === $tipo));
        } else {
            // Sem filtro: exclui 'servico' (não deve ir para mo_valor)
            $itens = array_values(array_filter($todas, fn($i) => $i['categoria'] !== 'servico'));
        }

        return Response::json(['ok' => true, 'itens' => $itens, 'tipo' => $tipo]);
    }
}
