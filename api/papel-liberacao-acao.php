<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if (!hasAcessoPapel()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso restrito ao módulo Papel']);
    exit;
}

$user = currentUser();
$acao = $_REQUEST['acao'] ?? '';

// Só 'verificar_liberacoes' é leitura (GET, chamada no load da página); as demais escrevem.
if ($acao !== 'verificar_liberacoes' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

try {
    $pdo = getDB();

    switch ($acao) {
        case 'verificar_liberacoes':
            $stmt = $pdo->query("
                SELECT granularidade, chave_natural, mes_pcp, semana, material, espessura,
                       largura_bobina, tpd_projeto, numero_of, peca_nome, qtd_pecas, massa_kg,
                       descricao_snapshot, nome_usuario_liberacao,
                       DATE_FORMAT(data_liberacao, '%d/%m/%Y %H:%i') AS data_liberacao_fmt
                FROM papel_liberacao_corte
                WHERE status = 'liberado' AND deleted_at IS NULL
            ");
            $liberacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'liberacoes' => $liberacoes
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'liberar_bobina':
            $mesPcp = trim($_POST['mes_pcp'] ?? '');
            $material = trim($_POST['material'] ?? '');
            $espessura = (float)($_POST['espessura'] ?? 0.0);
            $larguraBobina = (float)($_POST['largura_bobina'] ?? 0.0);
            $descricaoSnapshot = trim($_POST['descricao_snapshot'] ?? '');
            $itens = json_decode($_POST['itens'] ?? '[]', true);

            if ($mesPcp === '' || $material === '' || !is_array($itens) || count($itens) === 0) {
                throw new InvalidArgumentException('Dados insuficientes para liberar o lote de bobina.');
            }

            $nomeUsuario = $user['nome'] ?? 'Planejamento Papel';
            $semanasLiberadas = [];

            $pdo->beginTransaction();

            foreach ($itens as $item) {
                $semana = (int)($item['semana'] ?? 0);
                $qtdPecas = (int)($item['qtd_pecas'] ?? 0);
                $massaKg = (float)($item['massa_kg'] ?? 0.0);
                if ($semana <= 0) {
                    continue;
                }

                $chaveNatural = "{$mesPcp}|{$material}|{$espessura}|{$larguraBobina}|{$semana}";

                $stmtCheck = $pdo->prepare("
                    SELECT id FROM papel_liberacao_corte
                    WHERE granularidade = 'bobina' AND chave_natural = ? AND deleted_at IS NULL
                    LIMIT 1
                ");
                $stmtCheck->execute([$chaveNatural]);
                $existenteId = $stmtCheck->fetchColumn();

                if ($existenteId) {
                    $pdo->prepare("
                        UPDATE papel_liberacao_corte SET
                            qtd_pecas = ?, massa_kg = ?, descricao_snapshot = ?,
                            status = 'liberado', id_usuario_liberacao = ?, nome_usuario_liberacao = ?,
                            data_liberacao = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ")->execute([
                        $qtdPecas, $massaKg, $descricaoSnapshot,
                        $user['id'] ?? null, $nomeUsuario, $existenteId
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO papel_liberacao_corte (
                            granularidade, chave_natural, mes_pcp, semana, material, espessura,
                            largura_bobina, qtd_pecas, massa_kg, descricao_snapshot,
                            status, id_usuario_liberacao, nome_usuario_liberacao, data_liberacao
                        ) VALUES (
                            'bobina', ?, ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            'liberado', ?, ?, CURRENT_TIMESTAMP
                        )
                    ")->execute([
                        $chaveNatural, $mesPcp, $semana, $material, $espessura,
                        $larguraBobina, $qtdPecas, $massaKg, $descricaoSnapshot,
                        $user['id'] ?? null, $nomeUsuario
                    ]);
                }

                $semanasLiberadas[] = $semana;
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'mensagem' => 'Lote de bobina liberado com sucesso para o Chão de Fábrica!',
                'semanas_liberadas' => $semanasLiberadas
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'liberar_projeto':
            $tpdProjeto = trim($_POST['tpd_projeto'] ?? '');
            $ofsTotal = (int)($_POST['ofs_total'] ?? 0);
            $pecasTotal = (int)($_POST['pecas_total'] ?? 0);
            $massaTotal = (float)($_POST['massa_total'] ?? 0.0);
            $descricaoSnapshot = trim($_POST['descricao_snapshot'] ?? '');

            if ($tpdProjeto === '') {
                throw new InvalidArgumentException('Projeto (TPD) não informado para liberação.');
            }

            $nomeUsuario = $user['nome'] ?? 'Planejamento Papel';
            $chaveNatural = $tpdProjeto;

            $stmtCheck = $pdo->prepare("
                SELECT id FROM papel_liberacao_corte
                WHERE granularidade = 'projeto' AND chave_natural = ? AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmtCheck->execute([$chaveNatural]);
            $existenteId = $stmtCheck->fetchColumn();

            $pdo->beginTransaction();

            if ($existenteId) {
                $pdo->prepare("
                    UPDATE papel_liberacao_corte SET
                        qtd_pecas = ?, massa_kg = ?, descricao_snapshot = ?,
                        status = 'liberado', id_usuario_liberacao = ?, nome_usuario_liberacao = ?,
                        data_liberacao = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ")->execute([
                    $pecasTotal, $massaTotal, $descricaoSnapshot,
                    $user['id'] ?? null, $nomeUsuario, $existenteId
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO papel_liberacao_corte (
                        granularidade, chave_natural, tpd_projeto, qtd_pecas, massa_kg, descricao_snapshot,
                        status, id_usuario_liberacao, nome_usuario_liberacao, data_liberacao
                    ) VALUES (
                        'projeto', ?, ?, ?, ?, ?,
                        'liberado', ?, ?, CURRENT_TIMESTAMP
                    )
                ")->execute([
                    $chaveNatural, $tpdProjeto, $pecasTotal, $massaTotal, $descricaoSnapshot,
                    $user['id'] ?? null, $nomeUsuario
                ]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'mensagem' => "Kit Completo do Projeto {$tpdProjeto} ({$ofsTotal} OFs, {$pecasTotal} peças) liberado com sucesso para o Chão de Fábrica!"
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'liberar_peca':
            $itens = json_decode($_POST['itens'] ?? '[]', true);

            if (!is_array($itens) || count($itens) === 0) {
                throw new InvalidArgumentException('Nenhuma peça informada para liberação.');
            }

            $nomeUsuario = $user['nome'] ?? 'Planejamento Papel';
            $ofsLiberadas = [];

            $pdo->beginTransaction();

            foreach ($itens as $item) {
                $tpdProjeto = trim($item['tpd_projeto'] ?? '');
                $numeroOf = trim($item['numero_of'] ?? '');
                $pecaNome = trim($item['peca_nome'] ?? '');
                $qtdPecas = (int)($item['qtd_pecas'] ?? 0);
                if ($numeroOf === '') {
                    continue;
                }

                $chaveNatural = $numeroOf;

                $stmtCheck = $pdo->prepare("
                    SELECT id FROM papel_liberacao_corte
                    WHERE granularidade = 'peca' AND chave_natural = ? AND deleted_at IS NULL
                    LIMIT 1
                ");
                $stmtCheck->execute([$chaveNatural]);
                $existenteId = $stmtCheck->fetchColumn();

                if ($existenteId) {
                    $pdo->prepare("
                        UPDATE papel_liberacao_corte SET
                            qtd_pecas = ?, tpd_projeto = ?, peca_nome = ?,
                            status = 'liberado', id_usuario_liberacao = ?, nome_usuario_liberacao = ?,
                            data_liberacao = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ")->execute([
                        $qtdPecas, $tpdProjeto ?: null, $pecaNome ?: null,
                        $user['id'] ?? null, $nomeUsuario, $existenteId
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO papel_liberacao_corte (
                            granularidade, chave_natural, tpd_projeto, numero_of, peca_nome, qtd_pecas,
                            status, id_usuario_liberacao, nome_usuario_liberacao, data_liberacao
                        ) VALUES (
                            'peca', ?, ?, ?, ?, ?,
                            'liberado', ?, ?, CURRENT_TIMESTAMP
                        )
                    ")->execute([
                        $chaveNatural, $tpdProjeto ?: null, $numeroOf, $pecaNome ?: null, $qtdPecas,
                        $user['id'] ?? null, $nomeUsuario
                    ]);
                }

                $ofsLiberadas[] = $numeroOf;
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'mensagem' => count($ofsLiberadas) . ' peça(s) liberada(s) com sucesso para o Chão de Fábrica!',
                'ofs_liberadas' => $ofsLiberadas
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'cancelar_liberacao':
            $granularidade = trim($_POST['granularidade'] ?? '');
            $chaveNatural = trim($_POST['chave_natural'] ?? '');

            if (!in_array($granularidade, ['bobina', 'projeto', 'peca'], true) || $chaveNatural === '') {
                throw new InvalidArgumentException('Dados insuficientes para cancelar a liberação.');
            }

            $pdo->prepare("
                UPDATE papel_liberacao_corte SET
                    status = 'cancelado',
                    id_usuario_cancelamento = ?,
                    data_cancelamento = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE granularidade = ? AND chave_natural = ? AND deleted_at IS NULL
            ")->execute([$user['id'] ?? null, $granularidade, $chaveNatural]);

            echo json_encode([
                'success' => true,
                'mensagem' => 'Liberação cancelada.'
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Ação '{$acao}' inválida ou não especificada."]);
            break;
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
