<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Queue\DatabaseQueue;
use App\Repositories\NfseRepository;
use App\Services\Fiscal\CertificateManager;
use App\Services\Fiscal\NfseService;
use DomainException;
use PDO;
use Throwable;

/**
 * Orquestra as operações de NFS-e disparadas pela UI:
 *   - Reenfileirar emissão (manual, quando o job falhou ou o operador quer reprocessar).
 *   - Cancelar nota autorizada (chama provedor + grava status local).
 *   - Status do certificado digital.
 *
 * O fluxo "automático" (concluir OS → enfileirar → worker emite) continua via
 * OrdemServicoService + EmitirNfseJob; aqui só tratamos as ações manuais.
 */
final class NfseIntegrationService
{
    public function __construct(
        private readonly NfseRepository $repo  = new NfseRepository(),
        private readonly NfseSettingsService $settings = new NfseSettingsService(),
        private readonly ?PDO           $pdo   = null,
    ) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    /**
     * Reenfileira a emissão de uma nota que está pendente ou rejeitada.
     * Devolve o ID do job inserido na fila.
     */
    public function reemitir(int $notaId, int $operadorId): int
    {
        $nota = $this->repo->buscarPorId($notaId);
        if ($nota === null) {
            throw new DomainException("Nota fiscal #{$notaId} não encontrada.");
        }
        $settings = $this->settings->obter();
        if (!$this->settings->canRunFiscalWorker($settings)) {
            $this->bloquearAcaoFiscal('reemitir_nfse', $notaId, 'Worker fiscal desabilitado por configuração.', $settings);
        }
        if ($nota['status'] === 'autorizada') {
            throw new DomainException("Nota #{$notaId} já está autorizada — não há o que reemitir.");
        }
        if ($nota['status'] === 'cancelada') {
            throw new DomainException("Nota #{$notaId} foi cancelada e não pode ser reemitida.");
        }

        $this->repo->atualizarStatus($notaId, 'pendente');

        $queue = new DatabaseQueue($this->pdo());
        return $queue->enqueue('emitir_nfse', [
            'nota_id'     => $notaId,
            'os_id'       => $nota['os_id'],
            'operador_id' => $operadorId,
            'origem'      => 'manual',
        ]);
    }

    /**
     * Cancela uma NFS-e autorizada. Grava o status local mesmo se o provedor
     * falhar (em modo simulação), mas relança a exceção pra UI saber.
     */
    public function cancelar(int $notaId, string $motivo, ?CertificateManager $cert = null): array
    {
        $nota = $this->repo->buscarPorId($notaId);
        if ($nota === null) {
            throw new DomainException("Nota fiscal #{$notaId} não encontrada.");
        }
        $settings = $this->settings->obter();
        if (!$this->settings->canCancelReal($settings)) {
            $this->bloquearAcaoFiscal('cancelar_nfse', $notaId, 'Cancelamento real desabilitado por configuração.', $settings);
        }
        if ($nota['status'] !== 'autorizada') {
            throw new DomainException(
                "Apenas notas autorizadas podem ser canceladas. Status atual: {$nota['status']}."
            );
        }
        if (trim($motivo) === '') {
            throw new DomainException('Motivo do cancelamento é obrigatório.');
        }

        $service = new NfseService($cert ?? $this->makeCertificateManager());
        $identificadores = $this->resolverIdentificadores($nota, $service, true);
        $chaveAcesso = $identificadores['chave_acesso'] ?? '';
        if ($chaveAcesso === '') {
            throw new DomainException('Não foi possível localizar a chave de acesso desta NFS-e para cancelamento oficial.');
        }

        $resultado = $service->cancelar($chaveAcesso, $motivo);

        $this->pdo()->beginTransaction();
        try {
            $this->repo->atualizarStatus($notaId, 'cancelada');

            // Reflete o cancelamento no lançamento financeiro vinculado.
            if (!empty($nota['lancamento_id'])) {
                $this->pdo()->prepare(
                    "UPDATE lancamentos_receber
                     SET status = 'cancelado'
                     WHERE id = ? AND status = 'aberto'
                     LIMIT 1"
                )->execute([(int)$nota['lancamento_id']]);
            }

            $this->pdo()->commit();
        } catch (Throwable $e) {
            if ($this->pdo()->inTransaction()) $this->pdo()->rollBack();
            throw $e;
        }

        return $resultado;
    }

