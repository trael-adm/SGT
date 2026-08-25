-- =============================================================================
-- Trael — BOLETIM (Boletim Diário de Medição)
-- Migração: Potência Média diária (Dashboard Distribuição)
-- Idempotente — pode ser executada mais de uma vez sem efeito colateral.
-- USO: mysql -u root boletim_db < _inicial/migrar-boletim-potencia.sql
-- =============================================================================

USE boletim_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- Potência média (kVA) das unidades produzidas no dia — um valor por dia,
-- combinando todos os núcleos (não quebrado por ENR/JC/EMP, ver
-- PROJETO-BOLETIM.md > "Layout de referência"). Independente de
-- boletim_registros porque não tem granularidade de núcleo.
-- =============================================================================
CREATE TABLE IF NOT EXISTS boletim_potencia_diaria (
    id             INT          AUTO_INCREMENT PRIMARY KEY,
    `date`         DATE         NOT NULL,
    `area`         ENUM('distrib','forca') NOT NULL,
    potencia_media DECIMAL(10,2) NOT NULL,
    id_criador     INT          NOT NULL,
    created_at     TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     TIMESTAMP    NULL DEFAULT NULL,
    FOREIGN KEY (id_criador) REFERENCES usuarios(id),
    KEY idx_potencia_dia_area (`date`, `area`),
    KEY idx_potencia_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
