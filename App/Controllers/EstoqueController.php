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
use App\Repositories\ProdutoFullRepository;
use App\Services\AuditoriaService;
use App\Services\EstoqueService;

final class EstoqueController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly ProdutoFullRepository $repo    = new ProdutoFullRepository(),
        private readonly EstoqueService        $service = new EstoqueService(),
        private readonly AuditoriaService      $audit   = new AuditoriaService(),
    ) {}

    /**
     * GET /estoque — lista de produtos com busca, filtros e paginação
     */
    public function index(Request $request): Response
    {
        $filtros = [
            'busca'         => trim((string) $request->input('q', '')),
            'categoria'     => trim((string) $request->input('categoria', '')),
            'marca'         => trim((string) $request->input('marca', '')),
            'estoque_baixo' => trim((string) $request->input('estoque_baixo', '')),
            'ativo'         => trim((string) $request->input('ativo', '1')),
        ];

        $page = max(1, (int) $request->input('p', 1));
        $total = $this->repo->contar($filtros);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $totalPages) $page = $totalPages;

        $produtos   = $this->repo->listar($filtros, $page, self::PER_PAGE);
        $categorias = $this->repo->listarCategorias();
        $marcas     = $this->repo->listarMarcas();

        // Alertas de estoque baixo
        $alertasEstoque = $this->repo->listarEstoqueBaixo(10);

        return Response::html(View::render('estoque/index', [
            'titulo'         => 'Estoque — Produtos e Peças',
            'activeMenu'     => 'estoque',
            'produtos'       => $produtos,
            'filtros'        => $filtros,
            'categorias'     => $categorias,
            'marcas'         => $marcas,
            'alertasEstoque' => $alertasEstoque,
            'return_url'     => $this->currentEstoqueListUrl($request),
            'paginacao'      => [
                'page'        => $page,
                'per_page'    => self::PER_PAGE,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ]));
    }

    /**
     * GET /estoque/exportar — exporta o estoque filtrado no formato CSV para download
     */
    public function exportarCsv(Request $request): Response
    {
        $filtros = [
            'busca'         => trim((string) $request->input('q', '')),
            'categoria'     => trim((string) $request->input('categoria', '')),
            'marca'         => trim((string) $request->input('marca', '')),
            'estoque_baixo' => trim((string) $request->input('estoque_baixo', '')),
            'ativo'         => trim((string) $request->input('ativo', '1')),
        ];

        $produtos = $this->repo->listarTodos($filtros);

        $stream = fopen('php://temp', 'r+');
        // Insere o BOM (Byte Order Mark) para garantir compatibilidade com UTF-8 no Excel
        fwrite($stream, "\xEF\xBB\xBF");

        // Cabeçalhos das colunas
        $headers = [
            'ID',
            'Código',
            'EAN',
            'Descrição',
            'Categoria',
            'Marca',
            'NCM',
            'Unidade',
            'Preço de Custo (R$)',
            'Margem de Lucro (%)',
            'Valor de Venda (R$)',
            'Preço de Oferta (R$)',
            'Estoque Atual',
            'Estoque Mínimo',
            'Controla Estoque',
            'Status',
            'Data de Cadastro'
        ];
        fputcsv($stream, $headers, ';', '"', '\\');

        foreach ($produtos as $p) {
            $ativo = (int)($p['ativo'] ?? 1) === 1 ? 'Ativo' : 'Inativo';
            $controlaEstoque = (int)($p['controla_estoque'] ?? 1) === 1 ? 'Sim' : 'Não';
            $dataCadastro = !empty($p['created_at']) ? date('d/m/Y H:i:s', strtotime($p['created_at'])) : '';

            $linha = [
                $p['id'],
                $p['codigo'] ?: '',
                $p['ean'] ?: '',
                $p['descricao'],
                $p['categoria'] ?: '',
                $p['marca'] ?: '',
                $p['ncm'] ?: '',
                $p['unidade'] ?: 'un',
                number_format((float)($p['preco_custo'] ?? 0), 2, ',', ''),
                number_format((float)($p['margem_lucro'] ?? 0), 2, ',', ''),
                number_format((float)($p['valor_venda_calculado'] ?? $p['valor'] ?? 0), 2, ',', ''),
                number_format((float)($p['valor_oferta'] ?? 0), 2, ',', ''),
                number_format((float)($p['estoque_qty'] ?? 0), 2, ',', ''),
                number_format((float)($p['estoque_min'] ?? 0), 2, ',', ''),
                $controlaEstoque,
                $ativo,
                $dataCadastro
            ];

            fputcsv($stream, $linha, ';', '"', '\\');
        }

        rewind($stream);
        $csvContent = stream_get_contents($stream);
        fclose($stream);

        $filename = 'estoque_exportado_' . date('Ymd_His') . '.csv';

        return new Response($csvContent, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
        ]);
    }

    /**
     * GET /estoque/novo — formulário de novo produto
     */
    public function criar(Request $request): Response
    {
        return Response::html(View::render('estoque/form', [
            'titulo'     => 'Novo Produto',
            'activeMenu' => 'estoque',
            'produto'    => null,
            'csrf_token' => Csrf::token(),
            'modo'       => 'criar',
        ]));
    }

    /**
     * POST /estoque — salvar novo produto
     */
    public function salvar(Request $request): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/estoque/novo');
        }

        $dados = $this->extrairDados($request);
        $this->service->recalcularPrecos($dados);

        // Validação
        if (trim($dados['descricao']) === '') {
            Flash::set('error', 'O campo Descrição é obrigatório.');
            Flash::keepOld($dados);
            return Response::redirect('/estoque/novo');
        }

        // Verificar duplicidade de código
        $codigo = trim($dados['codigo'] ?? '');
        if ($codigo !== '' && $this->repo->buscarPorCodigo($codigo) !== null) {
            Flash::set('error', 'Já existe um produto com este código.');
            Flash::keepOld($dados);
            return Response::redirect('/estoque/novo');
        }

        $id = $this->repo->criar($dados);
        $this->audit->registrar('produtos', (string) $id, 'INSERT', $dados);

        Flash::set('success', 'Produto cadastrado com sucesso!');
        return Response::redirect('/estoque/' . $id);
    }

    /**
     * GET /estoque/{id} — visualização detalhada
     */
    public function visualizar(Request $request, string $id): Response
    {
        $produto = $this->repo->buscarPorId((int) $id);
        if ($produto === null) {
            throw new HttpException(404, "Produto #{$id} não encontrado.");
        }

        return Response::html(View::render('estoque/detalhe', [
            'titulo'     => 'Produto — ' . $produto['descricao'],
            'activeMenu' => 'estoque',
            'produto'    => $produto,
            'return_url' => $this->safeEstoqueReturnUrl($request),
        ]));
    }

    /**
     * GET /estoque/{id}/editar — formulário de edição
     */
    public function editar(Request $request, string $id): Response
    {
        $produto = $this->repo->buscarPorId((int) $id);
        if ($produto === null) {
            throw new HttpException(404, "Produto #{$id} não encontrado.");
        }

        return Response::html(View::render('estoque/form', [
            'titulo'     => 'Editar Produto — ' . $produto['descricao'],
            'activeMenu' => 'estoque',
            'produto'    => $produto,
            'csrf_token' => Csrf::token(),
            'modo'       => 'editar',
            'return_url' => $this->safeEstoqueReturnUrl($request),
        ]));
    }

    /**
     * POST /estoque/{id} — atualizar produto
     */
    public function atualizar(Request $request, string $id): Response
    {
        $returnUrl = $this->safeEstoqueReturnUrl($request);
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect($this->withReturnUrl("/estoque/{$id}/editar", $returnUrl));
        }

        $produto = $this->repo->buscarPorId((int) $id);
        if ($produto === null) {
            throw new HttpException(404, "Produto #{$id} não encontrado.");
        }

        $dados = $this->extrairDados($request);
        $this->service->recalcularPrecos($dados);

        if (trim($dados['descricao']) === '') {
            Flash::set('error', 'O campo Descrição é obrigatório.');
            Flash::keepOld($dados);
            return Response::redirect($this->withReturnUrl("/estoque/{$id}/editar", $returnUrl));
        }

        // Verificar duplicidade de código (excluindo o próprio)
        $codigo = trim($dados['codigo'] ?? '');
        if ($codigo !== '') {
            $existente = $this->repo->buscarPorCodigo($codigo);
            if ($existente !== null && (int) $existente['id'] !== (int) $id) {
                Flash::set('error', 'Já existe outro produto com este código.');
                Flash::keepOld($dados);
                return Response::redirect($this->withReturnUrl("/estoque/{$id}/editar", $returnUrl));
            }
        }

        $this->repo->atualizar((int) $id, $dados);
        $this->audit->registrar('produtos', (string) $id, 'UPDATE', $dados);

        Flash::set('success', 'Produto atualizado com sucesso!');
        return Response::redirect($this->withReturnUrl('/estoque/' . $id, $returnUrl));
    }

    /**
     * POST /estoque/{id}/desativar — desativar produto (soft delete)
     */
    public function desativar(Request $request, string $id): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect('/estoque');
        }

        $produto = $this->repo->buscarPorId((int) $id);
        if ($produto === null) {
            throw new HttpException(404, "Produto #{$id} não encontrado.");
        }

        $this->repo->desativar((int) $id);
        $this->audit->registrar('produtos', (string) $id, 'DESATIVAR', ['descricao' => $produto['descricao']]);
        Flash::set('success', 'Produto desativado.');
        return Response::redirect('/estoque');
    }

    /**
     * GET /estoque/importar — formulário de importação NF-e XML
     */
    public function importarForm(Request $request): Response
    {
        return Response::html(View::render('estoque/importar', [
            'titulo'     => 'Importar NF-e de Compra',
            'activeMenu' => 'estoque',
            'csrf_token' => Csrf::token(),
        ]));
    }

    /**
     * POST /estoque/importar/preview — PASSO 1: ler XML em memória e renderizar
     * a tela de pré-visualização para o Admin conferir/ajustar antes de salvar.
     * Não escreve nada no banco.
     */
    public function previewXml(Request $request): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect('/estoque/importar');
        }

        $arquivo = $request->file('xml_nfe');
        if ($arquivo === null || $arquivo['error'] !== UPLOAD_ERR_OK) {
            Flash::set('error', 'Selecione um arquivo XML válido.');
            return Response::redirect('/estoque/importar');
        }

        $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xml') {
            Flash::set('error', 'O arquivo deve ser um XML de NF-e.');
            return Response::redirect('/estoque/importar');
        }

        try {
            $importer = new \App\Services\Estoque\NfeXmlImporter(Database::pdo());
            $preview  = $importer->parseXml($arquivo['tmp_name']);
        } catch (\Throwable $e) {
            Flash::set('error', 'Erro ao ler XML: ' . $e->getMessage());
            return Response::redirect('/estoque/importar');
        }

        if ($preview['ja_importada']) {
            Flash::set('info', 'Esta NF-e já foi importada anteriormente. Nenhuma ação necessária.');
            return Response::redirect('/estoque/importar');
        }

        return Response::html(View::render('estoque/xml_preview', [
            'titulo'     => 'Conferência da NF-e — ' . $preview['chave_nfe'],
            'activeMenu' => 'estoque',
            'preview'    => $preview,
            'csrf_token' => Csrf::token(),
        ]));
    }

    /**
     * POST /estoque/importar/confirmar — PASSO 2: persiste a importação
     * com os dados ajustados pelo usuário (vinculação de peça + margem/preço).
     */
    public function confirmXml(Request $request): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect('/estoque/importar');
        }

        $payload = $this->extrairPayloadConfirmacao($request);
        if ($payload === null) {
            Flash::set('error', 'Dados da pré-visualização inválidos.');
            return Response::redirect('/estoque/importar');
        }

        try {
            $importer  = new \App\Services\Estoque\NfeXmlImporter(Database::pdo());
            $resultado = $importer->confirmarImportacao($payload, Auth::id());

            if (isset($resultado['aviso'])) {
                Flash::set('info', $resultado['aviso']);
            } else {
                $msg = "NF-e importada! {$resultado['inseridos']} produtos novos, "
                     . "{$resultado['atualizados']} atualizados.";
                if (($resultado['necessidades_match'] ?? 0) > 0) {
                    $msg .= " {$resultado['necessidades_match']} pendência(s) atendida(s).";
                }
                Flash::set('success', $msg);
            }

            $this->audit->registrar(
                'produtos',
                $resultado['chave_nfe'] ?? '',
                'IMPORTAR_NFE',
                $resultado,
            );
        } catch (\Throwable $e) {
            Flash::set('error', 'Erro ao confirmar importação: ' . $e->getMessage());
        }

        return Response::redirect('/estoque');
    }

    /**
     * Normaliza o POST do formulário de preview em um payload limpo para o Service.
     * Aceita números no formato BR (vírgula decimal) e descarta linhas inválidas.
     */
    private function extrairPayloadConfirmacao(Request $request): ?array
    {
        $chave = trim((string) $request->input('chave_nfe', ''));
        if ($chave === '' || strlen($chave) !== 44) {
            return null;
        }

        $num = static function (mixed $v): float {
            if ($v === null || $v === '') return 0.0;
            return (float) str_replace(',', '.', (string) $v);
        };

        $itensIn = (array) $request->input('itens', []);
        $itens   = [];
        foreach ($itensIn as $row) {
            if (!is_array($row)) continue;
            $codigo = trim((string) ($row['codigo'] ?? ''));
            if ($codigo === '' && trim((string) ($row['descricao'] ?? '')) === '') continue;

            $produtoId = (int) ($row['produto_id'] ?? 0);
            $itens[] = [
                'codigo'      => $codigo,
                'descricao'   => trim((string) ($row['descricao'] ?? '')),
                'ncm'         => trim((string) ($row['ncm']       ?? '')),
                'ean'         => trim((string) ($row['ean']       ?? '')),
                'unidade'     => trim((string) ($row['unidade']   ?? 'un')) ?: 'un',
                'qty'         => $num($row['qty']         ?? null),
                'vlr_unit'    => $num($row['vlr_unit']    ?? null),
                'produto_id'  => $produtoId > 0 ? $produtoId : null,
                'margem'      => $num($row['margem']      ?? null),
                'preco_venda' => $num($row['preco_venda'] ?? null),
            ];
        }

        if (empty($itens)) return null;

        return [
            'chave_nfe'    => $chave,
            'emitente'     => trim((string) $request->input('emitente', '')),
            'cnpj'         => trim((string) $request->input('cnpj', '')),
            'valor_total'  => $num($request->input('valor_total')),
            'data_emissao' => trim((string) $request->input('data_emissao', '')),
            'itens'        => $itens,
        ];
    }

    /**
     * Extrai dados do formulário.
     * Campos numéricos usam parseDecimalInput() para evitar zerar valores
     * existentes quando o input vem vazio (Bug 5: preco_custo zerado).
     */
    private function extrairDados(Request $request): array
    {
        return [
            'codigo'               => trim((string) $request->input('codigo', '')),
            'ean'                  => trim((string) $request->input('ean', '')),
            'descricao'            => trim((string) $request->input('descricao', '')),
            'categoria'            => trim((string) $request->input('categoria', '')),
            'marca'                => trim((string) $request->input('marca', '')),
            'ncm'                  => trim((string) $request->input('ncm', '')),
            'unidade'              => trim((string) $request->input('unidade', 'un')),
            'preco_custo'          => $this->parseDecimalInput($request->input('preco_custo')),
            'margem_lucro'         => $this->parseDecimalInput($request->input('margem_lucro')),
            'valor_venda_calculado'=> $this->parseDecimalInput($request->input('valor_venda_calculado')),
            'valor'                => $this->parseDecimalInput($request->input('valor')),
            'valor_oferta'         => $this->parseDecimalInput($request->input('valor_oferta')),
            'estoque_qty'          => $this->parseDecimalInput($request->input('estoque_qty')),
            'estoque_min'          => $this->parseDecimalInput($request->input('estoque_min')),
            'ativo'                => (int) $request->input('ativo', 1),
            'controla_estoque'     => (int) $request->input('controla_estoque', 1),
        ];
    }

    /**
     * Converte input de valor decimal de forma segura.
     * Retorna null para inputs vazios/ausentes em vez de 0.0,
     * para que o repositório ignore o campo no UPDATE.
     */
    private function parseDecimalInput(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private function currentEstoqueListUrl(Request $request): string
    {
        $query = $request->query;
        unset($query['return_url']);

        $qs = http_build_query($query);
        return '/estoque' . ($qs !== '' ? '?' . $qs : '');
    }

    private function safeEstoqueReturnUrl(Request $request): string
    {
        $returnUrl = trim((string) $request->input('return_url', ''));
        if ($returnUrl === '') {
            return '/estoque';
        }

        if (strlen($returnUrl) > 2048 || preg_match('/[\\r\\n]/', $returnUrl)) {
            return '/estoque';
        }

        $parts = parse_url($returnUrl);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
            return '/estoque';
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return '/estoque';
        }

        if ($path !== '/estoque' && !str_starts_with($path, '/estoque/')) {
            return '/estoque';
        }

        $safe = $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $safe .= '?' . $parts['query'];
        }

        return $safe;
    }

    private function withReturnUrl(string $path, string $returnUrl): string
    {
        if ($returnUrl === '/estoque') {
            return $path;
        }

        return $path . (str_contains($path, '?') ? '&' : '?') . 'return_url=' . rawurlencode($returnUrl);
    }
}
