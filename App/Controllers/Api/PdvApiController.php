<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Pdv\PdvDocumentType;
use App\Services\Pdv\PdvPaymentType;
use App\Services\Pdv\PdvService;
use InvalidArgumentException;
use OutOfRangeException;
use RuntimeException;
use Throwable;

final class PdvApiController
{
    public function __construct(
        private readonly PdvService $service = new PdvService(),
    ) {}

    public function status(Request $request): Response
    {
        return Response::json([
            'ok' => true,
            'pdv' => $this->service->status(),
        ]);
    }

    public function buscarClientes(Request $request): Response
    {
        $q = trim((string)$request->input('q', ''));
        $limit = max(1, min(20, (int)$request->input('limit', 10)));

        try {
            $clientes = $this->service->buscarClientes($q, $limit);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao consultar clientes do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'clientes' => $clientes,
        ]);
    }

    public function buscarProdutos(Request $request): Response
    {
        $q = trim((string)$request->input('q', ''));
        $limit = max(1, min(20, (int)$request->input('limit', 10)));

        try {
            $produtos = $this->service->buscarProdutos($q, $limit);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao consultar produtos do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'produtos' => $produtos,
        ]);
    }

    public function listarRascunhos(Request $request): Response
    {
        $limit = max(1, min(50, (int)$request->input('limit', 20)));

        try {
            $rascunhos = $this->service->listarRascunhos($limit);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao listar rascunhos do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'rascunhos' => $rascunhos,
        ]);
    }

    public function listarVendas(Request $request): Response
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
            $resultado = $this->service->listarVendas($filtros, $page, $limit);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Acesso negado. Listagem de vendas PDV disponível apenas para administrador.') {
                return Response::json(['ok' => false, 'error' => $e->getMessage()], 403);
            }
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao listar vendas do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'resultado' => $resultado,
        ]);
    }

    public function visualizarVenda(Request $request, string $id): Response
    {
        $vendaId = $this->requirePositiveId($id);
        if ($vendaId === null) {
            return Response::json(['ok' => false, 'error' => 'ID de venda inválido'], 400);
        }

        try {
            $detalhes = $this->service->detalharVenda($vendaId);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao carregar venda do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'detalhes' => $detalhes,
        ]);
    }

    public function criarRascunho(Request $request): Response
    {
        $dados = $this->body($request);

        if (isset($dados['cliente_id']) && !$this->isPositiveInt($dados['cliente_id'])) {
            return Response::json(['ok' => false, 'error' => 'cliente_id inválido'], 400);
        }
        if (isset($dados['forma_pagamento'])) {
            try {
                PdvPaymentType::assertValid(trim((string)$dados['forma_pagamento']));
            } catch (InvalidArgumentException $e) {
                return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
            }
        }

        try {
            $venda = $this->service->criarVendaRascunho($dados);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao processar requisição do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'venda' => $venda,
        ], 201);
    }

    public function adicionarItem(Request $request, string $id): Response
    {
        $vendaId = $this->requirePositiveId($id);
        if ($vendaId === null) {
            return Response::json(['ok' => false, 'error' => 'ID de venda inválido'], 400);
        }

        $dados = $this->body($request);
        if (!$this->isPositiveInt($dados['produto_id'] ?? null)) {
            return Response::json(['ok' => false, 'error' => 'produto_id obrigatório e válido'], 400);
        }

        $quantidade = $dados['quantidade'] ?? $dados['qtd'] ?? null;
        if (!is_numeric($quantidade) || (float)$quantidade <= 0) {
            return Response::json(['ok' => false, 'error' => 'quantidade deve ser maior que zero'], 400);
        }

        $valor = $dados['valor_unitario'] ?? $dados['valor_unit'] ?? null;
        if ($valor !== null && (!is_numeric($valor) || (float)$valor < 0)) {
            return Response::json(['ok' => false, 'error' => 'valor_unitario inválido'], 400);
        }

        try {
            $venda = $this->service->adicionarItem($vendaId, $dados);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao processar requisição do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'venda' => $venda,
        ]);
    }

    public function removerItem(Request $request, string $id, string $itemId): Response
    {
        $vendaId = $this->requirePositiveId($id);
        $itemPk = $this->requirePositiveId($itemId);
        if ($vendaId === null || $itemPk === null) {
            return Response::json(['ok' => false, 'error' => 'IDs de venda ou item inválidos'], 400);
        }

        try {
            $resultado = $this->service->removerItemPersistido($vendaId, $itemPk);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao remover item do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'resultado' => $resultado,
        ]);
    }

    public function registrarPagamento(Request $request, string $id): Response
    {
        $vendaId = $this->requirePositiveId($id);
        if ($vendaId === null) {
            return Response::json(['ok' => false, 'error' => 'ID de venda inválido'], 400);
        }

        $dados = $this->body($request);
        $forma = trim((string)($dados['forma_pagamento'] ?? ''));
        if ($forma === '') {
            return Response::json(['ok' => false, 'error' => 'forma_pagamento obrigatória'], 400);
        }

        try {
            PdvPaymentType::assertValid($forma);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        if (!array_key_exists('valor', $dados) || !is_numeric($dados['valor']) || (float)$dados['valor'] < 0) {
            return Response::json(['ok' => false, 'error' => 'valor deve ser maior ou igual a zero'], 400);
        }

        try {
            $resultado = $this->service->registrarPagamentoRascunho($vendaId, $dados);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao processar requisição do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'resultado' => $resultado,
        ]);
    }

    public function atualizarTotais(Request $request, string $id): Response
    {
        $vendaId = $this->requirePositiveId($id);
        if ($vendaId === null) {
            return Response::json(['ok' => false, 'error' => 'ID de venda inválido'], 400);
        }

        $dados = $this->body($request);

        foreach (['desconto_geral', 'acrescimo_geral', 'total_desconto_geral', 'total_acrescimo_geral'] as $campo) {
            if (array_key_exists($campo, $dados) && !is_numeric($dados[$campo])) {
                return Response::json(['ok' => false, 'error' => "{$campo} deve ser numérico"], 400);
            }
        }

        try {
            $resultado = $this->service->atualizarTotaisRascunho($vendaId, $dados);
        } catch (OutOfRangeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao atualizar totais do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'resultado' => $resultado,
        ]);
    }

    public function prepararDocumento(Request $request, string $id): Response
    {
        $vendaId = $this->requirePositiveId($id);
        if ($vendaId === null) {
            return Response::json(['ok' => false, 'error' => 'ID de venda inválido'], 400);
        }

        $dados = $this->body($request);
        $tipoDocumento = trim((string)($dados['tipo_documento'] ?? ''));
        if ($tipoDocumento === '') {
            return Response::json(['ok' => false, 'error' => 'tipo_documento obrigatório'], 400);
        }

        try {
            PdvDocumentType::assertValid($tipoDocumento);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        if (isset($dados['categoria'])) {
            $categoria = trim((string)$dados['categoria']);
            $categoriaEsperada = PdvDocumentType::categoria($tipoDocumento);
            if ($categoria !== $categoriaEsperada) {
                return Response::json(['ok' => false, 'error' => 'categoria incompatível com o tipo_documento'], 400);
            }
        }

        try {
            $documentoId = $this->service->prepararDocumento(
                $vendaId,
                $tipoDocumento,
                $dados
            );
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao processar requisição do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'documento_id' => $documentoId,
        ], 201);
    }

    public function registrarDocumentoFiscalManual(Request $request, string $id): Response
    {
        $vendaId = $this->requirePositiveId($id);
        if ($vendaId === null) {
            return Response::json(['ok' => false, 'error' => 'ID de venda inválido'], 400);
        }

        $dados = $this->body($request);

        try {
            $resultado = $this->service->registrarDocumentoFiscalManual($vendaId, $dados);
        } catch (OutOfRangeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            if (str_starts_with($message, 'Acesso negado.')) {
                return Response::json(['ok' => false, 'error' => $message], 403);
            }
            return Response::json(['ok' => false, 'error' => $message], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao registrar documento fiscal manual do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'resultado' => $resultado,
        ], 201);
    }

    public function cancelarVinculoDocumentoFiscalManual(Request $request, string $id, string $documentoId): Response
    {
        $vendaId = $this->requirePositiveId($id);
        $docId = $this->requirePositiveId($documentoId);
        if ($vendaId === null || $docId === null) {
            return Response::json(['ok' => false, 'error' => 'ID de venda ou documento inválido'], 400);
        }

        $dados = $this->body($request);
        $motivo = trim((string)($dados['motivo'] ?? ''));

        try {
            $resultado = $this->service->cancelarVinculoDocumentoFiscalManual($vendaId, $docId, $motivo);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            if (str_starts_with($message, 'Acesso negado.')) {
                return Response::json(['ok' => false, 'error' => $message], 403);
            }
            return Response::json(['ok' => false, 'error' => $message], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao cancelar vínculo fiscal manual do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'resultado' => $resultado,
        ]);
    }

    public function cancelarRascunho(Request $request, string $id): Response
    {
        $vendaId = $this->requirePositiveId($id);
        if ($vendaId === null) {
            return Response::json(['ok' => false, 'error' => 'ID de venda inválido'], 400);
        }

        $dados = $this->body($request);
        $motivo = $this->sanitizeCancelReason($dados);

        try {
            $this->service->cancelarRascunho($vendaId, $motivo);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao cancelar rascunho do PDV.'], 500);
        }

        return Response::json(['ok' => true]);
    }

    public function finalizarVenda(Request $request, string $id): Response
    {
        $vendaId = $this->requirePositiveId($id);
        if ($vendaId === null) {
            return Response::json(['ok' => false, 'error' => 'ID de venda inválido'], 400);
        }

        try {
            $resultado = $this->service->finalizarVenda($vendaId);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao finalizar venda do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'resultado' => $resultado,
        ]);
    }

    public function estornarVenda(Request $request, string $id): Response
    {
        $vendaId = $this->requirePositiveId($id);
        if ($vendaId === null) {
            return Response::json(['ok' => false, 'error' => 'ID de venda inválido'], 400);
        }

        $dados = $this->body($request);
        $motivo = $this->sanitizeCancelReason($dados);

        try {
            $resultado = $this->service->estornarVenda($vendaId, $motivo);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Acesso negado. Estorno de venda PDV disponível apenas para administrador.') {
                return Response::json(['ok' => false, 'error' => $e->getMessage()], 403);
            }
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao estornar venda do PDV.'], 500);
        }

        return Response::json([
            'ok' => true,
            'resultado' => $resultado,
        ]);
    }

    private function requirePositiveId(string $id): ?int
    {
        $value = (int)$id;
        return $value > 0 ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        return is_array($request->body) ? $request->body : [];
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function sanitizeCancelReason(array $dados): string
    {
        $raw = $dados['cancel_reason'] ?? $dados['motivo'] ?? '';
        $reason = trim(strip_tags((string)$raw));
        $reason = preg_replace('/\s+/u', ' ', $reason) ?? '';
        $reason = trim($reason);

        if ($reason === '') {
            return 'Cancelado manualmente.';
        }

        return mb_substr($reason, 0, 500);
    }

    private function isPositiveInt(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return ctype_digit((string)$value) && (int)$value > 0;
    }
}
