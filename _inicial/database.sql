-- =============================================================================
-- Trael — SGT (Sistema de Gestão Trael)
-- Banco de Dados: Estrutura inicial (autenticação + Retrabalho)
-- Versão: 1.0.0
-- Fuso horário: America/Cuiaba (UTC-4 fixo)
-- ===============================================trael_dbtrael_dbtrael_db_dev==============================
-- USO: execute em um servidor MySQL/MariaDB para criar o banco trael_db,
-- toda a estrutura e os dados iniciais (usuário admin + exemplos de retrabalho).
--   mysql -u root < _inicial/database.sql
-- ou importe este arquivo pelo phpMyAdmin/HeidiSQL do Laragon.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS trael_db
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE trael_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. AUTENTICAÇÃO E ACESSO (copiado do esqueleto GFT)
-- =============================================================================

-- Perfis de acesso (IDs fixos no código — não alterar)
CREATE TABLE IF NOT EXISTS perfis (
    id   INT         AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO perfis (id, nome) VALUES
    (1, 'Administrador'),
    (2, 'Planejador'),
    (3, 'Executor'),
    (4, 'Dashboard'),
    (5, 'Cliente Interno');

-- Unidades / filiais
CREATE TABLE IF NOT EXISTS alocacoes (
    id   INT         AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO alocacoes (id, nome) VALUES
    (1, 'Matriz'),
    (2, 'Filial 4');

CREATE TABLE IF NOT EXISTS usuarios (
    id             INT          AUTO_INCREMENT PRIMARY KEY,
    nome           VARCHAR(100) NOT NULL,
    email          VARCHAR(100) NOT NULL UNIQUE,
    senha          VARCHAR(255) NOT NULL,
    id_perfil      INT          NULL,
    id_alocacao    INT          NULL,
    e_executor     BOOLEAN      NOT NULL DEFAULT FALSE,
    ativo          BOOLEAN      NOT NULL DEFAULT TRUE,
    foto           VARCHAR(255) NULL,
    created_at     TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at     TIMESTAMP    NULL DEFAULT NULL,
    FOREIGN KEY (id_perfil)   REFERENCES perfis(id),
    FOREIGN KEY (id_alocacao) REFERENCES alocacoes(id),
    KEY idx_usuarios_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuário administrador padrão — e-mail: admin@trael.com.br  |  senha: Trael@Sgt2026#Admin
INSERT IGNORE INTO usuarios (nome, email, senha, id_perfil, id_alocacao, ativo) VALUES
    ('Administrador', 'admin@trael.com.br',
     '$2y$12$DKMTc29VTYIXpJp1cGWys.iWobmsbVnvI9T9Q0BWWoEBdrUT0Pfka', 1, 1, TRUE);

CREATE TABLE IF NOT EXISTS password_resets (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT          NOT NULL,
    token      VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP    NOT NULL,
    used       BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de atividades do sistema (login, logout, ações) — usado na proteção brute-force
CREATE TABLE IF NOT EXISTS logs_atividade (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT          NULL,
    tipo       ENUM('login_ok','login_erro','logout','acao') NOT NULL,
    descricao  VARCHAR(255) NULL,
    ip         VARCHAR(45)  NULL,
    user_agent TEXT         NULL,
    created_at TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id),
    KEY idx_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessões PHP armazenadas no banco (resiliência em ambiente sem filesystem persistente)
CREATE TABLE IF NOT EXISTS php_sessions (
    id         VARCHAR(128) NOT NULL PRIMARY KEY,
    data       MEDIUMTEXT   NOT NULL,
    updated_at INT UNSIGNED NOT NULL,
    KEY idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. RETRABALHO (chão de fábrica)
-- =============================================================================

-- Setores/áreas da fábrica (lista para o formulário, donut e ranking)
CREATE TABLE IF NOT EXISTS setores (
    id    INT         AUTO_INCREMENT PRIMARY KEY,
    nome  VARCHAR(80) NOT NULL UNIQUE,
    ativo BOOLEAN     NOT NULL DEFAULT TRUE,
    ordem INT         NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO setores (nome, ordem) VALUES
    ('Corte',       1),
    ('Usinagem',    2),
    ('Solda',       3),
    ('Montagem',    4),
    ('Bobinagem',   5),
    ('Pintura',     6),
    ('Acabamento',  7),
    ('Expedição',   8);

-- Pedidos (um pedido agrupa vários projetos)
CREATE TABLE IF NOT EXISTS pedidos (
    id         INT         AUTO_INCREMENT PRIMARY KEY,
    numero     VARCHAR(50) NOT NULL UNIQUE,                            -- Nº do pedido (ex.: PED-10422)
    created_at TIMESTAMP   NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP   NULL DEFAULT NULL,
    KEY idx_pedidos_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Projetos (cada projeto pertence a um pedido)
CREATE TABLE IF NOT EXISTS projetos (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    codigo     VARCHAR(50)  NOT NULL UNIQUE,                           -- Código do projeto (ex.: TPD-378787)
    descricao  VARCHAR(150) NULL,                                      -- Descrição / modelo
    id_pedido  INT          NOT NULL,                                  -- FK pedidos
    created_at TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP    NULL DEFAULT NULL,
    FOREIGN KEY (id_pedido) REFERENCES pedidos(id),
    KEY idx_projetos_pedido  (id_pedido),
    KEY idx_projetos_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reprovas / contenções (tabela de referência) — Local: IQF=inspeção final, LAB=laboratório, GER=geral
CREATE TABLE IF NOT EXISTS reprovas (
    id        INT          AUTO_INCREMENT PRIMARY KEY,
    codigo    VARCHAR(10)  NOT NULL UNIQUE,                            -- Ex.: M1, E1, R1
    familia   VARCHAR(80)  NOT NULL,                                   -- Família da contenção
    descricao VARCHAR(150) NOT NULL,                                   -- Descrição da contenção
    local     ENUM('IQF','LAB','GER') NOT NULL,                        -- Onde reprovou
    ativo     BOOLEAN      NOT NULL DEFAULT TRUE,
    ordem     INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO reprovas (codigo, familia, descricao, local, ordem) VALUES
    ('M1',  'ALÇA DE FIXAÇÃO PA', 'ALÇA DE FIXAÇÃO QUEBRADA',      'IQF',  1),
    ('M2',  'TERMINAL BT',        'TERMINAL DA BT QUEBRADO',       'IQF',  2),
    ('M3',  'COMUTADOR',          'COMUTADOR DERRETIDO',           'IQF',  3),
    ('M4',  'VAZAMENTO',          'VAZAMENTO NA AT',               'IQF',  4),
    ('M5',  'VAZAMENTO',          'VAZAMENTO NA BT',               'IQF',  5),
    ('M6',  'VAZAMENTO',          'VAZAMENTO NA TAMPA',            'IQF',  6),
    ('M7',  'VAZAMENTO',          'VAZAMENTO NO COMUTADOR',        'IQF',  7),
    ('M8',  'FURO NA SOLDA',      'FURO NO TANQUE',                'IQF',  8),
    ('M9',  'FURO NA SOLDA',      'FURO NA TAMPA',                 'IQF',  9),
    ('M10', 'FURO NA SOLDA',      'FURO NO RADIADOR',              'IQF', 10),
    ('M11', 'BT',                 'TERMINAL BT TROCADO',           'IQF', 11),
    ('E1',  'REPROVA ELÉTRICA',   'COR. EXCITAÇÃO FORA DE NORMA',  'LAB', 12),
    ('E2',  'REPROVA ELÉTRICA',   'PERDAS A VAZIO FORA DE NORMA',  'LAB', 13),
    ('E3',  'REPROVA ELÉTRICA',   'IMPEDANCIA FORA DE NORMA',      'LAB', 14),
    ('E4',  'REPROVA ELÉTRICA',   'PERDAS TOTAIS FORA DE NORMA',   'LAB', 15),
    ('E5',  'REPROVA ELÉTRICA',   'FIO ROMPIDO AT',                'LAB', 16),
    ('E6',  'REPROVA ELÉTRICA',   'FIO ROMPIDO BT',                'LAB', 17),
    ('E7',  'REPROVA ELÉTRICA',   'APLICADA AT',                   'LAB', 18),
    ('E8',  'REPROVA ELÉTRICA',   'APLICADA BT',                   'LAB', 19),
    ('E9',  'REPROVA ELÉTRICA',   'INDUZIDA',                      'LAB', 20),
    ('E10', 'REPROVA ELÉTRICA',   'INDUZIDA CURTO DIRETO',         'LAB', 21),
    ('R1',  'REVITALIZAÇÃO',      'REVITALIZAÇÃO',                 'GER', 22);

-- Registros de retrabalho (transformadores)
CREATE TABLE IF NOT EXISTS retrabalhos (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    id_responsavel   INT          NULL,                               -- FK usuarios
    id_projeto       INT          NULL,                               -- FK projetos
    ns_transformador VARCHAR(60)  NULL,                               -- Nº de série (substitui "peça")
    id_reprova       INT          NULL,                               -- FK reprovas
    data_reprova     DATE         NULL,                               -- Data da reprova (IQF/LAB/GER)
    data_inicio      DATE         NULL,                               -- Início do retrabalho
    data_finalizacao DATE         NULL,                               -- Finalização do retrabalho (dispara "Agu. Causa Raiz")
    status           ENUM('agu_abertura','agu_causa_raiz','finalizado') NOT NULL DEFAULT 'agu_abertura',
    observacoes      TEXT         NULL,
    causa_raiz       TEXT         NULL,                               -- Causa raiz (dispara "Finalizado")
    id_criador       INT          NULL,                               -- FK usuarios (quem registrou)
    concluido_em     TIMESTAMP    NULL DEFAULT NULL,
    created_at       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at       TIMESTAMP    NULL DEFAULT NULL,
    FOREIGN KEY (id_responsavel) REFERENCES usuarios(id),
    FOREIGN KEY (id_criador)     REFERENCES usuarios(id),
    FOREIGN KEY (id_projeto)     REFERENCES projetos(id),
    FOREIGN KEY (id_reprova)     REFERENCES reprovas(id),
    KEY idx_retra_status  (status),
    KEY idx_retra_projeto (id_projeto),
    KEY idx_retra_reprova (id_reprova),
    KEY idx_retra_ns      (ns_transformador),
    KEY idx_retra_created (created_at),
    KEY idx_retra_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
`trael_db_devtrael_db_devsgt-dev`perfis