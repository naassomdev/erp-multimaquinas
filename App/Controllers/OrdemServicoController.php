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
use App\Jobs\NotificarClienteJob;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\NecessidadeCompraRepository;
use App\Repositories\OrcamentoRepository;
use App\Repositories\OrdemServicoRepository;
use App\Repositories\UsuarioRepository;
use App\Services\AuditoriaService;
use App\Services\OrdemServicoService;
use App\Queue\DatabaseQueue;

final class OrdemServicoController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly OrdemServicoRepository    $repo           = new OrdemServicoRepository(),
        private readonly OrcamentoRepository       $orcRepo        = new OrcamentoRepository(),
        private readonly NecessidadeCompraRepository $necessidadeRepo = new NecessidadeCompraRepository(),
        private readonly AuditoriaService          $audit          = new AuditoriaService(),
    ) {}

    private function getService(): OrdemServicoService
    {
        return new OrdemServicoService(Database::pdo(), new DatabaseQueue(Database::pdo()), $this->repo);
    }

    /**
     * GET /os — lista de OS
     */
    public function index(Request $request): Response
    {
        $filtros = [
            'busca'       => trim((string) $request->input('q', '')),
            'status'      => trim((string) $request->input('status', '')),
            'data_inicio' => trim((string) $request->input('data_inicio', '')),
            'data_fim'    => trim((string) $request->input('data_fim', '')),
        ];

        $page = max(1, (int) $request->input('p', 1));
        $total = $this->repo->contar($filtros);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $totalPages) $page = $totalPages;

        $ordens = $this->repo->listar($filtros, $page, self::PER_PAGE);

        return Response::html(View::render('os/index', [
            'titulo'     => 'Ordens de Serviço',
            'activeMenu' => 'os',
            'ordens'     => $ordens,
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
     * GET /os/nova — formulário de criação
     */
    public function criar(Request $request): Response
    {
        return Response::html(View::render('os/form', [
            'titulo'           => 'Nova Ordem de Serviço',
            'activeMenu'       => 'os',
            'os'               => null,
            'equipamentos'     => [],
            'csrf_token'       => Csrf::token(),
            'modo'             => 'criar',
            'fabricantesHints' => $this->fabricantesHints(),
        ]));
    }

    /**
     * POST /os — salvar nova OS
     */
    public function salvar(Request $request): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/os/nova');
        }

        $dados = $this->extrairDados($request);
        $equipamentos = $this->extrairEquipamentos($request);

        if (trim($dados['nome_cliente']) === '') {
            Flash::set('error', 'O Nome do Cliente é obrigatório.');
            Flash::keepOld(array_merge($dados, ['equipamentos' => $equipamentos]));
            return Response::redirect('/os/nova');
        }

        if (empty($equipamentos)) {
            Flash::set('error', 'É necessário adicionar pelo menos um equipamento.');
            Flash::keepOld(array_merge($dados, ['equipamentos' => $equipamentos]));
            return Response::redirect('/os/nova');
        }

        $usuario = Auth::user();
        $dados['usuario_recebeu'] = $usuario['nome'] ?? '';
        $dados['data_entrada'] = date('Y-m-d H:i:s');

        // Fotos da recepção: $_FILES['fotos_recepcao'] vem em formato "multiple"
        // (arrays paralelos para name/type/tmp_name/error/size). O service trata.
        $fotosUploads = $_FILES['fotos_recepcao'] ?? null;

        try {
            $osId = $this->getService()->criar($dados, $equipamentos, $fotosUploads);
            $this->audit->registrar('ordem_servico', $osId, 'INSERT', $dados);

            Flash::set('success', "OS #{$osId} criada com sucesso!");
            return Response::redirect('/os/' . $osId);
        } catch (\Throwable $e) {
            Flash::set('error', 'Erro ao criar OS: ' . $e->getMessage());
            Flash::keepOld(array_merge($dados, ['equipamentos' => $equipamentos]));
            return Response::redirect('/os/nova');
        }
    }

    /**
     * GET /os/{id} — visualização detalhada
     */
    public function detalhe(Request $request, string $id): Response
    {
        $os = $this->repo->buscarPorId($id);
        if ($os === null) {
            throw new HttpException(404, "OS #{$id} não encontrada.");
        }

        $equipamentos = $this->repo->buscarEquipamentos($id);

        // Orçamentos indexados por equip_idx para exibição por equipamento
        $orcamentosRaw = $this->orcRepo->listarPorOs($id);
        $orcamentosPorEquip = [];
        foreach ($orcamentosRaw as $orc) {
            $orcamentosPorEquip[(int) $orc['equip_idx']] = $orc;
        }

        // Aceite digital do termo
        $termoService = new \App\Services\TermoService();
        $aceite = $termoService->buscarPorOsId($id);

        // Histórico de notificações de retirada
        $alertaService = new \App\Services\AlertaRetiradaService();
        $notifRetirada = $alertaService->historicoNotificacoes($id);

        // Mapa id → nome de usuários para seção "Destino físico" (uma query para todos os equipamentos)
        $uids = [];
        foreach ($equipamentos as $eq) {
            if (!empty($eq['devolucao_uid']))           $uids[] = (int) $eq['devolucao_uid'];
            if (!empty($eq['descarte_autorizado_uid'])) $uids[] = (int) $eq['descarte_autorizado_uid'];
            if (!empty($eq['descarte_executado_uid']))  $uids[] = (int) $eq['descarte_executado_uid'];
        }
        $mapaUsuarios = (new UsuarioRepository())->buscarMapaPorIds($uids);

        $resumoNecessidades = $this->necessidadeRepo->listarResumoPorOs($id);

        return Response::html(View::render('os/detalhe', [
            'titulo'              => "OS #{$id} — " . $os['nome_cliente'],
            'activeMenu'          => 'os',
            'os'                  => $os,
            'equipamentos'        => $equipamentos,
            'orcamentosPorEquip'  => $orcamentosPorEquip,
            'resumoNecessidades'  => $resumoNecessidades,
            'aceite'              => $aceite,
            'notifRetirada'       => $notifRetirada,
            'mapaUsuarios'        => $mapaUsuarios,
        ]));
    }

    /**
     * POST /os/{id}/whatsapp — envia mensagem da OS via Evolution.
     */
    public function enviarWhatsapp(Request $request, string $id): Response
    {
        $mensagem = trim((string) $request->input('mensagem', ''));
        $telefone = trim((string) $request->input('telefone', ''));

        if ($mensagem === '' || $telefone === '') {
            return Response::json(['success' => false, 'error' => 'Dados insuficientes.'], 400);
        }

        $os = $this->repo->buscarPorId($id);
        if ($os === null) {
            return Response::json(['success' => false, 'error' => 'OS não encontrada.'], 404);
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT fotos_os_json
               FROM os_equipamento
              WHERE os_id = :os_id AND ordem_idx = 0
              LIMIT 1"
        );
        $stmt->execute([':os_id' => $id]);

        $fotoUrl = '';
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false) {
            $fotos = json_decode((string) ($row['fotos_os_json'] ?? '[]'), true);
            if (is_array($fotos) && !empty($fotos[0])) {
                $fotoUrl = trim((string) $fotos[0]);
            }
        }

        try {
            (new NotificarClienteJob($pdo))->handle([
                'telefone' => $telefone,
                'mensagem' => $mensagem,
                'os_id'    => $id,
                'foto_url' => $fotoUrl,
            ]);

            return Response::json(['success' => true]);
        } catch (\Throwable $e) {
            error_log("[WA-OS] Erro ao enviar OS {$id}: " . $e->getMessage());
            return Response::json(['success' => false, 'error' => 'Erro ao enviar. Tente novamente.'], 500);
        }
    }

    /**
     * GET /os/{id}/editar — formulário de edição
     */
    public function editar(Request $request, string $id): Response
    {
        $os = $this->repo->buscarPorId($id);
        if ($os === null) {
            throw new HttpException(404, "OS #{$id} não encontrada.");
        }

        $equipamentos = $this->repo->buscarEquipamentos($id);

        return Response::html(View::render('os/form', [
            'titulo'           => "Editar OS #{$id}",
            'activeMenu'       => 'os',
            'os'               => $os,
            'equipamentos'     => $equipamentos,
            'csrf_token'       => Csrf::token(),
            'modo'             => 'editar',
            'fabricantesHints' => $this->fabricantesHints(),
        ]));
    }

    /**
     * POST /os/{id} — atualizar OS
     */
    public function atualizar(Request $request, string $id): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect("/os/{$id}/editar");
        }

        $os = $this->repo->buscarPorId($id);
        if ($os === null) {
            throw new HttpException(404, "OS #{$id} não encontrada.");
        }

        $dados = $this->extrairDados($request);
        $equipamentos = $this->extrairEquipamentos($request);

        if (trim($dados['nome_cliente']) === '') {
            Flash::set('error', 'O Nome do Cliente é obrigatório.');
            Flash::keepOld(array_merge($dados, ['equipamentos' => $equipamentos]));
            return Response::redirect("/os/{$id}/editar");
        }

        if (empty($equipamentos)) {
            Flash::set('error', 'É necessário ter pelo menos um equipamento.');
            Flash::keepOld(array_merge($dados, ['equipamentos' => $equipamentos]));
            return Response::redirect("/os/{$id}/editar");
        }

        $fotosUploads = $_FILES['fotos_recepcao'] ?? null;

        try {
            $this->getService()->atualizar($id, $dados, $equipamentos, $fotosUploads);
            $this->audit->registrar('ordem_servico', $id, 'UPDATE', $dados);

            Flash::set('success', "OS #{$id} atualizada com sucesso!");
            return Response::redirect('/os/' . $id);
        } catch (\Throwable $e) {
            Flash::set('error', 'Erro ao atualizar OS: ' . $e->getMessage());
            Flash::keepOld(array_merge($dados, ['equipamentos' => $equipamentos]));
            return Response::redirect("/os/{$id}/editar");
        }
    }

    /**
     * GET /os/{id}/imprimir — impressão da OS
     */
    public function imprimir(Request $request, string $id): Response
    {
        $os = $this->repo->buscarPorId($id);
        if ($os === null) {
            throw new HttpException(404, "OS #{$id} não encontrada.");
        }

        $equipamentos = $this->repo->buscarEquipamentos($id);

        // Termo de responsabilidade (texto editável)
        $tplService = new \App\Services\TemplateService();
        $termoTexto = $tplService->render('termo_responsabilidade', []);

        // Aceite digital
        $termoService = new \App\Services\TermoService();
        $aceite = $termoService->buscarPorOsId($id);

        // Dados da empresa para cabeçalho da OS
        $configRepo = new ConfiguracaoRepository();
        $cfgEmp     = $configRepo->listarPorPrefixo('empresa_');
        $cfgNfse    = $configRepo->listarPorPrefixo('nfse_prestador_');

        $empEndereco = $cfgEmp['empresa_endereco'] ?? '';
        if ($empEndereco === '') {
            $logradouro  = trim((string) ($cfgNfse['nfse_prestador_logradouro'] ?? ''));
            $numero      = trim((string) ($cfgNfse['nfse_prestador_numero'] ?? ''));
            $empEndereco = $logradouro . ($numero !== '' ? ', ' . $numero : '');
        }

        $empresa = [
            'nome'     => $cfgEmp['empresa_nome']     ?? 'Multimáquinas Assistência Técnica',
            'cnpj'     => $cfgEmp['empresa_cnpj']     ?: ($cfgNfse['nfse_prestador_cnpj'] ?? ''),
            'endereco' => $empEndereco,
            'bairro'   => $cfgEmp['empresa_bairro']   ?? ($cfgNfse['nfse_prestador_bairro'] ?? ''),
            'cidade'   => $cfgEmp['empresa_cidade']   ?? '',
            'uf'       => $cfgEmp['empresa_uf']       ?? '',
            'cep'      => $cfgEmp['empresa_cep']      ?: ($cfgNfse['nfse_prestador_cep'] ?? ''),
            'telefone' => $cfgEmp['empresa_telefone'] ?: ($cfgNfse['nfse_prestador_telefone'] ?? ''),
            'email'    => $cfgEmp['empresa_email']    ?? ($cfgNfse['nfse_prestador_email'] ?? ''),
        ];

        $logoExiste = is_file(BASE_PATH . '/public/img/logo.png');

        return Response::html(View::render('os/imprimir', [
            'titulo'       => "Imprimir OS #{$id}",
            'os'           => $os,
            'equipamentos' => $equipamentos,
            'termoTexto'   => $termoTexto,
            'aceite'       => $aceite,
            'empresa'      => $empresa,
            'logoExiste'   => $logoExiste,
        ]));
    }

    private function extrairDados(Request $request): array
    {
        return [
            'cliente_id'       => $request->input('cliente_id') ? (int) $request->input('cliente_id') : null,
            'nome_cliente'     => trim((string) $request->input('nome_cliente', '')),
            'telefone'         => trim((string) $request->input('telefone', '')),
            'doc_cliente'      => trim((string) $request->input('doc_cliente', '')),
            'contato_nome'     => trim((string) $request->input('contato_nome', '')),
            'contato_telefone' => trim((string) $request->input('contato_telefone', '')),
        ];
    }

    private function extrairEquipamentos(Request $request): array
    {
        $equipamentosData = $request->input('equipamentos');
        if (!is_array($equipamentosData)) {
            return [];
        }

        $equipamentos = [];
        foreach ($equipamentosData as $eq) {
            if (empty(trim((string)($eq['nome'] ?? '')))) continue;

            $equipamentos[] = [
                'nome'          => trim((string) ($eq['nome'] ?? '')),
                'fabricante'    => mb_strtoupper(trim((string) ($eq['fabricante'] ?? '')), 'UTF-8'),
                'modelo'        => mb_strtoupper(trim((string) ($eq['modelo'] ?? '')), 'UTF-8'),
                'serie'         => trim((string) ($eq['serie'] ?? '')),
                'defeito'       => trim((string) ($eq['defeito'] ?? '')),
                'voltagem'      => trim((string) ($eq['voltagem'] ?? '')),
                'cx'            => trim((string) ($eq['cx'] ?? '')),
                'em_garantia'   => isset($eq['em_garantia']) && $eq['em_garantia'] == '1' ? 1 : 0,
                'tipo_garantia' => in_array($eq['tipo_garantia'] ?? '', ['loja', 'fabricante']) ? $eq['tipo_garantia'] : null,
            ];
        }

        return $equipamentos;
    }

    /** @return array<int, string> lista de fabricantes já usados para autocomplete */
    private function fabricantesHints(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT DISTINCT fabricante
               FROM os_equipamento
              WHERE fabricante <> ''
              ORDER BY fabricante
              LIMIT 50"
        );
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
