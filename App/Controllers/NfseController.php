<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\NfseRepository;
use App\Services\AuditoriaService;
use App\Services\NfseDraftService;
use App\Services\NfseFiscalJobService;
use App\Services\NfseIntegrationService;
use App\Services\NfseSettingsService;

final class NfseController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly NfseRepository         $repo    = new NfseRepository(),
        private readonly NfseIntegrationService $service = new NfseIntegrationService(),
        private readonly NfseSettingsService    $settings = new NfseSettingsService(),
        private readonly NfseDraftService       $drafts = new NfseDraftService(),
        private readonly NfseFiscalJobService   $fiscalJobs = new NfseFiscalJobService(),
        private readonly AuditoriaService       $audit   = new AuditoriaService(),
    ) {}

    /**
     * GET /nfse — listagem de notas fiscais com filtros + cards de resumo.
     */
    public function index(Request $request): Response
    {
        $filtros = [
            'busca'  => trim((string)$request->input('q', '')),
            'status' => trim((string)$request->input('status', '')),
            'de'     => trim((string)$request->input('de', '')),
            'ate'    => trim((string)$request->input('ate', '')),
        ];

        $page  = max(1, (int)$request->input('p', 1));
        $total = $this->repo->contar($filtros);
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        if ($page > $totalPages) $page = $totalPages;

        $notas      = $this->repo->listar($filtros, $page, self::PER_PAGE);
        $resumo     = $this->repo->totaisPorStatus();
        $statusFila = $this->service->statusFila();

        return Response::html(View::render('nfse/index', [
            'titulo'     => 'NFS-e — Notas Fiscais de Serviço',
            'activeMenu' => 'nfse',
            'notas'      => $notas,
            'filtros'    => $filtros,
            'resumo'     => $resumo,
            'statusFila' => $statusFila,
            'settings'   => $this->settings->obter(),
            'paginacao'  => [
                'page'        => $page,
                'per_page'    => self::PER_PAGE,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ]));
    }

    /**
     * GET /nfse/{id} — detalhe da nota.
     */
    public function visualizar(Request $request, string $id): Response
    {
        $nota = $this->repo->buscarPorId((int)$id);
        if ($nota === null) {
            throw new HttpException(404, "Nota fiscal #{$id} não encontrada.");
        }

        return Response::html(View::render('nfse/detalhe', [
            'titulo'     => "NFS-e #{$id}",
            'activeMenu' => 'nfse',
            'nota'       => $nota,
            'settings'   => $this->settings->obter(),
            'identificadores' => $this->service->identificadoresNota((int)$id),
            'csrf_token' => Csrf::token(),
        ]));
    }

    public function novoRascunho(Request $request): Response
    {
        $osId = trim((string)$request->input('os_id', ''));
        $orcamentoId = (int)$request->input('orcamento_id', 0);
        $preview = null;

        if ($osId !== '') {
            try {
                $preview = $this->drafts->preparar($osId, $orcamentoId > 0 ? $orcamentoId : null);
            } catch (\Throwable $e) {
                Flash::set('error', $e->getMessage());
            }
        }

        return Response::html(View::render('nfse/rascunho', [
            'titulo' => 'NFS-e — Novo rascunho',
            'activeMenu' => 'nfse',
            'settings' => $this->settings->obter(),
            'preview' => $preview,
            'os_id' => $osId,
            'orcamento_id' => $orcamentoId > 0 ? $orcamentoId : '',
            'csrf_token' => Csrf::token(),
        ]));
    }

    public function criarRascunho(Request $request): Response
    {
        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/nfse/rascunho');
        }

        $osId = trim((string)$request->input('os_id', ''));
        $orcamentoId = (int)$request->input('orcamento_id', 0);
        $descricao = (string)$request->input('descricao_servico', '');

        try {
            $notaId = $this->drafts->criar($osId, $orcamentoId > 0 ? $orcamentoId : null, Auth::id() ?? 0, $descricao);
            $this->audit->registrar('notas_fiscais', (string)$notaId, 'NFSE_RASCUNHO_CRIAR', [
                'os_id' => $osId,
                'orcamento_id' => $orcamentoId > 0 ? $orcamentoId : null,
                'shadow' => true,
            ]);
            Flash::set('success', "Rascunho NFS-e #{$notaId} criado. Revise a conferência antes de qualquer transmissão.");
            return Response::redirect("/nfse/{$notaId}/conferencia");
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
            $qs = http_build_query(array_filter(['os_id' => $osId, 'orcamento_id' => $orcamentoId ?: null]));
            return Response::redirect('/nfse/rascunho' . ($qs ? '?' . $qs : ''));
        }
    }

    public function conferencia(Request $request, string $id): Response
    {
        $nota = $this->repo->buscarPorId((int)$id);
        if ($nota === null) {
            throw new HttpException(404, "Nota fiscal #{$id} não encontrada.");
        }

        return Response::html(View::render('nfse/conferencia', [
            'titulo' => "Conferência NFS-e #{$id}",
            'activeMenu' => 'nfse',
            'nota' => $nota,
            'settings' => $this->settings->obter(),
            'csrf_token' => Csrf::token(),
        ]));
    }

    public function salvarConferencia(Request $request, string $id): Response
    {
        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect("/nfse/{$id}/conferencia");
        }

        try {
            $this->drafts->salvarConferencia((int)$id, (string)$request->input('descricao_servico', ''), Auth::id() ?? 0);
            $this->audit->registrar('notas_fiscais', $id, 'NFSE_CONFERENCIA_SALVAR', ['shadow' => true]);
            Flash::set('success', 'Conferência salva. Transmissão permanece bloqueada enquanto a escrita estiver desabilitada.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        return Response::redirect("/nfse/{$id}/conferencia");
    }

    public function jobsFiscais(Request $request): Response
    {
        return Response::html(View::render('nfse/jobs_fiscais', [
            'titulo' => 'NFS-e — Jobs fiscais',
            'activeMenu' => 'nfse',
            'jobs' => $this->fiscalJobs->listarEmitirNfse(),
            'resumo' => $this->fiscalJobs->resumo(),
            'csrf_token' => Csrf::token(),
        ]));
    }

    public function arquivarJobFiscal(Request $request, string $id): Response
    {
        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/nfse/jobs-fiscais');
        }

        try {
            $this->fiscalJobs->arquivar((int)$id, (string)$request->input('motivo', ''), Auth::id() ?? 0);
            Flash::set('success', 'Job fiscal arquivado com segurança. Nenhuma transmissão fiscal foi realizada.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        return Response::redirect('/nfse/jobs-fiscais');
    }

    /**
     * POST /nfse/{id}/reemitir — reenfileira a emissão.
     */
    public function reemitir(Request $request, string $id): Response
    {
        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect("/nfse/{$id}");
        }

        try {
            $jobId = $this->service->reemitir((int)$id, Auth::id() ?? 0);
            $this->audit->registrar('notas_fiscais', $id, 'REEMITIR', ['job_id' => $jobId]);
            Flash::set('success', "Nota #{$id} reenfileirada para emissão (job #{$jobId}).");
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        return Response::redirect("/nfse/{$id}");
    }

    /**
     * POST /nfse/{id}/cancelar — cancela nota autorizada.
     */
    public function cancelar(Request $request, string $id): Response
    {
        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect("/nfse/{$id}");
        }

        $motivo = trim((string)$request->input('motivo', ''));

        try {
            $resultado = $this->service->cancelar((int)$id, $motivo);
            $this->audit->registrar('notas_fiscais', $id, 'CANCELAR', [
                'motivo'   => $motivo,
                'simulado' => !empty($resultado['simulado']),
            ]);
            Flash::set('success', "Nota #{$id} cancelada com sucesso.");
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        return Response::redirect("/nfse/{$id}");
    }

    /**
     * POST /nfse/{id}/sincronizar — consulta SEFIN e atualiza a nota local.
     */
    public function sincronizar(Request $request, string $id): Response
    {
        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada.');
            return Response::redirect("/nfse/{$id}");
        }

        try {
            $resultado = $this->service->sincronizar((int)$id);
            if (!empty($resultado['chave_acesso'])) {
                Flash::set(
                    'success',
                    "Sincronização concluída. Chave de acesso: {$resultado['chave_acesso']}."
                );
            } elseif (!empty($resultado['encontrada'])) {
                Flash::set('success', 'DPS encontrada na SEFIN, mas a chave de acesso ainda não foi disponibilizada.');
            } else {
                Flash::set('error', 'A DPS desta nota ainda não foi localizada na SEFIN.');
            }
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        return Response::redirect("/nfse/{$id}");
    }

    /**
     * GET /nfse/{id}/xml — devolve o XML de retorno (download).
     */
    public function baixarXml(Request $request, string $id): Response
    {
        $nota = $this->repo->buscarPorId((int)$id);
        if ($nota === null) {
            throw new HttpException(404, "Nota fiscal #{$id} não encontrada.");
        }
        $xml = (string)($nota['xml_retorno'] ?? '');
        if ($xml === '') {
            Flash::set('error', 'Esta nota não possui XML de retorno (provavelmente foi gerada em modo simulação).');
            return Response::redirect("/nfse/{$id}");
        }

        $filename = sprintf('nfse-%s-%s.xml', $id, $nota['numero'] ?: 'sem-numero');
        return new Response($xml, 200, [
            'Content-Type'        => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * GET /nfse/{id}/danfse — baixa o PDF oficial do DANFSE.
     */
    public function baixarDanfse(Request $request, string $id): Response
    {
        try {
            $resultado = $this->service->baixarDanfse((int)$id);
            $filename = sprintf('danfse-%s-%s.pdf', $id, $resultado['chave_acesso']);

            return new Response((string)$resultado['pdf'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
            return Response::redirect("/nfse/{$id}");
        }
    }

    /**
     * GET /nfse/configuracao — status do certificado e do worker.
     */
    public function configuracao(Request $request): Response
    {
        $parametrizacao = $this->service->statusParametrizacaoMunicipal();

        $settings = $this->settings->obter();

        return Response::html(View::render('nfse/configuracao', [
            'titulo'      => 'NFS-e — Configuração',
            'activeMenu'  => 'nfse',
            'certificado' => $this->service->statusCertificado(),
            'fila'        => $this->service->statusFila(),
            'homologacao' => $this->service->statusHomologacao($parametrizacao),
            'parametrizacao' => $parametrizacao,
            'settings'    => $settings,
            'csrf_token'  => Csrf::token(),
            'env'         => [
                'ambiente'  => (string)($settings['ambiente'] ?? Env::get('NFSE_AMBIENTE', 'homologacao') ?: 'homologacao'),
                'cert_path' => (string)(($settings['cert_path'] ?? Env::get('CERT_PATH', '')) ?: ''),
            ],
        ]));
    }

    /**
     * POST /nfse/configuracao — salva a configuração fiscal da empresa.
     */
    public function salvarConfiguracao(Request $request): Response
    {
        if (!Csrf::check((string)$request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/nfse/configuracao');
        }

        $current = $this->settings->obter();
        $certPath = $current['cert_path'] ?? '';
        $certPassword = trim((string)$request->input('cert_password', ''));
        $upload = $request->file('certificado_pfx');

        if ($upload !== null && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
                Flash::set('error', 'Falha no upload do certificado.');
                return Response::redirect('/nfse/configuracao');
            }

            $original = (string)($upload['name'] ?? 'certificado.pfx');
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if ($ext !== 'pfx' && $ext !== 'p12') {
                Flash::set('error', 'Envie um certificado nos formatos .pfx ou .p12.');
                return Response::redirect('/nfse/configuracao');
            }

            $dir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/storage/certs';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                Flash::set('error', 'Não foi possível criar a pasta de certificados.');
                return Response::redirect('/nfse/configuracao');
            }

            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($original, PATHINFO_FILENAME)) ?: 'certificado';
            $target = $dir . '/' . $safeName . '-' . date('YmdHis') . '.' . $ext;
            if (!@move_uploaded_file((string)$upload['tmp_name'], $target)) {
                Flash::set('error', 'Não foi possível salvar o certificado enviado.');
                return Response::redirect('/nfse/configuracao');
            }

            $certPath = $target;
        }

        $payload = [
            'real_enabled' => $request->input('real_enabled'),
            'cert_path' => $certPath,
            'cert_password' => $certPassword !== '' ? $certPassword : ($current['cert_password'] ?? ''),
            'prestador_cnpj' => $request->input('prestador_cnpj', ''),
            'prestador_razao_social' => $request->input('prestador_razao_social', ''),
            'prestador_inscricao_municipal' => $request->input('prestador_inscricao_municipal', ''),
            'prestador_codigo_municipio' => $request->input('prestador_codigo_municipio', ''),
            'prestador_cep' => $request->input('prestador_cep', ''),
            'prestador_logradouro' => $request->input('prestador_logradouro', ''),
            'prestador_numero' => $request->input('prestador_numero', ''),
            'prestador_complemento' => $request->input('prestador_complemento', ''),
            'prestador_bairro' => $request->input('prestador_bairro', ''),
            'prestador_telefone' => $request->input('prestador_telefone', ''),
            'prestador_email' => $request->input('prestador_email', ''),
            'prestador_opcao_simples' => $request->input('prestador_opcao_simples', '1'),
            'prestador_regime_apuracao_sn' => $request->input('prestador_regime_apuracao_sn', ''),
            'prestador_regime_especial' => $request->input('prestador_regime_especial', '0'),
            'serie_dps' => $request->input('serie_dps', '1'),
            'codigo_trib_nacional' => $request->input('codigo_trib_nacional', ''),
            'codigo_trib_municipal' => $request->input('codigo_trib_municipal', ''),
            'descricao_servico_padrao' => $request->input('descricao_servico_padrao', ''),
            'piscofins_cst' => $request->input('piscofins_cst', '08'),
            'endpoint_homologacao' => $request->input('endpoint_homologacao', ''),
            'endpoint_producao' => $request->input('endpoint_producao', ''),
            'enabled' => $request->input('enabled'),
            'ambiente' => $request->input('ambiente', 'homologacao'),
            'write_enabled' => $request->input('write_enabled'),
            'admin_only' => $request->input('admin_only'),
            'contador_aprova_total_os' => $request->input('contador_aprova_total_os'),
            'exigir_conferencia_manual' => $request->input('exigir_conferencia_manual'),
            'danfse_enabled' => $request->input('danfse_enabled'),
            'danfse_shadow_mode' => $request->input('danfse_shadow_mode'),
            'danfse_admin_only' => $request->input('danfse_admin_only'),
            'danfse_external_download_enabled' => $request->input('danfse_external_download_enabled'),
            'send_whatsapp_enabled' => $request->input('send_whatsapp_enabled'),
            'send_email_enabled' => $request->input('send_email_enabled'),
        ];

        try {
            $antes = $this->settings->normalizar($current);
            $this->settings->salvar($payload);
            $depois = $this->settings->normalizar($payload);
            $mudancas = [];
            foreach ($depois as $key => $value) {
                $old = (string)($antes[$key] ?? '');
                if ($old !== (string)$value) {
                    $mudancas[$key] = ['antes' => $old, 'depois' => (string)$value];
                }
            }
            $this->audit->registrar('configuracoes', 'nfse', 'UPDATE', [
                'escopo' => 'nfse',
                'mudancas' => $mudancas,
            ]);
            Flash::set('success', 'Configuração fiscal da NFS-e salva com sucesso.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        return Response::redirect('/nfse/configuracao');
    }
}
