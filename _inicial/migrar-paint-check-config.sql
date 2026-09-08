-- =============================================================================
-- Configuração do Paint Check: qual motor de OCR usar (paddleocr | vlm)
-- Switch visível só pro Administrador — o motor "vlm" só funciona onde o
-- Ollama + GPU estão disponíveis (servidor da empresa); no Railway (sem GPU)
-- o server.py cai automaticamente pro paddleocr mesmo que essa config peça vlm.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `paint_check_config` (
  `chave`      VARCHAR(50) NOT NULL PRIMARY KEY,
  `valor`      VARCHAR(255) NOT NULL,
  `descricao`  VARCHAR(255) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `paint_check_config` (`chave`, `valor`, `descricao`) VALUES
  ('motor_ocr', 'paddleocr', 'Motor de leitura do Paint Check: paddleocr (padrão, sempre disponível) ou vlm (Qwen2.5-VL local via Ollama, só funciona onde há GPU dedicada — servidor da empresa)')
ON DUPLICATE KEY UPDATE `descricao` = VALUES(`descricao`);
