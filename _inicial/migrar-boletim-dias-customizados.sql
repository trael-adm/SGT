-- =============================================================================
-- Trael — BOLETIM (Boletim Diário de Medição)
-- Migração: calendário customizado de dias de produção (modal "Métricas")
-- Não idempotente (MySQL 8.0 não suporta ADD COLUMN IF NOT EXISTS) — rodar uma
-- única vez; rodar de novo falha com "Duplicate column name", inofensivo.
-- USO: mysql -u root boletim_db < _inicial/migrar-boletim-dias-customizados.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- Lista JSON das datas (YYYY-MM-DD) marcadas como dia de produção no calendário
-- interativo do modal "Métricas" — ver PROJETO-BOLETIM.md > item 5 das Regras
-- de Negócio de Produção & Fábrica. NULL = usa o cálculo padrão de dias úteis
-- (boletimDiasUteisDoMes), sem calendário customizado salvo ainda.
-- =============================================================================
ALTER TABLE boletim_config_metas
    ADD COLUMN dias_customizados JSON NULL DEFAULT NULL AFTER dias_uteis;

SET FOREIGN_KEY_CHECKS = 1;
