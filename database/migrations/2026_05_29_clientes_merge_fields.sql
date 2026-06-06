-- Migration: 2026_05_29_clientes_merge_fields
-- Etapa 11B-1 — Campos de controle de mesclagem/inativação em clientes.
--
-- Rollback:
--   ALTER TABLE clientes
--     DROP INDEX idx_clientes_ativo,
--     DROP INDEX idx_clientes_merged_into,
--     DROP COLUMN ativo,
--     DROP COLUMN merged_into_id,
--     DROP COLUMN merged_at,
--     DROP COLUMN merged_by;

ALTER TABLE clientes
  ADD COLUMN ativo          TINYINT(1)   NOT NULL DEFAULT 1   COMMENT '1=ativo, 0=inativo/mesclado' AFTER obs,
  ADD COLUMN merged_into_id INT UNSIGNED NULL     DEFAULT NULL COMMENT 'ID do cliente canônico após mesclagem' AFTER ativo,
  ADD COLUMN merged_at      DATETIME     NULL     DEFAULT NULL AFTER merged_into_id,
  ADD COLUMN merged_by      INT UNSIGNED NULL     DEFAULT NULL COMMENT 'ID do usuário que executou a mesclagem' AFTER merged_at,
  ADD INDEX idx_clientes_ativo       (ativo),
  ADD INDEX idx_clientes_merged_into (merged_into_id);
