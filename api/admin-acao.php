<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Acesso negado.']);
    exit;
}

$acao = $_POST['acao'] ?? '';

if ($acao === 'salvar_acessos') {
    $idUsuario = (int) ($_POST['id_usuario'] ?? 0);
    $acessos = $_POST['acessos'] ?? []; // Array de recursos
    if (!is_array($acessos)) $acessos = [];
    
    try {
        $pdo = getDB();
        $pdo->beginTransaction();
        
        // Remove todos para recriar
        $stmtDel = $pdo->prepare('DELETE FROM usuario_acessos WHERE id_usuario = ?');
        $stmtDel->execute([$idUsuario]);
        
        if (!empty($acessos)) {
            $stmtIns = $pdo->prepare('INSERT IGNORE INTO usuario_acessos (id_usuario, tela) VALUES (?, ?)');
            foreach ($acessos as $rec) {
                $rec = trim((string)$rec);
                if ($rec !== '') {
                    $stmtIns->execute([$idUsuario, $rec]);
                }
            }
        }
        
        $pdo->commit();
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro interno ao salvar os acessos.']);
    }
    exit;
}

if ($acao === 'criar_usuario') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $idPerfil = (int)($_POST['id_perfil'] ?? 3);
    $eExecutor = isset($_POST['e_executor']) ? 1 : 0;

    if ($nome === '' || $email === '' || $senha === '') {
        echo json_encode(['sucesso' => false, 'erro' => 'Preencha todos os campos obrigatórios.']);
        exit;
    }

    try {
        $pdo = getDB();
        
        // Verifica se o e-mail já existe
        $stmtCheck = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND deleted_at IS NULL');
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetch()) {
            echo json_encode(['sucesso' => false, 'erro' => 'Este e-mail já está em uso por outro usuário ativo.']);
            exit;
        }

        $senhaHash = password_hash($senha, PASSWORD_BCRYPT);
        
        $stmtIns = $pdo->prepare('INSERT INTO usuarios (nome, email, senha, id_perfil, e_executor) VALUES (?, ?, ?, ?, ?)');
        $stmtIns->execute([$nome, $email, $senhaHash, $idPerfil, $eExecutor]);
        
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro interno ao criar usuário.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['sucesso' => false, 'erro' => 'Ação não reconhecida.']);
