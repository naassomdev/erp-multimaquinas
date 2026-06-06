-- ============================================================
-- Migration: 2026_06_03_estoque_movimentacoes_conserto_idempotencia.sql
-- Etapa intermediaria: rastreabilidade/idempotencia da baixa de conserto
-- Data: 2026-06-03
-- ============================================================
--
-- Contexto:
--   A baixa continua ocorrendo na retirada. Esta migration prepara a
--   movimentacao de estoque para registrar equipamento e impedir baixa
--   duplicada por item tecnico de conserto.
--
-- Dump pre-migration:
--   backups/estoque_idempotencia_20260603_082959/multimaquinas_erp_pre_estoque_idempotencia.sql
--
-- Observacao:
--   O indice unico exato (origem_tipo, origem_id, tipo) nao e aplicavel
--   sobre a base atual porque ja existem movimentacoes antigas de CSV com
--   mesma origem_tipo/origem_id/tipo. Por isso a unicidade fica restrita
--   a origem_tipo = 'os_equipamento_item' por meio da coluna gerada abaixo.
-- ============================================================

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'estoque_movimentacoes'
               AND COLUMN_NAME = 'equip_idx'
        ),
        'SELECT ''estoque_movimentacoes.equip_idx exists''',
        'ALTER TABLE estoque_movimentacoes ADD COLUMN equip_idx INT NULL AFTER os_id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'estoque_movimentacoes'
               AND COLUMN_NAME = 'origem_conserto_id'
        ),
        'SELECT ''estoque_movimentacoes.origem_conserto_id exists''',
        'ALTER TABLE estoque_movimentacoes
           ADD COLUMN origem_conserto_id VARCHAR(50)
             GENERATED ALWAYS AS (
               CASE
                 WHEN origem_tipo = ''os_equipamento_item'' THEN origem_id
                 ELSE NULL
               END
             ) STORED
             AFTER origem_id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
              FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'estoque_movimentacoes'
               AND INDEX_NAME = 'uq_estoque_os_equip_item_tipo'
        ),
        'SELECT ''uq_estoque_os_equip_item_tipo exists''',
        'ALTER TABLE estoque_movimentacoes
           ADD UNIQUE KEY uq_estoque_os_equip_item_tipo (origem_conserto_id, tipo)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
              FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'estoque_movimentacoes'
               AND INDEX_NAME = 'idx_estoque_os_equip'
        ),
        'SELECT ''idx_estoque_os_equip exists''',
        'ALTER TABLE estoque_movimentacoes
           ADD KEY idx_estoque_os_equip (os_id, equip_idx)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- ROLLBACK (avaliar impacto antes de executar):
--
-- ALTER TABLE estoque_movimentacoes DROP INDEX idx_estoque_os_equip;
-- ALTER TABLE estoque_movimentacoes DROP INDEX uq_estoque_os_equip_item_tipo;
-- ALTER TABLE estoque_movimentacoes DROP COLUMN origem_conserto_id;
-- ALTER TABLE estoque_movimentacoes DROP COLUMN equip_idx;
-- ============================================================
