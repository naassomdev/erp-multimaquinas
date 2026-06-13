-- ============================================================
-- Migration: 2026_06_13_notificacoes_retirada_aviso_lote.sql
-- Etapa: envio em lote de avisos de retirada por WhatsApp
-- Data: 2026-06-13
-- ============================================================
--
-- Contexto:
--   Versiona colunas e indices que ja existem em PRODUCAO na tabela
--   notificacoes_retirada, criados junto com o recurso de aviso de
--   retirada em lote. Esta migration apenas documenta/reproduz esse
--   schema em ambientes novos; em producao ela e um NO-OP (tudo ja existe).
--
--   Colunas versionadas:
--     equip_idx        -> qual equipamento da OS recebeu o aviso (os_equipamento.ordem_idx)
--     lote_id          -> agrupa um disparo em lote (bin2hex random)
--     status_envio     -> resultado do envio (enviado | falha | ignorado)
--     motivo_ignorado  -> motivo quando status_envio = 'ignorado'
--     retorno_api      -> resposta crua (JSON) da Evolution API, para auditoria
--
--   Indices versionados:
--     idx_nr_equip_data (os_id, equip_idx, enviado_em) -> elegibilidade, cooldown 7d, consulta por equipamento
--     idx_nr_lote       (lote_id)                      -> consulta por lote
--
-- Dump pre-migration: nao aplicavel (NO-OP em producao; nenhum dado alterado).
--
-- Garantias:
--   - Idempotente: cada coluna/indice e criado apenas se ainda nao existir
--     (checagem via information_schema; MySQL pode nao suportar IF NOT EXISTS).
--   - Nao altera dados. Nao faz UPDATE. Nao recria a tabela.
--   - Nao toca OS, estoque, financeiro, NF, PDV, orcamento ou tecnico.
-- ============================================================

-- ── Coluna: equip_idx ───────────────────────────────────────
SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notificacoes_retirada'
               AND COLUMN_NAME = 'equip_idx'
        ),
        'SELECT ''notificacoes_retirada.equip_idx exists''',
        'ALTER TABLE notificacoes_retirada ADD COLUMN equip_idx TINYINT UNSIGNED NULL AFTER os_id'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Coluna: lote_id ─────────────────────────────────────────
SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notificacoes_retirada'
               AND COLUMN_NAME = 'lote_id'
        ),
        'SELECT ''notificacoes_retirada.lote_id exists''',
        'ALTER TABLE notificacoes_retirada ADD COLUMN lote_id VARCHAR(36) NULL AFTER created_at'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Coluna: status_envio ────────────────────────────────────
SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notificacoes_retirada'
               AND COLUMN_NAME = 'status_envio'
        ),
        'SELECT ''notificacoes_retirada.status_envio exists''',
        'ALTER TABLE notificacoes_retirada ADD COLUMN status_envio ENUM(''enviado'',''falha'',''ignorado'') NOT NULL DEFAULT ''enviado'' AFTER lote_id'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Coluna: motivo_ignorado ─────────────────────────────────
SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notificacoes_retirada'
               AND COLUMN_NAME = 'motivo_ignorado'
        ),
        'SELECT ''notificacoes_retirada.motivo_ignorado exists''',
        'ALTER TABLE notificacoes_retirada ADD COLUMN motivo_ignorado VARCHAR(255) NULL AFTER status_envio'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Coluna: retorno_api ─────────────────────────────────────
SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notificacoes_retirada'
               AND COLUMN_NAME = 'retorno_api'
        ),
        'SELECT ''notificacoes_retirada.retorno_api exists''',
        'ALTER TABLE notificacoes_retirada ADD COLUMN retorno_api JSON NULL AFTER motivo_ignorado'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Indice: idx_nr_equip_data (elegibilidade / cooldown / por equipamento) ──
SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notificacoes_retirada'
               AND INDEX_NAME = 'idx_nr_equip_data'
        ),
        'SELECT ''idx_nr_equip_data exists''',
        'ALTER TABLE notificacoes_retirada ADD INDEX idx_nr_equip_data (os_id, equip_idx, enviado_em)'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Indice: idx_nr_lote (consulta por lote) ─────────────────
SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notificacoes_retirada'
               AND INDEX_NAME = 'idx_nr_lote'
        ),
        'SELECT ''idx_nr_lote exists''',
        'ALTER TABLE notificacoes_retirada ADD INDEX idx_nr_lote (lote_id)'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
