-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: termo_aceite_alertas
-- Data:      2026-05-06
-- Objetivo:  Aceite digital do Termo de Responsabilidade + sistema de alertas
--            de retirada e abandono de equipamentos.
--
-- Como rodar (aaPanel / phpMyAdmin):
--   USE multimaquinas_erp;
--   SOURCE database/migrations/2026_05_06_termo_aceite_alertas.sql;
-- Ou colar o conteúdo na aba SQL.
-- ─────────────────────────────────────────────────────────────────────────────

-- ═══════════════════════════════════════════════════════════════════════════════
-- 1. Tabela: termos_aceite
--    Registra cada aceite digital (link público) vinculado a uma OS.
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `termos_aceite` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `os_id`         VARCHAR(24)     NOT NULL COMMENT 'FK para ordem_servico.id',
    `slug`          CHAR(32)        NOT NULL COMMENT 'Identificador público para URL /termo/{slug}',
    `versao_termo`  MEDIUMTEXT      NOT NULL COMMENT 'Snapshot do texto do termo no momento da geração',
    `aceito_em`     DATETIME        NULL     COMMENT 'NULL = ainda não aceitou',
    `ip_cliente`    VARCHAR(45)     NULL     COMMENT 'IPv4 ou IPv6 do cliente no momento do aceite',
    `user_agent`    VARCHAR(512)    NULL     COMMENT 'Navegador do cliente no momento do aceite',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`),
    KEY `idx_aceite_os` (`os_id`),
    CONSTRAINT `fk_aceite_os` FOREIGN KEY (`os_id`)
        REFERENCES `ordem_servico` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Aceite digital do Termo de Responsabilidade — link público por OS';


-- ═══════════════════════════════════════════════════════════════════════════════
-- 2. Tabela: notificacoes_retirada
--    Log de cada notificação enviada ao cliente sobre retirada de equipamento.
--    Inclui suporte a upload de comprovante (print do WhatsApp).
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `notificacoes_retirada` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `os_id`         VARCHAR(24)     NOT NULL COMMENT 'FK para ordem_servico.id',
    `tipo`          ENUM('whatsapp','email','ligacao','sistema') NOT NULL DEFAULT 'whatsapp',
    `mensagem`      TEXT            NOT NULL COMMENT 'Texto da notificação enviada',
    `enviado_em`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `enviado_por`   INT UNSIGNED    NULL     COMMENT 'FK para usuarios.id — quem disparou',
    `print_path`    VARCHAR(512)    NULL     COMMENT 'Caminho do print/comprovante anexado',
    `obs`           TEXT            NULL     COMMENT 'Observação livre (ex: falou que busca semana que vem)',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notif_ret_os_data` (`os_id`, `enviado_em`),
    CONSTRAINT `fk_notif_ret_os` FOREIGN KEY (`os_id`)
        REFERENCES `ordem_servico` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de notificações de retirada de equipamento — provas de comunicação';


-- ═══════════════════════════════════════════════════════════════════════════════
-- 3. Adicionar valor 'descartado' ao ENUM de ordem_servico.status
--    (necessário para o fluxo de abandono legal após 90 dias)
-- ═══════════════════════════════════════════════════════════════════════════════

ALTER TABLE `ordem_servico`
    MODIFY COLUMN `status` ENUM(
        'aberta','andamento','pronto','retirado','cancelado','descartado'
    ) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aberta';


-- ═══════════════════════════════════════════════════════════════════════════════
-- 4. Seed: Templates de mensagens na tabela mensagens_templates
--    (idempotente via ON DUPLICATE KEY UPDATE)
-- ═══════════════════════════════════════════════════════════════════════════════

