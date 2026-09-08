<?php
declare(strict_types=1);

/**
 * Persiste o resultado de uma validação do Paint Check (checklist por campo,
 * ver api/paint-check-multi.php modo 'validar' com `_num_serie_identificado`)
 * — auditoria ISO (quem confirmou o quê) e dataset de fine-tuning do
 * reconhecimento do PaddleOCR (recorte + valor correto do VSAT, já vindo em
 * `campo.esperado`). Ver _inicial/migrar-paint-check-validacoes.sql.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if (!hasAcesso('pin.pai') && !hasAcesso('tab:pintura') && !hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sem permissão de acesso']);
    exit;
}

if (!podeEditar('pin.pai') && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas consulta: sem permissão para salvar validações']);
    exit;
}

$input = file_get_contents('php://input');
$payload = json_decode($input, true);

$numSerie = trim((string) ($payload['num_serie'] ?? ''));
$campos = $payload['campos'] ?? null;
$status = (string) ($payload['status'] ?? 'validado');
if (!in_array($status, ['validado', 'reprovado'], true)) $status = 'validado';

// Reprovação (ver botão "Reprovar" de pages/qualidade/paint-check.php): pode não
// ter checklist nenhum — o operador reprova antes de qualquer campo ter sido
// lido. Validação normal continua exigindo pelo menos 1 campo.
if (!is_array($campos)) $campos = [];
if ($numSerie === '' || ($status === 'validado' && count($campos) === 0)) {
    echo json_encode(['success' => false, 'error' => 'Payload inválido — faltam num_serie ou campos.']);
    exit;
}

$idUsuario = (int) (currentUser()['id'] ?? 0);
if ($idUsuario <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessão de usuário inválida']);
    exit;
}

// Mesmo padrão de uploads já usado em api/retrabalho-acao.php — nome aleatório
// (nunca reaproveita nome original), pasta por ano-mês pra não acumular
// dezenas de milhares de arquivos numa pasta só.
$destDir = __DIR__ . '/../uploads/paint-check/' . date('Y-m');
if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
    echo json_encode(['success' => false, 'error' => 'Não foi possível preparar o diretório de evidências.']);
    exit;
}

/** Decodifica um base64 (com ou sem prefixo data:) e grava como .jpg com nome aleatório. Devolve o caminho relativo salvo, ou null se não houver imagem. */
function salvarRecorte(string $destDir, ?string $base64): ?string
{
    if ($base64 === null || $base64 === '') return null;
    if (strpos($base64, ',') !== false) {
        $base64 = explode(',', $base64, 2)[1];
    }
    $bin = base64_decode($base64, true);
    if ($bin === false) return null;

    $nomeArquivo = bin2hex(random_bytes(16)) . '.jpg';
    $caminhoAbsoluto = $destDir . '/' . $nomeArquivo;
    if (@file_put_contents($caminhoAbsoluto, $bin) === false) return null;

    // Caminho relativo salvo no banco (a partir de uploads/) — o valor absoluto do
    // disco não é portável entre ambientes.
    return 'paint-check/' . basename($destDir) . '/' . $nomeArquivo;
}

$pdo = getDB();

try {
    $pdo->beginTransaction();

    $todosOk = true;
    foreach ($campos as $campo) {
        if (empty($campo['ok'])) { $todosOk = false; break; }
    }
    // Reprovação nunca é "tudo confirmado" — mesmo que algum campo tenha lido
    // certo antes de reprovar, o resultado geral da peça foi reprovação, não validação.
    if ($status === 'reprovado') $todosOk = false;

    $stmtValidacao = $pdo->prepare("
        INSERT INTO paint_check_validacoes
            (num_serie, cliente, nome_grupo, concessionaria_fallback, todos_campos_ok, status, id_usuario)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtValidacao->execute([
        $numSerie,
        (string) ($payload['cliente'] ?? ''),
        (string) ($payload['nome_grupo'] ?? ''),
        !empty($payload['fallback']) ? 1 : 0,
        $todosOk ? 1 : 0,
        $status,
        $idUsuario,
    ]);
    $idValidacao = (int) $pdo->lastInsertId();

    $stmtCampo = $pdo->prepare("
        INSERT INTO paint_check_validacao_campos
            (id_validacao, slot, label, status, esperado, leitura_proxima, distancia, leituras_json, detalhe, confirmado_manualmente, foto_recorte_path)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($campos as $campo) {
        $recortePath = salvarRecorte($destDir, isset($campo['foto_recorte']) ? (string) $campo['foto_recorte'] : null);

        $stmtCampo->execute([
            $idValidacao,
            (string) ($campo['slot'] ?? ''),
            (string) ($campo['label'] ?? ''),
            (string) ($campo['status'] ?? (!empty($campo['ok']) ? 'confirmado' : 'ilegivel')),
            $campo['esperado'] ?? null,
            $campo['leitura_proxima'] ?? null,
            isset($campo['distancia']) ? (int) $campo['distancia'] : null,
            isset($campo['leituras']) ? json_encode($campo['leituras'], JSON_UNESCAPED_UNICODE) : null,
            $campo['detalhe'] ?? null,
            !empty($campo['confirmado_manualmente']) ? 1 : 0,
            $recortePath,
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'id' => $idValidacao], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar validação: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
