-- ============================================================
-- BACKUP PRÉ-MIGRATION — Etapa 9C-1
-- Data: 2026-05-18
-- ============================================================

-- SHOW CREATE TABLE os_equipamento (estado antes da migration)
CREATE TABLE `os_equipamento` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `os_id` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordem_idx` int unsigned NOT NULL DEFAULT '0',
  `nome` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `serie` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `defeito` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `voltagem` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cx` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status_equip` enum('aberta','andamento','montagem','pronto','cancelado','retirado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aberta',
  `vista_explodida` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `obs_int` text COLLATE utf8mb4_unicode_ci,
  `obs_cli` text COLLATE utf8mb4_unicode_ci,
  `pecas_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `fotos_os_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'Fotos tiradas na entrada (OS)',
  `fotos_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'Fotos de análise do técnico',
  `em_garantia` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Equipamento está em garantia?',
  `tipo_garantia` enum('loja','fabricante') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tipo de garantia: pela loja ou pelo fabricante',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_os_ordem` (`os_id`,`ordem_idx`),
  KEY `idx_garantia` (`em_garantia`),
  CONSTRAINT `fk_os_equip_os` FOREIGN KEY (`os_id`) REFERENCES `ordem_servico` (`id`) ON DELETE CASCADE,
  CONSTRAINT `os_equipamento_chk_1` CHECK (json_valid(`pecas_json`)),
  CONSTRAINT `os_equipamento_chk_2` CHECK (json_valid(`fotos_os_json`)),
  CONSTRAINT `os_equipamento_chk_3` CHECK (json_valid(`fotos_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Distribuição de status_equip antes da migration:
-- andamento  34
-- aberta      9
-- pronto      7
-- retirado    5
-- cancelado   1
-- TOTAL:     56 equipamentos

-- Distribuição de status OS antes da migration:
-- aberta     20
-- andamento   9
-- retirado    7
-- cancelado   1
-- TOTAL:     37 OS
-- (nenhuma OS em 'pronto' ou 'descartado' em produção)
