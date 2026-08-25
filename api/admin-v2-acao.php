<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!hasAcesso('admin') && !hasAcesso('adm.usu') && !hasAcesso('adm.per')) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Acesso negado.']);
    exit;
}

if (!podeEditar('admin') && !podeEditar('adm.usu') && !podeEditar('adm.per') && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Apenas consulta: você não tem permissão para realizar alterações administrativas.']);
    exit;
}

$acao = $_POST['acao'] ?? '';
$pdo = getDB();

if ($acao === 'salvar_perfil') {
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $cod = trim($_POST['cod'] ?? '');
    $grupo = trim($_POST['grupo'] ?? '');
    $desc = trim($_POST['desc'] ?? '');
    $status = trim($_POST['status'] ?? 'ativo');
    $perms = $_POST['perms'] ?? '{}';
    if (!is_string($perms) || (json_decode($perms) === null && json_last_error() !== JSON_ERROR_NONE)) {
        $perms = '{}';
    }
    
    if ($nome === '' || $cod === '') {
        echo json_encode(['sucesso' => false, 'erro' => 'Nome e código são obrigatórios.']);
        exit;
    }
    
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE perfis SET nome=?, cod=?, grupo=?, descricao=?, status=?, perms=? WHERE id=?');
            $stmt->execute([$nome, $cod, $grupo, $desc, $status, $perms, $id]);
            echo json_encode(['sucesso' => true]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO perfis (nome, cod, grupo, descricao, status, perms) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$nome, $cod, $grupo, $desc, $status, $perms]);
            $newId = (int)$pdo->lastInsertId();
            echo json_encode(['sucesso' => true, 'id' => $newId, 'criado' => date('d/m/Y')]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro interno ao salvar perfil.']);
    }
    exit;
}

if ($acao === 'salvar_setor') {
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $cod = trim($_POST['cod'] ?? '');
    $resp = trim($_POST['resp'] ?? '');
    $cc = trim($_POST['cc'] ?? '');
    $turnos = $_POST['turnos'] ?? '[]';
    if (!is_string($turnos) || (json_decode($turnos) === null && json_last_error() !== JSON_ERROR_NONE)) {
        $turnos = '[]';
    }
    $perfil = trim($_POST['perfil'] ?? '');
    $status = trim($_POST['status'] ?? 'ativo');
    
    if ($nome === '' || $cod === '') {
        echo json_encode(['sucesso' => false, 'erro' => 'Nome e sigla são obrigatórios.']);
        exit;
    }
    
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE setores SET nome=?, cod=?, resp=?, cc=?, turnos=?, perfil=?, status=?, ativo=? WHERE id=?');
            $stmt->execute([$nome, $cod, $resp, $cc, $turnos, $perfil, $status, ($status === 'ativo' ? 1 : 0), $id]);
            echo json_encode(['sucesso' => true]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO setores (nome, cod, resp, cc, turnos, perfil, status, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$nome, $cod, $resp, $cc, $turnos, $perfil, $status, ($status === 'ativo' ? 1 : 0)]);
            $newId = (int)$pdo->lastInsertId();
            echo json_encode(['sucesso' => true, 'id' => $newId, 'criado' => date('d/m/Y')]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro interno ao salvar setor.']);
    }
    exit;
}

if ($acao === 'excluir_setor') {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE id_setor = ? AND deleted_at IS NULL');
        $stmtCheck->execute([$id]);
        $count = (int)$stmtCheck->fetchColumn();
        if ($count > 0) {
            echo json_encode(['sucesso' => false, 'erro' => 'Não é possível excluir: existem usuários vinculados.']);
            exit;
        }
        $stmt = $pdo->prepare('DELETE FROM setores WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro interno ao excluir setor.']);
    }
    exit;
}

