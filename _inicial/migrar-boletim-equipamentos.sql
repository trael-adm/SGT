-- =============================================================================
-- Trael — BOLETIM (Boletim Diário de Medição)
-- Migração: Equipamentos (Sprint 6 — Configurações)
-- Idempotente — pode ser executada mais de uma vez sem efeito colateral.
-- USO: mysql -u root boletim_db < _inicial/migrar-boletim-equipamentos.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- Status de equipamentos da fábrica — substitui equipment_status do protótipo
-- antigo (ver PROJETO-BOLETIM.md > "Banco de dados").
-- =============================================================================
CREATE TABLE IF NOT EXISTS boletim_equipamentos (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    `status`   ENUM('green','yellow','red') NOT NULL DEFAULT 'green',
    id_criador INT          NOT NULL,
    created_at TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP    NULL DEFAULT NULL,
    FOREIGN KEY (id_criador) REFERENCES usuarios(id),
    KEY idx_equipamentos_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
