<?php
require_once __DIR__ . '/../config/conexao.php';

$pdo = getDB();
$sql = "
CREATE TABLE IF NOT EXISTS `sgt_paintcheck_historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `data_hora` datetime NOT NULL,
  `total_transformadores` int(11) NOT NULL DEFAULT 0,
  `validos` int(11) NOT NULL DEFAULT 0,
  `invalidos` int(11) NOT NULL DEFAULT 0,
  `resultados_json` longtext NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_id_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sgt_paintcheck_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chave` varchar(100) NOT NULL,
  `valor_json` longtext NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_chave` (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    $pdo->exec($sql);
    echo "Tabelas do Paint Check criadas com sucesso!\n";
} catch (PDOException $e) {
    echo "Erro ao criar tabelas: " . $e->getMessage() . "\n";
}
