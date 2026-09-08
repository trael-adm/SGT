-- Módulo Setor de Papel — Inventário do Almoxarifado e Aproveitamento de Estoque
-- Idempotente: pode ser executado múltiplas vezes sem erro.
-- Aplicar com: mysql -u root trael_db < _inicial/migrar-papel-inventario.sql

CREATE TABLE IF NOT EXISTS papel_estoque_inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    categoria VARCHAR(50) NOT NULL DEFAULT 'papel_tiras', -- 'papel_tiras', 'kit_bt', 'cabeceiras'
    rua VARCHAR(20) NOT NULL,
    prateleira VARCHAR(30) NULL,
    caixa VARCHAR(30) NULL,
    tipo_papel VARCHAR(50) NULL, -- 'DOBRA', 'LISO', 'KIT', 'CABECEIRA'
    material VARCHAR(50) NOT NULL DEFAULT 'DIAMANTADO', -- 'DIAMANTADO', 'PRESSPHAN', 'KRAFT'
    modelo VARCHAR(50) NULL, -- 'DT', etc.
    tamanho VARCHAR(60) NULL, -- '330-15', '185-25', '435', '4x10'
    espessura DECIMAL(6,2) NULL,
    largura DECIMAL(8,2) NULL,
    comprimento DECIMAL(8,2) NULL,
    tpd_projeto VARCHAR(60) NULL,
    sequencia VARCHAR(50) NULL,
    mes_pcp VARCHAR(30) NULL,
    estoque_inicial_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estoque_real_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estoque_real_qtd INT NOT NULL DEFAULT 0,
    observacoes TEXT NULL,
    `status` ENUM('disponivel','reservado','consumido','inativo') NOT NULL DEFAULT 'disponivel',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_papel_est_local (rua, prateleira),
    KEY idx_papel_est_mat_esp (material, espessura),
    KEY idx_papel_est_tamanho (tamanho),
    KEY idx_papel_est_proj (tpd_projeto),
    KEY idx_papel_est_status (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS papel_aproveitamento_analise (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tpd_projeto VARCHAR(60) NOT NULL,
    of_filha VARCHAR(60) NULL,
    peca_nome VARCHAR(120) NOT NULL,
    peca_idx INT NOT NULL DEFAULT 0,
    material VARCHAR(50) NOT NULL,
    espessura DECIMAL(6,2) NOT NULL,
    tamanho_demanda VARCHAR(60) NULL,
    qtd_demandada INT NOT NULL DEFAULT 0,
    massa_demandada_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    id_estoque_inventario INT NOT NULL,
    qtd_aproveitada INT NOT NULL DEFAULT 0,
    massa_aproveitada_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('pendente','aprovado','rejeitado') NOT NULL DEFAULT 'pendente',
    id_usuario_decisao INT NULL,
    nome_usuario_decisao VARCHAR(100) NULL,
    data_decisao TIMESTAMP NULL DEFAULT NULL,
    motivo_rejeicao TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_papel_aprov_proj (tpd_projeto),
    KEY idx_papel_aprov_status (`status`),
    CONSTRAINT fk_papel_aprov_estoque FOREIGN KEY (id_estoque_inventario) REFERENCES papel_estoque_inventario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS papel_estoque_movimentacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_estoque INT NOT NULL,
    tipo_movimentacao ENUM('entrada','saida','reserva','estorno','ajuste') NOT NULL,
    quantidade_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantidade_qtd INT NOT NULL DEFAULT 0,
    saldo_anterior_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    saldo_novo_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    motivo_descricao VARCHAR(255) NULL,
    tpd_projeto VARCHAR(60) NULL,
    of_filha VARCHAR(60) NULL,
    responsavel_retirada VARCHAR(100) NULL,
    id_usuario INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_papel_mov_estoque (id_estoque),
    CONSTRAINT fk_papel_mov_estoque FOREIGN KEY (id_estoque) REFERENCES papel_estoque_inventario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