if ($acao === 'salvar_usuario') {
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $cpf = trim($_POST['cpf'] ?? '');
    $mat = trim($_POST['mat'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $id_setor = (int)($_POST['setor'] ?? 0);
    $id_perfil = (int)($_POST['perfil'] ?? 0);
    $status = trim($_POST['status'] ?? 'ativo');
    $excJson = $_POST['exc'] ?? '{}';
    $exc = json_decode($excJson, true) ?: [];

    // Limpar / formatar CPF
    $cpfClean = preg_replace('/[^\d]/', '', $cpf);
    if ($cpfClean !== '' && strlen($cpfClean) === 11) {
        $cpfFmt = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpfClean);
    } else {
        $cpfFmt = $cpf !== '' ? $cpf : null;
    }

    if ($nome === '' || $email === '') {
        echo json_encode(['sucesso' => false, 'erro' => 'Nome e e-mail são obrigatórios.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            // Verifica se o e-mail já existe noutro utilizador
            $stmtCheck = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id != ? AND deleted_at IS NULL');
            $stmtCheck->execute([$email, $id]);
            if ($stmtCheck->fetch()) {
                $pdo->rollBack();
                echo json_encode(['sucesso' => false, 'erro' => 'Este e-mail já está em uso.']);
                exit;
            }

            if ($senha !== '') {
                $senhaHash = password_hash($senha, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare('UPDATE usuarios SET nome=?, email=?, cpf=?, matricula=?, senha=?, id_setor=?, id_perfil=?, status=? WHERE id=?');
                $stmt->execute([$nome, $email, $cpfFmt, $mat, $senhaHash, $id_setor ?: null, $id_perfil ?: null, $status, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE usuarios SET nome=?, email=?, cpf=?, matricula=?, id_setor=?, id_perfil=?, status=? WHERE id=?');
                $stmt->execute([$nome, $email, $cpfFmt, $mat, $id_setor ?: null, $id_perfil ?: null, $status, $id]);
            }
        } else {
            // Verifica e-mail novo
            $stmtCheck = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND deleted_at IS NULL');
            $stmtCheck->execute([$email]);
            if ($stmtCheck->fetch()) {
                $pdo->rollBack();
                echo json_encode(['sucesso' => false, 'erro' => 'Este e-mail já está em uso.']);
                exit;
            }

            if ($senha === '') {
                $pdo->rollBack();
                echo json_encode(['sucesso' => false, 'erro' => 'Senha é obrigatória para novos usuários.']);
                exit;
            }

            $senhaHash = password_hash($senha, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, cpf, matricula, senha, id_setor, id_perfil, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$nome, $email, $cpfFmt, $mat, $senhaHash, $id_setor ?: null, $id_perfil ?: null, $status]);
            $id = (int)$pdo->lastInsertId();
        }

        // Atualizar Exceções (usuario_acessos)
        $stmtDel = $pdo->prepare('DELETE FROM usuario_acessos WHERE id_usuario = ?');
        $stmtDel->execute([$id]);

        if (!empty($exc)) {
            $stmtIns = $pdo->prepare('INSERT INTO usuario_acessos (id_usuario, tela, nivel) VALUES (?, ?, ?)');
            foreach ($exc as $tela => $nivel) {
                if (in_array($nivel, ['off', 'view', 'edit', 'total'])) {
                    $stmtIns->execute([$id, $tela, $nivel]);
                }
            }
        }

        $pdo->commit();
        echo json_encode(['sucesso' => true, 'id' => $id, 'criado' => date('d/m/Y')]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro ao salvar usuário: ' . $e->getMessage()]);
    }
    exit;
}

if ($acao === 'alterar_status_usuario') {
    $id = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? 'ativo');
    if ($status !== 'ativo' && $status !== 'inativo') {
        echo json_encode(['sucesso' => false, 'erro' => 'Status inválido.']);
        exit;
    }
    $curUser = currentUser();
    if ($id === (int)($curUser['id'] ?? 0) && $status === 'inativo') {
        echo json_encode(['sucesso' => false, 'erro' => 'Você não pode desativar seu próprio usuário.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare('UPDATE usuarios SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        echo json_encode(['sucesso' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro ao atualizar status: ' . $e->getMessage()]);
    }
    exit;
}

if ($acao === 'redefinir_senha') {
    $id = (int)($_POST['id'] ?? 0);
    $novaSenha = (string)($_POST['nova_senha'] ?? '');
    if (strlen($novaSenha) < 4) {
        echo json_encode(['sucesso' => false, 'erro' => 'A senha deve ter pelo menos 4 caracteres.']);
        exit;
    }
    try {
        $hash = password_hash($novaSenha, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $stmt->execute([$hash, $id]);
        echo json_encode(['sucesso' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro ao redefinir senha: ' . $e->getMessage()]);
    }
    exit;
}

if ($acao === 'excluir_usuario') {
    $id = (int)($_POST['id'] ?? 0);
    $curUser = currentUser();
    if ($id === (int)($curUser['id'] ?? 0)) {
        echo json_encode(['sucesso' => false, 'erro' => 'Você não pode excluir seu próprio usuário.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare('UPDATE usuarios SET deleted_at = NOW(), status = "inativo" WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['sucesso' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro ao excluir usuário: ' . $e->getMessage()]);
    }
    exit;
}

if ($acao === 'excluir_perfil') {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE id_perfil = ? AND deleted_at IS NULL');
        $stmtCheck->execute([$id]);
        $count = (int)$stmtCheck->fetchColumn();
        if ($count > 0) {
            echo json_encode(['sucesso' => false, 'erro' => 'Não é possível excluir: existem usuários com este perfil.']);
            exit;
        }
        $stmt = $pdo->prepare('DELETE FROM perfis WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['sucesso' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro ao excluir perfil: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['sucesso' => false, 'erro' => 'Ação não reconhecida.']);