    /**
     * Consulta a SEFIN para descobrir chave de acesso / XML oficial e
     * sincroniza a nota local.
     *
     * @return array{status:string,id_dps:?string,chave_acesso:?string,numero:?string,xml:?string,encontrada:bool}
     */
    public function sincronizar(int $notaId, ?CertificateManager $cert = null): array
    {
        $nota = $this->repo->buscarPorId($notaId);
        if ($nota === null) {
            throw new DomainException("Nota fiscal #{$notaId} não encontrada.");
        }
        $settings = $this->settings->obter();
        if (!$this->settings->canTransmitReal($settings)) {
            $this->bloquearAcaoFiscal('sincronizar_nfse', $notaId, 'Sincronização externa desabilitada por configuração.', $settings);
        }

        $service = new NfseService($cert ?? $this->makeCertificateManager());
        $identificadores = $this->resolverIdentificadores($nota, $service, true);
        $idDps = $identificadores['id_dps'] ?: $service->gerarIdDps((int)$nota['id']);
        $chaveAcesso = $identificadores['chave_acesso'] ?? '';

        if ($chaveAcesso === '') {
            $dps = $service->consultarDps($idDps);
            if ($dps === null) {
                $encontrada = $service->verificarDps($idDps);
                return [
                    'status' => (string)$nota['status'],
                    'id_dps' => $idDps,
                    'chave_acesso' => null,
                    'numero' => $nota['numero'] ?: null,
                    'xml' => $nota['xml_retorno'] ?: null,
                    'encontrada' => $encontrada,
                ];
            }

            $chaveAcesso = (string)($dps['chave_acesso'] ?? '');
        }

        if ($chaveAcesso === '') {
            return [
                'status' => (string)$nota['status'],
                'id_dps' => $idDps,
                'chave_acesso' => null,
                'numero' => $nota['numero'] ?: null,
                'xml' => $nota['xml_retorno'] ?: null,
                'encontrada' => true,
            ];
        }

        $consulta = $service->consultarNfse($chaveAcesso);
        if ($consulta === null) {
            throw new DomainException('A SEFIN localizou a DPS, mas não devolveu a NFS-e pela chave de acesso.');
        }

        $this->persistirConsulta($notaId, $consulta);

        return [
            'status' => 'autorizada',
            'id_dps' => $consulta['id_dps'] ?? $idDps,
            'chave_acesso' => $consulta['chave_acesso'] ?? $chaveAcesso,
            'numero' => $consulta['numero'] ?? null,
            'xml' => $consulta['xml'] ?? null,
            'encontrada' => true,
        ];
    }

    public function baixarDanfse(int $notaId, ?CertificateManager $cert = null): array
    {
        $settings = $this->settings->obter();
        $nota = $this->repo->buscarPorId($notaId);
        if ($nota === null) {
            throw new DomainException("Nota fiscal #{$notaId} não encontrada.");
        }
        if (!$this->settings->canDownloadExternalDanfse($settings)) {
            $this->bloquearAcaoFiscal(
                'baixar_danfse_externo',
                $notaId,
                'Download externo de DANFSe está desabilitado. O sistema está preparado para geração própria futura conforme configuração fiscal.',
                $settings
            );
        }

        $service = new NfseService($cert ?? $this->makeCertificateManager());
        $identificadores = $this->resolverIdentificadores($nota, $service, true);
        $chaveAcesso = $identificadores['chave_acesso'] ?? '';
        if ($chaveAcesso === '') {
            throw new DomainException('Não foi possível localizar a chave de acesso desta NFS-e para baixar o DANFSE.');
        }

        return [
            'chave_acesso' => $chaveAcesso,
            'pdf' => $service->baixarDanfse($chaveAcesso),
        ];
    }

    /**
     * Identificadores úteis para UI / sincronização.
     *
     * @return array{id_dps:?string,chave_acesso:?string}
     */
    public function identificadoresNota(int $notaId): array
    {
        $nota = $this->repo->buscarPorId($notaId);
        if ($nota === null) {
            throw new DomainException("Nota fiscal #{$notaId} não encontrada.");
        }

        return $this->resolverIdentificadores($nota);
    }

