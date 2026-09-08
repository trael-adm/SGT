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

try {
    $pdo = getDB();

    switch ($acao) {
        case 'listar_inventario':
            $rua = trim($_GET['rua'] ?? '');
            $categoria = trim($_GET['categoria'] ?? '');
            $material = trim($_GET['material'] ?? '');
            $espessura = trim($_GET['espessura'] ?? '');
            $status = trim($_GET['status'] ?? '');
            $busca = trim($_GET['busca'] ?? '');

            $where = ['deleted_at IS NULL'];
            $params = [];

            if ($rua !== '') {
                $where[] = 'rua = :rua';
                $params[':rua'] = $rua;
            }
            if ($categoria !== '') {
                $where[] = 'categoria = :categoria';
                $params[':categoria'] = $categoria;
            }
            if ($material !== '') {
                $where[] = 'material = :material';
                $params[':material'] = $material;
            }
            if ($espessura !== '') {
                $where[] = 'espessura = :espessura';
                $params[':espessura'] = (float)$espessura;
            }
            if ($status !== '') {
                $where[] = 'status = :status';
                $params[':status'] = $status;
            }
            if ($busca !== '') {
                $where[] = '(tamanho LIKE :b1 OR tpd_projeto LIKE :b2 OR rua LIKE :b3 OR prateleira LIKE :b4 OR modelo LIKE :b5 OR caixa LIKE :b6)';
                $params[':b1'] = "%{$busca}%";
                $params[':b2'] = "%{$busca}%";
                $params[':b3'] = "%{$busca}%";
                $params[':b4'] = "%{$busca}%";
                $params[':b5'] = "%{$busca}%";
                $params[':b6'] = "%{$busca}%";
            }

            $sqlWhere = implode(' AND ', $where);

            // KPIs consolidados
            $stmtKpi = $pdo->query("
                SELECT 
                    COUNT(*) as total_itens,
                    COALESCE(SUM(CASE WHEN status = 'disponivel' THEN estoque_real_kg ELSE 0 END), 0) as total_kg_disponivel,
                    COUNT(DISTINCT rua) as total_ruas,
                    COUNT(CASE WHEN categoria = 'kit_bt' AND status = 'disponivel' THEN 1 END) as total_kits_bt,
                    COUNT(CASE WHEN categoria = 'cabeceiras' AND status = 'disponivel' THEN 1 END) as total_cabeceiras
                FROM papel_estoque_inventario
                WHERE deleted_at IS NULL
            ");
            $kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

            // Listagem de registros
            $stmt = $pdo->prepare("
                SELECT *
                FROM papel_estoque_inventario
                WHERE {$sqlWhere}
                ORDER BY rua ASC, prateleira ASC, tamanho ASC
            ");
            $stmt->execute($params);
            $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'kpis' => $kpis,
                'total' => count($itens),
                'itens' => $itens
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'salvar_item':
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $categoria = trim($_POST['categoria'] ?? 'papel_tiras');
            $rua = trim($_POST['rua'] ?? '');
            $prateleira = trim($_POST['prateleira'] ?? '');
            $caixa = trim($_POST['caixa'] ?? '');
            $tipo_papel = trim($_POST['tipo_papel'] ?? 'DOBRA');
            $material = trim($_POST['material'] ?? 'DIAMANTADO');
            $modelo = trim($_POST['modelo'] ?? 'DT');
            $tamanho = trim($_POST['tamanho'] ?? '');
            $espessura = !empty($_POST['espessura']) ? (float)$_POST['espessura'] : 0.0;
            $tpd_projeto = trim($_POST['tpd_projeto'] ?? '');
            $estoque_real_kg = !empty($_POST['estoque_real_kg']) ? (float)$_POST['estoque_real_kg'] : 0.0;
            $estoque_real_qtd = !empty($_POST['estoque_real_qtd']) ? (int)$_POST['estoque_real_qtd'] : 0;
            $observacoes = trim($_POST['observacoes'] ?? '');
            $status = trim($_POST['status'] ?? 'disponivel');

            if ($rua === '') {
                throw new InvalidArgumentException('Informe a Rua do almoxarifado.');
            }
            if ($tamanho === '' && $categoria !== 'kit_bt') {
                throw new InvalidArgumentException('Informe o Tamanho ou especificação da peça/tira.');
            }

            if ($id) {
                // Atualização
                $stmtAnt = $pdo->prepare("SELECT estoque_real_kg, estoque_real_qtd FROM papel_estoque_inventario WHERE id = ?");
                $stmtAnt->execute([$id]);
                $ant = $stmtAnt->fetch(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("
                    UPDATE papel_estoque_inventario SET
                        categoria = :categoria,
                        rua = :rua,
                        prateleira = :prateleira,
                        caixa = :caixa,
                        tipo_papel = :tipo_papel,
                        material = :material,
                        modelo = :modelo,
                        tamanho = :tamanho,
                        espessura = :espessura,
                        tpd_projeto = :tpd_projeto,
                        estoque_real_kg = :estoque_real_kg,
                        estoque_real_qtd = :estoque_real_qtd,
                        observacoes = :observacoes,
                        status = :status,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':categoria' => $categoria,
                    ':rua' => $rua,
                    ':prateleira' => $prateleira ?: null,
                    ':caixa' => $caixa ?: null,
                    ':tipo_papel' => $tipo_papel,
                    ':material' => $material,
                    ':modelo' => $modelo ?: null,
                    ':tamanho' => $tamanho ?: null,
                    ':espessura' => $espessura,
                    ':tpd_projeto' => $tpd_projeto ?: null,
                    ':estoque_real_kg' => $estoque_real_kg,
                    ':estoque_real_qtd' => $estoque_real_qtd,
                    ':observacoes' => $observacoes ?: null,
                    ':status' => $status,
                    ':id' => $id
                ]);

                // Registrar ajuste se saldo mudou
                if ($ant && (float)$ant['estoque_real_kg'] !== $estoque_real_kg) {
                    $pdo->prepare("
                        INSERT INTO papel_estoque_movimentacoes (
                            id_estoque, tipo_movimentacao, quantidade_kg, saldo_anterior_kg, saldo_novo_kg,
                            motivo_descricao, id_usuario
                        ) VALUES (?, 'ajuste', ?, ?, ?, 'Ajuste manual de saldo no SGT', ?)
                    ")->execute([
                        $id,
                        abs($estoque_real_kg - (float)$ant['estoque_real_kg']),
                        (float)$ant['estoque_real_kg'],
                        $estoque_real_kg,
                        $user['id'] ?? null
                    ]);
                }

                echo json_encode(['success' => true, 'mensagem' => 'Item atualizado com sucesso!']);
            } else {
                // Inserção de novo item
                $stmt = $pdo->prepare("
                    INSERT INTO papel_estoque_inventario (
                        categoria, rua, prateleira, caixa, tipo_papel, material, modelo,
                        tamanho, espessura, tpd_projeto, estoque_inicial_kg, estoque_real_kg,
                        estoque_real_qtd, observacoes, status
                    ) VALUES (
                        :categoria, :rua, :prateleira, :caixa, :tipo_papel, :material, :modelo,
                        :tamanho, :espessura, :tpd_projeto, :estoque_real_kg, :estoque_real_kg,
                        :estoque_real_qtd, :observacoes, :status
                    )
                ");
                $stmt->execute([
                    ':categoria' => $categoria,
                    ':rua' => $rua,
                    ':prateleira' => $prateleira ?: null,
                    ':caixa' => $caixa ?: null,
                    ':tipo_papel' => $tipo_papel,
                    ':material' => $material,
                    ':modelo' => $modelo ?: null,
                    ':tamanho' => $tamanho ?: null,
                    ':espessura' => $espessura,
                    ':tpd_projeto' => $tpd_projeto ?: null,
                    ':estoque_real_kg' => $estoque_real_kg,
                    ':estoque_real_qtd' => $estoque_real_qtd,
                    ':observacoes' => $observacoes ?: null,
                    ':status' => $status
                ]);
                $newId = (int)$pdo->lastInsertId();

                // Registrar movimentação de entrada
                $pdo->prepare("
                    INSERT INTO papel_estoque_movimentacoes (
                        id_estoque, tipo_movimentacao, quantidade_kg, saldo_anterior_kg, saldo_novo_kg,
                        motivo_descricao, id_usuario
                    ) VALUES (?, 'entrada', ?, 0.0, ?, 'Cadastro manual de item no Almoxarifado', ?)
                ")->execute([
                    $newId,
                    $estoque_real_kg,
                    $estoque_real_kg,
                    $user['id'] ?? null
                ]);

                echo json_encode(['success' => true, 'mensagem' => 'Novo item cadastrado no Almoxarifado!']);
            }
            break;

        case 'dar_baixa':
            $id = (int)($_POST['id'] ?? 0);
            $qtdBaixaKg = !empty($_POST['quantidade_kg']) ? (float)$_POST['quantidade_kg'] : 0.0;
            $qtdBaixaUn = !empty($_POST['quantidade_qtd']) ? (int)$_POST['quantidade_qtd'] : 0;
            $motivo = trim($_POST['motivo'] ?? 'Baixa manual de retirada');
            $responsavel = trim($_POST['responsavel_retirada'] ?? ($user['nome'] ?? 'Operador'));

            if ($id <= 0) {
                throw new InvalidArgumentException('ID do item de estoque inválido.');
            }

            $stmt = $pdo->prepare("SELECT * FROM papel_estoque_inventario WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new RuntimeException('Item de estoque não encontrado.');
            }

            $saldoAtualKg = (float)$item['estoque_real_kg'];
            $saldoAtualQtd = (int)$item['estoque_real_qtd'];

            if ($qtdBaixaKg > $saldoAtualKg && $saldoAtualKg > 0) {
                throw new InvalidArgumentException("Quantidade informada ({$qtdBaixaKg} kg) é maior que o saldo atual ({$saldoAtualKg} kg).");
            }

            $novoSaldoKg = max(0.0, $saldoAtualKg - $qtdBaixaKg);
            $novoSaldoQtd = max(0, $saldoAtualQtd - $qtdBaixaUn);
            $novoStatus = ($novoSaldoKg <= 0 && $novoSaldoQtd <= 0) ? 'consumido' : 'disponivel';

            $pdo->beginTransaction();

            $pdo->prepare("
                UPDATE papel_estoque_inventario SET
                    estoque_real_kg = ?,
                    estoque_real_qtd = ?,
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$novoSaldoKg, $novoSaldoQtd, $novoStatus, $id]);

            $pdo->prepare("
                INSERT INTO papel_estoque_movimentacoes (
                    id_estoque, tipo_movimentacao, quantidade_kg, quantidade_qtd,
                    saldo_anterior_kg, saldo_novo_kg, motivo_descricao, responsavel_retirada, id_usuario
                ) VALUES (?, 'saida', ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $id,
                $qtdBaixaKg,
                $qtdBaixaUn,
                $saldoAtualKg,
                $novoSaldoKg,
                $motivo,
                $responsavel,
                $user['id'] ?? null
            ]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'mensagem' => "Baixa de {$qtdBaixaKg} kg registrada com sucesso! Novo saldo: {$novoSaldoKg} kg.",
                'novo_saldo_kg' => $novoSaldoKg,
                'novo_status' => $novoStatus
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'verificar_matches':
            // Retorna todos os itens disponíveis do inventário para fazer o match no frontend e backend
            $stmtEstoque = $pdo->query("
                SELECT id, categoria, rua, prateleira, caixa, tipo_papel, material, modelo,
                       tamanho, espessura, tpd_projeto, sequencia, mes_pcp,
                       estoque_real_kg, estoque_real_qtd, status
                FROM papel_estoque_inventario
                WHERE deleted_at IS NULL AND status IN ('disponivel', 'reservado')
                ORDER BY rua ASC, prateleira ASC
            ");
            $estoqueItens = $stmtEstoque->fetchAll(PDO::FETCH_ASSOC);

            // Retorna histórico de decisões já tomadas
            $stmtDecisoes = $pdo->query("
                SELECT id, tpd_projeto, of_filha, peca_nome, peca_idx, id_estoque_inventario,
                       qtd_aproveitada, massa_aproveitada_kg, status, nome_usuario_decisao,
                       DATE_FORMAT(data_decisao, '%d/%m/%Y %H:%i') as data_decisao_fmt, motivo_rejeicao
                FROM papel_aproveitamento_analise
                WHERE deleted_at IS NULL
            ");
            $decisoes = $stmtDecisoes->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'estoque' => $estoqueItens,
                'decisoes' => $decisoes
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'aprovar_aproveitamento':
            $idEstoque = (int)($_POST['id_estoque'] ?? 0);
            $tpdProjeto = trim($_POST['tpd_projeto'] ?? '');
            $ofFilha = trim($_POST['of_filha'] ?? '');
            $pecaNome = trim($_POST['peca_nome'] ?? '');
            $pecaIdx = (int)($_POST['peca_idx'] ?? 0);
            $material = trim($_POST['material'] ?? 'DIAMANTADO');
            $espessura = (float)($_POST['espessura'] ?? 0.0);
            $tamanhoDemanda = trim($_POST['tamanho_demanda'] ?? '');
            $qtdDemandada = (int)($_POST['qtd_demandada'] ?? 0);
            $massaDemandadaKg = (float)($_POST['massa_demandada_kg'] ?? 0.0);

            if ($idEstoque <= 0 || $tpdProjeto === '') {
                throw new InvalidArgumentException('Dados insuficientes para aprovação de aproveitamento.');
            }

            $stmt = $pdo->prepare("SELECT * FROM papel_estoque_inventario WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$idEstoque]);
            $estoque = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$estoque) {
                throw new RuntimeException('Item de estoque não encontrado.');
            }

            $pdo->beginTransaction();

            $nomeUsuario = $user['nome'] ?? 'Liderança Papel';

            // Registra ou atualiza a aprovação na tabela de análise
            $stmtCheck = $pdo->prepare("
                SELECT id FROM papel_aproveitamento_analise 
                WHERE tpd_projeto = ? AND peca_idx = ? AND (of_filha = ? OR of_filha IS NULL)
                LIMIT 1
            ");
            $stmtCheck->execute([$tpdProjeto, $pecaIdx, $ofFilha]);
            $aprovId = $stmtCheck->fetchColumn();

            if ($aprovId) {
                $pdo->prepare("
                    UPDATE papel_aproveitamento_analise SET
                        id_estoque_inventario = ?,
                        qtd_aproveitada = ?,
                        massa_aproveitada_kg = ?,
                        status = 'aprovado',
                        id_usuario_decisao = ?,
                        nome_usuario_decisao = ?,
                        data_decisao = CURRENT_TIMESTAMP,
                        motivo_rejeicao = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ")->execute([
                    $idEstoque,
                    $qtdDemandada,
                    $massaDemandadaKg,
                    $user['id'] ?? null,
                    $nomeUsuario,
                    $aprovId
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO papel_aproveitamento_analise (
                        tpd_projeto, of_filha, peca_nome, peca_idx, material, espessura,
                        tamanho_demanda, qtd_demandada, massa_demandada_kg,
                        id_estoque_inventario, qtd_aproveitada, massa_aproveitada_kg,
                        status, id_usuario_decisao, nome_usuario_decisao, data_decisao
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        'aprovado', ?, ?, CURRENT_TIMESTAMP
                    )
                ")->execute([
                    $tpdProjeto,
                    $ofFilha ?: null,
                    $pecaNome,
                    $pecaIdx,
                    $material,
                    $espessura,
                    $tamanhoDemanda ?: null,
                    $qtdDemandada,
                    $massaDemandadaKg,
                    $idEstoque,
                    $qtdDemandada,
                    $massaDemandadaKg,
                    $user['id'] ?? null,
                    $nomeUsuario
                ]);
            }

            // Abate saldo do estoque e registra movimentação
            $saldoAntKg = (float)$estoque['estoque_real_kg'];
            $saldoNovoKg = max(0.0, $saldoAntKg - $massaDemandadaKg);
            $novoStatusEstoque = ($saldoNovoKg <= 0 && (int)$estoque['estoque_real_qtd'] <= 1) ? 'reservado' : 'disponivel';

            $pdo->prepare("
                UPDATE papel_estoque_inventario SET
                    estoque_real_kg = ?,
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$saldoNovoKg, $novoStatusEstoque, $idEstoque]);

            $localizacaoTexto = "Rua {$estoque['rua']}" . (!empty($estoque['prateleira']) ? " / Prat. {$estoque['prateleira']}" : "") . (!empty($estoque['caixa']) ? " / Caixa {$estoque['caixa']}" : "");

            $pdo->prepare("
                INSERT INTO papel_estoque_movimentacoes (
                    id_estoque, tipo_movimentacao, quantidade_kg, saldo_anterior_kg, saldo_novo_kg,
                    motivo_descricao, tpd_projeto, of_filha, responsavel_retirada, id_usuario
                ) VALUES (?, 'reserva', ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $idEstoque,
                $massaDemandadaKg,
                $saldoAntKg,
                $saldoNovoKg,
                "Aproveitamento aprovado para o projeto {$tpdProjeto} ({$pecaNome})",
                $tpdProjeto,
                $ofFilha ?: null,
                $nomeUsuario,
                $user['id'] ?? null
            ]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'mensagem' => "✅ Aproveitamento APROVADO com sucesso!\n\nLocal de Retirada: {$localizacaoTexto}\nTamanho em Estoque: {$estoque['tamanho']}\nQuantidade Abatida da Máquina: {$qtdDemandada} pçs ({$massaDemandadaKg} kg)",
                'localizacao' => $localizacaoTexto,
                'tamanho_estoque' => $estoque['tamanho']
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'rejeitar_aproveitamento':
            $idEstoque = (int)($_POST['id_estoque'] ?? 0);
            $tpdProjeto = trim($_POST['tpd_projeto'] ?? '');
            $ofFilha = trim($_POST['of_filha'] ?? '');
            $pecaNome = trim($_POST['peca_nome'] ?? '');
            $pecaIdx = (int)($_POST['peca_idx'] ?? 0);
            $material = trim($_POST['material'] ?? 'DIAMANTADO');
            $espessura = (float)($_POST['espessura'] ?? 0.0);
            $motivo = trim($_POST['motivo_rejeicao'] ?? 'Cortar novo na máquina');

            if ($idEstoque <= 0 || $tpdProjeto === '') {
                throw new InvalidArgumentException('Dados insuficientes para rejeição.');
            }

            $nomeUsuario = $user['nome'] ?? 'Liderança Papel';

            $stmtCheck = $pdo->prepare("
                SELECT id FROM papel_aproveitamento_analise 
                WHERE tpd_projeto = ? AND peca_idx = ? AND (of_filha = ? OR of_filha IS NULL)
                LIMIT 1
            ");
            $stmtCheck->execute([$tpdProjeto, $pecaIdx, $ofFilha]);
            $aprovId = $stmtCheck->fetchColumn();

            if ($aprovId) {
                $pdo->prepare("
                    UPDATE papel_aproveitamento_analise SET
                        status = 'rejeitado',
                        id_usuario_decisao = ?,
                        nome_usuario_decisao = ?,
                        data_decisao = CURRENT_TIMESTAMP,
                        motivo_rejeicao = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ")->execute([
                    $user['id'] ?? null,
                    $nomeUsuario,
                    $motivo,
                    $aprovId
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO papel_aproveitamento_analise (
                        tpd_projeto, of_filha, peca_nome, peca_idx, material, espessura,
                        id_estoque_inventario, status, id_usuario_decisao, nome_usuario_decisao,
                        data_decisao, motivo_rejeicao
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, 'rejeitado', ?, ?,
                        CURRENT_TIMESTAMP, ?
                    )
                ")->execute([
                    $tpdProjeto,
                    $ofFilha ?: null,
                    $pecaNome,
                    $pecaIdx,
                    $material,
                    $espessura,
                    $idEstoque,
                    $user['id'] ?? null,
                    $nomeUsuario,
                    $motivo
                ]);
            }

            echo json_encode([
                'success' => true,
                'mensagem' => 'Aproveitamento rejeitado. A peça será cortada normalmente na máquina.'
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
