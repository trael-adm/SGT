-- Módulo Setor de Papel — Liberação de Corte (Programação PCP)
-- Persiste as ações de "Liberar" de pages/papel/programacao.php (modos bobina/projeto/peça),
-- que hoje só desativam o botão em memória e se perdem a cada reload / re-sync do VSAT.
-- Idempotente: pode ser executado múltiplas vezes sem erro.
--
-- Solução interina: o SGP-TELAS.md (estudo do módulo Papel) já modela papel_ordens /
-- papel_lotes / papel_lote_ordens para esse mesmo propósito, mas marca como "não
-- implementar ainda" até resolver bloqueios de negócio (ex.: se a data do PCP é
-- data de necessidade ou de execução). Esta tabela cobre as 3 granularidades de
-- liberação (bobina/projeto/peça) num único lugar, propositalmente mais simples,
-- para não desalinhar do modelo final quando ele for construído.

CREATE TABLE IF NOT EXISTS papel_liberacao_corte (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    granularidade           ENUM('bobina','projeto','peca') NOT NULL,
    chave_natural           VARCHAR(191) NOT NULL, -- composta em PHP, ver comentário por granularidade abaixo

    -- Contexto do modo "bobina": lote = uma semana de uma especificação de bobina
    -- (chave: mes_pcp|material|espessura|largura_bobina|semana — uma linha por semana liberada)
    mes_pcp                 VARCHAR(10)  NULL,
    semana                  INT          NULL,
    material                VARCHAR(50)  NULL,
    espessura                DECIMAL(6,2) NULL,
    largura_bobina           DECIMAL(8,2) NULL,

    -- Contexto do modo "projeto" (chave: tpd_projeto) e "peça" (chave: numero_of)
    tpd_projeto              VARCHAR(60) NULL,
    numero_of                VARCHAR(60) NULL,
    peca_nome                VARCHAR(160) NULL,

    qtd_pecas                INT NOT NULL DEFAULT 0,
    massa_kg                 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    descricao_snapshot       VARCHAR(255) NULL, -- texto legível p/ auditoria, ex.: "DIAMANTADO 1mm · Bobina 620mm"

    `status`                 ENUM('liberado','cancelado') NOT NULL DEFAULT 'liberado',
    id_usuario_liberacao     INT NULL,
    nome_usuario_liberacao   VARCHAR(100) NULL,
    data_liberacao           TIMESTAMP NULL DEFAULT NULL,
    id_usuario_cancelamento  INT NULL,
    data_cancelamento        TIMESTAMP NULL DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    KEY idx_papel_lib_chave (granularidade, chave_natural),
    KEY idx_papel_lib_tpd (tpd_projeto),
    KEY idx_papel_lib_of (numero_of),
    KEY idx_papel_lib_status (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