    /**
     * Status do certificado configurado em CERT_PATH/CERT_PASSWORD.
     * Retorna ['configurado'=>false, ...] quando não há certificado — útil
     * pra mostrar um aviso na tela de configuração sem quebrar.
     */
    public function statusCertificado(): array
    {
        $settings = $this->settings->obter();
        $certPath = trim((string)($settings['cert_path'] ?? ''));
        $certPass = (string)($settings['cert_password'] ?? '');

        if ($certPath === '') {
            return [
                'configurado' => false,
                'erro'        => 'Certificado não configurado na tela de NFS-e.',
            ];
        }
        if (!is_readable($certPath)) {
            return [
                'configurado' => false,
                'erro'        => "Arquivo do certificado não acessível: {$certPath}",
            ];
        }

        try {
            $cert = new CertificateManager($certPath, $certPass);
            $info = $cert->validarValidade();
            return ['configurado' => true] + $info;
        } catch (Throwable $e) {
            return [
                'configurado' => false,
                'erro'        => $e->getMessage(),
            ];
        }
    }

    /**
     * Status do worker da fila (jobs pendentes/processing/falhos do tipo emitir_nfse).
     */
    public function statusFila(): array
    {
        $st = $this->pdo()->prepare(
            "SELECT status, COUNT(*) AS total
             FROM jobs
             WHERE tipo = 'emitir_nfse'
             GROUP BY status"
        );
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_merge(['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0], $rows);
    }

    /**
     * Diagnóstico dos parâmetros municipais oficiais usados pela emissão nacional.
     *
     * @return array<string,mixed>
     */
    public function statusParametrizacaoMunicipal(): array
    {
        $settings = $this->settings->obter();
        $codigoMunicipio = trim((string)($settings['prestador_codigo_municipio'] ?? ''));
        $codigoServico = trim((string)($settings['codigo_trib_nacional'] ?? ''));
        $certificado = $this->statusCertificado();

        if ($codigoMunicipio === '') {
            return [
                'ok' => false,
                'codigo_municipio' => '',
                'codigo_servico' => $codigoServico,
                'competencia' => date('Y-m-d'),
                'checks' => [],
                'alertas' => [],
                'erros' => ['Código IBGE do município do prestador não configurado.'],
            ];
        }

        if (empty($certificado['configurado'])) {
            return [
                'ok' => false,
                'codigo_municipio' => $codigoMunicipio,
                'codigo_servico' => $codigoServico,
                'competencia' => date('Y-m-d'),
                'checks' => [],
                'alertas' => [],
                'erros' => [
                    'Consulta oficial não executada porque o certificado digital não pôde ser carregado.',
                    (string)($certificado['erro'] ?? 'Certificado indisponível.'),
                ],
            ];
        }

        try {
            return (new NfseService($this->makeCertificateManager()))
                ->diagnosticarParametrosMunicipais($codigoServico !== '' ? $codigoServico : null);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'codigo_municipio' => $codigoMunicipio,
                'codigo_servico' => $codigoServico,
                'competencia' => date('Y-m-d'),
                'checks' => [],
                'alertas' => [],
                'erros' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Diagnóstico objetivo da prontidão para homologação.
     */
    public function statusHomologacao(?array $parametrizacao = null): array
    {
        $settings          = $this->settings->obter();
        $ambiente          = (string)($settings['ambiente'] ?? Env::get('NFSE_AMBIENTE', 'homologacao') ?: 'homologacao');
        $certPath          = trim((string)($settings['cert_path'] ?? ''));
        $certPasswordSet   = trim((string)($settings['cert_password'] ?? '')) !== '';
        $certificado       = $this->statusCertificado();
        $parametrizacao    = $parametrizacao ?? $this->statusParametrizacaoMunicipal();
        $basePath          = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $workerScript      = $basePath . '/scripts/worker.php';
        $workerLog         = $basePath . '/storage/logs/worker.log';
        $emissaoReal       = NfseService::supportsRealEmission();
        $modoIntegracao    = NfseService::integrationMode();
        $endpointHomolog = $this->endpointCheck('homologacao', (string)($settings['endpoint_homologacao'] ?? ''));
        $endpointProd = $this->endpointCheck('producao', (string)($settings['endpoint_producao'] ?? ''));

        $checks = [
            [
                'label'   => 'Modo real habilitado',
                'ok'      => filter_var((string)($settings['real_enabled'] ?? '0'), FILTER_VALIDATE_BOOLEAN),
                'detalhe' => 'NFSE_REAL_ENABLED=' . ((string)($settings['real_enabled'] ?? '0')),
            ],
            [
                'label'   => 'Ambiente definido como homologacao',
                'ok'      => $ambiente === 'homologacao',
                'detalhe' => "Valor atual: {$ambiente}",
            ],
            [
                'label'   => 'CERT_PATH configurado',
                'ok'      => $certPath !== '',
                'detalhe' => $certPath !== '' ? $certPath : 'Defina CERT_PATH no .env',
            ],
            [
                'label'   => 'CERT_PASSWORD configurado',
                'ok'      => $certPasswordSet,
                'detalhe' => $certPasswordSet ? 'Senha informada no .env' : 'Defina CERT_PASSWORD no .env',
            ],
            [
                'label'   => 'Certificado legivel e valido',
                'ok'      => !empty($certificado['configurado']),
                'detalhe' => !empty($certificado['configurado'])
                    ? 'Certificado carregado com sucesso'
                    : (string)($certificado['erro'] ?? 'Certificado indisponivel'),
            ],
            [
                'label'   => 'Worker disponivel no projeto principal',
                'ok'      => is_file($workerScript),
                'detalhe' => 'Comando esperado: php scripts/worker.php',
            ],
            [
                'label'   => 'SDK de emissão disponível',
                'ok'      => is_file($basePath . '/erp-nfse/vendor/autoload.php'),
                'detalhe' => 'Dependência esperada em erp-nfse/vendor',
            ],
            [
                'label'   => 'Endpoint SEFIN homologação coerente',
                'ok'      => $endpointHomolog['ok'],
                'detalhe' => $endpointHomolog['detalhe'],
            ],
            [
                'label'   => 'Endpoint SEFIN produção coerente',
                'ok'      => $endpointProd['ok'],
                'detalhe' => $endpointProd['detalhe'],
            ],
            [
                'label'   => 'Emissao real habilitada',
                'ok'      => $emissaoReal,
                'detalhe' => $emissaoReal
                    ? 'NfseService conectado ao provedor real'
                    : 'NfseService ainda retorna simulacao',
            ],
        ];

        if (($parametrizacao['codigo_servico'] ?? '') === '') {
            $checks[] = [
                'label' => 'Código de tributação nacional informado',
                'ok' => false,
                'detalhe' => 'Defina o código tributário nacional para validar alíquota e retenção oficial.',
            ];
        }

        if (!empty($parametrizacao['erros'])) {
            $checks[] = [
                'label' => 'API de Parâmetros Municipais acessível',
                'ok' => false,
                'detalhe' => implode(' | ', $parametrizacao['erros']),
            ];
        } else {
            $checks[] = [
                'label' => 'API de Parâmetros Municipais acessível',
                'ok' => !empty($parametrizacao['ok']),
                'detalhe' => 'Consulta oficial concluída para o município do prestador.',
            ];

            $aderenteAmbiente = $parametrizacao['convenio']['aderente_ambiente_nacional'] ?? null;
            $aderenteEmissor = $parametrizacao['convenio']['aderente_emissor_nacional'] ?? null;
            $aliquota = $parametrizacao['aliquota']['aliquota'] ?? null;

            $checks[] = [
                'label' => 'Município aderente ao Ambiente Nacional',
                'ok' => $aderenteAmbiente === 1,
                'detalhe' => 'Valor retornado: ' . (string)($aderenteAmbiente ?? 'não informado'),
            ];
            $checks[] = [
                'label' => 'Município aderente ao Emissor Nacional',
                'ok' => $aderenteEmissor === 1,
                'detalhe' => 'Valor retornado: ' . (string)($aderenteEmissor ?? 'não informado'),
            ];
            $checks[] = [
                'label' => 'Alíquota municipal vigente localizada',
                'ok' => is_numeric($aliquota),
                'detalhe' => is_numeric($aliquota)
                    ? 'Alíquota oficial atual: ' . number_format((float)$aliquota, 2, '.', '') . '%.'
                    : 'A API não devolveu alíquota vigente para o serviço configurado.',
            ];
        }

        $bloqueios = array_values(array_map(
            static fn (array $check): string => $check['label'] . ' — ' . $check['detalhe'],
            array_filter($checks, static fn (array $check): bool => !$check['ok'])
        ));

        return [
            'pronto'          => $bloqueios === [],
            'checks'          => $checks,
            'bloqueios'       => $bloqueios,
            'worker_script'   => $workerScript,
            'worker_log'      => $workerLog,
            'modo_integracao' => $modoIntegracao,
        ];
    }

    /**
     * @return array{ok:bool,detalhe:string}
     */
    private function endpointCheck(string $ambiente, string $endpoint): array
    {
        $normalized = NfseService::normalizeEndpoint($endpoint);
        $oficial = NfseService::officialEndpoint($ambiente);

        if ($normalized === '') {
            return [
                'ok' => true,
                'detalhe' => "Em branco; o SDK usará o endpoint oficial {$oficial}.",
            ];
        }

        if (NfseService::endpointLooksOfficial($normalized, $ambiente)) {
            return [
                'ok' => true,
                'detalhe' => "Configurado: {$normalized}",
            ];
        }

        return [
            'ok' => false,
            'detalhe' => "Configurado: {$normalized}. Esperado algo compatível com {$oficial}.",
        ];
    }

    /**
     * @param array<string,string> $settings
     */
    private function bloquearAcaoFiscal(string $acao, ?int $notaId, string $motivo, array $settings): never
    {
        (new AuditoriaService())->registrar('notas_fiscais', (string)($notaId ?? 0), 'NFSE_BLOQUEIO', [
            'acao_tentada' => $acao,
            'motivo' => $motivo,
            'flags' => $this->settings->fiscalFlagsSnapshot($settings),
        ]);

        throw new DomainException($motivo);
    }

    private function makeCertificateManager(): ?CertificateManager
    {
        $settings = $this->settings->obter();
        $certPath = trim((string)($settings['cert_path'] ?? Env::get('CERT_PATH', '') ?? ''));
        $certPass = (string)($settings['cert_password'] ?? Env::get('CERT_PASSWORD', '') ?? '');

        if ($certPath === '' || !is_readable($certPath)) {
            return null;
        }

        try {
            return new CertificateManager($certPath, $certPass);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $nota
     * @return array{id_dps:?string,chave_acesso:?string}
     */
    private function resolverIdentificadores(array $nota, ?NfseService $service = null, bool $buscarRemoto = false): array
    {
        $chaveAcesso = $this->normalizarChaveAcesso((string)($nota['protocolo'] ?? ''));
        $idDps = null;

        $xml = (string)($nota['xml_retorno'] ?? '');
        if ($xml !== '') {
            $meta = (new NfseService())->extrairMetadadosXml($xml);
            $idDps = $meta['id_dps'] ?? null;
        }

        if ($idDps === null && $service !== null) {
            try {
                $idDps = $service->gerarIdDps((int)$nota['id']);
            } catch (Throwable) {
                $idDps = null;
            }
        }

        if ($buscarRemoto && $chaveAcesso === '' && $idDps !== null && $service !== null) {
            $consulta = $service->consultarDps($idDps);
            $chaveAcesso = $this->normalizarChaveAcesso((string)($consulta['chave_acesso'] ?? ''));
        }

        return [
            'id_dps' => $idDps,
            'chave_acesso' => $chaveAcesso !== '' ? $chaveAcesso : null,
        ];
    }

    private function normalizarChaveAcesso(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?: '';
        return strlen($digits) === 50 ? $digits : '';
    }

    /**
     * @param array{chave_acesso:string,numero:?string,id_dps:?string,xml:string} $consulta
     */
    private function persistirConsulta(int $notaId, array $consulta): void
    {
        $this->pdo()->prepare(
            "UPDATE notas_fiscais
             SET status = 'autorizada',
                 numero = ?,
                 protocolo = ?,
                 xml_retorno = ?,
                 atualizado_em = NOW()
             WHERE id = ?
             LIMIT 1"
        )->execute([
            $consulta['numero'] ?? null,
            $consulta['chave_acesso'],
            $consulta['xml'],
            $notaId,
        ]);
    }
}
