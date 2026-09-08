-- =============================================================================
-- Cache local do VSAT: dw.vw_relacao_num_series_previo
-- Substitui a dependência de NS.OF.xlsx (Power Query, cache defasado) como
-- fonte primária do Paint Check — populada por scripts/sincronizar_vsat_num_series.ps1
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `vsat_num_series_previo` (
  `num_serie`         VARCHAR(20)  NOT NULL,
  `num_serie_cliente` VARCHAR(60)  DEFAULT NULL COMMENT 'NumSerieCliente / "Patrimônio" no relatório VSAT',
  `observacao`        VARCHAR(1000) DEFAULT NULL COMMENT 'Observacao (It_Pedido) — padrão "Item N - código"',
  `cd_referencia`     VARCHAR(30)  DEFAULT NULL,
  `descricao`         VARCHAR(255) DEFAULT NULL COMMENT 'ds_Prod',
  `cd_pedido`         VARCHAR(30)  DEFAULT NULL,
  `pedido_cliente`    VARCHAR(60)  DEFAULT NULL COMMENT 'PedidoCliente',
  `cliente`           VARCHAR(150) DEFAULT NULL COMMENT 'NomeCli',
  `cd_of`             VARCHAR(20)  DEFAULT NULL,
  `data_pcp`          DATE         DEFAULT NULL COMMENT 'DataEntraProducao',
  `atualizado_em`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`num_serie`),
  KEY `idx_vsat_num_series_previo_cliente` (`cliente`),
  KEY `idx_vsat_num_series_previo_cd_of` (`cd_of`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
