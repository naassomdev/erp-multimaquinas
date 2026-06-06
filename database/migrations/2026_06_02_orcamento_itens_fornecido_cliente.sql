-- ============================================================
-- Migration: 2026_06_02_orcamento_itens_fornecido_cliente.sql
-- Etapa 1: Peças fornecidas pelo cliente
-- Data: 2026-06-02
-- ============================================================
--
-- Contexto:
--   Permite manter itens diagnosticados/orçados no histórico, mas remover
--   sua cobrança quando o cliente trouxer as peças fisicamente.
--
-- Garantias:
--   - Não altera dados antigos em massa.
--   - Não baixa estoque.
--   - Não altera financeiro, NF, PDV, WhatsApp, permissões ou fuso horário.
--   - Campos novos são neutros por padrão.
--
-- Dump pré-migration:
--   backups/etapa1_pecas_cliente_20260602_214405/dump_pre_migration.sql
-- ============================================================

ALTER TABLE orcamento_itens
  ADD COLUMN fornecido_cliente TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 quando a peça foi fornecida pelo cliente e não deve ser cobrada/baixar estoque'
    AFTER obs,
  ADD COLUMN fornecido_em DATETIME NULL
    COMMENT 'Data/hora em que a recepção/admin confirmou fornecimento pelo cliente'
    AFTER fornecido_cliente,
  ADD COLUMN fornecido_por INT NULL
    COMMENT 'Usuário que confirmou o fornecimento pelo cliente'
    AFTER fornecido_em,
  ADD COLUMN motivo_remocao_cobranca VARCHAR(255) NULL
    COMMENT 'Motivo para manter o item no histórico sem cobrança'
    AFTER fornecido_por,
  ADD COLUMN valor_original DECIMAL(10,2) NULL
    COMMENT 'Valor unitário original antes da remoção da cobrança'
    AFTER motivo_remocao_cobranca,
  ADD COLUMN subtotal_original DECIMAL(10,2) NULL
    COMMENT 'Subtotal original antes da remoção da cobrança'
    AFTER valor_original;

-- ============================================================
-- ROLLBACK (se necessário, após avaliar impacto dos dados):
--
-- ALTER TABLE orcamento_itens
--   DROP COLUMN subtotal_original,
--   DROP COLUMN valor_original,
--   DROP COLUMN motivo_remocao_cobranca,
--   DROP COLUMN fornecido_por,
--   DROP COLUMN fornecido_em,
--   DROP COLUMN fornecido_cliente;
-- ============================================================
