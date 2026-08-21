<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';

requireLogin();
$base = defined('APP_URL') ? APP_URL : '';
$aba = $_GET['tab'] ?? $_GET['aba'] ?? 'perfis';
header('Location: ' . $base . '/pages/admin/usuarios.php?aba=' . urlencode((string)$aba));
exit;
