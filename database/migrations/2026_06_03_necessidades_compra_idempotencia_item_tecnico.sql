-- ============================================================
-- Migration: 2026_06_03_necessidades_compra_idempotencia_item_tecnico.sql
-- Etapa: idempotencia forte de necessidades_compra por item tecnico
-- Data: 2026-06-03
-- ============================================================
--
-- Contexto:
--   O fluxo deve permitir no maximo uma necessidade ativa
--   (pendente/comprado) por tecnico_itens.id, preservando historico
--   cancelado e itens manuais sem tecnico_item_id.
--
-- Dump pre-migration:
--   backups/necessidades_compra_20260603_084801/multimaquinas_erp_pre_necessidades_compra.sql
-- ============================================================

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'necessidades_compra'
               AND COLUMN_NAME = 'tecnico_item_ativo_id'
        ),
        'SELECT ''necessidades_compra.tecnico_item_ativo_id exists''',
        'ALTER TABLE necessidades_compra
           ADD COLUMN tecnico_item_ativo_id INT UNSIGNED
             GENERATED ALWAYS AS (
               CASE
                 WHEN tecnico_item_id IS NOT NULL AND status IN (''pendente'', ''comprado'')
                   THEN tecnico_item_id
                 ELSE NULL
               END
             ) STORED
             AFTER tecnico_item_id'
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
               AND TABLE_NAME = 'necessidades_compra'
               AND INDEX_NAME = 'uq_nc_tecnico_item_ativo'
        ),
        'SELECT ''uq_nc_tecnico_item_ativo exists''',
        'ALTER TABLE necessidades_compra
           ADD UNIQUE KEY uq_nc_tecnico_item_ativo (tecnico_item_ativo_id)'
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
               AND TABLE_NAME = 'necessidades_compra'
               AND INDEX_NAME = 'idx_nc_tecnico_item'
        ),
        'SELECT ''idx_nc_tecnico_item exists''',
        'ALTER TABLE necessidades_compra
           ADD KEY idx_nc_tecnico_item (tecnico_item_id)'
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
               AND TABLE_NAME = 'necessidades_compra'
               AND INDEX_NAME = 'idx_nc_os_equip_item'
        ),
        'SELECT ''idx_nc_os_equip_item exists''',
        'ALTER TABLE necessidades_compra
           ADD KEY idx_nc_os_equip_item (os_id, equip_idx, tecnico_item_id)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- ROLLBACK (avaliar impacto antes de executar):
--
-- ALTER TABLE necessidades_compra DROP INDEX idx_nc_os_equip_item;
-- ALTER TABLE necessidades_compra DROP INDEX idx_nc_tecnico_item;
-- ALTER TABLE necessidades_compra DROP INDEX uq_nc_tecnico_item_ativo;
-- ALTER TABLE necessidades_compra DROP COLUMN tecnico_item_ativo_id;
-- ============================================================
