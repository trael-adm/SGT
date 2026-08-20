<?php
declare(strict_types=1);

require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/session.php';

if (isLoggedIn()) {
    $usuario = currentUser();
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO logs_atividade (id_usuario, tipo, ip, user_agent)
            VALUES (?, 'logout', ?, ?)
        ");
        $stmt->execute([$usuario['id'], $ip, $ua]);
    } catch (PDOException) {
        // Log failure não impede o logout
    }
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"] ?? '/',
        $params["domain"] ?? '',
        (bool)($params["secure"] ?? false),
        (bool)($params["httponly"] ?? true)
    );
}

session_destroy();

header('Location: ' . APP_URL . '/login.php');
exit;
