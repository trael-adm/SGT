-- Módulo Produção — 1ª atividade (Card do Laboratório + leitura de QR)
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já com a tabela criada).
-- Aplicar com: mysql -u root trael_db < _inicial/migrar-producao.sql

-- Registro mínimo de N° de série -> Projeto, usado para resolver a leitura do QR
-- enquanto não existe uma tela de cadastro dedicada de transformadores.
CREATE TABLE IF NOT EXISTS producao_transformadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ns_transformador VARCHAR(60) NOT NULL,
    id_projeto INT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_producao_transf_ns (ns_transformador),
    CONSTRAINT fk_prod_transf_projeto FOREIGN KEY (id_projeto) REFERENCES projetos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um registro por passagem de um transformador numa estação (IQF/LAB/GER).
--
-- ns_ativo é uma coluna gerada que só recebe valor quando status='em_andamento';
-- o índice único nela garante, a nível de banco, que nunca existam duas linhas
-- em_andamento simultâneas para o mesmo N° de série (NULL não conflita com NULL
-- em UNIQUE KEY, então transformadores finalizados não disputam o índice).
CREATE TABLE IF NOT EXISTS producao_etapas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ns_transformador VARCHAR(60) NOT NULL,
    id_projeto INT NOT NULL,
    estacao ENUM('IQF','LAB','GER') NOT NULL,
    `status` ENUM('em_andamento','finalizado') NOT NULL DEFAULT 'em_andamento',
    data_inicio TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_fim TIMESTAMP NULL DEFAULT NULL,
    id_responsavel INT NULL,
    id_criador INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    ns_ativo VARCHAR(60) GENERATED ALWAYS AS (
        CASE WHEN `status` = 'em_andamento' THEN ns_transformador ELSE NULL END
    ) STORED,
    KEY idx_prod_etapas_ns (ns_transformador),
    KEY idx_prod_etapas_estacao_status (estacao, `status`),
    UNIQUE KEY uq_prod_etapas_ns_ativo (ns_ativo),
    CONSTRAINT fk_prod_etapas_projeto FOREIGN KEY (id_projeto) REFERENCES projetos(id),
    CONSTRAINT fk_prod_etapas_responsavel FOREIGN KEY (id_responsavel) REFERENCES usuarios(id),
    CONSTRAINT fk_prod_etapas_criador FOREIGN KEY (id_criador) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Patches idempotentes para bancos onde producao_etapas já existia sem o
-- ─── índice único / FK acima (ex.: trael_db_dev, criado antes desta revisão).
DROP PROCEDURE IF EXISTS _migrar_producao_patch;
DELIMITER $$
CREATE PROCEDURE _migrar_producao_patch()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producao_etapas' AND COLUMN_NAME = 'ns_ativo'
    ) THEN
        ALTER TABLE producao_etapas
            ADD COLUMN ns_ativo VARCHAR(60) GENERATED ALWAYS AS (
                CASE WHEN `status` = 'em_andamento' THEN ns_transformador ELSE NULL END
            ) STORED;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producao_etapas' AND INDEX_NAME = 'uq_prod_etapas_ns_ativo'
    ) THEN
        ALTER TABLE producao_etapas ADD UNIQUE KEY uq_prod_etapas_ns_ativo (ns_ativo);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'producao_etapas' AND CONSTRAINT_NAME = 'fk_prod_etapas_criador'
    ) THEN
        ALTER TABLE producao_etapas ADD CONSTRAINT fk_prod_etapas_criador FOREIGN KEY (id_criador) REFERENCES usuarios(id);
    END IF;
END$$
DELIMITER ;
CALL _migrar_producao_patch();
DROP PROCEDURE _migrar_producao_patch;

-- Seed de exemplo — associa N°s de série (padrão numérico já usado em
-- retrabalhos.ns_transformador) a projetos já cadastrados, só para a leitura
-- de QR ter o que resolver enquanto não existe uma tela de cadastro dedicada.
INSERT IGNORE INTO producao_transformadores (ns_transformador, id_projeto)
SELECT '900201', id FROM projetos WHERE codigo = 'TPD-427786' LIMIT 1;
INSERT IGNORE INTO producao_transformadores (ns_transformador, id_projeto)
SELECT '900202', id FROM projetos WHERE codigo = 'TPD-427787' LIMIT 1;
INSERT IGNORE INTO producao_transformadores (ns_transformador, id_projeto)
SELECT '900203', id FROM projetos WHERE codigo = 'TPD-400003' LIMIT 1;

-- Um item de exemplo já "em andamento" na estação IQF, para testar o cenário
-- de conflito (transformador 900202 já em outra estação) assim que a tela subir.
-- A checagem por ns_transformador (sem filtrar status) garante que o seed rode
-- uma única vez por ambiente, mesmo que a etapa de exemplo já tenha sido
-- finalizada por um teste manual.
INSERT INTO producao_etapas (ns_transformador, id_projeto, estacao, `status`, data_inicio, id_responsavel, id_criador)
SELECT '900202', p.id, 'IQF', 'em_andamento', NOW() - INTERVAL 47 MINUTE, u.id, u.id
FROM projetos p, usuarios u
WHERE p.codigo = 'TPD-427787' AND u.email = 'admin@trael.com.br'
  AND NOT EXISTS (
      SELECT 1 FROM producao_etapas WHERE ns_transformador = '900202'
  )
LIMIT 1;
