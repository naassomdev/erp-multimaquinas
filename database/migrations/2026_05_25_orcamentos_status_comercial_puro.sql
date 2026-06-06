-- ============================================================
-- Migration: 2026_05_25_orcamentos_status_comercial_puro.sql
-- Etapa: 9H-6
-- Data: 2026-05-25
-- ============================================================
--
-- Contexto:
--   orcamentos.status representa o estado comercial do orçamento:
--     rascunho → enviado → aprovado → cancelado
--
--   Os estados físicos/técnicos são representados exclusivamente por:
--     os_equipamento.status_equip (pronto, retirado, devolvido, descartado)
--
-- Etapas anteriores:
--   9H-3: dados migrados (pronto/retirado → aprovado) — 8 registros
--   9H-4: STATUS_VALIDOS, services, views e JS simplificados
--   9H-5: validação completa — 0 registros com pronto/retirado
--
-- Pré-condição obrigatória (verificar antes de aplicar):
--   SELECT COUNT(*) FROM orcamentos WHERE status IN ('pronto','retirado');
--   -- Deve retornar 0. Caso contrário, executar 9H-3 primeiro.
-- ============================================================

-- ── FORWARD MIGRATION ────────────────────────────────────────
ALTER TABLE orcamentos
  MODIFY status ENUM('rascunho','enviado','aprovado','cancelado')
  NOT NULL DEFAULT 'rascunho';

-- ============================================================
-- ROLLBACK (desfazer, se necessário):
--
-- ALTER TABLE orcamentos
--   MODIFY status ENUM('rascunho','enviado','aprovado','cancelado','pronto','retirado')
--   NOT NULL DEFAULT 'rascunho';
--
-- Nota: o rollback restaura os valores no ENUM mas não recria
-- os dados originais (esses foram migrados para 'aprovado' em 9H-3).
-- Se necessário restaurar dados: ver backup em
--   backups/etapa9h6_20260525_202721/orcamentos_full.sql
-- ============================================================
