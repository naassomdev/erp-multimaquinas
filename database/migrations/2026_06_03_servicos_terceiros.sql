-- ============================================================
-- Migration: 2026_06_03_servicos_terceiros.sql
-- Etapa: controle de rebobinamento / servico terceirizado
-- Data: 2026-06-03
--
-- Dump pre-migration:
--   backups/servicos_terceiros_20260603_223832/multimaquinas_erp_pre_servicos_terceiros.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS servicos_terceiros (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    os_id VARCHAR(24) NOT NULL,
    equip_idx INT UNSIGNED NOT NULL DEFAULT 0,
    tecnico_item_id INT UNSIGNED NULL,
    tipo VARCHAR(50) NOT NULL DEFAULT 'rebobinamento',
    fornecedor_nome VARCHAR(150) NULL,
    status ENUM('aguardando_envio','enviado','retornado','cancelado') NOT NULL DEFAULT 'aguardando_envio',
    saida_em DATETIME NULL,
    previsao_retorno DATE NULL,
    retorno_em DATETIME NULL,
    observacao TEXT NULL,
    observacao_retorno TEXT NULL,
    criado_por INT UNSIGNED NULL,
    atualizado_por INT UNSIGNED NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL,
    INDEX idx_serv_terc_os_equip (os_id, equip_idx),
    INDEX idx_serv_terc_item (tecnico_item_id),
    INDEX idx_serv_terc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ROLLBACK (avaliar impacto antes de executar):
--
-- DROP TABLE servicos_terceiros;
-- ============================================================
