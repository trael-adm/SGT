<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

// ─── Autenticação ─────────────────────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido']);
    exit;
}

$acao   = trim((string) ($_POST['acao'] ?? ''));
$userId = (int) (currentUser()['id'] ?? 0);

/** Lê e normaliza os campos do formulário de retrabalho. */
function lerCamposRetrabalho(): array
{
    $dataOk = static function (string $d): ?string {
        $d = trim($d);
        return ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    };

    $obs       = trim((string) ($_POST['observacoes'] ?? ''));
    $causaRaiz = trim((string) ($_POST['causa_raiz'] ?? ''));
    $ns        = trim((string) ($_POST['ns_transformador'] ?? ''));
    $idProjeto = (int) ($_POST['id_projeto'] ?? 0);
    $idReprova = (int) ($_POST['id_reprova'] ?? 0);

    // Obs.: responsável = usuário logado (definido na ação). Status = derivado (derivarStatus()).
    return [
        'observacoes'      => $obs !== '' ? $obs : null,
        'causa_raiz'       => $causaRaiz !== '' ? $causaRaiz : null,
        'ns_transformador' => $ns !== '' ? $ns : null,
        'id_projeto'       => $idProjeto > 0 ? $idProjeto : null,
        'id_reprova'       => $idReprova > 0 ? $idReprova : null,
        'data_reprova'     => $dataOk((string) ($_POST['data_reprova'] ?? '')),
        'data_inicio'      => $dataOk((string) ($_POST['data_inicio'] ?? '')),
        'data_finalizacao' => $dataOk((string) ($_POST['data_finalizacao'] ?? '')),
    ];
}

/**
 * Deriva o status a partir dos dados (fluxo por etapas, automático ao salvar):
 *   causa raiz preenchida        -> finalizado
 *   data de finalização presente -> agu_causa_raiz
 *   senão                        -> agu_abertura
 */
function derivarStatus(array $c): string
{
    if ($c['causa_raiz'] !== null)       return 'finalizado';
    if ($c['data_finalizacao'] !== null) return 'agu_causa_raiz';
    return 'agu_abertura';
}

/** Valida os campos. Retorna string de erro ou null se OK. $idAtual exclui o próprio registro na edição. */
function validarRetrabalho(PDO $pdo, array $c, int $idAtual = 0): ?string
{
    if ($c['id_projeto'] === null)           return 'Selecione o projeto.';
    if ($c['ns_transformador'] === null)     return 'Informe o N° de série do transformador.';
    if ($c['id_reprova'] === null)           return 'Selecione o código de reprova.';

    // Projeto precisa existir
    $q = $pdo->prepare("SELECT id FROM projetos WHERE id = ? AND deleted_at IS NULL");
    $q->execute([$c['id_projeto']]);
    if (!$q->fetch()) return 'Projeto inválido.';

    // Reprova precisa existir e estar ativa
    $q = $pdo->prepare("SELECT id FROM reprovas WHERE id = ? AND ativo = 1");
    $q->execute([$c['id_reprova']]);
    if (!$q->fetch()) return 'Código de reprova inválido.';

    // Unicidade do N° de série: o mesmo NS não pode estar em outro projeto
    $q = $pdo->prepare("
        SELECT pr.codigo
        FROM retrabalhos r
        JOIN projetos pr ON pr.id = r.id_projeto
        WHERE r.ns_transformador = ? AND r.id_projeto <> ? AND r.deleted_at IS NULL AND r.id <> ?
        LIMIT 1
    ");
    $q->execute([$c['ns_transformador'], $c['id_projeto'], $idAtual]);
    if ($confl = $q->fetch()) {
        return 'Este N° de série já está registrado em outro projeto (' . $confl['codigo'] . ').';
    }

    // Coerência de datas
    if ($c['data_inicio'] && $c['data_finalizacao'] && $c['data_finalizacao'] < $c['data_inicio']) {
        return 'A data de finalização não pode ser anterior à data de início.';
    }

    return null;
}

try {
    $pdo = getDB();

    switch ($acao) {

        // ─── Registrar novo retrabalho ────────────────────────────────────────
        case 'registrar': {
            $c = lerCamposRetrabalho();
            if ($err = validarRetrabalho($pdo, $c)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $err]);
                exit;
            }
            $status      = derivarStatus($c);
            $concluidoEm = ($status === 'finalizado') ? date('Y-m-d H:i:s') : null;

            // Responsável = usuário logado (fixo). Status derivado dos dados.
            $stmt = $pdo->prepare("
                INSERT INTO retrabalhos
                    (id_responsavel, id_projeto, ns_transformador, id_reprova,
                     data_reprova, data_inicio, data_finalizacao, status, observacoes, causa_raiz,
                     id_criador, concluido_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId, $c['id_projeto'], $c['ns_transformador'], $c['id_reprova'],
                $c['data_reprova'], $c['data_inicio'], $c['data_finalizacao'], $status,
                $c['observacoes'], $c['causa_raiz'], $userId, $concluidoEm,
            ]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Retrabalho registrado.', 'id' => (int) $pdo->lastInsertId()]);
            break;
        }

        // ─── Editar retrabalho ────────────────────────────────────────────────
        case 'editar': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }
            $c = lerCamposRetrabalho();
            if ($err = validarRetrabalho($pdo, $c, $id)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $err]);
                exit;
            }

            // Carregar carimbo atual para preservar a data de finalização já registrada
            $stmtCur = $pdo->prepare("SELECT concluido_em FROM retrabalhos WHERE id = ? AND deleted_at IS NULL");
            $stmtCur->execute([$id]);
            $atual = $stmtCur->fetch();
            if (!$atual) {
                echo json_encode(['sucesso' => false, 'erro' => 'Registro não encontrado.']);
                exit;
            }

            $status      = derivarStatus($c);
            $concluidoEm = ($status === 'finalizado')
                ? ($atual['concluido_em'] ?: date('Y-m-d H:i:s'))
                : null;

            // Responsável original preservado; status derivado dos dados.
            $stmt = $pdo->prepare("
                UPDATE retrabalhos SET
                    id_projeto = ?, ns_transformador = ?,
                    id_reprova = ?, data_reprova = ?, data_inicio = ?, data_finalizacao = ?,
                    status = ?, observacoes = ?, causa_raiz = ?, concluido_em = ?
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([
                $c['id_projeto'], $c['ns_transformador'], $c['id_reprova'],
                $c['data_reprova'], $c['data_inicio'], $c['data_finalizacao'],
                $status, $c['observacoes'], $c['causa_raiz'], $concluidoEm, $id,
            ]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Retrabalho atualizado.']);
            break;
        }

        // ─── Excluir (soft delete) ────────────────────────────────────────────
        case 'excluir': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']); exit; }
            $stmt = $pdo->prepare("UPDATE retrabalhos SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Retrabalho excluído.']);
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
