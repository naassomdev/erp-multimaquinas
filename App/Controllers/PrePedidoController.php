<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\PrePedidoService;
use App\Services\UploadService;
use Throwable;

final class PrePedidoController
{
    public function __construct(
        private readonly PrePedidoService $service = new PrePedidoService(),
        private readonly UploadService    $upload  = new UploadService(),
    ) {}

    /**
     * GET /pre-pedido — formulário/preview (já existia).
     */
    public function index(Request $request): Response
    {
        return Response::html(View::render('pre-pedido/index', [
            'titulo'     => 'Gerador de Pré-Pedido / Orçamento',
            'activeMenu' => 'pre-pedido',
            'usuario'    => Auth::user(),
        ]));
    }

    /**
     * POST /api/pre-pedido — recebe dados + foto, salva e devolve URLs prontas.
     *
     * Resposta JSON:
     * {
     *   "ok": true,
     *   "slug": "abc...",
     *   "numero": "0001/2026",
     *   "url_publica":  "https://host/pre-pedido/abc.../visualizar",
     *   "url_imprimir": "https://host/pre-pedido/abc.../visualizar?print=1",
     *   "url_whatsapp": "https://wa.me/55...?text=...",
     *   "url_mailto":   "mailto:cliente@...?subject=...&body=..."
     * }
     */
    public function salvar(Request $request): Response
    {
        try {
            $dados = $this->extrair($request);
            $erro  = $this->validar($dados);
            if ($erro !== null) {
                return Response::json(['ok' => false, 'error' => $erro], 422);
            }

            // Foto opcional — só processa se veio arquivo válido.
            $fotoUrl = null;
            $upload  = $_FILES['foto'] ?? null;
            if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                // OS ID falso só pra reutilizar o esquema de pasta do UploadService.
                $bucketId = 'pp-' . date('Ymd');
                $fotoUrl  = $this->upload->salvar($bucketId, 0, UploadService::KIND_FOTO, $upload);
            }

            $usuario = Auth::user();
            $registro = $this->service->criar([
                'nome'               => $dados['nome'],
                'telefone'           => $dados['telefone'],
                'email'              => $dados['email'],
                'descricao'          => $dados['descricao'],
                'qtd'                => $dados['qtd'],
                'valor'              => $dados['valor'],
                'vantagens'          => $dados['vantagens'],
                'aplicacoes'         => $dados['aplicacoes'],
                'especificacoes'     => $dados['especificacoes'],
                'prazo_entrega'      => $dados['prazo_entrega'],
                'cond_pagamento'     => $dados['cond_pagamento'],
                'validade_orcamento' => $dados['validade_orcamento'],
                'foto_url'           => $fotoUrl,
                'criado_por'         => $usuario['nome'] ?? '',
            ]);

            // Recarrega para montar links com payload completo.
            $completo = $this->service->carregar($registro['slug']) ?? [];

            $base        = $this->baseUrl();
            $urlPublica  = $base . '/pre-pedido/' . $registro['slug'] . '/visualizar';
            $urlImprimir = $urlPublica . '?print=1';
            $urlWhats    = $this->service->montarLinkWhatsapp($completo, $urlPublica);
            $urlMail     = $this->service->montarLinkMailto($completo, $urlPublica);

            return Response::json([
                'ok'           => true,
                'slug'         => $registro['slug'],
                'numero'       => $registro['numero'],
                'total'        => $registro['total'],
                'foto_url'     => $fotoUrl,
                'url_publica'  => $urlPublica,
                'url_imprimir' => $urlImprimir,
                'url_whatsapp' => $urlWhats,
                'url_mailto'   => $urlMail,
            ]);
        } catch (Throwable $e) {
            error_log('[PrePedido] salvar falhou: ' . $e->getMessage());
            return Response::json([
                'ok'    => false,
                'error' => 'Não foi possível salvar o pré-pedido: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /pre-pedido/{slug}/visualizar — magic link público.
     * Sem AuthMiddleware: o slug aleatório (16 hex) já é a "credencial".
     * ?print=1 dispara o window.print() automaticamente.
     */
    public function visualizar(Request $request, string $slug): Response
    {
        $registro = $this->service->carregar($slug);
        if ($registro === null) {
            throw new HttpException(404, 'Orçamento não encontrado ou expirado.');
        }

        $autoPrint = $request->input('print') === '1';

        return Response::html(View::render('pre-pedido/visualizar', [
            'titulo'     => "Orçamento Nº {$registro['numero']} — Multimáquinas",
            'registro'   => $registro,
            'auto_print' => $autoPrint,
        ]));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function extrair(Request $request): array
    {
        $vantagens = $this->parseStringListJson((string)$request->input('vantagens_json', '[]'));
        $aplicacoes = $this->parseStringListJson((string)$request->input('aplicacoes_json', '[]'));
        $especificacoes = $this->parseSpecListJson((string)$request->input('especificacoes_json', '[]'));

        return [
            'nome'              => trim((string)$request->input('nome', '')),
            'telefone'          => trim((string)$request->input('telefone', '')),
            'email'             => trim((string)$request->input('email', '')),
            'descricao'         => trim((string)$request->input('descricao', '')),
            'qtd'               => (int)$request->input('qtd', 1),
            'valor'             => (float)str_replace(',', '.', (string)$request->input('valor', '0')),
            'vantagens'         => $vantagens,
            'aplicacoes'        => $aplicacoes,
            'especificacoes'    => $especificacoes,
            'prazo_entrega'     => trim((string)$request->input('prazo_entrega', '')),
            'cond_pagamento'    => trim((string)$request->input('cond_pagamento', '')),
            'validade_orcamento'=> trim((string)$request->input('validade_orcamento', '')),
        ];
    }

    private function validar(array $d): ?string
    {
        if ($d['nome'] === '')      return 'Nome do cliente é obrigatório.';
        if ($d['descricao'] === '') return 'Descrição do item é obrigatória.';
        if ($d['qtd'] < 1)          return 'Quantidade deve ser ≥ 1.';
        if ($d['valor'] <= 0)       return 'Valor unitário deve ser maior que zero.';
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            return 'E-mail inválido.';
        }
        if (mb_strlen($d['prazo_entrega']) > 140) {
            return 'Prazo de entrega excede o limite de 140 caracteres.';
        }
        if (mb_strlen($d['cond_pagamento']) > 180) {
            return 'Condições de pagamento excedem o limite de 180 caracteres.';
        }
        if (mb_strlen($d['validade_orcamento']) > 140) {
            return 'Validade do orçamento excede o limite de 140 caracteres.';
        }
        return null;
    }

    /** @return array<int, string> */
    private function parseStringListJson(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $list = [];
        foreach ($data as $v) {
            $txt = trim((string)$v);
            if ($txt !== '') {
                $list[] = mb_substr($txt, 0, 180);
            }
        }
        return array_values(array_unique($list));
    }

    /** @return array<int, array{chave:string, valor:string}> */
    private function parseSpecListJson(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $specs = [];
        foreach ($data as $row) {
            if (!is_array($row)) continue;
            $chave = trim((string)($row['chave'] ?? ''));
            $valor = trim((string)($row['valor'] ?? ''));
            if ($chave === '' || $valor === '') continue;
            $specs[] = [
                'chave' => mb_substr($chave, 0, 80),
                'valor' => mb_substr($valor, 0, 180),
            ];
        }
        return $specs;
    }

    /**
     * Base URL absoluta para montar magic-link. Respeita proxy reverso
     * (X-Forwarded-Proto/Host) quando definido pelo aaPanel/Nginx.
     */
    private function baseUrl(): string
    {
        $env = trim((string)(getenv('APP_URL') ?: ''));
        if ($env !== '') return rtrim($env, '/');

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = (string)$_SERVER['HTTP_X_FORWARDED_PROTO'];
        }
        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }
}
