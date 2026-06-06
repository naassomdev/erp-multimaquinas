<?php
use App\Core\View;
use App\Core\Flash;
use App\Core\Csrf;
/**
 * @var ?array  $cliente
 * @var string  $csrf_token
 * @var string  $modo  ('criar' | 'editar')
 * @var array<string, string> $doc_settings
 */
$c      = $cliente;
$edit   = $modo === 'editar';
$action = $edit ? '/clientes/' . (int) $c['id'] : '/clientes';
$docSettings = $doc_settings ?? [];
$planName = (string) ($docSettings['plan_name'] ?? 'Grátis');
$planLimit = (string) ($docSettings['monthly_plan_limit'] ?? '50');

$v = function (string $campo) use ($c, $edit): string {
    $old = \App\Core\Flash::old($campo);
    if ($old !== '') return $old;
    return $edit ? (string) ($c[$campo] ?? '') : '';
};

$ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
$ufAtual = strtoupper($v('uf'));
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title"><?= $edit ? 'Editar Cliente' : 'Novo Cliente' ?></h1>
            <p class="page-header__subtitle">
                <?= $edit ? 'Atualize os dados cadastrais do cliente' : 'Preencha os dados do novo cliente' ?>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/clientes/configuracao-documentos" class="btn btn-outline-secondary">
                <i class="ph ph-gear me-1"></i> Configurar CPF/CNPJ
            </a>
            <a href="/clientes" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <?php if ($edit): ?>
                <a href="/clientes/<?= (int) $c['id'] ?>" class="btn btn-outline-secondary">
                    <i class="ph ph-eye me-1"></i> Visualizar
                </a>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="<?= $action ?>" class="d-flex flex-column gap-4" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

        <!-- Card: Dados pessoais -->
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-user-circle"></i> Dados pessoais
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Nome / Razao Social <span class="required">*</span></label>
                        <input type="text" name="nome" value="<?= View::e($v('nome')) ?>"
                               required autofocus placeholder="Nome completo ou razao social"
                               class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Nome Fantasia</label>
                        <input type="text" name="nome_fantasia" value="<?= View::e($v('nome_fantasia')) ?>"
                               placeholder="Nome fantasia (opcional)" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Data de Nascimento</label>
                        <input type="date" name="data_nascimento" value="<?= View::e($v('data_nascimento')) ?>"
                               class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">CPF / CNPJ</label>
                        <div class="d-flex gap-2">
                            <input type="text" name="cpf_cnpj" id="cpf_cnpj" value="<?= View::e($v('cpf_cnpj')) ?>"
                                   placeholder="000.000.000-00" maxlength="18"
                                   class="form-control text-mono">
                            <button type="button" id="btnBuscarDoc" title="Buscar Documento na Receita/CPFHub"
                                    class="btn btn-outline-secondary flex-shrink-0">
                                <i class="ph ph-magnifying-glass"></i>
                            </button>
                        </div>
                        <div class="form-text">
                            CPF consulta a CPFHub configurada no módulo de clientes. Referência atual: plano <?= View::e($planName) ?> com <?= View::e($planLimit) ?> consultas/mês. O CNPJ segue funcional via provedor separado.
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">RG / Inscricao Estadual</label>
                        <input type="text" name="rg_ie" value="<?= View::e($v('rg_ie')) ?>"
                               placeholder="RG ou IE" class="form-control">
                    </div>
                </div>
            </div>
        </div>

        <!-- Card: Contato -->
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-phone"></i> Contato
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Telefone Principal</label>
                        <input type="text" name="telefone" id="telefone" value="<?= View::e($v('telefone')) ?>"
                               placeholder="(00) 0000-0000" class="form-control text-mono">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telefone 2</label>
                        <input type="text" name="telefone2" value="<?= View::e($v('telefone2')) ?>"
                               placeholder="(00) 0000-0000" class="form-control text-mono">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Celular / WhatsApp</label>
                        <input type="text" name="celular" id="celular" value="<?= View::e($v('celular')) ?>"
                               placeholder="(00) 00000-0000" class="form-control text-mono">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="email" value="<?= View::e($v('email')) ?>"
                               placeholder="cliente@email.com" class="form-control">
                    </div>
                </div>
            </div>
        </div>

        <!-- Card: Endereco -->
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-map-pin"></i> Endereco
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">CEP</label>
                        <div class="d-flex gap-2">
                            <input type="text" name="cep" id="cep" value="<?= View::e($v('cep')) ?>"
                                   placeholder="00000-000" maxlength="9"
                                   class="form-control text-mono">
                            <button type="button" id="btnBuscarCep" title="Buscar CEP"
                                    class="btn btn-outline-secondary flex-shrink-0">
                                <i class="ph ph-magnifying-glass"></i>
                            </button>
                        </div>
                        <div class="form-text">Auto-preenche ao digitar 8 digitos.</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Endereco / Logradouro</label>
                        <input type="text" name="endereco" id="endereco" value="<?= View::e($v('endereco')) ?>"
                               placeholder="Rua, Avenida..." class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Numero</label>
                        <input type="text" name="numero" id="numero" value="<?= View::e($v('numero')) ?>"
                               placeholder="No" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Complemento</label>
                        <input type="text" name="complemento" id="complemento" value="<?= View::e($v('complemento')) ?>"
                               placeholder="Apto, bloco..." class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Bairro</label>
                        <input type="text" name="bairro" id="bairro" value="<?= View::e($v('bairro')) ?>"
                               placeholder="Bairro" class="form-control">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Cidade</label>
                        <input type="text" name="cidade" id="cidade" value="<?= View::e($v('cidade')) ?>"
                               placeholder="Cidade" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">UF</label>
                        <select name="uf" id="uf" class="form-select">
                            <option value="">Selecione</option>
                            <?php foreach ($ufs as $u): ?>
                                <option value="<?= $u ?>" <?= $ufAtual === $u ? 'selected' : '' ?>><?= $u ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="cod_cidade" id="cod_cidade" value="<?= View::e($v('cod_cidade')) ?>">
                </div>
            </div>
        </div>

        <!-- Card: Observacoes -->
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-note"></i> Observacoes
            </div>
            <div class="card-body">
                <label class="form-label">Observacoes internas</label>
                <textarea name="obs" rows="3" placeholder="Anotacoes sobre o cliente..."
                          class="form-control"><?= View::e($v('obs')) ?></textarea>
            </div>
        </div>

        <!-- Acoes -->
        <div class="d-flex flex-column-reverse flex-sm-row justify-content-sm-end gap-2">
            <a href="/clientes" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ph ph-<?= $edit ? 'floppy-disk' : 'check-circle' ?> me-1"></i>
                <?= $edit ? 'Salvar alteracoes' : 'Cadastrar cliente' ?>
            </button>
        </div>
    </form>
