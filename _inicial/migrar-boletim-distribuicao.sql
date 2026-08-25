-- =============================================================================
-- Trael — BOLETIM (Boletim Diário de Medição)
-- Migração: tabelas de domínio necessárias ao Dashboard Distribuição (Sprint 3)
-- Idempotente — pode ser executada mais de uma vez sem efeito colateral.
-- USO: mysql -u root boletim_db < _inicial/migrar-boletim-distribuicao.sql
-- =============================================================================

USE boletim_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- Metas mensais — uma linha por mês (YYYY-MM). Sempre com os dois campos de
-- meta de TPD (distribuição e força), mesmo que um fique zerado naquele mês —
-- ver decisão em PROJETO-BOLETIM.md > "Banco de dados".
-- =============================================================================
CREATE TABLE IF NOT EXISTS boletim_config_metas (
    `month`               CHAR(7)   NOT NULL PRIMARY KEY,
    meta_total            INT       NOT NULL DEFAULT 0,
    meta_tpm              INT       NOT NULL DEFAULT 0,
    meta_tpd_distribuicao INT       NOT NULL DEFAULT 0,
    meta_tpd_forca        INT       NOT NULL DEFAULT 0,
    meta_tps              INT       NOT NULL DEFAULT 0,
    dias_uteis             INT      NOT NULL DEFAULT 0,
    dias_trabalhados       INT      NOT NULL DEFAULT 0,
    created_at            TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Registros de produção diária — `area`/`line` são independentes de propósito
-- (ver PROJETO-BOLETIM.md > "Banco de dados"): um TPD produzido na linha de
-- Média Força fica com area='forca' e line='TPD', nunca migra para line='TPM'.
-- =============================================================================
CREATE TABLE IF NOT EXISTS boletim_registros (
    id           INT          AUTO_INCREMENT PRIMARY KEY,
    `date`       DATE         NOT NULL,
    `area`       ENUM('distrib','forca') NOT NULL,
    line         VARCHAR(10)  NOT NULL,
    core_type    ENUM('ENR','JC','EMP','LAB') NULL,
    prog         INT          NOT NULL DEFAULT 0,
    `real`       INT          NOT NULL DEFAULT 0,
    description  VARCHAR(255) NULL,
    `origin`     ENUM('manual','excel','excel_consolidated') NOT NULL DEFAULT 'manual',
    id_criador   INT          NOT NULL,
    created_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at   TIMESTAMP    NULL DEFAULT NULL,
    FOREIGN KEY (id_criador) REFERENCES usuarios(id),
    KEY idx_boletim_registros_consulta (`date`, `area`, line, core_type),
    KEY idx_boletim_registros_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
