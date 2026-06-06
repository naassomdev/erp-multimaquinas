-- ============================================================
-- Migration: 2026_06_06_os_equipamento_obs_recepcao.sql
-- Etapa: mensagem interna recepcao <-> tecnico por equipamento
-- Data: 2026-06-06
-- ============================================================
--
-- Contexto:
--   Canal de recado interno por equipamento entre a recepcao/administracao
--   (tela de orcamento) e o tecnico (painel tecnico). NAO e impressa no
--   orcamento (diferente de obs_cli) e NAO e o laudo (obs_int).
--   O painel tecnico ja tinha um slot de leitura preparado para
--   os_equipamento.obs_recepcao; esta coluna passa a alimenta-lo.
--
-- Dump pre-migration:
--   backups/obs_recepcao_20260606_201650/dump_os_equipamento.sql
--
-- Idempotente: adiciona a coluna apenas se ainda nao existir.
-- ============================================================

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'os_equipamento'
               AND COLUMN_NAME = 'obs_recepcao'
        ),
        'SELECT ''os_equipamento.obs_recepcao exists''',
        'ALTER TABLE os_equipamento ADD COLUMN obs_recepcao TEXT NULL AFTER obs_cli'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
