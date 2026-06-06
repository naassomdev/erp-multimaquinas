<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\CatalogService;
use App\Services\CatalogSourceSettingsService;
use Throwable;

/**
 * Endpoints de catálogo de vista explodida (Felap, TSN, Bosch, Milwaukee).
 *
 * Atua como proxy autenticado para o microserviço Node em 127.0.0.1:3001.
 * Toda chamada passa primeiro pelo AuthMiddleware (ver routes.php).
 */
final class CatalogApiController
{
    public function __construct(
        private readonly CatalogService $service = new CatalogService(),
        private readonly CatalogSourceSettingsService $settings = new CatalogSourceSettingsService(),
    ) {}

    /** GET /api/catalogo/fontes */
    public function fontes(Request $request): Response
    {
        $fontes = $this->settings->listarAtivas();
        return Response::json([
            'ok' => true,
            'total' => count($fontes),
            'fontes' => $fontes,
        ]);
    }

    /** GET /api/catalogo/marcas?fonte= */
    public function marcas(Request $request): Response
    {
        $fonteId = (string) $request->input('fonte', '');
        $fonte = $fonteId !== '' ? $this->settings->buscarAtiva($fonteId) : null;

        if ($fonteId !== '' && $fonte === null) {
            return Response::json(['ok' => false, 'erro' => 'Fonte não disponível'], 404);
        }

        if ($fonte !== null && ($fonte['driver'] ?? '') === 'custom') {
            return Response::json(['ok' => true, 'total' => 0, 'marcas' => []]);
        }

        return $this->forward(fn() => $this->service->get('/api/marcas', [
            'fonte' => (string) ($fonte['driver'] ?? $fonteId),
        ]));
    }

    /**
     * GET /api/catalogo/modelos?fonte=felap&marca=2[&q=...]
     * GET /api/catalogo/modelos?fonte=tsn&brand=DW[&q=...]
     */
    public function modelos(Request $request): Response
    {
        $fonteId = (string) $request->input('fonte', '');
        $fonte = $this->settings->buscarAtiva($fonteId);
        if ($fonte === null) {
            return Response::json(['ok' => false, 'erro' => 'Fonte não disponível'], 404);
        }
        if (($fonte['driver'] ?? '') === 'custom') {
            return Response::json(['ok' => false, 'erro' => 'Esta fonte usa busca direta e não expõe lista de modelos.'], 400);
        }

        return $this->forward(fn() => $this->service->get('/api/modelos', [
            'fonte' => (string) ($fonte['driver'] ?? ''),
            'marca' => (string) $request->input('marca', ''),
            'brand' => (string) $request->input('brand', ''),
            'q'     => (string) $request->input('q', ''),
        ]));
    }

    /**
     * GET /api/catalogo/produto?fonte=...&modelo=...
     * GET /api/catalogo/produto?fonte=bosch&typenr=...
     */
    public function produto(Request $request): Response
    {
        $fonteId = (string) $request->input('fonte', '');
        $fonte = $this->settings->buscarAtiva($fonteId);
        if ($fonte === null) {
            return Response::json(['ok' => false, 'erro' => 'Fonte não disponível'], 404);
        }

        if (($fonte['driver'] ?? '') === 'custom') {
            $modelo = trim((string) $request->input('modelo', ''));
            $marca = trim((string) $request->input('marca_nome', ''));
            $query = trim((string) $request->input('q', ''));
            if ($modelo === '' && $query === '') {
                return Response::json(['ok' => false, 'erro' => 'Informe o modelo ou a busca para a fonte configurada.'], 400);
            }

            $documento = $this->settings->gerarResultadoCustom($fonte, $modelo, $marca, $query);
            return Response::json([
                'ok' => true,
                'total' => 1,
                'documentos' => [$documento],
            ]);
        }

        return $this->forward(fn() => $this->service->get('/api/produto', [
            'fonte'  => (string) ($fonte['driver'] ?? ''),
            'modelo' => (string) $request->input('modelo', ''),
            'typenr' => (string) $request->input('typenr', ''),
        ]));
    }

    /**
     * GET /api/catalogo/pdf?marca=2&modelo=DCD776-TIPO10.pdf
     * Resolve o redirect 302 do Felap e devolve a URL final pro frontend abrir.
     */
    public function pdfFelap(Request $request): Response
    {
        $marca = (int) $request->input('marca', 0);
        $arq   = trim((string) $request->input('modelo', ''));
        if ($marca <= 0 || $arq === '') {
            return Response::json(['ok' => false, 'erro' => 'Parâmetros marca e modelo obrigatórios'], 400);
        }

        try {
            $url = $this->service->resolvePdfRedirect($marca, $arq);
            if ($url === null) {
                return Response::json(['ok' => false, 'erro' => 'PDF não encontrado'], 404);
            }
            return Response::json(['ok' => true, 'url' => $url]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'erro' => $e->getMessage()], 502);
        }
    }

    /** GET /api/catalogo/health — healthcheck do microserviço Node */
    public function health(Request $request): Response
    {
        return Response::json([
            'ok'      => true,
            'catalog' => $this->service->isAlive() ? 'online' : 'offline',
        ]);
    }

    // ── Internal ───────────────────────────────────────────────────────

    /**
     * Executa o callable do service e devolve a resposta com o status HTTP
     * que o Node retornou (preserva 4xx/5xx). Trata indisponibilidade do
     * microserviço como 502 Bad Gateway.
     */
    private function forward(callable $fn): Response
    {
        try {
            $data   = $fn();
            $status = (int) ($data['_http_status'] ?? 200);
            unset($data['_http_status']);
            return Response::json($data, $status >= 200 && $status < 600 ? $status : 200);
        } catch (Throwable $e) {
            return Response::json([
                'ok'   => false,
                'erro' => 'Catalog API indisponível: ' . $e->getMessage(),
            ], 502);
        }
    }
}
