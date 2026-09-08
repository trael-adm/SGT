-- =============================================================================
-- Persistência das validações do Paint Check (checklist por campo) — auditoria
-- ISO + dataset de fine-tuning do reconhecimento do PaddleOCR (recorte + valor
-- correto do VSAT). Ver api/paint-check-salvar-validacao.php.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `paint_check_validacoes` (
  `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `num_serie`                VARCHAR(50)  NOT NULL,
  `cliente`                  VARCHAR(255) DEFAULT NULL,
  `nome_grupo`               VARCHAR(120) DEFAULT NULL,
  `concessionaria_fallback`  TINYINT(1)   NOT NULL DEFAULT 0,
  `todos_campos_ok`          TINYINT(1)   NOT NULL DEFAULT 0,
  `id_usuario`               INT          NOT NULL,
  `created_at`               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pcv_num_serie` (`num_serie`),
  KEY `idx_pcv_usuario` (`id_usuario`),
  CONSTRAINT `fk_pcv_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `paint_check_validacao_campos` (
  `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_validacao`             INT UNSIGNED NOT NULL,
  `slot`                     VARCHAR(40)  NOT NULL,
  `label`                    VARCHAR(120) NOT NULL,
  `status`                   ENUM('confirmado','divergente','ilegivel') NOT NULL,
  `esperado`                 VARCHAR(100) DEFAULT NULL,
  `leitura_proxima`          VARCHAR(100) DEFAULT NULL,
  `distancia`                TINYINT UNSIGNED DEFAULT NULL,
  `leituras_json`            TEXT DEFAULT NULL,
  `detalhe`                  VARCHAR(255) DEFAULT NULL,
  `confirmado_manualmente`   TINYINT(1)   NOT NULL DEFAULT 0,
  `foto_recorte_path`        VARCHAR(255) DEFAULT NULL,
  `created_at`               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pcvc_validacao` (`id_validacao`),
  CONSTRAINT `fk_pcvc_validacao` FOREIGN KEY (`id_validacao`) REFERENCES `paint_check_validacoes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