</div>

<script>
// -- Busca CEP via API
document.getElementById('btnBuscarCep')?.addEventListener('click', async () => {
    const cep = document.getElementById('cep')?.value.replace(/\D/g, '');
    if (!cep || cep.length !== 8) {
        alert('Informe um CEP valido com 8 digitos.');
        return;
    }

    const btn = document.getElementById('btnBuscarCep');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const resp = await fetch(`/api/clientes/cep/${cep}`, {
            headers: { 'Accept': 'application/json' }
        });
        const json = await resp.json();
        if (json.ok && json.data) {
            const d = json.data;
            document.getElementById('endereco').value    = d.endereco || '';
            document.getElementById('complemento').value = d.complemento || '';
            document.getElementById('bairro').value      = d.bairro || '';
            document.getElementById('cidade').value      = d.cidade || '';
            document.getElementById('uf').value          = d.uf || '';
            document.getElementById('cod_cidade').value  = d.cod_cidade || '';
            document.getElementById('numero')?.focus();
        } else {
            alert(json.error || 'CEP nao encontrado.');
        }
    } catch (e) {
        alert('Erro ao consultar CEP. Tente novamente.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph ph-magnifying-glass"></i>';
    }
});

const docInput = document.getElementById('cpf_cnpj');
const btnBuscarDoc = document.getElementById('btnBuscarDoc');
let cpfAutoLookupTimer = null;
let ultimoDocumentoConsultado = '';
let buscaDocumentoEmAndamento = false;

