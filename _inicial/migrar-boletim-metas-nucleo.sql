-- =============================================================================
-- Trael — BOLETIM (Boletim Diário de Medição)
-- Migração: metas por núcleo da Distribuição (Meta Enrolado/Convencional/JC-TRIF)
-- Não idempotente (MySQL 8.0 não suporta ADD COLUMN IF NOT EXISTS) — rodar uma
-- única vez; rodar de novo falha com "Duplicate column name", inofensivo.
-- USO: mysql -u root boletim_db < _inicial/migrar-boletim-metas-nucleo.sql
-- =============================================================================

USE boletim_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- A meta de TPD-Distribuição é separada por núcleo (Enrolado/Convencional/
-- JC-TRIF), além do total já existente em `meta_tpd_distribuicao` — ver
-- PROJETO-BOLETIM.md > "Banco de dados". O relatório mostra só a linha de
-- meta total (`meta_tpd_distribuicao`); os 3 campos abaixo existem só para
-- Configurações permitir planejar por núcleo.
-- =============================================================================
ALTER TABLE boletim_config_metas
    ADD COLUMN meta_enrolado     INT NOT NULL DEFAULT 0 AFTER meta_tpd_distribuicao,
    ADD COLUMN meta_convencional INT NOT NULL DEFAULT 0 AFTER meta_enrolado,
    ADD COLUMN meta_jctrif       INT NOT NULL DEFAULT 0 AFTER meta_convencional;

SET FOREIGN_KEY_CHECKS = 1;
