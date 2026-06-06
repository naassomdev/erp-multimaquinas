-- ============================================================
-- Migration: 2026_06_05_sync_orcamento_tecnico_itens.sql
-- Etapa: sincronizacao controlada de itens do orcamento com itens tecnicos
-- Data: 2026-06-05
-- ============================================================
--
-- Contexto:
--   Apos revisao pela recepcao, o orcamento passa a ser a fonte de verdade
--   dos itens do equipamento. Estes campos preservam vinculo e permitem
--   desativar itens tecnicos removidos do orcamento sem apagar historico.
--
-- Dump pre-migration:
--   backups/sync_orc_tecnico_20260605_213148/dump_tecnico_orcamento_necessidades.sql
-- ============================================================

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tecnico_itens'
               AND COLUMN_NAME = 'ativo'
        ),
        'SELECT ''tecnico_itens.ativo exists''',
        'ALTER TABLE tecnico_itens
           ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER valor_total'
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
               AND TABLE_NAME = 'tecnico_itens'
               AND COLUMN_NAME = 'origem_orcamento_item_id'
        ),
        'SELECT ''tecnico_itens.origem_orcamento_item_id exists''',
        'ALTER TABLE tecnico_itens
           ADD COLUMN origem_orcamento_item_id INT UNSIGNED NULL AFTER ativo'
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
               AND TABLE_NAME = 'tecnico_itens'
               AND COLUMN_NAME = 'atualizado_por_orcamento_em'
        ),
        'SELECT ''tecnico_itens.atualizado_por_orcamento_em exists''',
        'ALTER TABLE tecnico_itens
           ADD COLUMN atualizado_por_orcamento_em DATETIME NULL AFTER origem_orcamento_item_id'
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
               AND TABLE_NAME = 'tecnico_itens'
               AND COLUMN_NAME = 'removido_orcamento_em'
        ),
        'SELECT ''tecnico_itens.removido_orcamento_em exists''',
        'ALTER TABLE tecnico_itens
           ADD COLUMN removido_orcamento_em DATETIME NULL AFTER atualizado_por_orcamento_em'
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
               AND TABLE_NAME = 'tecnico_itens'
               AND INDEX_NAME = 'idx_tecnico_itens_os_equip_ativo'
        ),
        'SELECT ''idx_tecnico_itens_os_equip_ativo exists''',
        'ALTER TABLE tecnico_itens
           ADD KEY idx_tecnico_itens_os_equip_ativo (os_id, equip_idx, ativo)'
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
               AND TABLE_NAME = 'tecnico_itens'
               AND INDEX_NAME = 'idx_tecnico_itens_orc_item'
        ),
        'SELECT ''idx_tecnico_itens_orc_item exists''',
        'ALTER TABLE tecnico_itens
           ADD KEY idx_tecnico_itens_orc_item (origem_orcamento_item_id)'
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
               AND TABLE_NAME = 'orcamento_itens'
               AND COLUMN_NAME = 'produto_id'
        ),
        'SELECT ''orcamento_itens.produto_id exists''',
        'ALTER TABLE orcamento_itens
           ADD COLUMN produto_id INT UNSIGNED NULL AFTER codigo'
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
               AND TABLE_NAME = 'orcamento_itens'
               AND COLUMN_NAME = 'tecnico_item_id'
        ),
        'SELECT ''orcamento_itens.tecnico_item_id exists''',
        'ALTER TABLE orcamento_itens
           ADD COLUMN tecnico_item_id INT UNSIGNED NULL AFTER produto_id'
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
               AND TABLE_NAME = 'orcamento_itens'
               AND INDEX_NAME = 'idx_orcamento_itens_produto'
        ),
        'SELECT ''idx_orcamento_itens_produto exists''',
        'ALTER TABLE orcamento_itens
           ADD KEY idx_orcamento_itens_produto (produto_id)'
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
               AND TABLE_NAME = 'orcamento_itens'
               AND INDEX_NAME = 'idx_orcamento_itens_tecnico_item'
        ),
        'SELECT ''idx_orcamento_itens_tecnico_item exists''',
        'ALTER TABLE orcamento_itens
           ADD KEY idx_orcamento_itens_tecnico_item (tecnico_item_id)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- ROLLBACK (avaliar impacto antes de executar):
--
-- ALTER TABLE orcamento_itens DROP INDEX idx_orcamento_itens_tecnico_item;
-- ALTER TABLE orcamento_itens DROP INDEX idx_orcamento_itens_produto;
-- ALTER TABLE orcamento_itens DROP COLUMN tecnico_item_id;
-- ALTER TABLE orcamento_itens DROP COLUMN produto_id;
-- ALTER TABLE tecnico_itens DROP INDEX idx_tecnico_itens_orc_item;
-- ALTER TABLE tecnico_itens DROP INDEX idx_tecnico_itens_os_equip_ativo;
-- ALTER TABLE tecnico_itens DROP COLUMN removido_orcamento_em;
-- ALTER TABLE tecnico_itens DROP COLUMN atualizado_por_orcamento_em;
-- ALTER TABLE tecnico_itens DROP COLUMN origem_orcamento_item_id;
-- ALTER TABLE tecnico_itens DROP COLUMN ativo;
-- ============================================================
