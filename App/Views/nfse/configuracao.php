<?php
use App\Core\View;
/**
 * @var array $certificado  Status do certificado (configurado, dias_restantes, ...)
 * @var array $fila         Stats do worker (pending, processing, done, failed)
 * @var array $homologacao  Checklist de prontidão para homologação
 * @var array $parametrizacao Diagnóstico da API oficial de parâmetros municipais
 * @var array $settings     Configuração fiscal persistida
 * @var array $env          Variáveis de ambiente relevantes
 * @var string $csrf_token
 */
$certOk = !empty($certificado['configurado']);
$diasRest = (int)($certificado['dias_restantes'] ?? 0);
$alertaCert = $certOk && $diasRest <= 30;
$homologacaoOk = !empty($homologacao['pronto']);
$paramOk = !empty($parametrizacao['ok']);
$v = static fn (string $key, string $default = ''): string => (string)($settings[$key] ?? $default);
?>
<div class="d-flex flex-column gap-4">

    <!-- Page header -->
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-header__title">
                <i class="ph ph-gear"></i> NFS-e — Configuração
            </h1>
            <p class="page-header__subtitle mb-0">Status do certificado digital, do worker e do ambiente.</p>
        </div>
        <div class="page-header__actions">
            <a href="/nfse" class="btn btn-secondary">
                <i class="ph ph-arrow-left"></i> Voltar
            </a>
        </div>
    </div>

    <div class="card shadow-sm border-top border-3 <?= $homologacaoOk ? 'border-success' : 'border-danger' ?>">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="ph ph-flask"></i> Prontidão para homologação
            <?php if ($homologacaoOk): ?>
                <span class="status-badge status-badge--success ms-auto">Pronta</span>
            <?php else: ?>
                <span class="status-badge status-badge--danger ms-auto">Bloqueada</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="d-flex flex-column gap-2">
                        <?php foreach (($homologacao['checks'] ?? []) as $check): ?>
                            <div class="d-flex align-items-start gap-2">
                                <i class="ph <?= !empty($check['ok']) ? 'ph-check-circle text-success' : 'ph-x-circle text-danger' ?>"></i>
                                <div>
                                    <div class="fw-semibold"><?= View::e($check['label'] ?? 'Item') ?></div>
                                    <div class="small text-body-secondary"><?= View::e($check['detalhe'] ?? '') ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-5">
                    <?php if (!empty($homologacao['bloqueios'])): ?>
                        <div class="alert alert-danger mb-3">
                            <strong>Bloqueios atuais:</strong>
                            <ul class="mb-0 mt-2 ps-3">
                                <?php foreach ($homologacao['bloqueios'] as $bloqueio): ?>
                                    <li><?= View::e($bloqueio) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <dl class="row mb-0">
                        <dt class="col-sm-5">Integração</dt>
                        <dd class="col-sm-7">
                            <?php if (($homologacao['modo_integracao'] ?? '') === 'real'): ?>
                                <span class="status-badge status-badge--success">Real</span>
                            <?php else: ?>
                                <span class="status-badge status-badge--warning">Simulação</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5">Worker</dt>
                        <dd class="col-sm-7 text-mono small">php scripts/worker.php</dd>

                        <dt class="col-sm-5">Log</dt>
                        <dd class="col-sm-7 text-mono small"><?= View::e($homologacao['worker_log'] ?? '—') ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-top border-3 <?= $paramOk ? 'border-success' : 'border-warning' ?>">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="ph ph-buildings"></i> Parâmetros Municipais Oficiais
            <?php if ($paramOk): ?>
                <span class="status-badge status-badge--success ms-auto">Consultado</span>
            <?php else: ?>
                <span class="status-badge status-badge--warning ms-auto">Revisar</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-4">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Município</dt>
                        <dd class="col-sm-7 text-mono"><?= View::e((string)($parametrizacao['codigo_municipio'] ?? '—')) ?></dd>

                        <dt class="col-sm-5">Serviço</dt>
                        <dd class="col-sm-7 text-mono"><?= View::e((string)($parametrizacao['codigo_servico'] ?? '—')) ?></dd>

                        <dt class="col-sm-5">Competência</dt>
                        <dd class="col-sm-7 text-mono"><?= View::e((string)($parametrizacao['competencia'] ?? '—')) ?></dd>

                        <dt class="col-sm-5">Alíquota</dt>
                        <dd class="col-sm-7 text-mono">
                            <?= isset($parametrizacao['aliquota']['aliquota']) ? View::e(number_format((float)$parametrizacao['aliquota']['aliquota'], 2, '.', '') . '%') : '—' ?>
                        </dd>

                        <dt class="col-sm-5">Incidência</dt>
                        <dd class="col-sm-7 text-mono"><?= View::e((string)($parametrizacao['aliquota']['incidencia'] ?? '—')) ?></dd>
                    </dl>
                </div>
                <div class="col-lg-4">
                    <div class="d-flex flex-column gap-2">
                        <?php foreach (($parametrizacao['checks'] ?? []) as $check): ?>
                            <div class="d-flex align-items-start gap-2">
                                <i class="ph <?= !empty($check['ok']) ? 'ph-check-circle text-success' : 'ph-warning-circle text-warning' ?>"></i>
                                <div>
                                    <div class="fw-semibold"><?= View::e((string)($check['label'] ?? 'Item')) ?></div>
                                    <div class="small text-body-secondary"><?= View::e((string)($check['detalhe'] ?? '')) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <?php if (!empty($parametrizacao['erros'])): ?>
                        <div class="alert alert-danger mb-3">
                            <strong>Erros da consulta:</strong>
                            <ul class="mb-0 mt-2 ps-3">
                                <?php foreach ($parametrizacao['erros'] as $erro): ?>
                                    <li><?= View::e((string)$erro) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($parametrizacao['alertas'])): ?>
                        <div class="alert alert-warning mb-3">
                            <strong>Pontos de atenção:</strong>
                            <ul class="mb-0 mt-2 ps-3">
                                <?php foreach ($parametrizacao['alertas'] as $alerta): ?>
                                    <li><?= View::e((string)$alerta) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <dl class="row mb-0">
                        <dt class="col-sm-7">Aderente ao ambiente</dt>
                        <dd class="col-sm-5 text-mono"><?= View::e((string)($parametrizacao['convenio']['aderente_ambiente_nacional'] ?? '—')) ?></dd>

                        <dt class="col-sm-7">Aderente ao emissor</dt>
                        <dd class="col-sm-5 text-mono"><?= View::e((string)($parametrizacao['convenio']['aderente_emissor_nacional'] ?? '—')) ?></dd>

                        <dt class="col-sm-7">Retenção municipal</dt>
                        <dd class="col-sm-5 text-mono"><?= !empty($parametrizacao['retencao']['municipal_ativa']) ? 'ativa' : 'não' ?></dd>

                        <dt class="col-sm-7">Art. 6º</dt>
                        <dd class="col-sm-5 text-mono"><?= !empty($parametrizacao['retencao']['artigo_sexto_habilitado']) ? 'habilitado' : 'não' ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="ph ph-sliders"></i> Dados fiscais da empresa
        </div>
        <div class="card-body">
            <form method="POST" action="/nfse/configuracao" class="d-flex flex-column gap-4" autocomplete="off" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

                <div class="row g-3">
                    <div class="col-12">
                        <div class="alert alert-warning mb-0">
                            <strong>Trava de segurança:</strong> nesta etapa, mantenha escrita/transmissão desabilitada.
                            Rascunhos e conferência não transmitem DPS/XML real.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Ambiente</label>
                        <select name="ambiente" class="form-select">
                            <option value="homologacao" <?= $v('ambiente', 'homologacao') === 'homologacao' ? 'selected' : '' ?>>Homologação</option>
                            <option value="producao" <?= $v('ambiente') === 'producao' ? 'selected' : '' ?>>Produção</option>
                        </select>
                    </div>

                    <?php
                    $switches = [
                        ['enabled', 'Emissão NFS-e habilitada'],
                        ['write_enabled', 'Escrita/transmissão habilitada'],
                        ['admin_only', 'Apenas admin pode emitir'],
                        ['contador_aprova_total_os', 'O contador autorizou emitir NFS-e pelo valor total consolidado da OS/orçamento, sem discriminação individual de peças?'],
                        ['exigir_conferencia_manual', 'Exigir conferência manual antes da emissão'],
                        ['danfse_enabled', 'Geração DANFSe habilitada'],
                        ['danfse_shadow_mode', 'DANFSe em shadow mode'],
                        ['danfse_admin_only', 'Apenas admin pode gerar DANFSe'],
                        ['danfse_external_download_enabled', 'Download externo de DANFSe habilitado'],
                        ['send_whatsapp_enabled', 'Envio por WhatsApp habilitado'],
                        ['send_email_enabled', 'Envio por e-mail habilitado'],
                    ];
                    ?>
                    <?php foreach ($switches as [$key, $label]): ?>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="<?= View::e($key) ?>" name="<?= View::e($key) ?>" value="1" <?= $v($key, in_array($key, ['exigir_conferencia_manual', 'danfse_shadow_mode', 'admin_only', 'danfse_admin_only'], true) ? '1' : '0') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="<?= View::e($key) ?>"><?= View::e($label) ?></label>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="real_enabled" name="real_enabled" value="1" <?= $v('real_enabled') === 'true' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="real_enabled">Habilitar emissão real da NFS-e</label>
                        </div>
                        <div class="form-text">Deixe desabilitado enquanto estiver só configurando os dados e validando homologação.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Certificado A1 (.pfx/.p12)</label>
                        <input type="file" name="certificado_pfx" accept=".pfx,.p12,application/x-pkcs12" class="form-control">
                        <div class="form-text">Envie um novo arquivo apenas quando quiser trocar o certificado atual.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Senha do certificado</label>
                        <input type="password" name="cert_password" value="" class="form-control">
                        <div class="form-text"><?= !empty($settings['cert_password']) ? 'Já existe uma senha salva. Preencha só se quiser substituir.' : 'Obrigatória para validar o certificado.' ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Arquivo atual</label>
                        <input type="text" value="<?= View::e($v('cert_path')) ?>" class="form-control text-mono" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">CNPJ do prestador</label>
                        <input type="text" name="prestador_cnpj" value="<?= View::e($v('prestador_cnpj')) ?>" class="form-control text-mono" maxlength="18">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Razão social</label>
                        <input type="text" name="prestador_razao_social" value="<?= View::e($v('prestador_razao_social')) ?>" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Inscrição municipal</label>
                        <input type="text" name="prestador_inscricao_municipal" value="<?= View::e($v('prestador_inscricao_municipal')) ?>" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Código IBGE do município</label>
                        <input type="text" name="prestador_codigo_municipio" value="<?= View::e($v('prestador_codigo_municipio')) ?>" class="form-control text-mono">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">CEP</label>
                        <input type="text" name="prestador_cep" value="<?= View::e($v('prestador_cep')) ?>" class="form-control text-mono">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Logradouro</label>
                        <input type="text" name="prestador_logradouro" value="<?= View::e($v('prestador_logradouro')) ?>" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Número</label>
                        <input type="text" name="prestador_numero" value="<?= View::e($v('prestador_numero')) ?>" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Complemento</label>
                        <input type="text" name="prestador_complemento" value="<?= View::e($v('prestador_complemento')) ?>" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Bairro</label>
                        <input type="text" name="prestador_bairro" value="<?= View::e($v('prestador_bairro')) ?>" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Telefone</label>
                        <input type="text" name="prestador_telefone" value="<?= View::e($v('prestador_telefone')) ?>" class="form-control text-mono">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="prestador_email" value="<?= View::e($v('prestador_email')) ?>" class="form-control">
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Opção simples nacional</label>
                        <select name="prestador_opcao_simples" class="form-select">
                            <option value="1" <?= $v('prestador_opcao_simples', '1') === '1' ? 'selected' : '' ?>>1 - Não optante</option>
                            <option value="2" <?= $v('prestador_opcao_simples') === '2' ? 'selected' : '' ?>>2 - Optante MEI</option>
                            <option value="3" <?= $v('prestador_opcao_simples') === '3' ? 'selected' : '' ?>>3 - Optante ME/EPP</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Regime apuração SN</label>
                        <input type="text" name="prestador_regime_apuracao_sn" value="<?= View::e($v('prestador_regime_apuracao_sn')) ?>" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Regime especial</label>
                        <input type="text" name="prestador_regime_especial" value="<?= View::e($v('prestador_regime_especial', '0')) ?>" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Série DPS</label>
                        <input type="text" name="serie_dps" value="<?= View::e($v('serie_dps', '1')) ?>" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Código trib. nacional</label>
                        <input type="text" name="codigo_trib_nacional" value="<?= View::e($v('codigo_trib_nacional')) ?>" class="form-control text-mono">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Código trib. municipal</label>
                        <input type="text" name="codigo_trib_municipal" value="<?= View::e($v('codigo_trib_municipal')) ?>" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">PIS/COFINS CST</label>
                        <input type="text" name="piscofins_cst" value="<?= View::e($v('piscofins_cst', '08')) ?>" class="form-control text-mono">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Descrição padrão do serviço</label>
                        <input type="text" name="descricao_servico_padrao" value="<?= View::e($v('descricao_servico_padrao', 'Serviço prestado')) ?>" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Endpoint homologação</label>
                        <input type="url" name="endpoint_homologacao" value="<?= View::e($v('endpoint_homologacao')) ?>" class="form-control text-mono" placeholder="Opcional">
                        <div class="form-text">Se deixar em branco, o SDK usa a SEFIN oficial. URLs de documentação com <code>/docs/index</code> são normalizadas automaticamente.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Endpoint produção</label>
                        <input type="url" name="endpoint_producao" value="<?= View::e($v('endpoint_producao')) ?>" class="form-control text-mono" placeholder="Opcional">
                        <div class="form-text">Use apenas quando houver necessidade real de sobrescrever o endpoint padrão da SEFIN.</div>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="ph ph-floppy-disk"></i> Salvar configuração fiscal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4">

        <!-- Certificado -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100 border-top border-3 <?= $certOk ? ($alertaCert ? 'border-warning' : 'border-success') : 'border-danger' ?>">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="ph ph-lock-key"></i> Certificado digital
                    <?php if ($certOk): ?>
                        <?php if ($alertaCert): ?>
                            <span class="status-badge status-badge--warning ms-auto">Expira em breve</span>
                        <?php else: ?>
                            <span class="status-badge status-badge--success ms-auto">OK</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="status-badge status-badge--danger ms-auto">Não configurado</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($certOk): ?>
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Razão social</dt>
                            <dd class="col-sm-7"><?= View::e($certificado['razao_social'] ?? '—') ?></dd>

                            <dt class="col-sm-5">Serial</dt>
                            <dd class="col-sm-7 text-mono small"><?= View::e($certificado['serial'] ?? '—') ?></dd>

                            <dt class="col-sm-5">Expira em</dt>
                            <dd class="col-sm-7 text-mono"><?= View::e($certificado['expira_em'] ?? '—') ?></dd>

                            <dt class="col-sm-5">Dias restantes</dt>
                            <dd class="col-sm-7 text-mono fw-bold <?= $alertaCert ? 'text-warning' : 'text-success' ?>">
                                <?= $diasRest ?> dia<?= $diasRest === 1 ? '' : 's' ?>
                            </dd>
                        </dl>

                        <?php if ($alertaCert): ?>
                            <div class="alert alert-warning d-flex align-items-start gap-2 mt-3 mb-0">
                                <i class="ph ph-warning"></i>
                                <span>Certificado expira em <?= $diasRest ?> dia(s). Providencie a renovação para evitar interrupção na emissão de NFS-e.</span>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-danger mb-0">
                            <i class="ph ph-x-circle"></i>
                            <strong>Certificado não disponível.</strong><br>
                            <?= View::e($certificado['erro'] ?? 'Erro desconhecido.') ?>
                        </div>
                        <p class="small text-body-secondary mt-3 mb-0">
                            Defina <code>CERT_PATH</code> e <code>CERT_PASSWORD</code> no arquivo <code>.env</code>
                            apontando para um arquivo <code>.pfx</code> válido.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Worker / fila -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <i class="ph ph-robot"></i> Worker da fila
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-6">Pendentes</dt>
                        <dd class="col-sm-6 text-mono fw-bold text-warning"><?= (int)$fila['pending'] ?></dd>

                        <dt class="col-sm-6">Em processamento</dt>
                        <dd class="col-sm-6 text-mono fw-bold text-info"><?= (int)$fila['processing'] ?></dd>

                        <dt class="col-sm-6">Concluídos</dt>
                        <dd class="col-sm-6 text-mono fw-bold text-success"><?= (int)$fila['done'] ?></dd>

                        <dt class="col-sm-6">Falhas</dt>
                        <dd class="col-sm-6 text-mono fw-bold text-danger"><?= (int)$fila['failed'] ?></dd>
                    </dl>

                    <?php if ((int)$fila['failed'] > 0): ?>
                        <div class="alert alert-warning d-flex align-items-start gap-2 mt-3 mb-0">
                            <i class="ph ph-warning"></i>
                            <span>
                                Há <?= (int)$fila['failed'] ?> job(s) com falha. Verifique <code>storage/logs/worker.log</code>
                                e/ou abra a nota correspondente em <a href="/nfse?status=rejeitada">notas rejeitadas</a>.
                            </span>
                        </div>
                    <?php endif; ?>

                    <p class="small text-body-secondary mt-3 mb-0">
                        O worker é um processo separado (<code>php scripts/worker.php</code>) que deve ficar
                        ativo via Supervisor / systemd. Sem ele, as notas ficam permanentemente como pendentes.
                    </p>
                </div>
            </div>
        </div>

        <!-- Ambiente -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <i class="ph ph-globe"></i> Ambiente
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">NFSE_AMBIENTE</dt>
                        <dd class="col-sm-7">
                            <?php if (($env['ambiente'] ?? '') === 'producao'): ?>
                                <span class="status-badge status-badge--danger">Produção</span>
                            <?php else: ?>
                                <span class="status-badge status-badge--warning">Homologação</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5">CERT_PATH</dt>
                        <dd class="col-sm-7 text-mono small"><?= !empty($env['cert_path']) ? View::e($env['cert_path']) : '<span class="text-body-secondary">não definido</span>' ?></dd>
                    </dl>
                    <p class="small text-body-secondary mt-3 mb-0">
                        A emissão em homologação só fica válida quando o transmissor real estiver ligado em
                        <code>App/Services/Fiscal/NfseService.php</code>. Enquanto o modo for
                        <strong>simulação</strong>, toda nota seguirá com número fictício.
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>
