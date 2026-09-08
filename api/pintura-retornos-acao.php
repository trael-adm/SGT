<?php
declare(strict_types=1);

/**
 * Backend da estação PIN (Pintura) — dedicado e isolado do fluxo de
 * LAB/IQF já existente em api/retrabalho-acao.php (nenhuma função ou query
 * é compartilhada; só a tabela `producao_etapas`/`retrabalhos` é a mesma
 * infraestrutura, ver _inicial/migrar-pintura-estacao-pin.sql).
 *
 * Duas origens de chamada:
 * - `reprovar_pintura`: disparada pelo modal "Reprovar" do Paint Check
 *   (pages/qualidade/paint-check.php) — abre a reprova E já cria a
 *   pendência "aguardando_retorno" na estação PIN (toda reprova de pintura
 *   vira uma pendência de retorno, diferente de LAB/IQF onde isso depende
 *   da flag `reprovas.vai_retrabalho`).
 * - `aprovar_retorno` / `reprovar_retorno` / `excluir_retorno`: disparadas
 *   pela tela pages/pintura/retornos.php (fila de "aguardando retorno" da
 *   Pintura, mesmo padrão visual de pages/producao/retornos.php).
 *
 * PIN é uma estação única (sem a cadeia LAB->IQF que existe na Produção) —
 * por isso as ações aqui são mais simples que as equivalentes de
 * api/retrabalho-acao.php: não há lógica de "voltar pra estação de origem".
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

if (!hasAcesso('pin.pai') && !hasAcesso('pin.retornos') && !hasAcesso('tab:pintura') && !hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Sem permissão de acesso']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido']);
    exit;
}

$acao   = trim((string) ($_POST['acao'] ?? ''));
$userId = (int) (currentUser()['id'] ?? 0);
$pdo    = getDB();

/** Devolve o id do lote de reprovas ativo (não finalizado) do NS/projeto na estação PIN, ou null. */
function pinLoteAtivo(PDO $pdo, string $ns, int $idProjeto): ?int
{
    $stmt = $pdo->prepare("
        SELECT id, id_lote FROM retrabalhos
        WHERE ns_transformador = ? AND id_projeto = ? AND estacao = 'PIN' AND deleted_at IS NULL
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$ns, $idProjeto]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return $row['id_lote'] ? (int) $row['id_lote'] : (int) $row['id'];
}

