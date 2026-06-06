-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: mensagens_templates
-- Data:      2026-05-06
-- Objetivo:  Centralizar os textos de notificação (WhatsApp, e-mail, sistema)
--            em uma tabela editável via UI, em vez de strings hard-coded
--            espalhadas pelos services.
--
-- Como rodar (aaPanel / phpMyAdmin):
--   USE multimaquinas_erp;
--   SOURCE database/migrations/2026_05_06_mensagens_templates.sql;
-- Ou colar o conteúdo na aba SQL.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `mensagens_templates` (
    `chave`          VARCHAR(64)  NOT NULL COMMENT 'identificador lógico — ex: os_criada, pre_pedido_whatsapp',
    `canal`          ENUM('whatsapp','email','sistema') NOT NULL DEFAULT 'sistema',
    `descricao`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'texto curto exibido na tela admin',
    `assunto`        VARCHAR(255) NULL COMMENT 'usado apenas em e-mail',
    `corpo`          TEXT         NOT NULL,
    `ativo`          TINYINT(1)   NOT NULL DEFAULT 1,
    `atualizado_em`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `criado_em`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Templates de mensagens (WhatsApp/e-mail/sistema) editáveis via UI';

-- ─── Seed: textos exatamente como estão hoje no PHP ──────────────────────────
-- ON DUPLICATE KEY UPDATE → idempotente, pode rodar a migration mais de uma
-- vez sem sobrescrever ajustes que o operador já fez na tela.
-- Para FORÇAR o reset ao default, deletar a linha e rodar de novo.

INSERT INTO `mensagens_templates` (`chave`, `canal`, `descricao`, `assunto`, `corpo`)
VALUES
(
    'os_criada',
    'whatsapp',
    'Aviso ao cliente quando uma OS é aberta na recepção',
    NULL,
    'Olá, {{cliente_primeiro_nome}}!\n\nSua Ordem de Serviço *#{{os_id}}* foi aberta com sucesso.\nEquipamento(s) que ficaram conosco:\n{{equipamentos_lista}}\n\nVamos te avisar quando o orçamento estiver pronto. Obrigado pela confiança!'
),
(
    'os_criada_email',
    'email',
    'Mesmo aviso da OS criada, em formato e-mail (assunto + corpo)',
    'Sua OS #{{os_id}} foi aberta — Multimáquinas',
    'Olá, {{cliente_primeiro_nome}}!\n\nSua Ordem de Serviço #{{os_id}} foi aberta com sucesso.\nEquipamento(s) que ficaram conosco:\n{{equipamentos_lista}}\n\nVamos te avisar quando o orçamento estiver pronto. Obrigado pela confiança!\n\n— Multimáquinas Assistência Técnica'
),
(
    'pre_pedido',
    'whatsapp',
    'Mensagem enviada com o link público do orçamento (pré-pedido)',
    NULL,
    'Olá, {{cliente_primeiro_nome}}!\n\nSegue seu orçamento Multimáquinas — Nº {{numero}}.\n\n• Item: {{item_descricao}}\n• Quantidade: {{item_qtd}}\n• Total: R$ {{item_total_brl}}\n\nVisualize / imprima o orçamento completo neste link:\n{{link_publico}}\n\nTermos: orçamento válido por {{validade_dias}} dias, sujeito à disponibilidade em estoque.\nQualquer dúvida estamos à disposição.\n\n— Multimáquinas Assistência Técnica'
),
(
    'pre_pedido_email',
    'email',
    'Mesmo orçamento do pré-pedido, em formato e-mail',
    'Orçamento Multimáquinas Nº {{numero}}',
    'Olá, {{cliente_primeiro_nome}}!\n\nSegue seu orçamento Multimáquinas — Nº {{numero}}.\n\n• Item: {{item_descricao}}\n• Quantidade: {{item_qtd}}\n• Total: R$ {{item_total_brl}}\n\nVisualize / imprima o orçamento completo neste link:\n{{link_publico}}\n\nTermos: orçamento válido por {{validade_dias}} dias, sujeito à disponibilidade em estoque.\nQualquer dúvida estamos à disposição.\n\n— Multimáquinas Assistência Técnica'
)
ON DUPLICATE KEY UPDATE
    -- Atualiza apenas a descrição (metadado) — nunca sobrescreve corpo/assunto
    -- que o operador pode ter ajustado na tela admin.
    `descricao` = VALUES(`descricao`);