async function buscarDocumentoCliente({ silent = false } = {}) {
    const doc = docInput?.value.replace(/\D/g, '') || '';

    if (!doc || (doc.length !== 11 && doc.length !== 14)) {
        if (!silent) {
            alert('Informe um CPF com 11 digitos ou um CNPJ com 14 digitos para buscar.');
        }
        return;
    }

    if (!btnBuscarDoc) {
        return;
    }

    if (cpfAutoLookupTimer) {
        clearTimeout(cpfAutoLookupTimer);
        cpfAutoLookupTimer = null;
    }

    if (buscaDocumentoEmAndamento) {
        return;
    }

    buscaDocumentoEmAndamento = true;
    btnBuscarDoc.disabled = true;
    btnBuscarDoc.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const resp = await fetch(`/api/clientes/documento/${doc}`, {
            headers: { 'Accept': 'application/json' }
        });

        if (!resp.ok && resp.redirected) {
            alert('Sessão expirada. Recarregue a página e faça login novamente.');
            return;
        }

        const raw = await resp.text();
        let json = null;

        try {
            json = raw ? JSON.parse(raw) : {};
        } catch (parseError) {
            console.error('Resposta inesperada da API de documento:', raw);
            throw new Error('Resposta inválida da API');
        }

        if (json.ok && json.data) {
            const d = json.data;
            ultimoDocumentoConsultado = doc;

            if (d.tipo === 'cpf') {
                if (d.nome) document.querySelector('[name="nome"]').value = d.nome;
                if (d.data_nascimento) document.querySelector('[name="data_nascimento"]').value = d.data_nascimento;
            } else if (d.tipo === 'cnpj') {
                if (d.nome) document.querySelector('[name="nome"]').value = d.nome;
                if (d.nome_fantasia) document.querySelector('[name="nome_fantasia"]').value = d.nome_fantasia;
                if (d.telefone) document.getElementById('telefone').value = d.telefone;
                if (d.email) document.querySelector('[name="email"]').value = d.email;
                if (d.endereco) document.getElementById('endereco').value = d.endereco;
                if (d.numero) document.getElementById('numero').value = d.numero;
                if (d.complemento) document.getElementById('complemento').value = d.complemento;
                if (d.bairro) document.getElementById('bairro').value = d.bairro;
                if (d.cidade) document.getElementById('cidade').value = d.cidade;
                if (d.uf) document.getElementById('uf').value = d.uf;

                if (d.cep) {
                    const cepInput = document.getElementById('cep');
                    if (cepInput) {
                        cepInput.value = d.cep;
                        cepInput.dispatchEvent(new Event('input'));
                    }
                }
            }
        } else {
            alert(json.error || 'Documento nao encontrado.');
        }
    } catch (e) {
        console.error('Erro busca documento:', e);
        alert('Erro ao consultar o documento. Verifique sua conexao e tente novamente.');
    } finally {
        buscaDocumentoEmAndamento = false;
        btnBuscarDoc.disabled = false;
        btnBuscarDoc.innerHTML = '<i class="ph ph-magnifying-glass"></i>';
    }
}

btnBuscarDoc?.addEventListener('click', () => buscarDocumentoCliente());

// -- Mascara simples de CPF/CNPJ
document.getElementById('cpf_cnpj')?.addEventListener('input', function () {
    let v = this.value.replace(/\D/g, '');
    if (v.length <= 11) {
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    } else {
        v = v.substring(0, 14);
        v = v.replace(/^(\d{2})(\d)/, '$1.$2');
        v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
        v = v.replace(/\.(\d{3})(\d)/, '.$1/$2');
        v = v.replace(/(\d{4})(\d)/, '$1-$2');
    }
    this.value = v;

    const digits = v.replace(/\D/g, '');
    if (digits !== ultimoDocumentoConsultado) {
        ultimoDocumentoConsultado = '';
    }

    if (cpfAutoLookupTimer) {
        clearTimeout(cpfAutoLookupTimer);
    }

    if (digits.length === 11 && digits !== ultimoDocumentoConsultado) {
        cpfAutoLookupTimer = setTimeout(() => {
            if ((docInput?.value.replace(/\D/g, '') || '') === digits) {
                buscarDocumentoCliente({ silent: true });
            }
        }, 500);
    }
});

// -- Mascara de telefone
function maskPhone(el) {
    el?.addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '');
        if (v.length > 11) v = v.substring(0, 11);
        if (v.length > 10) {
            v = v.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
        } else if (v.length > 6) {
            v = v.replace(/^(\d{2})(\d{4})(\d{0,4})$/, '($1) $2-$3');
        } else if (v.length > 2) {
            v = v.replace(/^(\d{2})(\d{0,5})$/, '($1) $2');
        }
        this.value = v;
    });
}
maskPhone(document.getElementById('telefone'));
maskPhone(document.getElementById('celular'));

// -- Auto-busca CEP ao digitar 8 digitos
document.getElementById('cep')?.addEventListener('input', function () {
    let v = this.value.replace(/\D/g, '');
    if (v.length > 8) v = v.substring(0, 8);
    if (v.length > 5) v = v.replace(/^(\d{5})(\d)/, '$1-$2');
    this.value = v;
    if (v.replace('-', '').length === 8) {
        document.getElementById('btnBuscarCep')?.click();
    }
});
</script>