-- 4a. Termo de Responsabilidade completo (exibido na página pública de aceite)
INSERT INTO `mensagens_templates` (`chave`, `canal`, `descricao`, `assunto`, `corpo`)
VALUES (
    'termo_responsabilidade',
    'sistema',
    'Termo de Responsabilidade exibido na página de aceite digital e na impressão da OS',
    NULL,
    'TERMO DE RESPONSABILIDADE E CONDIÇÕES DE PRESTAÇÃO DE SERVIÇO\n\n1. DO ORÇAMENTO E TAXA DE DIAGNÓSTICO\n1.1. A elaboração do orçamento requer mão de obra técnica para abertura, análise e remontagem do equipamento/motor.\n1.2. Caso o cliente NÃO APROVE (cancele) o orçamento apresentado, será cobrada uma Taxa de Diagnóstico no valor de R$ 15,00 por equipamento/motor, destinada a cobrir os custos operacionais de desmontagem e remontagem.\n1.3. Esta taxa não será cobrada em casos onde o equipamento for diagnosticado como \"sem conserto\" ou com \"perda total\".\n1.4. Caso o orçamento seja aprovado, o valor da taxa de diagnóstico é isento, sendo cobrado apenas o valor do serviço e das peças.\n\n2. DA GARANTIA DOS SERVIÇOS (Art. 26, II do CDC)\n2.1. A garantia dos serviços prestados e das peças substituídas é de 90 (noventa) dias corridos, contados a partir da data de retirada do equipamento pelo cliente.\n2.2. A garantia cobre exclusivamente a mão de obra realizada e as peças trocadas e descritas nesta Ordem de Serviço. Não há garantia sobre outros componentes da máquina que não foram alvo do conserto ou sobre defeitos causados por mau uso, quedas, picos de energia, instalação incorreta, desgaste natural ou violação do lacre de garantia por terceiros.\n\n3. DOS PRAZOS DE RETIRADA E TAXA DE ARMAZENAMENTO\n3.1. Após a notificação de que o equipamento está consertado (ou após a recusa do orçamento), o cliente tem o prazo máximo de 30 (trinta) dias corridos para realizar a retirada do bem em nossa loja.\n3.2. Para orçamentos reprovados/cancelados, o prazo de retirada é de 07 (sete) dias corridos após a notificação.\n3.3. Ultrapassados os prazos descritos acima, será cobrada uma Taxa de Armazenamento/Guarda de R$ 2,00 por dia de atraso, devido à ocupação do espaço físico da assistência técnica.\n\n4. DO ABANDONO DO EQUIPAMENTO (Art. 1.275, III do Código Civil)\n4.1. O prazo máximo legal de permanência do equipamento nas dependências da assistência técnica é de 90 (noventa) dias corridos, contados a partir da data de envio da notificação de conclusão do serviço ou reprovação do orçamento.\n4.2. Passados os 90 (noventa) dias sem que o cliente retire o equipamento e quite os débitos pendentes (serviços executados, peças e taxas de armazenamento), o equipamento será considerado legalmente ABANDONADO, configurando a renúncia do direito de propriedade por parte do cliente, conforme estabelece o Artigo 1.275, inciso III, do Código Civil Brasileiro.\n4.3. Caracterizado o abandono, a assistência técnica reserva-se o pleno direito de alienar (vender), doar, desmontar para uso de peças ou sucatear o equipamento. O valor da venda ou das peças será utilizado para abater os custos do conserto realizado, taxas de orçamento e de armazenamento, não cabendo ao cliente qualquer tipo de reclamação, indenização ou restituição posterior.\n\n5. DAS COMUNICAÇÕES E NOTIFICAÇÕES\n5.1. O cliente concorda que todas as notificações referentes ao andamento do serviço, aprovação de orçamentos e avisos de retirada serão feitas preferencialmente pelos meios de contato fornecidos neste cadastro (WhatsApp, telefone, e-mail ou SMS).\n5.2. É de inteira responsabilidade do cliente manter seus dados de contato atualizados.'
)
ON DUPLICATE KEY UPDATE
    `descricao` = VALUES(`descricao`);


-- 4b. Mensagem WhatsApp resumida com link para aceite do termo
INSERT INTO `mensagens_templates` (`chave`, `canal`, `descricao`, `assunto`, `corpo`)
VALUES (
    'os_criada_com_termo',
    'whatsapp',
    'Aviso ao cliente quando OS é aberta — inclui link para aceite do termo',
    NULL,
    'Olá, *{{cliente_primeiro_nome}}*!\n\nSua Ordem de Serviço *#{{os_id}}* foi registrada na Multimáquinas 🔧\n\nEquipamento(s):\n{{equipamentos_lista}}\n\n📋 *Leia e aceite nosso Termo de Responsabilidade:*\n{{link_termo}}\n\nAssim que o orçamento estiver pronto, avisaremos.\nObrigado pela confiança! — Multimáquinas'
)
ON DUPLICATE KEY UPDATE
    `descricao` = VALUES(`descricao`);


-- 4c. Mensagem de reforço de retirada (quinzenal)
INSERT INTO `mensagens_templates` (`chave`, `canal`, `descricao`, `assunto`, `corpo`)
VALUES (
    'reforco_retirada',
    'whatsapp',
    'Lembrete quinzenal para cliente retirar equipamento pronto/cancelado',
    NULL,
    'Olá, *{{cliente_primeiro_nome}}*!\n\nLembramos que seu equipamento da OS *#{{os_id}}* está aguardando retirada em nossa loja há *{{dias_aguardando}} dias*.\n\nEquipamento(s):\n{{equipamentos_lista}}\n\n⚠️ Conforme nosso Termo de Responsabilidade, equipamentos não retirados em até 90 dias poderão ser considerados abandonados.\n\nPor favor, entre em contato para agendar a retirada.\n\n— Multimáquinas Assistência Técnica'
)
ON DUPLICATE KEY UPDATE
    `descricao` = VALUES(`descricao`);
