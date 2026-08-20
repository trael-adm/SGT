<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

// ─── Autenticação e Autorização ───────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

if (!hasAcesso('tab:retrabalho') && !hasAcesso('tab:laboratorio') && !hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Sem permissão de acesso aos cadastros de projetos']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido']);
    exit;
}

$acao = trim((string) ($_POST['acao'] ?? ''));

try {
    $pdo = getDB();

    switch ($acao) {

        // ─── Pedido: registrar / editar ───────────────────────────────────────
        case 'pedido_salvar': {
            $id     = (int) ($_POST['id'] ?? 0);
            $numero = trim((string) ($_POST['numero'] ?? ''));

            if ($numero === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Informe o número do pedido.']);
                exit;
            }

            // Unicidade do número (ignorando o próprio registro em edição)
            $chk = $pdo->prepare("SELECT id FROM pedidos WHERE numero = ? AND deleted_at IS NULL AND id <> ?");
            $chk->execute([$numero, $id]);
            if ($chk->fetch()) {
                echo json_encode(['sucesso' => false, 'erro' => 'Já existe um pedido com esse número.']);
                exit;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE pedidos SET numero = ? WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$numero, $id]);
                echo json_encode(['sucesso' => true, 'mensagem' => 'Pedido atualizado.']);
            } else {
                $stmt = $pdo->prepare("INSERT INTO pedidos (numero) VALUES (?)");
                $stmt->execute([$numero]);
                echo json_encode(['sucesso' => true, 'mensagem' => 'Pedido cadastrado.', 'id' => (int) $pdo->lastInsertId()]);
            }
            break;
        }

        // ─── Pedido: definir prioridade (página independente de controle) ─────
        case 'pedido_prioridade': {
            $id         = (int) ($_POST['id'] ?? 0);
            $prioridade = trim((string) ($_POST['prioridade'] ?? ''));
            $sequencia  = (int) ($_POST['sequencia'] ?? 0);
            $validas    = ['emergente', 'urgente', 'importante', 'neutro'];

            if ($id <= 0 || !in_array($prioridade, $validas, true)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Pedido ou prioridade inválidos.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE pedidos SET prioridade = ?, sequencia = ? WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$prioridade, $sequencia, $id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Prioridade do pedido atualizada.']);
            break;
        }

        // ─── Projeto: definir prioridade ──────────────────────────────────────
        case 'projeto_prioridade': {
            $id         = (int) ($_POST['id'] ?? 0);
            $prioridade = trim((string) ($_POST['prioridade'] ?? ''));
            $sequencia  = (int) ($_POST['sequencia'] ?? 0);
            $validas    = ['emergente', 'urgente', 'importante', 'neutro'];

            if ($id <= 0 || !in_array($prioridade, $validas, true)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Projeto ou prioridade inválidos.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE projetos SET prioridade = ?, sequencia = ? WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$prioridade, $sequencia, $id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Prioridade do projeto atualizada.']);
            break;
        }

        // ─── NS: definir prioridade ───────────────────────────────────────────
        case 'ns_prioridade': {
            $ns         = trim((string) ($_POST['ns_transformador'] ?? ''));
            $prioridade = trim((string) ($_POST['prioridade'] ?? ''));
            $sequencia  = (int) ($_POST['sequencia'] ?? 0);
            $validas    = ['emergente', 'urgente', 'importante', 'neutro'];

            if ($ns === '' || !in_array($prioridade, $validas, true)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'N° de série ou prioridade inválidos.']);
                exit;
            }

            // Atualiza todos os registros abertos deste NS
            $stmt = $pdo->prepare("UPDATE retrabalhos SET prioridade = ?, sequencia = ? WHERE ns_transformador = ? AND deleted_at IS NULL");
            $stmt->execute([$prioridade, $sequencia, $ns]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Prioridade do N° de série atualizada.']);
            break;
        }

        // ─── Pedido: excluir (soft delete) ────────────────────────────────────
        case 'pedido_excluir': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Pedido inválido.']);
                exit;
            }
            // Bloquear exclusão se houver projetos vinculados
            $c = $pdo->prepare("SELECT COUNT(*) FROM projetos WHERE id_pedido = ? AND deleted_at IS NULL");
            $c->execute([$id]);
            if ((int) $c->fetchColumn() > 0) {
                echo json_encode(['sucesso' => false, 'erro' => 'Não é possível excluir: o pedido possui projetos vinculados.']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE pedidos SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Pedido excluído.']);
            break;
        }

        // ─── Projeto: registrar / editar ──────────────────────────────────────
        case 'projeto_salvar': {
            $id        = (int) ($_POST['id'] ?? 0);
            $codigo    = trim((string) ($_POST['codigo'] ?? ''));
            $descricao = trim((string) ($_POST['descricao'] ?? ''));
            $idPedido  = (int) ($_POST['id_pedido'] ?? 0);

            if ($codigo === '' || $idPedido <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Informe o código do projeto e o pedido.']);
                exit;
            }

            // Pedido precisa existir
            $p = $pdo->prepare("SELECT id FROM pedidos WHERE id = ? AND deleted_at IS NULL");
            $p->execute([$idPedido]);
            if (!$p->fetch()) {
                echo json_encode(['sucesso' => false, 'erro' => 'Pedido inválido.']);
                exit;
            }

            // Unicidade do código (ignorando o próprio registro em edição)
            $chk = $pdo->prepare("SELECT id FROM projetos WHERE codigo = ? AND deleted_at IS NULL AND id <> ?");
            $chk->execute([$codigo, $id]);
            if ($chk->fetch()) {
                echo json_encode(['sucesso' => false, 'erro' => 'Já existe um projeto com esse código.']);
                exit;
            }

            $descricao = $descricao !== '' ? $descricao : null;

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE projetos SET codigo = ?, descricao = ?, id_pedido = ? WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$codigo, $descricao, $idPedido, $id]);
                echo json_encode(['sucesso' => true, 'mensagem' => 'Projeto atualizado.']);
            } else {
                $stmt = $pdo->prepare("INSERT INTO projetos (codigo, descricao, id_pedido) VALUES (?, ?, ?)");
                $stmt->execute([$codigo, $descricao, $idPedido]);
                echo json_encode(['sucesso' => true, 'mensagem' => 'Projeto cadastrado.', 'id' => (int) $pdo->lastInsertId()]);
            }
            break;
        }

        // ─── Projeto: excluir (soft delete) ───────────────────────────────────
        case 'projeto_excluir': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Projeto inválido.']);
                exit;
            }
            // Bloquear exclusão se houver retrabalhos vinculados
            $c = $pdo->prepare("SELECT COUNT(*) FROM retrabalhos WHERE id_projeto = ? AND deleted_at IS NULL");
            $c->execute([$id]);
            if ((int) $c->fetchColumn() > 0) {
                echo json_encode(['sucesso' => false, 'erro' => 'Não é possível excluir: o projeto possui retrabalhos vinculados.']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE projetos SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Projeto excluído.']);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'Ação desconhecida.']);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao processar a solicitação.']);
}
