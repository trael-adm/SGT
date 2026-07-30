<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/planilha-ns-of.php';

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

/** Converte uma string 'YYYY-MM-DD' em data válida ou null. */
function lerData(string $d): ?string
{
    $d = trim($d);
    return ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
}

/**
 * Lê e normaliza os campos comuns do formulário de retrabalho — tudo exceto a
 * identificação da reprova (id_reprova/data_reprova), que em "registrar" pode
 * vir em lote (várias reprovas numa só triagem) e em "editar" é um valor único.
 */
function lerCamposRetrabalho(): array
{
    $obs          = trim((string) ($_POST['observacoes'] ?? ''));
    $causaRaiz    = trim((string) ($_POST['causa_raiz'] ?? ''));
    $causaReprova = trim((string) ($_POST['causa_reprova'] ?? ''));
    $ns           = trim((string) ($_POST['ns_transformador'] ?? ''));
    $idProjeto    = (int) ($_POST['id_projeto'] ?? 0);

    // Obs.: responsável = usuário logado (definido na ação). Status = derivado (derivarStatus()).
    return [
        'observacoes'      => $obs !== '' ? $obs : null,
        'causa_raiz'       => $causaRaiz !== '' ? $causaRaiz : null,
        'causa_reprova'    => $causaReprova !== '' ? $causaReprova : null,
        'ns_transformador' => $ns !== '' ? $ns : null,
        'id_projeto'       => $idProjeto > 0 ? $idProjeto : null,
        'data_chegada'     => lerData((string) ($_POST['data_chegada'] ?? '')),
        'data_inicio'      => lerData((string) ($_POST['data_inicio'] ?? '')),
        'data_finalizacao' => lerData((string) ($_POST['data_finalizacao'] ?? '')),
        'setores_destino'  => lerSetoresDestino(),
    ];
}

/** Lê `id_reprova[]` + `data_reprova[]` do POST (lote da triagem). Ignora entradas sem código. */
function lerReprovasEmLote(): array
{
    $ids   = (array) ($_POST['id_reprova'] ?? []);
    $datas = (array) ($_POST['data_reprova'] ?? []);
    $itens = [];
    foreach ($ids as $i => $idReprova) {
        $idReprova = (int) $idReprova;
        if ($idReprova <= 0) continue;
        $itens[] = ['id_reprova' => $idReprova, 'data_reprova' => lerData((string) ($datas[$i] ?? ''))];
    }
    return $itens;
}

/** Lê `setores_destino[]` do POST e valida contra a lista permitida. Retorna string "a,b,c" ou null. */
function lerSetoresDestino(): ?string
{
    $permitidos = array_keys(retrabalhoSetoresTriagem());
    $enviados   = array_intersect((array) ($_POST['setores_destino'] ?? []), $permitidos);
    return $enviados ? implode(',', array_values($enviados)) : null;
}

/**
 * Move os anexos enviados em `anexos[]` (multipart) para o diretório final, uma
 * única vez. Valida extensão + MIME real do arquivo. Retorna a lista de arquivos
 * movidos (para depois vincular a 1+ retrabalhos com vincularAnexos()) ou uma
 * string de erro. Lista vazia (sem erro) se nenhum arquivo foi enviado.
 */
function processarAnexosUpload(): array|string
{
    if (empty($_FILES['anexos']) || empty($_FILES['anexos']['name'][0])) {
        return [];
    }

    $ALLOWED = [
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',  'gif'  => 'image/gif',
        'webp' => 'image/webp', 'pdf'  => 'application/pdf',
    ];
    $MAX_BYTES = 8 * 1024 * 1024;
    $MAX_ARQUIVOS = 10;

    $nomes    = $_FILES['anexos']['name'];
    $tmpNames = $_FILES['anexos']['tmp_name'];
    $errors   = $_FILES['anexos']['error'];
    $sizes    = $_FILES['anexos']['size'];

    if (count($nomes) > $MAX_ARQUIVOS) {
        return "Envie no máximo {$MAX_ARQUIVOS} anexos por vez.";
    }

    $destDir = __DIR__ . '/../uploads/retrabalho';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return 'Não foi possível preparar o diretório de anexos.';
    }

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $movidos  = [];

    foreach ($nomes as $i => $nomeOriginal) {
        if ($nomeOriginal === '' || ($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($errors[$i] !== UPLOAD_ERR_OK) {
            return "Falha ao enviar o arquivo \"{$nomeOriginal}\".";
        }
        if ($sizes[$i] > $MAX_BYTES) {
            return "O arquivo \"{$nomeOriginal}\" excede o limite de 8MB.";
        }

        $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        if (!isset($ALLOWED[$ext])) {
            return "Tipo de arquivo não permitido: \"{$nomeOriginal}\" (use imagem ou PDF).";
        }
        $mimeReal = finfo_file($finfo, $tmpNames[$i]);
        if ($mimeReal !== $ALLOWED[$ext]) {
            return "O conteúdo do arquivo \"{$nomeOriginal}\" não corresponde à extensão informada.";
        }

        $nomeArquivo = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($tmpNames[$i], $destDir . '/' . $nomeArquivo)) {
            return "Não foi possível salvar o arquivo \"{$nomeOriginal}\".";
        }

        $movidos[] = ['nome_arquivo' => $nomeArquivo, 'nome_original' => $nomeOriginal, 'tamanho_bytes' => (int) $sizes[$i]];
    }
    finfo_close($finfo);

    return $movidos;
}

