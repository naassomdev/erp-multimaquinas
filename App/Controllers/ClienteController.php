<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\ClienteRepository;
use App\Services\AuditoriaService;
use App\Services\ClienteDocumentoSettingsService;

final class ClienteController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly ClienteRepository $repo = new ClienteRepository(),
        private readonly AuditoriaService  $audit = new AuditoriaService(),
        private readonly ClienteDocumentoSettingsService $documentoSettings = new ClienteDocumentoSettingsService(),
    ) {}

    /**
     * GET /clientes — lista com busca + paginação
     */
    public function index(Request $request): Response
    {
        $filtros = [
            'busca' => trim((string) $request->input('q', '')),
            'uf'    => trim((string) $request->input('uf', '')),
        ];

        $page = max(1, (int) $request->input('p', 1));
        $total = $this->repo->contar($filtros);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $totalPages) $page = $totalPages;

        $clientes = $this->repo->listar($filtros, $page, self::PER_PAGE);

        return Response::html(View::render('clientes/index', [
            'titulo'     => 'Clientes',
            'activeMenu' => 'clientes',
            'clientes'   => $clientes,
            'filtros'    => $filtros,
            'paginacao'  => [
                'page'        => $page,
                'per_page'    => self::PER_PAGE,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ]));
    }

    /**
     * GET /clientes/novo — formulário de criação
     */
    public function criar(Request $request): Response
    {
        return Response::html(View::render('clientes/form', [
            'titulo'     => 'Novo Cliente',
            'activeMenu' => 'clientes',
            'cliente'    => null,
            'csrf_token' => Csrf::token(),
            'modo'       => 'criar',
            'doc_settings' => $this->documentoSettings->obter(),
        ]));
    }

    /**
     * POST /clientes — salvar novo cliente
     */
    public function salvar(Request $request): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/clientes/novo');
        }

        $dados = $this->extrairDados($request);

        // Validação básica
        if (trim($dados['nome']) === '') {
            Flash::set('error', 'O campo Nome é obrigatório.');
            Flash::keepOld($dados);
            return Response::redirect('/clientes/novo');
        }

        // Verificar duplicidade de CPF/CNPJ
        $doc = preg_replace('/\D/', '', $dados['cpf_cnpj'] ?? '');
        if ($doc !== '' && $this->repo->buscarPorCpfCnpj($doc) !== null) {
            Flash::set('error', 'Já existe um cliente cadastrado com este CPF/CNPJ.');
            Flash::keepOld($dados);
            return Response::redirect('/clientes/novo');
        }

        $id = $this->repo->criar($dados);
        $this->audit->registrar('clientes', (string) $id, 'INSERT', $dados);

        // 11B-4: Verificação pós-salvamento (backend validation non-blocking)
        $duplicados = $this->repo->buscarPossiveisDuplicadosCadastro($dados, $id);
        if (!empty($duplicados)) {
            Flash::set('warning', 'Cliente cadastrado com sucesso, mas existem ' . count($duplicados) . ' possíveis duplicados (verifique o cadastro).');
        } else {
            Flash::set('success', 'Cliente cadastrado com sucesso!');
        }

        return Response::redirect('/clientes/' . $id);
    }

    /**
     * GET /clientes/{id} — visualização detalhada
     */
    public function visualizar(Request $request, string $id): Response
    {
        $cliente = $this->repo->buscarPorId((int) $id);
        if ($cliente === null) {
            throw new HttpException(404, "Cliente #{$id} não encontrado.");
        }

        $ordens = $this->repo->listarOsDoCliente((int) $id);

        return Response::html(View::render('clientes/detalhe', [
            'titulo'     => 'Cliente — ' . $cliente['nome'],
            'activeMenu' => 'clientes',
            'cliente'    => $cliente,
            'ordens'     => $ordens,
        ]));
    }

    /**
     * GET /clientes/{id}/editar — formulário de edição
     */
    public function editar(Request $request, string $id): Response
    {
        $cliente = $this->repo->buscarPorId((int) $id);
        if ($cliente === null) {
            throw new HttpException(404, "Cliente #{$id} não encontrado.");
        }

        return Response::html(View::render('clientes/form', [
            'titulo'     => 'Editar Cliente — ' . $cliente['nome'],
            'activeMenu' => 'clientes',
            'cliente'    => $cliente,
            'csrf_token' => Csrf::token(),
            'modo'       => 'editar',
            'doc_settings' => $this->documentoSettings->obter(),
        ]));
    }

    /**
     * GET /clientes/configuracao-documentos
     */
    public function configuracaoDocumentos(Request $request): Response
    {
        return Response::html(View::render('clientes/configuracao_documentos', [
            'titulo'      => 'Clientes - Configuração CPF/CNPJ',
            'activeMenu'  => 'clientes',
            'settings'    => $this->documentoSettings->obter(),
            'csrf_token'  => Csrf::token(),
        ]));
    }

    /**
     * POST /clientes/configuracao-documentos
     */
    public function salvarConfiguracaoDocumentos(Request $request): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/clientes/configuracao-documentos');
        }

        $payload = [
            'cpfhub_api_key' => $request->input('cpfhub_api_key', ''),
            'cpfhub_base_url' => $request->input('cpfhub_base_url', ''),
            'cpfhub_mcp_url' => $request->input('cpfhub_mcp_url', ''),
            'cnpj_base_url' => $request->input('cnpj_base_url', ''),
            'plan_name' => $request->input('plan_name', ''),
            'monthly_plan_limit' => $request->input('monthly_plan_limit', ''),
            'support_whatsapp_number' => $request->input('support_whatsapp_number', ''),
            'support_whatsapp_url' => $request->input('support_whatsapp_url', ''),
        ];

        try {
            $this->documentoSettings->salvar($payload);
            $this->audit->registrar('configuracoes', 'cliente_documentos', 'UPDATE', [
                'escopo' => 'cliente_documentos',
            ]);
            Flash::set('success', 'Configuração de CPF/CNPJ salva com sucesso.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        return Response::redirect('/clientes/configuracao-documentos');
    }

    /**
     * POST /clientes/{id} — atualizar cliente
     */
    public function atualizar(Request $request, string $id): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect("/clientes/{$id}/editar");
        }

        $cliente = $this->repo->buscarPorId((int) $id);
        if ($cliente === null) {
            throw new HttpException(404, "Cliente #{$id} não encontrado.");
        }

        $dados = $this->extrairDados($request);

        if (trim($dados['nome']) === '') {
            Flash::set('error', 'O campo Nome é obrigatório.');
            Flash::keepOld($dados);
            return Response::redirect("/clientes/{$id}/editar");
        }

        // Verificar duplicidade de CPF/CNPJ (excluindo o próprio)
        $doc = preg_replace('/\D/', '', $dados['cpf_cnpj'] ?? '');
        if ($doc !== '') {
            $existente = $this->repo->buscarPorCpfCnpj($doc);
            if ($existente !== null && (int) $existente['id'] !== (int) $id) {
                Flash::set('error', 'Já existe outro cliente com este CPF/CNPJ.');
                Flash::keepOld($dados);
                return Response::redirect("/clientes/{$id}/editar");
            }
        }

        $this->repo->atualizar((int) $id, $dados);
        $this->audit->registrar('clientes', (string) $id, 'UPDATE', $dados);

        // 11B-4: Verificação pós-salvamento (backend validation non-blocking)
        $duplicados = $this->repo->buscarPossiveisDuplicadosCadastro($dados, (int) $id);
        if (!empty($duplicados)) {
            Flash::set('warning', 'Cliente atualizado, mas existem ' . count($duplicados) . ' possíveis duplicados encontrados com os mesmos dados.');
        } else {
            Flash::set('success', 'Cliente atualizado com sucesso!');
        }

        return Response::redirect('/clientes/' . $id);
    }

    /**
     * POST /clientes/{id}/excluir — excluir cliente
     */
    public function excluir(Request $request, string $id): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect('/clientes');
        }

        $cliente = $this->repo->buscarPorId((int) $id);
        if ($cliente === null) {
            throw new HttpException(404, "Cliente #{$id} não encontrado.");
        }

        $ok = $this->repo->excluir((int) $id);
        if (!$ok) {
            Flash::set('error', 'Não é possível excluir: cliente possui Ordens de Serviço vinculadas.');
            return Response::redirect('/clientes/' . $id);
        }

        $this->audit->registrar('clientes', (string) $id, 'DELETE', ['nome' => $cliente['nome']]);
        Flash::set('success', 'Cliente excluído com sucesso.');
        return Response::redirect('/clientes');
    }

    /**
     * Extrai os dados do formulário do request.
     */
    private function extrairDados(Request $request): array
    {
        return [
            'nome'            => trim((string) $request->input('nome', '')),
            'nome_fantasia'   => trim((string) $request->input('nome_fantasia', '')),
            'data_nascimento' => $request->input('data_nascimento', '') ?: null,
            'telefone'        => trim((string) $request->input('telefone', '')),
            'telefone2'       => trim((string) $request->input('telefone2', '')),
            'email'           => trim((string) $request->input('email', '')),
            'cpf_cnpj'        => trim((string) $request->input('cpf_cnpj', '')),
            'rg_ie'           => trim((string) $request->input('rg_ie', '')),
            'fone'            => trim((string) $request->input('fone', '')),
            'celular'         => trim((string) $request->input('celular', '')),
            'whatsapp'        => trim((string) $request->input('whatsapp', '')),
            'endereco'        => trim((string) $request->input('endereco', '')),
            'numero'          => trim((string) $request->input('numero', '')),
            'complemento'     => trim((string) $request->input('complemento', '')),
            'bairro'          => trim((string) $request->input('bairro', '')),
            'cod_cidade'      => trim((string) $request->input('cod_cidade', '')),
            'cidade'          => trim((string) $request->input('cidade', '')),
            'uf'              => strtoupper(trim((string) $request->input('uf', ''))),
            'cep'             => trim((string) $request->input('cep', '')),
            'obs'             => trim((string) $request->input('obs', '')),
        ];
    }
}