/** Garante que existe uma pendência `aguardando_retorno` na estação PIN pra esse NS — cria se não existir. */
function pinGarantirPendencia(PDO $pdo, string $ns, int $idProjeto, int $userId): void
{
    $stmt = $pdo->prepare("
        SELECT id FROM producao_etapas
        WHERE ns_transformador = ? AND estacao = 'PIN' AND status = 'aguardando_retorno' AND deleted_at IS NULL
    ");
    $stmt->execute([$ns]);
    if ($stmt->fetch()) return;

    $pdo->prepare("
        INSERT INTO producao_etapas
            (ns_transformador, id_projeto, estacao, status, data_inicio, id_responsavel, id_criador)
        VALUES (?, ?, 'PIN', 'aguardando_retorno', NOW(), ?, ?)
    ")->execute([$ns, $idProjeto, $userId, $userId]);
}

try {
    switch ($acao) {

        // ─── Reprovação disparada pelo Paint Check ─────────────────────────
        case 'reprovar_pintura': {
            $idProjeto   = (int) ($_POST['id_projeto'] ?? 0);
            $ns          = trim((string) ($_POST['ns_transformador'] ?? ''));
            $observacoes = trim((string) ($_POST['observacoes'] ?? ''));
            $idsReprova  = array_values(array_filter(array_map('intval', (array) ($_POST['id_reprova'] ?? [])), fn ($x) => $x > 0));

            if ($idProjeto <= 0 || $ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido — informe o projeto e o N° de série.']);
                exit;
            }
            if (!$idsReprova) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Selecione ao menos um motivo de reprovação.']);
                exit;
            }

            $qProjeto = $pdo->prepare('SELECT id FROM projetos WHERE id = ? AND deleted_at IS NULL');
            $qProjeto->execute([$idProjeto]);
            if (!$qProjeto->fetch()) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Projeto inválido.']);
                exit;
            }

            foreach ($idsReprova as $idRep) {
                $qRep = $pdo->prepare('SELECT id FROM reprovas WHERE id = ? AND ativo = 1');
                $qRep->execute([$idRep]);
                if (!$qRep->fetch()) {
                    http_response_code(400);
                    echo json_encode(['sucesso' => false, 'erro' => 'Código de reprova inválido.']);
                    exit;
                }
            }

            $pdo->beginTransaction();

            // Reaproveita o lote ativo se já houver uma reprova PIN em aberto pra
            // esse NS/projeto (ex.: operador reprovou de novo antes de resolver a
            // pendência anterior) — senão abre um lote novo.
            $idLote = pinLoteAtivo($pdo, $ns, $idProjeto);

            $dataReprova = date('Y-m-d');
            $stmtIns = $pdo->prepare("
                INSERT INTO retrabalhos
                    (id_responsavel, id_projeto, ns_transformador, id_reprova, estacao, data_reprova,
                     status, observacoes, setores_destino, id_criador, id_lote)
                VALUES (?, ?, ?, ?, 'PIN', ?, 'agu_abertura', ?, 'pintura', ?, ?)
            ");
            $primeiroId = null;
            foreach ($idsReprova as $idRep) {
                $stmtIns->execute([$userId, $idProjeto, $ns, $idRep, $dataReprova, $observacoes !== '' ? $observacoes : null, $userId, $idLote]);
                if ($primeiroId === null) $primeiroId = (int) $pdo->lastInsertId();
            }
            if ($idLote === null) {
                // Primeira reprova deste ciclo: o próprio primeiro registro é o lote.
                $pdo->prepare('UPDATE retrabalhos SET id_lote = ? WHERE id = ?')->execute([$primeiroId, $primeiroId]);
            }

            // Fecha o apontamento em_andamento (se houver — ver pages/pintura/index.php/
            // api/pintura-acao.php) antes de abrir a pendência de retorno, mesmo padrão de
            // api/retrabalho-acao.php::registrar() pro LAB/IQF: a peça sai de "em inspeção"
            // e passa a "aguardando retorno", nunca os dois ao mesmo tempo.
            $pdo->prepare("
                UPDATE producao_etapas SET deleted_at = NOW()
                WHERE ns_transformador = ? AND status = 'em_andamento' AND deleted_at IS NULL
            ")->execute([$ns]);

            pinGarantirPendencia($pdo, $ns, $idProjeto, $userId);

            $pdo->commit();

            echo json_encode([
                'sucesso'  => true,
                'mensagem' => 'Reprovação registrada. O transformador foi encaminhado para os Retornos da Pintura.',
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ─── Aprovar retorno (correção confirmada, encerra a pendência) ────
        case 'aprovar_retorno': {
            $idProjeto = (int) ($_POST['id_projeto'] ?? 0);
            $ns        = trim((string) ($_POST['ns_transformador'] ?? ''));

            if ($idProjeto <= 0 || $ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT id FROM producao_etapas
                WHERE ns_transformador = ? AND id_projeto = ? AND estacao = 'PIN'
                  AND status = 'aguardando_retorno' AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([$ns, $idProjeto]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Este transformador não está aguardando retorno da Pintura.']);
                exit;
            }

            $pdo->beginTransaction();

            $pdo->prepare("
                UPDATE producao_etapas SET deleted_at = NOW()
                WHERE ns_transformador = ? AND estacao = 'PIN' AND status = 'aguardando_retorno' AND deleted_at IS NULL
            ")->execute([$ns]);

            $agora = date('Y-m-d H:i:s');
            $hoje  = date('Y-m-d');
            $pdo->prepare("
                UPDATE retrabalhos SET status = 'finalizado', concluido_em = ?, data_finalizacao = ?
                WHERE ns_transformador = ? AND id_projeto = ? AND estacao = 'PIN' AND deleted_at IS NULL AND status != 'finalizado'
            ")->execute([$agora, $hoje, $ns, $idProjeto]);

            $pdo->commit();

            echo json_encode(['sucesso' => true, 'mensagem' => 'Retorno aprovado e finalizado com sucesso.'], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ─── Reprovar retorno (reincidência ou nova reprova) ────────────────
        case 'reprovar_retorno': {
            $tipo       = (string) ($_POST['tipo_reprova'] ?? 'reincidencia');
            $idProjeto  = (int) ($_POST['id_projeto'] ?? 0);
            $ns         = trim((string) ($_POST['ns_transformador'] ?? ''));
            $idsReprova = array_values(array_filter(array_map('intval', (array) ($_POST['id_reprova'] ?? [])), fn ($x) => $x > 0));

            if ($idProjeto <= 0 || $ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT id FROM producao_etapas
                WHERE ns_transformador = ? AND id_projeto = ? AND estacao = 'PIN'
                  AND status = 'aguardando_retorno' AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([$ns, $idProjeto]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Este transformador não está aguardando retorno da Pintura.']);
                exit;
            }

            if ($tipo === 'reincidencia') {
                // Repete os mesmos motivos do lote atual — o operador já viu a
                // peça de novo com o(s) mesmo(s) defeito(s) de antes.
                $idLote = pinLoteAtivo($pdo, $ns, $idProjeto);
                if ($idLote !== null) {
                    $stmtAnt = $pdo->prepare("
                        SELECT DISTINCT id_reprova FROM retrabalhos
                        WHERE (id_lote = ? OR id = ?) AND deleted_at IS NULL AND id_reprova IS NOT NULL
                    ");
                    $stmtAnt->execute([$idLote, $idLote]);
                    $idsReprova = array_map('intval', $stmtAnt->fetchAll(PDO::FETCH_COLUMN));
                }
            }

            if (!$idsReprova) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Selecione ao menos um motivo de reprovação.']);
                exit;
            }

            $pdo->beginTransaction();

            $idLote = pinLoteAtivo($pdo, $ns, $idProjeto);
            $dataReprova = date('Y-m-d');
            $stmtIns = $pdo->prepare("
                INSERT INTO retrabalhos
                    (id_responsavel, id_projeto, ns_transformador, id_reprova, estacao, data_reprova,
                     status, setores_destino, id_criador, id_lote)
                VALUES (?, ?, ?, ?, 'PIN', ?, 'agu_abertura', 'pintura', ?, ?)
            ");
            foreach ($idsReprova as $idRep) {
                $stmtIns->execute([$userId, $idProjeto, $ns, $idRep, $dataReprova, $userId, $idLote]);
            }

            // A pendência de retorno já existia (é pré-requisito desta ação) —
            // só garante que continua ativa (defensivo, sem custo se já existir).
            pinGarantirPendencia($pdo, $ns, $idProjeto, $userId);

            $pdo->commit();

            echo json_encode(['sucesso' => true, 'mensagem' => 'Nova reprova registrada. Peça mantida nos Retornos da Pintura.'], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ─── Excluir retorno (remove a pendência, soft delete) ──────────────
        case 'excluir_retorno': {
            $idProjeto = (int) ($_POST['id_projeto'] ?? 0);
            $ns        = trim((string) ($_POST['ns_transformador'] ?? ''));

            if ($ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'N° de série inválido.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT id FROM producao_etapas
                WHERE ns_transformador = ? AND estacao = 'PIN' AND status = 'aguardando_retorno' AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([$ns]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Este transformador não está aguardando retorno da Pintura.']);
                exit;
            }

            $pdo->prepare("
                UPDATE producao_etapas SET deleted_at = NOW()
                WHERE ns_transformador = ? AND estacao = 'PIN' AND status = 'aguardando_retorno' AND deleted_at IS NULL
            ")->execute([$ns]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Retorno excluído com sucesso.'], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'Ação desconhecida.']);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao processar a solicitação.']);
}