/** Vincula os arquivos já movidos (processarAnexosUpload()) a 1+ retrabalhos. */
function vincularAnexos(PDO $pdo, array $arquivos, array $idsRetrabalho, int $userId): void
{
    if (!$arquivos || !$idsRetrabalho) return;
    $stmt = $pdo->prepare("
        INSERT INTO retrabalho_anexos (id_retrabalho, nome_arquivo, nome_original, tamanho_bytes, id_criador)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($idsRetrabalho as $idRetrabalho) {
        foreach ($arquivos as $a) {
            $stmt->execute([$idRetrabalho, $a['nome_arquivo'], $a['nome_original'], $a['tamanho_bytes'], $userId]);
        }
    }
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
function validarRetrabalho(PDO $pdo, array $c, int $idReprova, int $idAtual = 0): ?string
{
    if ($c['id_projeto'] === null)           return 'Selecione o projeto.';
    if ($c['ns_transformador'] === null)     return 'Informe o N° de série do transformador.';
    if ($idReprova <= 0)                     return 'Selecione o código de reprova.';

    // Projeto precisa existir
    $q = $pdo->prepare("SELECT id FROM projetos WHERE id = ? AND deleted_at IS NULL");
    $q->execute([$c['id_projeto']]);
    if (!$q->fetch()) return 'Projeto inválido.';

    // Reprova precisa existir e estar ativa
    $q = $pdo->prepare("SELECT id FROM reprovas WHERE id = ? AND ativo = 1");
    $q->execute([$idReprova]);
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

        // ─── Registrar novo(s) retrabalho(s) — 1+ reprovas numa mesma triagem ──
        case 'registrar': {
            $c        = lerCamposRetrabalho();
            $reprovas = lerReprovasEmLote();

            if (!$reprovas) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Adicione ao menos uma reprova.']);
                exit;
            }
            foreach ($reprovas as $i => $rep) {
                if ($err = validarRetrabalho($pdo, $c, $rep['id_reprova'])) {
                    http_response_code(400);
                    echo json_encode(['sucesso' => false, 'erro' => 'Reprova ' . ($i + 1) . ': ' . $err]);
                    exit;
                }
            }

            $arquivos = processarAnexosUpload();
            if (is_string($arquivos)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $arquivos]);
                exit;
            }

            $status      = derivarStatus($c);
            $concluidoEm = ($status === 'finalizado') ? date('Y-m-d H:i:s') : null;

            // Responsável = usuário logado (fixo). Status derivado dos dados. Triagem
            // (chegada/causa/observações/setores/anexos) é a mesma para todo o lote.
            $stmt = $pdo->prepare("
                INSERT INTO retrabalhos
                    (id_responsavel, id_projeto, ns_transformador, id_reprova,
                     data_reprova, data_chegada, data_inicio, data_finalizacao, status,
                     observacoes, causa_raiz, causa_reprova, setores_destino,
                     id_criador, concluido_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $novosIds = [];
            foreach ($reprovas as $rep) {
                $stmt->execute([
                    $userId, $c['id_projeto'], $c['ns_transformador'], $rep['id_reprova'],
                    $rep['data_reprova'], $c['data_chegada'], $c['data_inicio'], $c['data_finalizacao'], $status,
                    $c['observacoes'], $c['causa_raiz'], $c['causa_reprova'], $c['setores_destino'],
                    $userId, $concluidoEm,
                ]);
                $novosIds[] = (int) $pdo->lastInsertId();
            }

            // Marca todo o lote com o id do 1º registro: reprovas da mesma triagem contam
            // como 1 ocorrência só nas Repetências (ver COALESCE(id_lote, id) em relacao.php).
            if ($novosIds) {
                $ph = implode(',', array_fill(0, count($novosIds), '?'));
                $pdo->prepare("UPDATE retrabalhos SET id_lote = ? WHERE id IN ($ph)")
                    ->execute([$novosIds[0], ...$novosIds]);
            }

            // "Laboratório" em Próximos setores manda o transformador de volta pra lá —
            // cai na aba Retornos do Produção (ver pages/producao/retornos.php) até o
            // operador dar entrada de novo por lá (o que resolve essa linha sozinho,
            // ver api/producao-acao.php::case 'confirmar'). Os demais setores ainda não
            // têm tela própria no Produção, então não geram nada aqui por enquanto.
            $setoresDestino = $c['setores_destino'] !== null ? explode(',', $c['setores_destino']) : [];
            if (in_array('laboratorio', $setoresDestino, true)) {
                $pdo->prepare("
                    INSERT INTO producao_etapas
                        (ns_transformador, id_projeto, estacao, status, data_inicio, id_responsavel, id_criador)
                    VALUES (?, ?, 'LAB', 'aguardando_retorno', NOW(), ?, ?)
                ")->execute([$c['ns_transformador'], $c['id_projeto'], $userId, $userId]);
            }

            vincularAnexos($pdo, $arquivos, $novosIds, $userId);

            $msg = count($novosIds) > 1 ? count($novosIds) . ' reprovas registradas.' : 'Retrabalho registrado.';
            echo json_encode(['sucesso' => true, 'mensagem' => $msg, 'ids' => $novosIds]);
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
            $c         = lerCamposRetrabalho();
            $idReprova = (int) ($_POST['id_reprova'] ?? 0);
            $dataReprova = lerData((string) ($_POST['data_reprova'] ?? ''));
            if ($err = validarRetrabalho($pdo, $c, $idReprova, $id)) {
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
                    id_reprova = ?, data_reprova = ?, data_chegada = ?, data_inicio = ?, data_finalizacao = ?,
                    status = ?, observacoes = ?, causa_raiz = ?, causa_reprova = ?, setores_destino = ?, concluido_em = ?
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([
                $c['id_projeto'], $c['ns_transformador'], $idReprova,
                $dataReprova, $c['data_chegada'], $c['data_inicio'], $c['data_finalizacao'],
                $status, $c['observacoes'], $c['causa_raiz'], $c['causa_reprova'], $c['setores_destino'],
                $concluidoEm, $id,
            ]);

            $arquivos = processarAnexosUpload();
            if (is_string($arquivos)) {
                echo json_encode(['sucesso' => true, 'mensagem' => 'Retrabalho atualizado, mas houve um problema com os anexos: ' . $arquivos]);
                break;
            }
            vincularAnexos($pdo, $arquivos, [$id], $userId);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Retrabalho atualizado.']);
            break;
        }

        // ─── Confirmar chegada física ao retrabalho via leitura de QR ──────────
        // O código lido é resolvido do mesmo jeito que a leitura de QR de Produção
        // (etiqueta de OF -> planilha NS.OF, com N° de série puro como reserva) e
        // comparado com o que este registro espera — nunca confia num id_projeto/ns
        // vindo do cliente. Só grava data_chegada quando bate.
        case 'confirmar_chegada': {
            $id     = (int) ($_POST['id'] ?? 0);
            $codigo = trim((string) ($_POST['codigo'] ?? ''));

            if ($codigo === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Informe o código lido.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT r.id, r.ns_transformador, r.data_chegada, pr.codigo AS projeto_codigo
                FROM retrabalhos r
                JOIN projetos pr ON pr.id = r.id_projeto
                WHERE r.id = ? AND r.deleted_at IS NULL
            ");
            $stmt->execute([$id]);
            $registro = $stmt->fetch();
            if (!$registro) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro não encontrado.']);
                exit;
            }
            if ($registro['data_chegada'] !== null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Chegada já confirmada para este registro.']);
                exit;
            }

            // Etiqueta de OF resolve N° de série + projeto pela planilha; código que não
            // bate nesse formato (ou que a planilha não conhece) é tratado como o próprio
            // N° de série digitado à mão — aí só o NS é conferido, sem projeto/OF.
            $cdOf    = extrairCdOfDaEtiqueta($codigo);
            $dadosOF = $cdOf !== null ? buscarPlanilhaOF($cdOf) : null;
            if ($dadosOF && $dadosOF['num_serie'] !== '') {
                $nsLido   = $dadosOF['num_serie'];
                $projLido = $dadosOF['cd_referencia'] !== '' ? $dadosOF['cd_referencia'] : null;
            } else {
                $nsLido   = $codigo;
                $projLido = null;
            }

            $nsConfere   = $nsLido === $registro['ns_transformador'];
            $projConfere = $projLido === null || $projLido === $registro['projeto_codigo'];

            if (!$nsConfere || !$projConfere) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' =>
                    'Transformador não confere: esperado NS ' . $registro['ns_transformador'] . ' / Projeto ' . $registro['projeto_codigo']
                    . ', lido NS ' . $nsLido . ($projLido !== null ? ' / Projeto ' . $projLido : '') . '.'
                ]);
                exit;
            }

            $pdo->prepare("UPDATE retrabalhos SET data_chegada = CURDATE() WHERE id = ?")->execute([$id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Chegada confirmada.']);
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
