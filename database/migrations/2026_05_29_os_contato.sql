-- Migration: 2026_05_29_os_contato
-- Etapa 10F-2 — Contato responsável na OS (nome + telefone).
--
-- Cenário: cliente é empresa, mas quem traz o equipamento é um funcionário.
-- Os campos ficam opcionais (NULL). Quando preenchidos, a saudação WhatsApp
-- e o atendimento usam o contato em vez do cliente principal.
--
-- Rollback:
--   ALTER TABLE ordem_servico
--     DROP COLUMN contato_nome,
--     DROP COLUMN contato_telefone;

ALTER TABLE ordem_servico
  ADD COLUMN contato_nome     VARCHAR(120) NULL DEFAULT NULL
             COMMENT 'Nome do contato/responsável que trouxe o equipamento (opcional)'
             AFTER telefone,
  ADD COLUMN contato_telefone VARCHAR(30)  NULL DEFAULT NULL
             COMMENT 'WhatsApp/telefone do contato (opcional, priorizado sobre cliente.telefone)'
             AFTER contato_nome;
