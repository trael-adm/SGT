<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

// Permissão: Admin ou podeEditar(ret.cus) / tab:retrabalho
if (!isAdmin() && !podeEditar('ret.cus') && !podeEditar('tab:retrabalho')) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Você não tem permissão para alterar os custos e parâmetros de retrabalho.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido']);
    exit;
}

// Validação de CSRF Token
$token = (string) ($_POST['csrf_token'] ?? '');
if (!validarCsrfToken($token)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Token de segurança inválido ou expirado. Recarregue a página e tente novamente.']);
    exit;
}

$pdo = getDB();

try {
    $pdo->beginTransaction();

    // 1. Atualização pontual de uma única reprova (Auto-Save Rápido)
    if (isset($_POST['reprova_id']) && isset($_POST['tempo_minutos'])) {
        $idRep = (int) $_POST['reprova_id'];
        $minutos = (int) preg_replace('/[^\d]/', '', (string) $_POST['tempo_minutos']);
        if ($minutos < 0) $minutos = 0;

        if ($idRep > 0) {
            $stmtRep = $pdo->prepare("UPDATE reprovas SET tempo_padrao_minutos = :minutos WHERE id = :id");
            $stmtRep->execute(['minutos' => $minutos, 'id' => $idRep]);
        }

        $pdo->commit();
        echo json_encode([
            'sucesso'   => true,
            'mensagem'  => 'Tempo da reprova salvo automaticamente!'
        ]);
        exit;
    }

    // 2. Salvar Custo Hora-Homem e Horas Dia (se enviados)
    if (isset($_POST['custo_hora_homem'])) {
        $custoHoraStr = trim((string) $_POST['custo_hora_homem']);
        $custoHora = (float) str_replace(',', '.', $custoHoraStr);
        if ($custoHora < 0) $custoHora = 0.0;

        $stmtCfg = $pdo->prepare("
            INSERT INTO retrabalho_configuracoes (chave, valor, descricao)
            VALUES (:chave, :valor, :descricao)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_at = NOW()
        ");

        $stmtCfg->execute([
            'chave' => 'custo_hora_homem',
            'valor' => number_format($custoHora, 2, '.', ''),
            'descricao' => 'Custo médio da hora de trabalho para retrabalho (R$/h)'
        ]);
    }

    if (isset($_POST['horas_trabalho_dia'])) {
        $horasDiaStr = trim((string) $_POST['horas_trabalho_dia']);
        $horasDia = (float) str_replace(',', '.', $horasDiaStr);
        if ($horasDia <= 0) $horasDia = 8.80;

        $stmtCfg = $pdo->prepare("
            INSERT INTO retrabalho_configuracoes (chave, valor, descricao)
            VALUES (:chave, :valor, :descricao)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_at = NOW()
        ");

        $stmtCfg->execute([
            'chave' => 'horas_trabalho_dia',
            'valor' => number_format($horasDia, 2, '.', ''),
            'descricao' => 'Horas úteis de expediente padrão por dia'
        ]);
    }

    // 3. Salvar Tempos Padrão das Reprovas (Minutos) se enviados em lote
    $reprovasTempos = $_POST['reprovas_tempos'] ?? [];
    if (is_array($reprovasTempos) && !empty($reprovasTempos)) {
        $stmtRep = $pdo->prepare("
            UPDATE reprovas
            SET tempo_padrao_minutos = :minutos
            WHERE id = :id
        ");

        foreach ($reprovasTempos as $idRep => $minutosStr) {
            $id = (int) $idRep;
            $minutos = (int) preg_replace('/[^\d]/', '', (string) $minutosStr);
            if ($minutos < 0) $minutos = 0;

            if ($id > 0) {
                $stmtRep->execute([
                    'minutos' => $minutos,
                    'id'      => $id
                ]);
            }
        }
    }

    $pdo->commit();

    echo json_encode([
        'sucesso'   => true,
        'mensagem'  => 'Alterações salvas automaticamente com sucesso!'
    ]);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro'    => 'Erro ao salvar configurações: ' . $e->getMessage()
    ]);
}
