-- =============================================================================
-- Trael — SGT
-- Migração: Materiais utilizados na Triagem do retrabalho (checklist de peças
-- trocadas + quantidade, preenchido antes da Causa da Reprova) + tabelas de
-- catálogo e de uso.
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-retrabalho-materiais.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── Catálogo de materiais (lista fixa exibida na Triagem) ─────────────────────
CREATE TABLE IF NOT EXISTS retrabalho_materiais_catalogo (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    descricao VARCHAR(100) NOT NULL,
    unidade   ENUM('KG','L','UND') NOT NULL DEFAULT 'UND',
    ativo     BOOLEAN NOT NULL DEFAULT TRUE,
    ordem     INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO retrabalho_materiais_catalogo (id, descricao, unidade, ordem) VALUES
    (1,  'ISOLADOR AT',              'UND', 1),
    (2,  'BORRACHA ISOLADOR AT',     'KG',  2),
    (3,  'TERMINAL AT',              'UND', 3),
    (4,  'ISOLADOR BT',              'UND', 4),
    (5,  'BORRACHA ISOLADOR BT',     'KG',  5),
    (6,  'TERMINAL BT',              'UND', 6),
    (7,  'BORRACHA TAMPA',           'KG',  7),
    (8,  'VÁLVULA DE ALÍVIO',        'UND', 8),
    (9,  'TERMINAL DE ATERRAMENTO',  'UND', 9),
    (10, 'PRESILHA TAMPA',           'UND', 10),
    (11, 'COMUTADOR',                'UND', 11),
    (12, 'TERMINAL TAMPA/TANQUE',    'UND', 12),
    (13, 'PARAFUSO E PORCA TAMPA',   'UND', 13),
    (14, 'BOBINA NOVA',              'UND', 14),
    (15, 'CALÇO DE BOBINA',          'UND', 15),
    (16, 'NÚCLEO NOVO',              'UND', 16),
    (17, 'ISOLAÇÃO ENTRE COLUNAS',   'UND', 17),
    (18, 'ÓLEO NOVO',                'L',   18);

-- ─── Uso de materiais por triagem (agrupado por id_lote, igual setores/causa) ──
CREATE TABLE IF NOT EXISTS retrabalho_material_uso (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    id_lote        INT NOT NULL,                 -- mesmo id_lote de retrabalhos (grupo da triagem)
    id_material    INT NULL,                     -- FK catálogo; NULL quando for "Outros"
    material_outro VARCHAR(150) NULL,             -- descrição livre quando id_material é NULL
    quantidade     DECIMAL(10,2) NOT NULL DEFAULT 0,
    id_criador     INT NULL,
    created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_retra_mat_uso_material FOREIGN KEY (id_material) REFERENCES retrabalho_materiais_catalogo(id),
    CONSTRAINT fk_retra_mat_uso_criador  FOREIGN KEY (id_criador)  REFERENCES usuarios(id),
    KEY idx_retra_mat_uso_lote (id_lote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   SELECT * FROM retrabalho_materiais_catalogo ORDER BY ordem;
--   SHOW TABLES LIKE 'retrabalho_material_uso';
-- =============================================================================
