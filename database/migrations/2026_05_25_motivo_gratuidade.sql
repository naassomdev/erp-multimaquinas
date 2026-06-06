-- ============================================================
-- Migration: 2026_05_25_motivo_gratuidade.sql
-- Etapa: 9J-1
-- Data: 2026-05-25
-- ============================================================
--
-- Contexto:
--   O sistema usava total = 0 como proxy para "não cobrar cliente",
--   sem registrar o motivo. Isso gerava ambiguidade entre:
--     - garantia de fabricante
--     - cortesia
--     - isenção manual
--     - erro de orçamento vazio
--
--   Este campo é informativo e nullable — não bloqueia operações.
--   Não altera fluxo financeiro, retirada, estoque ou PDV.
--
-- Pré-condição:
--   Nenhuma. Campo nullable com DEFAULT NULL.
--
-- Backfill executado (9J-1, 2026-05-25):
--   1 registro: id=34, OS 20260520-002, equip_idx=1
--   → motivo_gratuidade = 'garantia_fabricante'
--     (em_garantia=1, tipo_garantia='fabricante', total=0.00)
-- ============================================================

-- ── FORWARD MIGRATION ────────────────────────────────────────
ALTER TABLE orcamentos
  ADD COLUMN motivo_gratuidade ENUM('garantia_fabricante','cortesia') NULL
  COMMENT 'Motivo quando total = 0 e não há cobrança ao cliente'
  AFTER total;

-- ── BACKFILL (executado junto com a migration) ────────────────
UPDATE orcamentos o
  JOIN os_equipamento eq
    ON eq.os_id = o.os_id AND eq.ordem_idx = o.equip_idx
   SET o.motivo_gratuidade = 'garantia_fabricante'
 WHERE o.total = 0
   AND eq.em_garantia = 1
   AND eq.tipo_garantia = 'fabricante'
   AND o.motivo_gratuidade IS NULL;

-- ============================================================
-- ROLLBACK (desfazer, se necessário):
--
-- ALTER TABLE orcamentos DROP COLUMN motivo_gratuidade;
--
-- Nota: o rollback remove a coluna e perde os dados de backfill.
-- Não há impacto em fluxo financeiro, retirada ou estoque.
-- ============================================================
