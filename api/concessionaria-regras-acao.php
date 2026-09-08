<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Acesso restrito ao Administrador']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido']);
    exit;
}

$acao   = trim((string) ($_POST['acao'] ?? ''));
$userId = (int) (currentUser()['id'] ?? 0);

/** Lê e normaliza os campos do formulário de regra de concessionária. */
function lerCamposConcessionariaRegra(): array
{
    $nomeGrupo   = trim((string) ($_POST['nome_grupo'] ?? ''));
    $normaRef    = trim((string) ($_POST['norma_referencia'] ?? ''));
    $observacoes = trim((string) ($_POST['observacoes'] ?? ''));

    $locaisObrigValidos = ['tampa', 'tanque', 'gancho'];
    $locaisObrig = array_values(array_intersect((array) ($_POST['locais_obrigatorios'] ?? []), $locaisObrigValidos));

    // Onde o código adicional/patrimônio aparece serigrafado — multivalorado
    // (ex.: Equatorial e Neoenergia têm o código tanto na tampa quanto no
    // tanque), por isso checkboxes em vez de um select de valor único.
    $localCodValidos = ['tampa', 'tanque'];
    $localCod = array_values(array_intersect((array) ($_POST['local_codigo_adicional'] ?? []), $localCodValidos));

    // Onde a serigrafia de potência (kVA) é exigida — universal no tanque,
    // só a Energisa exige também na tampa (pintura.md §3.3.C).
    $localPotenciaValidos = ['tampa', 'tanque'];
    $localPotencia = array_values(array_intersect((array) ($_POST['local_potencia'] ?? []), $localPotenciaValidos));

    $exigeEloFusivel = !empty($_POST['exige_elo_fusivel']) ? 1 : 0;

    $apelidos = [];
    foreach ((array) ($_POST['apelidos'] ?? []) as $a) {
        $a = trim((string) $a);
        if ($a !== '') $apelidos[] = $a;
    }
    $apelidos = array_values(array_unique($apelidos));

    return [
        'nome_grupo' => $nomeGrupo,
        'norma_referencia' => $normaRef !== '' ? $normaRef : null,
        'local_codigo_adicional' => $localCod,
        'local_potencia' => $localPotencia,
        'exige_elo_fusivel' => $exigeEloFusivel,
        'locais_obrigatorios' => $locaisObrig,
        'observacoes' => $observacoes !== '' ? $observacoes : null,
        'ativo' => !empty($_POST['ativo']) ? 1 : 0,
        'apelidos' => $apelidos,
    ];
}

/** Valida os campos — retorna string de erro ou null se OK. $idAtual exclui o próprio registro na edição. */
function validarConcessionariaRegra(PDO $pdo, array $c, int $idAtual = 0): ?string
{
    if ($c['nome_grupo'] === '') return 'Informe o nome do grupo/concessionária.';
    if (empty($c['locais_obrigatorios'])) return 'Selecione ao menos um local obrigatório (Tampa, Tanque ou Gancho).';
    if (empty($c['local_potencia'])) return 'Selecione ao menos um local de potência (Tampa ou Tanque) — é serigrafia universal.';

    $q = $pdo->prepare('SELECT id FROM concessionaria_regras WHERE nome_grupo = ? AND deleted_at IS NULL AND id <> ?');
    $q->execute([$c['nome_grupo'], $idAtual]);
    if ($q->fetch()) return 'Já existe uma regra cadastrada para "' . $c['nome_grupo'] . '".';

    if (!empty($c['apelidos'])) {
        $ph = implode(',', array_fill(0, count($c['apelidos']), '?'));
        $q = $pdo->prepare("SELECT apelido FROM concessionaria_regras_apelidos WHERE apelido IN ($ph) AND id_regra <> ?");
        $q->execute([...$c['apelidos'], $idAtual]);
        if ($conf = $q->fetch()) {
            return 'O apelido "' . $conf['apelido'] . '" já está cadastrado em outra concessionária.';
        }
    }

    return null;
}

