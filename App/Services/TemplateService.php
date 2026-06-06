<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Renderização e persistência de templates de mensagens (WhatsApp/e-mail/sistema).
 *
 * Engine: substituição literal de {{placeholders}} por valores do array $vars
 * (str_replace simples — sem condicional, sem loops). Suficiente para os casos
 * atuais (avisos transacionais curtos). Se um dia precisar de lógica, dá pra
 * trocar por um Mustache/Twig sem alterar a API pública.
 *
 * Resiliência: se a tabela não existe, está vazia, ou o banco caiu, o serviço
 * cai automaticamente nos templates HARD-CODED em DEFAULTS — garantindo que
 * notificações nunca quebram por causa de configuração ausente.
 */
final class TemplateService
{
    /**
     * Templates de fallback (espelham EXATAMENTE o que está hoje no PHP).
     * Servem de paraquedas: se a chave não existir no banco, usamos isso.
     */
    private const DEFAULTS = [
        'os_criada' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "Olá, {{cliente_primeiro_nome}}!\n\nSua Ordem de Serviço *#{{os_id}}* foi aberta com sucesso.\nEquipamento(s) que ficaram conosco:\n{{equipamentos_lista}}\n\nVamos te avisar quando o orçamento estiver pronto. Obrigado pela confiança!",
        ],
        'os_criada_email' => [
            'canal'   => 'email',
            'assunto' => 'Sua OS #{{os_id}} foi aberta — Multimáquinas',
            'corpo'   => "Olá, {{cliente_primeiro_nome}}!\n\nSua Ordem de Serviço #{{os_id}} foi aberta com sucesso.\nEquipamento(s) que ficaram conosco:\n{{equipamentos_lista}}\n\nVamos te avisar quando o orçamento estiver pronto. Obrigado pela confiança!\n\n— Multimáquinas Assistência Técnica",
        ],
        'os_criada_com_termo' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "Olá, *{{cliente_primeiro_nome}}*!\n\nSua Ordem de Serviço *#{{os_id}}* foi registrada na Multimáquinas 🔧\n\nEquipamento(s):\n{{equipamentos_lista}}\n\n📋 *Leia e aceite nosso Termo de Responsabilidade:*\n{{link_termo}}\n\nAssim que o orçamento estiver pronto, avisaremos.\nObrigado pela confiança! — Multimáquinas",
        ],
        'pre_pedido' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "Olá, {{cliente_primeiro_nome}}!\n\nSegue seu orçamento Multimáquinas — Nº {{numero}}.\n\n• Item: {{item_descricao}}\n• Quantidade: {{item_qtd}}\n• Total: R\$ {{item_total_brl}}\n\nVisualize / imprima o orçamento completo neste link:\n{{link_publico}}\n\nTermos: orçamento válido por {{validade_dias}} dias, sujeito à disponibilidade em estoque.\nQualquer dúvida estamos à disposição.\n\n— Multimáquinas Assistência Técnica",
        ],
        'pre_pedido_email' => [
            'canal'   => 'email',
            'assunto' => 'Orçamento Multimáquinas Nº {{numero}}',
            'corpo'   => "Olá, {{cliente_primeiro_nome}}!\n\nSegue seu orçamento Multimáquinas — Nº {{numero}}.\n\n• Item: {{item_descricao}}\n• Quantidade: {{item_qtd}}\n• Total: R\$ {{item_total_brl}}\n\nVisualize / imprima o orçamento completo neste link:\n{{link_publico}}\n\nTermos: orçamento válido por {{validade_dias}} dias, sujeito à disponibilidade em estoque.\nQualquer dúvida estamos à disposição.\n\n— Multimáquinas Assistência Técnica",
        ],
        'termo_responsabilidade' => [
            'canal'   => 'sistema',
            'assunto' => null,
            'corpo'   => "TERMO DE RESPONSABILIDADE E CONDIÇÕES DE PRESTAÇÃO DE SERVIÇO\n\n1. DO ORÇAMENTO E TAXA DE DIAGNÓSTICO\n1.1. A elaboração do orçamento requer mão de obra técnica para abertura, análise e remontagem do equipamento/motor.\n1.2. Caso o cliente NÃO APROVE (cancele) o orçamento apresentado, será cobrada uma Taxa de Diagnóstico no valor de R\$ 15,00 por equipamento/motor, destinada a cobrir os custos operacionais de desmontagem e remontagem.\n1.3. Esta taxa não será cobrada em casos onde o equipamento for diagnosticado como \"sem conserto\" ou com \"perda total\".\n1.4. Caso o orçamento seja aprovado, o valor da taxa de diagnóstico é isento, sendo cobrado apenas o valor do serviço e das peças.\n\n2. DA GARANTIA DOS SERVIÇOS (Art. 26, II do CDC)\n2.1. A garantia dos serviços prestados e das peças substituídas é de 90 (noventa) dias corridos, contados a partir da data de retirada do equipamento pelo cliente.\n2.2. A garantia cobre exclusivamente a mão de obra realizada e as peças trocadas e descritas nesta Ordem de Serviço.\n\n3. DOS PRAZOS DE RETIRADA E TAXA DE ARMAZENAMENTO\n3.1. Após a notificação de que o equipamento está consertado (ou após a recusa do orçamento), o cliente tem o prazo máximo de 30 (trinta) dias corridos para realizar a retirada do bem em nossa loja.\n3.2. Para orçamentos reprovados/cancelados, o prazo de retirada é de 07 (sete) dias corridos após a notificação.\n3.3. Ultrapassados os prazos descritos acima, será cobrada uma Taxa de Armazenamento/Guarda de R\$ 2,00 por dia de atraso.\n\n4. DO ABANDONO DO EQUIPAMENTO (Art. 1.275, III do Código Civil)\n4.1. O prazo máximo legal de permanência do equipamento é de 90 (noventa) dias corridos.\n4.2. Passados os 90 dias sem retirada e quitação de débitos, o equipamento será considerado legalmente ABANDONADO.\n4.3. Caracterizado o abandono, a assistência técnica reserva-se o direito de alienar, doar, desmontar ou sucatear o equipamento.\n\n5. DAS COMUNICAÇÕES E NOTIFICAÇÕES\n5.1. O cliente concorda que todas as notificações serão feitas pelos meios de contato fornecidos neste cadastro.\n5.2. É de inteira responsabilidade do cliente manter seus dados de contato atualizados.",
        ],
        'reforco_retirada' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "Olá, *{{cliente_primeiro_nome}}*!\n\nLembramos que seu equipamento da OS *#{{os_id}}* está aguardando retirada em nossa loja há *{{dias_aguardando}} dias*.\n\nEquipamento(s):\n{{equipamentos_lista}}\n\n⚠️ Conforme nosso Termo de Responsabilidade, equipamentos não retirados em até 90 dias poderão ser considerados abandonados.\n\nPor favor, entre em contato para agendar a retirada.\n\n— Multimáquinas Assistência Técnica",
        ],
        // Mensagem do orçamento enviada pelo WhatsApp.
        'orcamento_os' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "{{saudacao}}, {{cliente_nome}}, tudo bem?\n{{remetente_nome}} aqui, da Multimáquinas.\n\nSegue o orçamento da OS *#{{os_id}}*.\n\n*Equipamento:* {{equipamento_nome}}{{diagnostico_bloco}}\n\n*Itens para o conserto:*\n{{itens_lista}}\n\n*Total do orçamento:* *{{total_brl}}*\n\nDeseja prosseguir com o orçamento?\n\nPrazo de entrega *até {{prazo_dias_uteis}} dias úteis* após aprovação.\n\nObrigado pela confiança! — Multimáquinas",
        ],
        'orcamento_os_sem_saudacao' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "Segue também o orçamento de outro equipamento da OS *#{{os_id}}*.\n\n*Equipamento:* {{equipamento_nome}}{{diagnostico_bloco}}\n\n*Itens para o conserto:*\n{{itens_lista}}\n\n*Total do orçamento:* *{{total_brl}}*\n\nDeseja prosseguir com o orçamento?\n\nPrazo de entrega *até {{prazo_dias_uteis}} dias úteis* após aprovação.\n\nObrigado pela confiança! — Multimáquinas",
        ],
        // 10A-3: lembrete revisado — OS, total e CTA APROVADO/CANCELADO.
        'orcamento_os_lembrete' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "{{saudacao}}, {{cliente_nome}}! {{remetente_nome}} aqui, da Multimáquinas.\n\nPassando para lembrar do orçamento da OS *#{{os_id}}*.\n\n*Equipamento:* {{equipamento_nome}}\n*Total do orçamento:* *{{total_brl}}*\n\nPara *aprovar* o conserto, responda *APROVADO*.\nPara *recusar*, responda *CANCELADO*.\n\nEstamos à disposição.\n— Multimáquinas Assistência Técnica",
        ],
        'orcamento_os_lembrete_sem_saudacao' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "Passando para lembrar do orçamento da OS *#{{os_id}}*.\n\n*Equipamento:* {{equipamento_nome}}\n*Total do orçamento:* *{{total_brl}}*\n\nPara *aprovar* o conserto, responda *APROVADO*.\nPara *recusar*, responda *CANCELADO*.\n\nEstamos à disposição.\n— Multimáquinas Assistência Técnica",
        ],
        // 10A-2: templates para orçamento com total = 0 e motivo de gratuidade definido.
        'orcamento_os_gratuidade_fabricante' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "{{saudacao}}, {{cliente_nome}}! {{remetente_nome}} aqui, da Multimáquinas.\n\nO atendimento da OS *#{{os_id}}* para o equipamento *{{equipamento_nome}}* está registrado como *garantia de fabricante*, sem cobrança ao cliente.{{diagnostico_bloco}}\n\nQualquer dúvida estamos à disposição.\n— Multimáquinas Assistência Técnica",
        ],
        'orcamento_os_gratuidade_fabricante_sem_saudacao' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "Segue também o atendimento da OS *#{{os_id}}* para o equipamento *{{equipamento_nome}}*, registrado como *garantia de fabricante*, sem cobrança ao cliente.{{diagnostico_bloco}}\n\nQualquer dúvida estamos à disposição.\n— Multimáquinas Assistência Técnica",
        ],
        'orcamento_os_gratuidade_cortesia' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "{{saudacao}}, {{cliente_nome}}! {{remetente_nome}} aqui, da Multimáquinas.\n\nO atendimento da OS *#{{os_id}}* para o equipamento *{{equipamento_nome}}* foi registrado como *cortesia*, sem cobrança ao cliente.{{diagnostico_bloco}}\n\nQualquer dúvida estamos à disposição.\n— Multimáquinas Assistência Técnica",
        ],
        'orcamento_os_gratuidade_cortesia_sem_saudacao' => [
            'canal'   => 'whatsapp',
            'assunto' => null,
            'corpo'   => "Segue também o atendimento da OS *#{{os_id}}* para o equipamento *{{equipamento_nome}}*, registrado como *cortesia*, sem cobrança ao cliente.{{diagnostico_bloco}}\n\nQualquer dúvida estamos à disposição.\n— Multimáquinas Assistência Técnica",
        ],
        // 10C-3: e-mail formal com PDF do orçamento em anexo.
        'orcamento_os_email' => [
            'canal'   => 'email',
            'assunto' => 'Orçamento OS #{{os_id}} — {{equipamento_nome}}',
            'corpo'   => "Olá, {{cliente_nome}}.\n\nSegue em anexo o orçamento referente ao equipamento {{equipamento_nome}}, OS #{{os_id}}.\n\nO orçamento é válido até {{validade}}.\n\nPara APROVAR o conserto, responda este e-mail com APROVADO ou entre em contato pelo WhatsApp.\nPara RECUSAR, responda CANCELADO.\n\nAtenciosamente,\n{{remetente_nome}}\nMultimáquinas Assistência Técnica\nTel/WhatsApp: {{empresa_telefone}}",
        ],
    ];

    /**
     * Catálogo de variáveis disponíveis por chave de template.
     * Alimenta a paleta clicável da tela admin e a pré-visualização.
     *
     * Cada item: ['nome' => label, 'exemplo' => valor de demonstração].
     * O label aparece como `{{slug}}` na UI (slug é a key do array).
     */
    private const VARS_DISPONIVEIS = [

        'os_criada' => [
            'cliente_nome'          => ['nome' => 'Nome completo do cliente',                'exemplo' => 'João Silva'],
            'cliente_primeiro_nome' => ['nome' => 'Primeiro nome do cliente',                'exemplo' => 'João'],
            'os_id'                 => ['nome' => 'Número da OS',                            'exemplo' => '20260506-001'],
            'equipamentos_lista'    => ['nome' => 'Lista de equipamentos (multilinha • …)',  'exemplo' => "• Lavadora Brastemp\n• Microondas Panasonic"],
            'equipamentos_qtd'      => ['nome' => 'Quantidade de equipamentos',              'exemplo' => '2'],
        ],
        'os_criada_email' => [
            'cliente_nome'          => ['nome' => 'Nome completo do cliente',                'exemplo' => 'João Silva'],
            'cliente_primeiro_nome' => ['nome' => 'Primeiro nome do cliente',                'exemplo' => 'João'],
            'os_id'                 => ['nome' => 'Número da OS',                            'exemplo' => '20260506-001'],
            'equipamentos_lista'    => ['nome' => 'Lista de equipamentos',                   'exemplo' => "• Lavadora Brastemp\n• Microondas Panasonic"],
            'equipamentos_qtd'      => ['nome' => 'Quantidade de equipamentos',              'exemplo' => '2'],
        ],
        'pre_pedido' => [
            'cliente_nome'          => ['nome' => 'Nome completo do cliente',  'exemplo' => 'João Silva'],
            'cliente_primeiro_nome' => ['nome' => 'Primeiro nome do cliente',  'exemplo' => 'João'],
            'numero'                => ['nome' => 'Nº do orçamento (NNNN/AAAA)', 'exemplo' => '0001/2026'],
            'item_descricao'        => ['nome' => 'Descrição do item',         'exemplo' => 'Motor Elétrico 2CV Weg'],
            'item_qtd'              => ['nome' => 'Quantidade',                'exemplo' => '1'],
            'item_total_brl'        => ['nome' => 'Total formatado (BRL)',     'exemplo' => '450,00'],
            'link_publico'          => ['nome' => 'URL pública do orçamento',  'exemplo' => 'https://multimaquinas.site/pre-pedido/abc123…/visualizar'],
            'validade_dias'         => ['nome' => 'Validade em dias',          'exemplo' => '15'],
        ],
        'pre_pedido_email' => [
            'cliente_nome'          => ['nome' => 'Nome completo do cliente',  'exemplo' => 'João Silva'],
            'cliente_primeiro_nome' => ['nome' => 'Primeiro nome do cliente',  'exemplo' => 'João'],
            'numero'                => ['nome' => 'Nº do orçamento',           'exemplo' => '0001/2026'],
            'item_descricao'        => ['nome' => 'Descrição do item',         'exemplo' => 'Motor Elétrico 2CV Weg'],
            'item_qtd'              => ['nome' => 'Quantidade',                'exemplo' => '1'],
            'item_total_brl'        => ['nome' => 'Total formatado (BRL)',     'exemplo' => '450,00'],
            'link_publico'          => ['nome' => 'URL pública do orçamento',  'exemplo' => 'https://multimaquinas.site/pre-pedido/abc123…/visualizar'],
            'validade_dias'         => ['nome' => 'Validade em dias',          'exemplo' => '15'],
        ],
        'os_criada_com_termo' => [
            'cliente_nome'          => ['nome' => 'Nome completo do cliente',                'exemplo' => 'João Silva'],
            'cliente_primeiro_nome' => ['nome' => 'Primeiro nome do cliente',                'exemplo' => 'João'],
            'os_id'                 => ['nome' => 'Número da OS',                            'exemplo' => '20260506-001'],
            'equipamentos_lista'    => ['nome' => 'Lista de equipamentos (multilinha)',       'exemplo' => "• Lavadora Brastemp\n• Microondas Panasonic"],
            'equipamentos_qtd'      => ['nome' => 'Quantidade de equipamentos',              'exemplo' => '2'],
            'link_termo'            => ['nome' => 'URL pública para aceite do termo',        'exemplo' => 'https://multimaquinas.site/termo/abc123def456...'],
        ],
        'termo_responsabilidade' => [],
        'reforco_retirada' => [
            'cliente_nome'          => ['nome' => 'Nome completo do cliente',         'exemplo' => 'João Silva'],
            'cliente_primeiro_nome' => ['nome' => 'Primeiro nome do cliente',         'exemplo' => 'João'],
            'os_id'                 => ['nome' => 'Número da OS',                     'exemplo' => '20260506-001'],
            'equipamentos_lista'    => ['nome' => 'Lista de equipamentos',            'exemplo' => '• Lavadora Brastemp'],
            'dias_aguardando'       => ['nome' => 'Dias aguardando retirada',         'exemplo' => '45'],
        ],
        'orcamento_os' => [
            'saudacao'              => ['nome' => 'Saudação por horário',                        'exemplo' => 'Boa noite'],
            'cliente_nome'          => ['nome' => 'Nome do cliente',                             'exemplo' => 'João Silva'],
            'remetente_nome'        => ['nome' => 'Primeiro nome do usuário logado',            'exemplo' => 'Carlos'],
            'os_id'                 => ['nome' => 'Número da OS',                                'exemplo' => '20260506-001'],
            'equipamento_numero'    => ['nome' => 'Número visual do equipamento',                'exemplo' => '1'],
            'equipamento_nome'      => ['nome' => 'Nome do equipamento',                         'exemplo' => 'Lavadora Brastemp 15kg'],
            'itens_lista'           => ['nome' => 'Lista descritiva dos itens (sem preços)',     'exemplo' => "• Motor elétrico 1/4 CV (1 un)\n• Rolamento 6204 (2 un)"],
            'prazo_dias_uteis'      => ['nome' => 'Prazo de entrega em dias úteis',               'exemplo' => '20'],
            'total_brl'             => ['nome' => 'Valor total do orçamento',                    'exemplo' => 'R$ 450,00'],
            'diagnostico_bloco'     => ['nome' => 'Bloco opcional de diagnóstico/serviço',       'exemplo' => "\n*Diagnóstico / serviço:*\nTroca de rolamentos e revisão geral."],
        ],
        'orcamento_os_lembrete' => [
            'saudacao'              => ['nome' => 'Saudação por horário',                        'exemplo' => 'Boa tarde'],
            'cliente_nome'          => ['nome' => 'Nome do cliente formatado',                   'exemplo' => 'João Silva'],
            'remetente_nome'        => ['nome' => 'Primeiro nome do usuário logado',            'exemplo' => 'Carlos'],
            'os_id'                 => ['nome' => 'Número da OS',                                'exemplo' => '20260506-001'],
            'equipamento_nome'      => ['nome' => 'Nome do equipamento',                         'exemplo' => 'Martelete Makita HR5210.0 10kg'],
            'total_brl'             => ['nome' => 'Valor total do orçamento',                    'exemplo' => 'R$ 450,00'],
        ],
        // 10A-2
        'orcamento_os_gratuidade_fabricante' => [
            'saudacao'              => ['nome' => 'Saudação por horário',                          'exemplo' => 'Bom dia'],
            'cliente_nome'          => ['nome' => 'Nome do cliente',                               'exemplo' => 'João Silva'],
            'remetente_nome'        => ['nome' => 'Primeiro nome do usuário logado',              'exemplo' => 'Carlos'],
            'os_id'                 => ['nome' => 'Número da OS',                                  'exemplo' => '20260506-001'],
            'equipamento_nome'      => ['nome' => 'Nome do equipamento',                           'exemplo' => 'Lavadora Brastemp 15kg'],
            'diagnostico_bloco'     => ['nome' => 'Bloco opcional de diagnóstico/serviço',         'exemplo' => "\n*Diagnóstico / serviço:*\nMotor substituído em garantia."],
            'motivo_gratuidade'     => ['nome' => 'Slug do motivo (garantia_fabricante/cortesia)', 'exemplo' => 'garantia_fabricante'],
            'motivo_gratuidade_label' => ['nome' => 'Rótulo legível do motivo',                   'exemplo' => 'garantia de fabricante'],
        ],
        'orcamento_os_gratuidade_cortesia' => [
            'saudacao'              => ['nome' => 'Saudação por horário',                          'exemplo' => 'Boa tarde'],
            'cliente_nome'          => ['nome' => 'Nome do cliente',                               'exemplo' => 'João Silva'],
            'remetente_nome'        => ['nome' => 'Primeiro nome do usuário logado',              'exemplo' => 'Carlos'],
            'os_id'                 => ['nome' => 'Número da OS',                                  'exemplo' => '20260506-001'],
            'equipamento_nome'      => ['nome' => 'Nome do equipamento',                           'exemplo' => 'Lavadora Brastemp 15kg'],
            'diagnostico_bloco'     => ['nome' => 'Bloco opcional de diagnóstico/serviço',         'exemplo' => "\n*Diagnóstico / serviço:*\nRevisão geral coberta como cortesia."],
            'motivo_gratuidade'     => ['nome' => 'Slug do motivo (garantia_fabricante/cortesia)', 'exemplo' => 'cortesia'],
            'motivo_gratuidade_label' => ['nome' => 'Rótulo legível do motivo',                   'exemplo' => 'cortesia'],
        ],
        // 10C-3
        'orcamento_os_email' => [
            'cliente_nome'           => ['nome' => 'Nome do cliente',                         'exemplo' => 'João Silva'],
            'equipamento_nome'       => ['nome' => 'Nome do equipamento',                     'exemplo' => 'Lavadora Brastemp 15kg'],
            'os_id'                  => ['nome' => 'Número da OS',                            'exemplo' => '20260506-001'],
            'total_brl'              => ['nome' => 'Valor total do orçamento (BRL)',          'exemplo' => 'R$ 450,00'],
            'validade'               => ['nome' => 'Data de validade (dd/mm/aaaa)',           'exemplo' => '12/06/2026'],
            'empresa_telefone'       => ['nome' => 'Telefone/WhatsApp da empresa',            'exemplo' => '(11) 99999-9999'],
            'empresa_email'          => ['nome' => 'E-mail da empresa',                       'exemplo' => 'contato@multimaquinas.site'],
            'remetente_nome'         => ['nome' => 'Primeiro nome do usuário logado',        'exemplo' => 'Carlos'],
            'motivo_gratuidade_label'=> ['nome' => 'Rótulo do motivo de gratuidade',         'exemplo' => 'garantia de fabricante'],
        ],
    ];

    public function __construct(private readonly ?PDO $pdo = null) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    // ── API de renderização ────────────────────────────────────────────────

    /**
     * Renderiza o corpo do template substituindo {{vars}} pelos valores.
     * Variáveis ausentes em $vars são deixadas como string vazia (não falha).
     */
    public function render(string $chave, array $vars): string
    {
        $tpl = $this->buscarOuFallback($chave);
        return $this->aplicar($tpl['corpo'] ?? '', $vars);
    }

    /**
     * Renderiza o assunto (apenas para templates de canal=email).
     * Retorna '' se o template não tem assunto definido.
     */
    public function renderAssunto(string $chave, array $vars): string
    {
        $tpl = $this->buscarOuFallback($chave);
        $assunto = (string)($tpl['assunto'] ?? '');
        return $assunto === '' ? '' : $this->aplicar($assunto, $vars);
    }

    /**
     * Renderiza simultaneamente assunto + corpo. Útil para canal=email.
     * @return array{assunto:string, corpo:string, canal:string}
     */
    public function renderFull(string $chave, array $vars): array
    {
        $tpl = $this->buscarOuFallback($chave);
        return [
            'canal'   => (string)($tpl['canal'] ?? 'sistema'),
            'assunto' => $this->aplicar((string)($tpl['assunto'] ?? ''), $vars),
            'corpo'   => $this->aplicar((string)($tpl['corpo']   ?? ''), $vars),
        ];
    }

    // ── API de gestão (tela admin) ─────────────────────────────────────────

    /**
     * Lista todos os templates conhecidos (catálogo). Mescla:
     *  - linhas que já existem no banco
     *  - chaves do DEFAULTS que ainda não foram seeded
     * Assim a tela mostra tudo, mesmo se a migration ainda não rodou.
     *
     * @return array<int, array{chave:string, canal:string, descricao:string, assunto:?string, corpo:string, atualizado_em:?string, fonte:string}>
     */
    public function listar(): array
    {
        $rows = [];
        try {
            $stmt = $this->pdo()->query(
                "SELECT chave, canal, descricao, assunto, corpo, atualizado_em
                 FROM mensagens_templates
                 ORDER BY chave ASC"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rows = array_map(static fn($r) => $r + ['fonte' => 'banco'], $rows);
        } catch (Throwable) {
            // Tabela ainda não existe — segue só com defaults.
        }

        $existentes = array_column($rows, 'chave');
        foreach (self::DEFAULTS as $chave => $def) {
            if (in_array($chave, $existentes, true)) continue;
            $rows[] = [
                'chave'         => $chave,
                'canal'         => $def['canal'],
                'descricao'     => '(template padrão — não editado)',
                'assunto'       => $def['assunto'],
                'corpo'         => $def['corpo'],
                'atualizado_em' => null,
                'fonte'         => 'default',
            ];
        }

        usort($rows, static fn($a, $b) => strcmp($a['chave'], $b['chave']));
        return $rows;
    }

    /**
     * Busca um template específico pela chave. Retorna o do banco se existir,
     * senão o default. Retorna null se a chave for desconhecida (não está
     * em DEFAULTS nem no banco).
     */
    public function buscar(string $chave): ?array
    {
        $tpl = $this->buscarOuFallback($chave);
        return $tpl ?: null;
    }

    /**
     * Cria/atualiza um template (UPSERT). Valida sintaxe antes de gravar.
     *
     * @param array{descricao?:string, canal?:string, assunto?:?string, corpo:string} $dados
     */
    public function salvar(string $chave, array $dados): void
    {
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $chave)) {
            throw new InvalidArgumentException("Chave inválida: {$chave}. Use apenas a-z, 0-9 e _.");
        }

        $corpo   = (string)($dados['corpo'] ?? '');
        $assunto = isset($dados['assunto']) ? (string)$dados['assunto'] : null;

        $erro = $this->validar($corpo);
        if ($erro !== null) {
            throw new InvalidArgumentException("Corpo inválido: {$erro}");
        }
        if ($assunto !== null && $assunto !== '') {
            $erroA = $this->validar($assunto);
            if ($erroA !== null) {
                throw new InvalidArgumentException("Assunto inválido: {$erroA}");
            }
        }

        // Avisa sobre placeholders desconhecidos, mas não bloqueia — o usuário
        // pode estar adicionando uma variável que será suportada depois.
        // (a UI usa esse retorno como "warning", não como erro fatal)

        $sql = "INSERT INTO mensagens_templates (chave, canal, descricao, assunto, corpo)
                VALUES (:chave, :canal, :descricao, :assunto, :corpo)
                ON DUPLICATE KEY UPDATE
                    canal     = VALUES(canal),
                    descricao = VALUES(descricao),
                    assunto   = VALUES(assunto),
                    corpo     = VALUES(corpo)";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            ':chave'     => $chave,
            ':canal'     => $dados['canal']     ?? (self::DEFAULTS[$chave]['canal'] ?? 'sistema'),
            ':descricao' => $dados['descricao'] ?? '',
            ':assunto'   => $assunto,
            ':corpo'     => $corpo,
        ]);
    }

    /**
     * Restaura um template ao texto-padrão do código (deleta a linha do banco;
     * a próxima leitura cai no DEFAULTS).
     */
    public function restaurarPadrao(string $chave): void
    {
        $stmt = $this->pdo()->prepare("DELETE FROM mensagens_templates WHERE chave = ? LIMIT 1");
        $stmt->execute([$chave]);
    }

    // ── Validação e introspecção ──────────────────────────────────────────

    /**
     * Valida sintaxe de um template. Retorna mensagem de erro ou null se ok.
     *
     * Detecta:
     *  - chaves abertas sem fechamento: "{{nome"
     *  - chaves fechadas sem abertura: "nome}}"
     *  - placeholders mal formados: "{{ }}", "{{ nome com espaço }}"
     */
    public function validar(string $template): ?string
    {
        // Conta abre/fecha: têm que ser iguais.
        $abre  = substr_count($template, '{{');
        $fecha = substr_count($template, '}}');
        if ($abre !== $fecha) {
            $diff = $abre - $fecha;
            if ($diff > 0) {
                return "{$abre} `{{` aberto(s) e {$fecha} `}}` fechado(s) — "
                     . "faltam {$diff} fechamento(s) `}}`.";
            }
            return "{$abre} `{{` aberto(s) e {$fecha} `}}` fechado(s) — "
                 . abs($diff) . " fechamento(s) `}}` a mais.";
        }

        // Cada {{ deve ser seguido por }} antes do próximo {{.
        $pos = 0;
        while (($abrePos = strpos($template, '{{', $pos)) !== false) {
            $fechaPos = strpos($template, '}}', $abrePos + 2);
            if ($fechaPos === false) {
                return "Tag `{{` aberta na posição {$abrePos} sem fechamento.";
            }
            // Outro {{ entre eles?
            $proximoAbre = strpos($template, '{{', $abrePos + 2);
            if ($proximoAbre !== false && $proximoAbre < $fechaPos) {
                return "Tag aninhada/sobreposta perto da posição {$proximoAbre}: cada `{{ }}` deve fechar antes do próximo abrir.";
            }
            // Conteúdo do placeholder não pode ter espaços, quebras ou estar vazio.
            $conteudo = substr($template, $abrePos + 2, $fechaPos - $abrePos - 2);
            if ($conteudo === '' || preg_match('/\s/', $conteudo)) {
                $preview = $conteudo === '' ? '(vazio)' : trim($conteudo);
                return "Placeholder mal formado: `{{{$preview}}}`. Use apenas letras, números e _ — sem espaços.";
            }
            if (!preg_match('/^[a-z0-9_]+$/i', $conteudo)) {
                return "Placeholder com caracteres inválidos: `{{{$conteudo}}}`. Permitido: a-z, 0-9, _.";
            }
            $pos = $fechaPos + 2;
        }

        return null;
    }

    /**
     * Extrai todos os placeholders distintos usados num template.
     * @return string[]
     */
    public function placeholdersUsados(string $template): array
    {
        if (!preg_match_all('/\{\{([a-z0-9_]+)\}\}/i', $template, $matches)) {
            return [];
        }
        return array_values(array_unique($matches[1]));
    }

    /**
     * Variáveis disponíveis (catálogo) para uma dada chave de template.
     * Usado pela UI para montar a paleta clicável.
     *
     * @return array<string, array{nome:string, exemplo:string}>
     */
    public function variaveisDisponiveis(string $chave): array
    {
        $aliases = [
            'orcamento_os_sem_saudacao' => 'orcamento_os',
            'orcamento_os_lembrete_sem_saudacao' => 'orcamento_os_lembrete',
            'orcamento_os_gratuidade_fabricante_sem_saudacao' => 'orcamento_os_gratuidade_fabricante',
            'orcamento_os_gratuidade_cortesia_sem_saudacao' => 'orcamento_os_gratuidade_cortesia',
        ];
        $chave = $aliases[$chave] ?? $chave;

        return self::VARS_DISPONIVEIS[$chave] ?? [];
    }

    /**
     * Renderiza um template usando os exemplos do catálogo — para a
     * pré-visualização da tela admin.
     */
    public function preview(string $chave, string $corpoOverride = ''): string
    {
        $vars = [];
        foreach ($this->variaveisDisponiveis($chave) as $slug => $info) {
            $vars[$slug] = (string)($info['exemplo'] ?? '');
        }
        $corpo = $corpoOverride !== '' ? $corpoOverride : (string)($this->buscarOuFallback($chave)['corpo'] ?? '');
        return $this->aplicar($corpo, $vars);
    }

    // ── Internos ──────────────────────────────────────────────────────────

    /**
     * Aplica substituição {{var}} → valor. Variáveis ausentes viram ''.
     */
    private function aplicar(string $template, array $vars): string
    {
        if ($template === '' || strpos($template, '{{') === false) {
            return $template;
        }

        // Fluxo: tudo que está em $vars vira chave-valor; o que sobrar de
        // placeholder sem valor é zerado num passo final.
        $chaves = [];
        $valores = [];
        foreach ($vars as $k => $v) {
            $chaves[]  = '{{' . $k . '}}';
            $valores[] = is_scalar($v) || $v === null ? (string)$v : '';
        }
        $resultado = str_replace($chaves, $valores, $template);

        // Limpa placeholders que ficaram (variável não fornecida).
        $resultado = preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $resultado);
        return $resultado ?? $template;
    }

    /**
     * Busca no banco; se não achou, cai pro DEFAULTS; se não achou em nenhum,
     * retorna estrutura vazia (corpo='').
     */
    private function buscarOuFallback(string $chave): array
    {
        try {
            $stmt = $this->pdo()->prepare(
                "SELECT chave, canal, descricao, assunto, corpo
                 FROM mensagens_templates
                 WHERE chave = ? AND ativo = 1
                 LIMIT 1"
            );
            $stmt->execute([$chave]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (Throwable) {
            // Tabela ausente / banco indisponível — segue para o fallback.
        }

        if (isset(self::DEFAULTS[$chave])) {
            return [
                'chave'     => $chave,
                'canal'     => self::DEFAULTS[$chave]['canal'],
                'assunto'   => self::DEFAULTS[$chave]['assunto'],
                'corpo'     => self::DEFAULTS[$chave]['corpo'],
                'descricao' => '(default hardcoded)',
            ];
        }

        return ['chave' => $chave, 'canal' => 'sistema', 'assunto' => null, 'corpo' => '', 'descricao' => ''];
    }
}
