-- Script de migração do Módulo Papel — Fase 1 (SGT / SGE)
-- Nomenclatura Padronizada da Engenharia
-- Data: 2026-08-22

CREATE TABLE IF NOT EXISTS papel_maquinas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  codigo_vsat   VARCHAR(20)  NOT NULL,
  nome          VARCHAR(120) NOT NULL,
  apelido_chao  VARCHAR(120) NULL,
  observacao    VARCHAR(255) NULL,
  ativo         TINYINT(1)   NOT NULL DEFAULT 1,
  desativado_em TIMESTAMP NULL DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at    TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY idx_codigo (codigo_vsat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS papel_pecas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  codigo_vsat    VARCHAR(20)  NOT NULL,
  nome_engenharia VARCHAR(160) NOT NULL, -- Nome oficial padronizado da Engenharia
  bloco          ENUM('parte_ativa','nucleo','enrolamento_at','enrolamento_bt','mfl') NULL,
  categoria      VARCHAR(60)  NULL,
  material       VARCHAR(60)  NULL,
  espessura      DECIMAL(5,2) NULL,
  codigo_materia_prima VARCHAR(20) NULL,
  origem         ENUM('vsat','manual') NOT NULL DEFAULT 'vsat',
  ativo          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at     TIMESTAMP NULL DEFAULT NULL,
  KEY idx_codigo (codigo_vsat),
  KEY idx_bloco (bloco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carga inicial de máquinas do setor de papel (Semente)
INSERT INTO papel_maquinas (codigo_vsat, nome, apelido_chao, ativo) VALUES
('17001', 'Sliter Papel', 'Slitter', 1),
('17002', 'Guilhotina de Pedal', 'Guilhotina Manual', 1),
('17003', 'Máquina Dobradeira de Papel', 'Dobradeira', 1),
('17004', 'Sanfonadeira de Papel', 'Sanfonadeira', 1),
('17007', 'Mesa para Colagem Talisca 02', 'Mesa Colagem 02', 1),
('17008', 'Mesa para Colagem Talisca 03', 'Mesa Colagem 03', 1),
('17012', 'Guilhotina Elétrica', 'Guilhotina Motorizada', 1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome);
