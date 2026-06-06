-- ============================================================
-- MIGRATION 9C-1 — Devolução e Descarte por Equipamento
-- Data: 2026-05-18
-- Banco: multimaquinas_erp
-- MySQL: 8.0.45
-- ============================================================
--
-- Objetivo:
--   Preparar schema para descarte/devolução granular por
--   equipamento, substituindo o modelo legado de descarte
--   por OS (ordem_servico.status = 'descartado').
--
-- O que esta migration FAZ:
--   1. Adiciona 'devolvido' e 'descartado' ao ENUM status_equip
--      de os_equipamento.
--   2. Adiciona colunas de rastreamento de autorização de
--      descarte e de devolução em os_equipamento.
--   3. Adiciona 'descarte' ao ENUM tipo de notificacoes_tecnico
--      para suportar notificações futuras ao técnico.
--
-- O que esta migration NÃO FAZ:
--   - Não altera nenhum dado existente.
--   - Não altera ordem_servico.status (legado mantido por ora).
--   - Não altera AlertaRetiradaService.
--   - Não cria endpoints, views ou lógica de negócio.
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 1;

-- ── PASSO 1 ── Expandir ENUM status_equip em os_equipamento ──────────────────
--
-- Novos valores adicionados ao FINAL do ENUM (compatível com ALGORITHM=INPLACE):
--   'devolvido' = equipamento devolvido ao cliente sem conserto (orçamento recusado)
--   'descartado' = equipamento descartado individualmente com autorização registrada
--
-- Semântica dos status terminais após esta migration:
--   retirado   = entregue após conserto aprovado, pago e faturado
--   devolvido  = devolvido sem conserto (orçamento recusado/cancelado)
--   descartado = descartado com autorização explícita do cliente
--   cancelado  = serviço recusado — destino físico AINDA não finalizado

ALTER TABLE `os_equipamento`
  MODIFY COLUMN `status_equip`
    ENUM('aberta','andamento','montagem','pronto','cancelado','retirado','devolvido','descartado')
    COLLATE utf8mb4_unicode_ci
    NOT NULL
    DEFAULT 'aberta'
    COMMENT 'Status atual do equipamento dentro da OS';

-- ── PASSO 2 ── Adicionar colunas de autorização/registro ─────────────────────
--
-- Todas as colunas são NULL por padrão — dados existentes não são afetados.
-- ALGORITHM=INSTANT para ADD COLUMN nullable no MySQL 8.0.
--
-- Bloco descarte (quem, quando, como autorizou):
--   descarte_autorizado_em   — timestamp da autorização do cliente
--   descarte_autorizado_por  — nome livre do cliente/responsável
--   descarte_autorizado_uid  — users.id do funcionário que registrou
--   descarte_meio            — canal: presencial / telefone / whatsapp / email
--
-- Bloco devolução (quando e quem registrou):
--   devolucao_em             — timestamp da devolução física
--   devolucao_uid            — users.id do funcionário que registrou

ALTER TABLE `os_equipamento`
  ADD COLUMN `descarte_autorizado_em`
    DATETIME NULL DEFAULT NULL
    COMMENT 'Quando o cliente autorizou o descarte deste equipamento'
    AFTER `status_equip`,

  ADD COLUMN `descarte_autorizado_por`
    VARCHAR(120) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL
    COMMENT 'Nome do cliente/responsável que autorizou o descarte'
    AFTER `descarte_autorizado_em`,

  ADD COLUMN `descarte_autorizado_uid`
    INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'FK users.id — funcionário que registrou a autorização de descarte'
    AFTER `descarte_autorizado_por`,

  ADD COLUMN `descarte_meio`
    ENUM('presencial','telefone','whatsapp','email')
    COLLATE utf8mb4_unicode_ci
    NULL DEFAULT NULL
    COMMENT 'Canal pelo qual o cliente autorizou o descarte'
    AFTER `descarte_autorizado_uid`,

  ADD COLUMN `devolucao_em`
    DATETIME NULL DEFAULT NULL
    COMMENT 'Quando o equipamento foi fisicamente devolvido ao cliente'
    AFTER `descarte_meio`,

  ADD COLUMN `devolucao_uid`
    INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'FK users.id — funcionário que registrou a devolução'
    AFTER `devolucao_em`;

-- ── PASSO 3 ── Expandir ENUM tipo em notificacoes_tecnico ────────────────────
--
-- Adiciona 'descarte' para suportar notificações ao técnico quando
-- a recepção autorizar o descarte de um equipamento (implementação futura).

ALTER TABLE `notificacoes_tecnico`
  MODIFY COLUMN `tipo`
    ENUM('aprovado','cancelado','pronto','info','descarte')
    COLLATE utf8mb4_unicode_ci
    NOT NULL
    DEFAULT 'info'
    COMMENT 'Tipo da notificação';

-- ── VERIFICAÇÃO PÓS-MIGRATION ─────────────────────────────────────────────────

SHOW COLUMNS FROM `os_equipamento`;
SHOW COLUMNS FROM `notificacoes_tecnico` WHERE Field = 'tipo';