/** Substitui a lista de apelidos de uma regra (mesma estratégia de "reenvia tudo" já usada em outros CRUDs do sistema). */
function gravarApelidos(PDO $pdo, int $idRegra, array $apelidos): void
{
    $pdo->prepare('DELETE FROM concessionaria_regras_apelidos WHERE id_regra = ?')->execute([$idRegra]);
    if (!$apelidos) return;

    $stmt = $pdo->prepare('INSERT INTO concessionaria_regras_apelidos (id_regra, apelido) VALUES (?, ?)');
    foreach ($apelidos as $apelido) {
        $stmt->execute([$idRegra, $apelido]);
    }
}

try {
    $pdo = getDB();

    switch ($acao) {

        case 'salvar': {
            $id = (int) ($_POST['id'] ?? 0);
            $c  = lerCamposConcessionariaRegra();

            if ($err = validarConcessionariaRegra($pdo, $c, $id)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $err]);
                exit;
            }

            $locaisObrigStr  = implode(',', $c['locais_obrigatorios']);
            $localCodStr     = implode(',', $c['local_codigo_adicional']);
            $localPotenciaStr = implode(',', $c['local_potencia']);

            $pdo->beginTransaction();

            if ($id > 0) {
                $stmtCheck = $pdo->prepare('SELECT nome_grupo FROM concessionaria_regras WHERE id = ? AND deleted_at IS NULL');
                $stmtCheck->execute([$id]);
                $atual = $stmtCheck->fetch();
                if (!$atual) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['sucesso' => false, 'erro' => 'Regra não encontrada.']);
                    exit;
                }
                if ($atual['nome_grupo'] === 'PARTICULAR' && $c['nome_grupo'] !== 'PARTICULAR') {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['sucesso' => false, 'erro' => 'A regra PARTICULAR (padrão ABNT) não pode ser renomeada — ela é o fallback do sistema.']);
                    exit;
                }

                $pdo->prepare('
                    UPDATE concessionaria_regras SET
                        nome_grupo = ?, norma_referencia = ?,
                        local_codigo_adicional = ?, local_potencia = ?, exige_elo_fusivel = ?, locais_obrigatorios = ?,
                        observacoes = ?, ativo = ?
                    WHERE id = ? AND deleted_at IS NULL
                ')->execute([
                    $c['nome_grupo'], $c['norma_referencia'],
                    $localCodStr, $localPotenciaStr, $c['exige_elo_fusivel'], $locaisObrigStr,
                    $c['observacoes'], $c['ativo'], $id,
                ]);
            } else {
                $pdo->prepare('
                    INSERT INTO concessionaria_regras
                        (nome_grupo, norma_referencia,
                         local_codigo_adicional, local_potencia, exige_elo_fusivel, locais_obrigatorios, observacoes, ativo, id_criador)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    $c['nome_grupo'], $c['norma_referencia'],
                    $localCodStr, $localPotenciaStr, $c['exige_elo_fusivel'], $locaisObrigStr, $c['observacoes'], $c['ativo'], $userId,
                ]);
                $id = (int) $pdo->lastInsertId();
            }

            gravarApelidos($pdo, $id, $c['apelidos']);

            $pdo->commit();

            echo json_encode(['sucesso' => true, 'mensagem' => 'Regra salva com sucesso.', 'id' => $id]);
            break;
        }

        case 'excluir': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Regra inválida.']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT nome_grupo FROM concessionaria_regras WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$id]);
            $regra = $stmt->fetch();
            if (!$regra) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Regra não encontrada.']);
                exit;
            }
            // A regra PARTICULAR pode ser excluída — se isso acontecer,
            // buscarRegraConcessionaria() (includes/concessionaria-regras.php)
            // cai num fallback embutido no código (padrão ABNT hardcoded), então
            // o Paint Check continua funcionando; só se perde a customização
            // que estivesse salva nessa linha (norma, locais obrigatórios etc.).

            $pdo->prepare('UPDATE concessionaria_regras SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Regra excluída.']);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'Ação inválida.']);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
}
