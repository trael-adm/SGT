<?php
declare(strict_types=1);

/**
 * SOMA — API Central de Ações e Mutações (Trael PCP)
 */

require_once dirname(__DIR__) . '/config/conexao.php';
require_once dirname(__DIR__) . '/config/session.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/soma-helpers.php';

somaGarantirTabelas();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido.']);
    exit;
}

// 1. Exige autenticação
if (!isset($_SESSION['usuario']['id'])) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

// 2. Validação CSRF
if (!verificarCsrf()) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Token de segurança CSRF inválido ou expirado. Recarregue a página.']);
    exit;
}

$acao = trim((string) ($_POST['acao'] ?? ''));
$db = getDB();
$usuarioId = (int) $_SESSION['usuario']['id'];
$idPerfil = (int) ($_SESSION['usuario']['id_perfil'] ?? 0);

try {
    switch ($acao) {
        // ========================================================
        // EMPRESAS
        // ========================================================
        case 'salvar_empresa':
            requirePerfil([1, 2]);
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $cod = strtoupper(trim((string) ($_POST['cod'] ?? '')));
            $nome = trim((string) ($_POST['nome'] ?? ''));
            $ativo = isset($_POST['ativo']) ? (int) $_POST['ativo'] : 1;

            if ($cod === '' || $nome === '') {
                throw new InvalidArgumentException('Código e Nome da Empresa são obrigatórios.');
            }

            // Verifica unicidade de código
            $chk = $db->prepare('SELECT id FROM soma_empresas WHERE cod = :cod AND deleted_at IS NULL AND (:id IS NULL OR id != :id_comp) LIMIT 1');
            $chk->execute(['cod' => $cod, 'id' => $id, 'id_comp' => $id]);
            if ($chk->fetch()) {
                throw new InvalidArgumentException("O código de empresa '{$cod}' já está cadastrado.");
            }

            if ($id) {
                $stmt = $db->prepare('UPDATE soma_empresas SET cod = :cod, nome = :nome, ativo = :ativo WHERE id = :id AND deleted_at IS NULL');
                $stmt->execute(['cod' => $cod, 'nome' => $nome, 'ativo' => $ativo, 'id' => $id]);
                registrarLog('config_editar_empresa', "Empresa editada: {$cod} - {$nome} (ID {$id})");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Empresa atualizada com sucesso!', 'id' => $id]);
            } else {
                $stmt = $db->prepare('INSERT INTO soma_empresas (cod, nome, ativo, id_criador) VALUES (:cod, :nome, :ativo, :uid)');
                $stmt->execute(['cod' => $cod, 'nome' => $nome, 'ativo' => $ativo, 'uid' => $usuarioId]);
                $novoId = (int) $db->lastInsertId();
                registrarLog('config_criar_empresa', "Empresa criada: {$cod} - {$nome} (ID {$novoId})");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Empresa cadastrada com sucesso!', 'id' => $novoId]);
            }
            break;

        case 'excluir_empresa':
            requirePerfil([1, 2]);
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('ID inválido para exclusão.');

            $stmt = $db->prepare('UPDATE soma_empresas SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $id]);
            registrarLog('config_excluir_empresa', "Empresa excluída (soft delete ID {$id})");
            echo json_encode(['sucesso' => true, 'mensagem' => 'Empresa excluída com sucesso!']);
            break;

        // ========================================================
        // SETORES
        // ========================================================
        case 'salvar_setor':
            requirePerfil([1, 2]);
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $cod = strtoupper(trim((string) ($_POST['cod'] ?? '')));
            $descricao = trim((string) ($_POST['descricao'] ?? ''));
            $meta = isset($_POST['meta']) ? (float) str_replace(',', '.', (string) $_POST['meta']) : 80.0;
            $ativo = isset($_POST['ativo']) ? (int) $_POST['ativo'] : 1;

            if ($cod === '' || $descricao === '') {
                throw new InvalidArgumentException('Código e Descrição do Setor são obrigatórios.');
            }

            $chk = $db->prepare('SELECT id FROM soma_setores WHERE cod = :cod AND deleted_at IS NULL AND (:id IS NULL OR id != :id_comp) LIMIT 1');
            $chk->execute(['cod' => $cod, 'id' => $id, 'id_comp' => $id]);
            if ($chk->fetch()) {
                throw new InvalidArgumentException("O código de setor '{$cod}' já está cadastrado.");
            }

            if ($id) {
                $stmt = $db->prepare('UPDATE soma_setores SET cod = :cod, descricao = :descricao, meta = :meta, ativo = :ativo WHERE id = :id AND deleted_at IS NULL');
                $stmt->execute(['cod' => $cod, 'descricao' => $descricao, 'meta' => $meta, 'ativo' => $ativo, 'id' => $id]);
                registrarLog('config_editar_setor', "Setor editado: {$cod} - {$descricao} (ID {$id})");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Setor atualizado com sucesso!', 'id' => $id]);
            } else {
                $stmt = $db->prepare('INSERT INTO soma_setores (cod, descricao, meta, ativo, id_criador) VALUES (:cod, :descricao, :meta, :ativo, :uid)');
                $stmt->execute(['cod' => $cod, 'descricao' => $descricao, 'meta' => $meta, 'ativo' => $ativo, 'uid' => $usuarioId]);
                $novoId = (int) $db->lastInsertId();
                registrarLog('config_criar_setor', "Setor criado: {$cod} - {$descricao} (ID {$novoId})");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Setor cadastrado com sucesso!', 'id' => $novoId]);
            }
            break;

        case 'excluir_setor':
            requirePerfil([1, 2]);
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('ID inválido para exclusão.');

            $stmt = $db->prepare('UPDATE soma_setores SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $id]);
            registrarLog('config_excluir_setor', "Setor excluído (soft delete ID {$id})");
            echo json_encode(['sucesso' => true, 'mensagem' => 'Setor excluído com sucesso!']);
            break;

        // ========================================================
        // OPERADORES
        // ========================================================
        case 'salvar_operador':
            requirePerfil([1, 2]);
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $cod = strtoupper(trim((string) ($_POST['cod'] ?? '')));
            $nome = trim((string) ($_POST['nome'] ?? ''));
            $ativo = isset($_POST['ativo']) ? (int) $_POST['ativo'] : 1;

            if ($cod === '' || $nome === '') {
                throw new InvalidArgumentException('Código e Nome do Operador são obrigatórios.');
            }

            $chk = $db->prepare('SELECT id FROM soma_operadores WHERE cod = :cod AND deleted_at IS NULL AND (:id IS NULL OR id != :id_comp) LIMIT 1');
            $chk->execute(['cod' => $cod, 'id' => $id, 'id_comp' => $id]);
            if ($chk->fetch()) {
                throw new InvalidArgumentException("O código de operador '{$cod}' já está cadastrado.");
            }

            if ($id) {
                $stmt = $db->prepare('UPDATE soma_operadores SET cod = :cod, nome = :nome, ativo = :ativo WHERE id = :id AND deleted_at IS NULL');
                $stmt->execute(['cod' => $cod, 'nome' => $nome, 'ativo' => $ativo, 'id' => $id]);
                registrarLog('config_editar_operador', "Operador editado: {$cod} - {$nome} (ID {$id})");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Operador atualizado com sucesso!', 'id' => $id]);
            } else {
                $stmt = $db->prepare('INSERT INTO soma_operadores (cod, nome, ativo, id_criador) VALUES (:cod, :nome, :ativo, :uid)');
                $stmt->execute(['cod' => $cod, 'nome' => $nome, 'ativo' => $ativo, 'uid' => $usuarioId]);
                $novoId = (int) $db->lastInsertId();
                registrarLog('config_criar_operador', "Operador criado: {$cod} - {$nome} (ID {$novoId})");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Operador cadastrado com sucesso!', 'id' => $novoId]);
            }
            break;

        case 'excluir_operador':
            requirePerfil([1, 2]);
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('ID inválido para exclusão.');

            $stmt = $db->prepare('UPDATE soma_operadores SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $id]);
            registrarLog('config_excluir_operador', "Operador excluído (soft delete ID {$id})");
            echo json_encode(['sucesso' => true, 'mensagem' => 'Operador excluído com sucesso!']);
            break;

        // ========================================================
        // MÁQUINAS
        // ========================================================
        case 'salvar_maquina':
            requirePerfil([1, 2]);
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $cod = strtoupper(trim((string) ($_POST['cod'] ?? '')));
            $nome = trim((string) ($_POST['nome'] ?? ''));
            $patrimonio = trim((string) ($_POST['patrimonio'] ?? ''));
            $descricao = trim((string) ($_POST['descricao_completa'] ?? ''));
            $setorLocal = trim((string) ($_POST['setor_local'] ?? ''));
            $ativo = isset($_POST['ativo']) ? (int) $_POST['ativo'] : 1;

            if ($cod === '' || $nome === '') {
                throw new InvalidArgumentException('Código e Nome da Máquina são obrigatórios.');
            }

            $chk = $db->prepare('SELECT id FROM soma_maquinas WHERE cod = :cod AND deleted_at IS NULL AND (:id IS NULL OR id != :id_comp) LIMIT 1');
            $chk->execute(['cod' => $cod, 'id' => $id, 'id_comp' => $id]);
            if ($chk->fetch()) {
                throw new InvalidArgumentException("O código de máquina '{$cod}' já está cadastrado.");
            }

            if ($id) {
                $stmt = $db->prepare('UPDATE soma_maquinas SET cod = :cod, nome = :nome, patrimonio = :patrimonio, descricao_completa = :desc, setor_local = :setor, ativo = :ativo WHERE id = :id AND deleted_at IS NULL');
                $stmt->execute([
                    'cod'        => $cod,
                    'nome'       => $nome,
                    'patrimonio' => $patrimonio ?: null,
                    'desc'       => $descricao ?: null,
                    'setor'      => $setorLocal ?: null,
                    'ativo'      => $ativo,
                    'id'         => $id
                ]);
                registrarLog('config_editar_maquina', "Máquina editada: {$cod} - {$nome} (ID {$id})");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Máquina atualizada com sucesso!', 'id' => $id]);
            } else {
                $stmt = $db->prepare('INSERT INTO soma_maquinas (cod, nome, patrimonio, descricao_completa, setor_local, ativo, id_criador) VALUES (:cod, :nome, :patrimonio, :desc, :setor, :ativo, :uid)');
                $stmt->execute([
                    'cod'        => $cod,
                    'nome'       => $nome,
                    'patrimonio' => $patrimonio ?: null,
                    'desc'       => $descricao ?: null,
                    'setor'      => $setorLocal ?: null,
                    'ativo'      => $ativo,
                    'uid'        => $usuarioId
                ]);
                $novoId = (int) $db->lastInsertId();
                registrarLog('config_criar_maquina', "Máquina criada: {$cod} - {$nome} (ID {$novoId})");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Máquina cadastrada com sucesso!', 'id' => $novoId]);
            }
            break;

        case 'excluir_maquina':
            requirePerfil([1, 2]);
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('ID inválido para exclusão.');

            $stmt = $db->prepare('UPDATE soma_maquinas SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $id]);
            registrarLog('config_excluir_maquina', "Máquina excluída (soft delete ID {$id})");
            echo json_encode(['sucesso' => true, 'mensagem' => 'Máquina excluída com sucesso!']);
            break;

        // ========================================================
        // MOTIVOS DE PARADA
        // ========================================================
        case 'salvar_motivo_parada':
            requirePerfil([1, 2]);
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $cod = strtoupper(trim((string) ($_POST['cod'] ?? '')));
            $descricao = trim((string) ($_POST['descricao'] ?? ''));
            $tipo = trim((string) ($_POST['tipo'] ?? 'NAO_PROG'));
            if (!in_array($tipo, ['PROG', 'NAO_PROG'], true)) {
                $tipo = 'NAO_PROG';
            }
            $ativo = isset($_POST['ativo']) ? (int) $_POST['ativo'] : 1;

            if ($cod === '' || $descricao === '') {
                throw new InvalidArgumentException('Código e Descrição do Motivo de Parada são obrigatórios.');
            }

            $chk = $db->prepare('SELECT id FROM soma_paradas_motivos WHERE cod = :cod AND deleted_at IS NULL AND (:id IS NULL OR id != :id_comp) LIMIT 1');
            $chk->execute(['cod' => $cod, 'id' => $id, 'id_comp' => $id]);
            if ($chk->fetch()) {
                throw new InvalidArgumentException("O código de motivo '{$cod}' já está cadastrado.");
            }

            if ($id) {
                $stmt = $db->prepare('UPDATE soma_paradas_motivos SET cod = :cod, descricao = :descricao, tipo = :tipo, ativo = :ativo WHERE id = :id AND deleted_at IS NULL');
                $stmt->execute(['cod' => $cod, 'descricao' => $descricao, 'tipo' => $tipo, 'ativo' => $ativo, 'id' => $id]);
                registrarLog('config_editar_motivo', "Motivo de parada editado: {$cod} - {$descricao} (ID {$id})");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Motivo de parada atualizado com sucesso!', 'id' => $id]);
            } else {
                $stmt = $db->prepare('INSERT INTO soma_paradas_motivos (cod, descricao, tipo, ativo, id_criador) VALUES (:cod, :descricao, :tipo, :ativo, :uid)');
                $stmt->execute(['cod' => $cod, 'descricao' => $descricao, 'tipo' => $tipo, 'ativo' => $ativo, 'uid' => $usuarioId]);
                $novoId = (int) $db->lastInsertId();
                registrarLog('config_criar_motivo', "Motivo de parada criado: {$cod} - {$descricao} (ID {$novoId})");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Motivo de parada cadastrado com sucesso!', 'id' => $novoId]);
            }
            break;

        case 'excluir_motivo_parada':
            requirePerfil([1, 2]);
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('ID inválido para exclusão.');

            $stmt = $db->prepare('UPDATE soma_paradas_motivos SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $id]);
            registrarLog('config_excluir_motivo', "Motivo de parada excluído (soft delete ID {$id})");
            echo json_encode(['sucesso' => true, 'mensagem' => 'Motivo de parada excluído com sucesso!']);
            break;

        // ========================================================
        // DIGITADOR (LANÇAMENTO DE TURNO)
        // ========================================================
        case 'salvar_lancamento_turno':
            requirePerfil([1, 2, 3]);

            $data = trim((string) ($_POST['data'] ?? ''));
            $turno = trim((string) ($_POST['turno'] ?? 'D'));
            $idOperador = (int) ($_POST['id_operador'] ?? 0);
            $idMaquina = (int) ($_POST['id_maquina'] ?? 0);
            $idSetor = (int) ($_POST['id_setor'] ?? 0);
            $idEmpresa = !empty($_POST['id_empresa']) ? (int) $_POST['id_empresa'] : null;

            // Auto-resolução de operador por nome se ID não informado
            if ($idOperador <= 0 && !empty($_POST['nome_operador'])) {
                $nomeOp = trim((string) $_POST['nome_operador']);
                $stOp = $db->prepare('SELECT id FROM soma_operadores WHERE (nome LIKE :n OR cod LIKE :c) AND deleted_at IS NULL LIMIT 1');
                $stOp->execute(['n' => "%$nomeOp%", 'c' => "%$nomeOp%"]);
                $opAchado = $stOp->fetchColumn();
                if ($opAchado) {
                    $idOperador = (int) $opAchado;
                } else {
                    $codOp = 'OP-' . substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nomeOp)), 0, 8);
                    if ($codOp === 'OP-') $codOp = 'OP-' . time();
                    $stInsOp = $db->prepare('INSERT INTO soma_operadores (cod, nome, ativo) VALUES (:c, :n, 1)');
                    $stInsOp->execute(['c' => $codOp, 'n' => $nomeOp]);
                    $idOperador = (int) $db->lastInsertId();
                }
            }

            // Auto-resolução de máquina por código ou nome se ID não informado
            if ($idMaquina <= 0 && !empty($_POST['nome_maquina'])) {
                $nomeMaq = trim((string) $_POST['nome_maquina']);
                $stMaq = $db->prepare('SELECT id FROM soma_maquinas WHERE (cod LIKE :c OR nome LIKE :n) AND deleted_at IS NULL LIMIT 1');
                $stMaq->execute(['c' => "%$nomeMaq%", 'n' => "%$nomeMaq%"]);
                $maqAchada = $stMaq->fetchColumn();
                if ($maqAchada) {
                    $idMaquina = (int) $maqAchada;
                } else {
                    $codMaq = 'MAQ-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nomeMaq));
                    if ($codMaq === 'MAQ-') $codMaq = 'MAQ-' . time();
                    $stInsMaq = $db->prepare('INSERT INTO soma_maquinas (cod, nome, ativo) VALUES (:c, :n, 1)');
                    $stInsMaq->execute(['c' => $codMaq, 'n' => 'Máquina ' . $nomeMaq]);
                    $idMaquina = (int) $db->lastInsertId();
                }
            }

            // Setor padrão (Bobinagem / 1º setor ativo) se não especificado
            if ($idSetor <= 0) {
                $idSetor = (int) ($db->query('SELECT id FROM soma_setores WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
            }

            $hInicio = trim((string) ($_POST['h_inicio'] ?? '07:30'));
            $hFim = trim((string) ($_POST['h_fim'] ?? '17:18'));
            $minutosDisponiveis = (float) ($_POST['minutos_disponiveis'] ?? 528.0);

            if ($data === '' || $idOperador <= 0 || $idMaquina <= 0 || $idSetor <= 0) {
                throw new InvalidArgumentException('Data, Operador, Máquina e Setor são campos obrigatórios.');
            }

            if ($minutosDisponiveis <= 0) {
                $minutosDisponiveis = 480.0;
            }

            $pecasJson = $_POST['pecas'] ?? '[]';
            $paradasJson = $_POST['paradas'] ?? '[]';
            $observacaoCampo = trim((string) ($_POST['observacao_campo'] ?? ''));

            $pecas = json_decode((string) $pecasJson, true) ?: [];
            $paradas = json_decode((string) $paradasJson, true) ?: [];

            // Cálculo dos minutos produzidos
            $minutosProduzidos = 0.0;
            $pecasValidas = [];
            foreach ($pecas as $p) {
                $codPeca = trim((string) ($p['cod_peca'] ?? ''));
                $descPeca = trim((string) ($p['descricao_peca'] ?? ''));
                $qtd = (int) ($p['qtd'] ?? 1);
                $tp = (float) ($p['tp_padrao_min'] ?? 0.0);
                if ($codPeca !== '' && $qtd > 0 && $tp > 0) {
                    $totalProd = round($qtd * $tp, 2);
                    $minutosProduzidos += $totalProd;
                    $pecasValidas[] = [
                        'cod_peca'               => $codPeca,
                        'descricao_peca'         => $descPeca,
                        'qtd'                    => $qtd,
                        'tp_padrao_min'          => $tp,
                        'tp_total_produzido_min' => $totalProd
                    ];
                }
            }

            // Cálculo dos minutos parados
            $minutosParadas = 0.0;
            $paradasValidas = [];
            foreach ($paradas as $pr) {
                $idMotivo = (int) ($pr['id_motivo'] ?? 0);
                $duracao = (float) ($pr['duracao_minutos'] ?? 0.0);
                $obs = trim((string) ($pr['observacao'] ?? ''));
                if ($idMotivo > 0 && $duracao > 0) {
                    $minutosParadas += $duracao;
                    $paradasValidas[] = [
                        'id_motivo'       => $idMotivo,
                        'duracao_minutos' => $duracao,
                        'observacao'      => $obs
                    ];
                }
            }

            // Cálculo da Eficiência %
            $eficiencia = $minutosDisponiveis > 0 ? round(($minutosProduzidos / $minutosDisponiveis) * 100, 2) : 0.0;

            // Busca meta do setor
            $stmtSetor = $db->prepare('SELECT meta FROM soma_setores WHERE id = :id LIMIT 1');
            $stmtSetor->execute(['id' => $idSetor]);
            $metaSetor = (float) ($stmtSetor->fetchColumn() ?: 80.0);

            // Status da Cronoanálise
            if ($eficiencia >= $metaSetor) {
                $status = '[DENTRO DO PADRÃO]';
            } elseif ($eficiencia >= ($metaSetor * 0.75)) {
                $status = '[DESVIO MODERADO]';
            } else {
                $status = '[GARGALO CRÍTICO]';
            }

            $db->beginTransaction();
            try {
                // 1. Insere Turno
                $stmtTurno = $db->prepare('
                    INSERT INTO soma_turnos 
                    (data, turno, id_operador, id_maquina, id_setor, id_empresa, h_inicio, h_fim, minutos_disponiveis, minutos_produzidos, minutos_paradas, eficiencia, status, id_criador)
                    VALUES 
                    (:data, :turno, :id_operador, :id_maquina, :id_setor, :id_empresa, :h_inicio, :h_fim, :minutos_disponiveis, :minutos_produzidos, :minutos_paradas, :eficiencia, :status, :uid)
                ');
                $stmtTurno->execute([
                    'data'                => $data,
                    'turno'               => in_array($turno, ['D', 'N', 'M'], true) ? $turno : 'D',
                    'id_operador'         => $idOperador,
                    'id_maquina'          => $idMaquina,
                    'id_setor'            => $idSetor,
                    'id_empresa'          => $idEmpresa,
                    'h_inicio'            => $hInicio ?: '07:00:00',
                    'h_fim'               => $hFim ?: '17:00:00',
                    'minutos_disponiveis' => $minutosDisponiveis,
                    'minutos_produzidos'  => $minutosProduzidos,
                    'minutos_paradas'     => $minutosParadas,
                    'eficiencia'          => $eficiencia,
                    'status'              => $status,
                    'uid'                 => $usuarioId
                ]);
                $idTurno = (int) $db->lastInsertId();

                // 2. Insere peças produzidas
                if (!empty($pecasValidas)) {
                    $stmtPeca = $db->prepare('
                        INSERT INTO soma_registros_producao 
                        (id_turno, cod_peca, descricao_peca, qtd, tp_padrao_min, tp_total_produzido_min, id_criador)
                        VALUES 
                        (:id_turno, :cod, :desc, :qtd, :tp, :total, :uid)
                    ');
                    foreach ($pecasValidas as $pv) {
                        $stmtPeca->execute([
                            'id_turno' => $idTurno,
                            'cod'      => $pv['cod_peca'],
                            'desc'     => $pv['descricao_peca'],
                            'qtd'      => $pv['qtd'],
                            'tp'       => $pv['tp_padrao_min'],
                            'total'    => $pv['tp_total_produzido_min'],
                            'uid'      => $usuarioId
                        ]);
                    }
                }

                // 3. Insere paradas
                if (!empty($paradasValidas)) {
                    $stmtPar = $db->prepare('
                        INSERT INTO soma_registros_paradas 
                        (id_turno, id_motivo, duracao_minutos, observacao, id_criador)
                        VALUES 
                        (:id_turno, :id_motivo, :duracao, :obs, :uid)
                    ');
                    foreach ($paradasValidas as $prv) {
                        $stmtPar->execute([
                            'id_turno'  => $idTurno,
                            'id_motivo' => $prv['id_motivo'],
                            'duracao'   => $prv['duracao_minutos'],
                            'obs'       => $prv['observacao'],
                            'uid'       => $usuarioId
                        ]);
                    }
                }

                // 4. Insere observações
                if ($observacaoCampo !== '') {
                    $stmtObs = $db->prepare('
                        INSERT INTO soma_observacoes (id_turno, texto, id_criador)
                        VALUES (:id_turno, :texto, :uid)
                    ');
                    $stmtObs->execute([
                        'id_turno' => $idTurno,
                        'texto'    => $observacaoCampo,
                        'uid'      => $usuarioId
                    ]);
                }

                $db->commit();

                registrarLog('digitador_lancar_turno', "Lançamento de turno registrado: ID {$idTurno}, Eficiência {$eficiencia}%, {$status}");

                echo json_encode([
                    'sucesso'    => true,
                    'mensagem'   => "Turno registrado com sucesso! Eficiência apurada: {$eficiencia}% ({$status})",
                    'id_turno'   => $idTurno,
                    'eficiencia' => $eficiencia,
                    'status'     => $status
                ]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            break;

        // ========================================================
        // BASE DE DADOS (CONSULTA & EXCLUSÃO DE TURNOS)
        // ========================================================
        case 'obter_detalhes_turno':
            requirePerfil([1, 2, 3, 4]);
            $idTurno = (int) ($_POST['id_turno'] ?? 0);
            if ($idTurno <= 0) throw new InvalidArgumentException('ID de turno inválido.');

            $stmtT = $db->prepare('
                SELECT t.*, 
                       op.nome AS operador_nome, op.cod AS operador_cod,
                       maq.nome AS maquina_nome, maq.cod AS maquina_cod, maq.patrimonio AS maquina_patrimonio,
                       st.descricao AS setor_nome, st.cod AS setor_cod, st.meta AS setor_meta,
                       emp.nome AS empresa_nome, emp.cod AS empresa_cod,
                       u.nome AS criador_nome
                FROM soma_turnos t
                LEFT JOIN soma_operadores op ON op.id = t.id_operador
                LEFT JOIN soma_maquinas maq ON maq.id = t.id_maquina
                LEFT JOIN soma_setores st ON st.id = t.id_setor
                LEFT JOIN soma_empresas emp ON emp.id = t.id_empresa
                LEFT JOIN usuarios u ON u.id = t.id_criador
                WHERE t.id = :id AND t.deleted_at IS NULL
                LIMIT 1
            ');
            $stmtT->execute(['id' => $idTurno]);
            $turno = $stmtT->fetch();

            if (!$turno) {
                http_response_code(404);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro de turno não encontrado.']);
                exit;
            }

            // Peças
            $stmtP = $db->prepare('SELECT * FROM soma_registros_producao WHERE id_turno = :id AND deleted_at IS NULL ORDER BY id ASC');
            $stmtP->execute(['id' => $idTurno]);
            $pecas = $stmtP->fetchAll();

            // Paradas
            $stmtPar = $db->prepare('
                SELECT p.*, m.cod AS motivo_cod, m.descricao AS motivo_descricao, m.tipo AS motivo_tipo
                FROM soma_registros_paradas p
                LEFT JOIN soma_paradas_motivos m ON m.id = p.id_motivo
                WHERE p.id_turno = :id AND p.deleted_at IS NULL
                ORDER BY p.id ASC
            ');
            $stmtPar->execute(['id' => $idTurno]);
            $paradas = $stmtPar->fetchAll();

            // Observações
            $stmtO = $db->prepare('SELECT * FROM soma_observacoes WHERE id_turno = :id AND deleted_at IS NULL ORDER BY id ASC');
            $stmtO->execute(['id' => $idTurno]);
            $observacoes = $stmtO->fetchAll();

            echo json_encode([
                'sucesso'     => true,
                'turno'       => $turno,
                'pecas'       => $pecas,
                'paradas'     => $paradas,
                'observacoes' => $observacoes
            ]);
            break;

        case 'processar_ocr_folha':
            requirePerfil([1, 2, 3]);

            $texto = (string) ($_POST['texto'] ?? '');
            
            // Dicionário de motivos do banco para mapeamento
            $todosMotivos = $db->query('SELECT id, cod, descricao, tipo FROM soma_paradas_motivos WHERE deleted_at IS NULL')->fetchAll();
            $motivosMap = [];
            foreach ($todosMotivos as $m) {
                $motivosMap[strtoupper(trim((string)$m['cod']))] = $m;
            }

            // Operadores para auto-match
            $todosOps = $db->query('SELECT id, cod, nome FROM soma_operadores WHERE deleted_at IS NULL')->fetchAll();
            // Máquinas para auto-match
            $todasMaqs = $db->query('SELECT id, cod, nome FROM soma_maquinas WHERE deleted_at IS NULL')->fetchAll();

            $resultado = [
                'data' => date('Y-m-d'),
                'turno' => 'D',
                'id_operador' => null,
                'nome_operador' => '',
                'id_maquina' => null,
                'nome_maquina' => '',
                'id_setor' => 1, // Bobinagem
                'h_inicio' => '07:30',
                'h_fim' => '17:18',
                'minutos_disponiveis' => 528,
                'pecas' => [],
                'paradas' => [],
                'observacao' => ''
            ];

            // 1. Data (dd/mm/aaaa ou dd-mm-aaaa)
            if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4}|\d{2})\b/', $texto, $m)) {
                $ano = strlen($m[3]) === 2 ? ('20' . $m[3]) : $m[3];
                $resultado['data'] = sprintf('%04d-%02d-%02d', (int)$ano, (int)$m[2], (int)$m[1]);
            }

            // 2. Turno
            if (preg_match('/(NOTURNO|TURNO[\s\-_]*NOTURNO)/i', $texto)) {
                $resultado['turno'] = 'N';
            } elseif (preg_match('/(MISTO|ESPECIAL)/i', $texto)) {
                $resultado['turno'] = 'M';
            } else {
                $resultado['turno'] = 'D';
            }

            // 3. Máquina (Ex: Máquina 40 / 40)
            if (preg_match('/M[AÁ]QUINA\s*[\:\-\_]?\s*([A-Za-z0-9\-_]+)/i', $texto, $m)) {
                $maqNum = trim($m[1]);
                $resultado['nome_maquina'] = $maqNum;
                foreach ($todasMaqs as $maq) {
                    if (str_contains(strtoupper($maq['cod']), strtoupper($maqNum)) || str_contains(strtoupper($maq['nome']), strtoupper($maqNum))) {
                        $resultado['id_maquina'] = (int)$maq['id'];
                        $resultado['nome_maquina'] = $maq['nome'];
                        break;
                    }
                }
            }

            // 4. Operador / Bobinador(a) (Ex: Ana Paula D)
            if (preg_match('/(?:BOBINADOR[A-Z\(\)]*|OPERADOR[A-Z\(\)]*|NOME)\s*[\:\-\_]?\s*([A-Za-zÀ-ÿ\s\.]+)/i', $texto, $m)) {
                $opNome = trim($m[1]);
                // Limpa sufixos de cabeçalho
                $opNome = preg_replace('/\b(AT|BT|TURNO|DATA|HORA|INICIO|MÁQUINA|MAQUINA).*/i', '', $opNome);
                $opNome = trim($opNome);
                if (strlen($opNome) >= 3) {
                    $resultado['nome_operador'] = $opNome;
                    foreach ($todosOps as $op) {
                        if (stripos($op['nome'], $opNome) !== false || stripos($opNome, $op['nome']) !== false) {
                            $resultado['id_operador'] = (int)$op['id'];
                            $resultado['nome_operador'] = $op['nome'];
                            break;
                        }
                    }
                }
            }

            // 5. Horários de Turno
            if (preg_match('/(?:INICIO|IN[IÍ]CIO)\s*[:\s]*(\d{1,2}:\d{2})/i', $texto, $m)) {
                $resultado['h_inicio'] = strlen($m[1]) === 4 ? ('0' . $m[1]) : $m[1];
            }
            if (preg_match('/(?:SA[IÍ]DA|FIM|T[EÉ]RMINO)\s*[:\s]*(\d{1,2}:\d{2})/i', $texto, $m)) {
                $resultado['h_fim'] = strlen($m[1]) === 4 ? ('0' . $m[1]) : $m[1];
            }
            if (preg_match('/(?:Minutos|MINUTOS)\s*[:\s]*(\d{2,4})/i', $texto, $m)) {
                $resultado['minutos_disponiveis'] = (float)$m[1];
            }

            // 6. Peças / Produção (Projeto, Padrão, Quantidade)
            // Ex: 423536 0.8 6
            if (preg_match_all('/\b(\d{5,7})\s+([0-9]+[.,][0-9]+|[0-9]+)\s+(\d{1,4})\b/', $texto, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $tp = (float)str_replace(',', '.', $match[2]);
                    $qtd = (int)$match[3];
                    $resultado['pecas'][] = [
                        'cod_peca' => $match[1],
                        'descricao_peca' => 'Trafo Projeto ' . $match[1],
                        'qtd' => $qtd,
                        'tp_padrao_min' => $tp,
                        'tp_total_produzido_min' => round($qtd * $tp, 2)
                    ];
                }
            }

            // 7. Paradas apontadas (Código, Hora Início, Hora Fim)
            // Ex: 9 12:20 13:20  ou  63 17:10 17:15
            if (preg_match_all('/\b(\d{1,3})\s+(\d{1,2}:\d{2})\s+(\d{1,2}:\d{2})\b/', $texto, $matchesPar, PREG_SET_ORDER)) {
                foreach ($matchesPar as $mp) {
                    $codMot = $mp[1];
                    $hIni = $mp[2];
                    $hFim = $mp[3];

                    $tsIni = strtotime("2000-01-01 $hIni");
                    $tsFim = strtotime("2000-01-01 $hFim");
                    $duracaoMin = ($tsFim >= $tsIni) ? round(($tsFim - $tsIni) / 60) : 0;

                    $idMotivo = null;
                    $descMotivo = 'Parada Cód. ' . $codMot;
                    if (isset($motivosMap[$codMot])) {
                        $idMotivo = (int)$motivosMap[$codMot]['id'];
                        $descMotivo = $motivosMap[$codMot]['descricao'];
                    }

                    $resultado['paradas'][] = [
                        'id_motivo' => $idMotivo,
                        'cod_motivo' => $codMot,
                        'descricao_motivo' => $descMotivo,
                        'h_inicio' => $hIni,
                        'h_fim' => $hFim,
                        'duracao_minutos' => $duracaoMin,
                        'observacao' => "Apontado na folha das {$hIni} às {$hFim}"
                    ];
                }
            }

            echo json_encode([
                'sucesso' => true,
                'dados' => $resultado
            ]);
            break;

        case 'excluir_turno':
            requirePerfil([1, 2]);
            $idTurno = (int) ($_POST['id_turno'] ?? 0);
            if ($idTurno <= 0) throw new InvalidArgumentException('ID de turno inválido para exclusão.');

            $db->beginTransaction();
            try {
                $db->prepare('UPDATE soma_turnos SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL')->execute(['id' => $idTurno]);
                $db->prepare('UPDATE soma_registros_producao SET deleted_at = NOW() WHERE id_turno = :id AND deleted_at IS NULL')->execute(['id' => $idTurno]);
                $db->prepare('UPDATE soma_registros_paradas SET deleted_at = NOW() WHERE id_turno = :id AND deleted_at IS NULL')->execute(['id' => $idTurno]);
                $db->prepare('UPDATE soma_observacoes SET deleted_at = NOW() WHERE id_turno = :id AND deleted_at IS NULL')->execute(['id' => $idTurno]);

                $db->commit();
                registrarLog('base_dados_excluir_turno', "Turno ID {$idTurno} excluído (soft delete completo)");
                echo json_encode(['sucesso' => true, 'mensagem' => 'Registro de turno excluído com sucesso!']);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => "Ação '{$acao}' não reconhecida."]);
            break;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log("Erro em api/soma-acao.php ({$acao}): " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno ao processar a solicitação.']);
}
