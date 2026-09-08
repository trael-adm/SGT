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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido']);
    exit;
}

// O acesso a este painel é protegido pelo acesso 'qua.tip', 'tab:retrabalho' ou admin
if (!hasAcesso('qua.tip') && !hasAcesso('tab:retrabalho') && !hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Sem permissão de acesso']);
    exit;
}

$acao = trim((string)($_POST['acao'] ?? ''));
$pdo = getDB();

// Verificação de permissão de escrita para ações de modificação
if (in_array($acao, ['salvar', 'excluir', 'salvar_campo_rapido'], true)) {
    if (!podeEditar('qua.tip') && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['sucesso' => false, 'erro' => 'Apenas consulta: você não tem permissão para cadastrar ou editar tipos de reprova.']);
        exit;
    }
}

function gerarProximoCodigoReprova(string $setorCausador, PDO $pdo): string {
    $setorNorm = mb_strtoupper(trim($setorCausador));
    $prefixMap = [
        'ENGENHARIA'     => 'EG',
        'CALDEIRARIA'    => 'C',
        'ELÉTRICO'       => 'E',
        'ELETRICO'       => 'E',
        'LINHA'          => 'L',
        'MONTAGEM FINAL' => 'L',
        'PINTURA'        => 'P',
        'REVITALIZAÇÃO'  => 'R',
        'REVITALIZACAO'  => 'R',
    ];

    $prefix = $prefixMap[$setorNorm] ?? '';

    if ($prefix === '') {
        $prefix = 'SC';
    }

    $stmt = $pdo->prepare("SELECT codigo FROM reprovas WHERE codigo REGEXP ?");
    $stmt->execute(['^' . $prefix . '[0-9]+$']);
    $codigos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maxNum = 0;
    foreach ($codigos as $cod) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/i', $cod, $m)) {
            $num = (int)$m[1];
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }
    }

    return $prefix . ($maxNum + 1);
}

switch ($acao) {
    case 'obter_proximo_codigo':
        $setor = trim((string)($_POST['setor_causador'] ?? ''));
        $prox = gerarProximoCodigoReprova($setor, $pdo);
        echo json_encode(['sucesso' => true, 'proximo_codigo' => $prox]);
        break;

    case 'salvar_reprova':
        $id            = (int)($_POST['id'] ?? 0);
        $setorCausador = mb_strtoupper(trim((string)($_POST['setor_causador'] ?? '')));
        if ($setorCausador === '') {
            $setorCausador = 'S/ SETOR CAUSADOR';
        }
        
        $codigo        = mb_strtoupper(trim((string)($_POST['codigo'] ?? '')));
        if ($codigo === '' && $id === 0) {
            $codigo = gerarProximoCodigoReprova($setorCausador, $pdo);
        }

        $familia       = mb_strtoupper(trim((string)($_POST['familia'] ?? '')));
        $descricao     = mb_strtoupper(trim((string)($_POST['descricao'] ?? '')));
        // O formulário de edição só envia checkboxes "locais[]" (nunca o campo
        // singular "local"). Checkbox HTML desmarcado não é enviado pelo navegador
        // — então "nenhum setor marcado" chega aqui como locais AUSENTE, não como
        // array vazio. Os dois casos significam a mesma coisa: nenhuma estação
        // selecionada de propósito (reprova fica indisponível pra apontamento até
        // ser reconfigurada) — não cai mais em 'GER' por padrão em nenhum dos dois.
        $locaisPost = $_POST['locais'] ?? [];
        if (is_array($locaisPost)) {
            $validos = array_values(array_unique(array_intersect(array_map('strtoupper', array_map('trim', $locaisPost)), ['IQF', 'LAB', 'RET'])));
            $local = implode(',', $validos);
        } else {
            $local = mb_strtoupper(trim((string)$locaisPost));
        }

        $vaiRetrabalho = isset($_POST['vai_retrabalho']) ? ((int)$_POST['vai_retrabalho'] === 0 ? 0 : 1) : 1;

        if ($codigo === '' || $familia === '' || $descricao === '') {
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'Preencha todos os campos corretamente.']);
            exit;
        }

        try {
            if ($id > 0) {
                // Verificar se o código já existe para outro ID
                $stmt = $pdo->prepare("SELECT id FROM reprovas WHERE codigo = ? AND id != ?");
                $stmt->execute([$codigo, $id]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['sucesso' => false, 'erro' => 'Já existe uma reprova com este código.']);
                    exit;
                }

                $stmt = $pdo->prepare("UPDATE reprovas SET codigo = ?, setor_causador = ?, familia = ?, descricao = ?, local = ?, vai_retrabalho = ? WHERE id = ?");
                $stmt->execute([$codigo, $setorCausador, $familia, $descricao, $local, $vaiRetrabalho, $id]);
                echo json_encode(['sucesso' => true, 'mensagem' => 'Reprova atualizada com sucesso.']);
            } else {
                // Verificar se o código já existe
                $stmt = $pdo->prepare("SELECT id FROM reprovas WHERE codigo = ?");
                $stmt->execute([$codigo]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['sucesso' => false, 'erro' => 'Já existe uma reprova com este código.']);
                    exit;
                }

                // Definir ordem máxima para o novo registro
                $stmt = $pdo->query("SELECT MAX(ordem) AS max_ordem FROM reprovas");
                $maxOrdem = (int)$stmt->fetchColumn();
                $novaOrdem = $maxOrdem + 1;

                $stmt = $pdo->prepare("INSERT INTO reprovas (codigo, setor_causador, familia, descricao, local, vai_retrabalho, ordem, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$codigo, $setorCausador, $familia, $descricao, $local, $vaiRetrabalho, $novaOrdem]);
                echo json_encode(['sucesso' => true, 'mensagem' => 'Reprova cadastrada com sucesso.', 'codigo' => $codigo]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['sucesso' => false, 'erro' => 'Erro interno ao salvar: ' . $e->getMessage()]);
        }
        break;

    case 'toggle_vai_retrabalho':
    case 'set_vai_retrabalho':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'ID inválido.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT vai_retrabalho FROM reprovas WHERE id = ? AND ativo = 1");
            $stmt->execute([$id]);
            $current = $stmt->fetchColumn();

            if ($current === false) {
                http_response_code(404);
                echo json_encode(['sucesso' => false, 'erro' => 'Reprova não encontrada.']);
                exit;
            }

            if (isset($_POST['valor'])) {
                $novoValor = ((int)$_POST['valor'] === 1) ? 1 : 0;
            } else {
                $novoValor = ((int)$current === 1) ? 0 : 1;
            }

            $stmt = $pdo->prepare("UPDATE reprovas SET vai_retrabalho = ? WHERE id = ?");
            $stmt->execute([$novoValor, $id]);

            echo json_encode([
                'sucesso' => true,
                'id' => $id,
                'vai_retrabalho' => $novoValor,
                'mensagem' => $novoValor === 1 ? 'Definido para ir ao Retrabalho.' : 'Definido para correção interna (Retornos).'
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['sucesso' => false, 'erro' => 'Erro ao alternar status de retrabalho.']);
        }
        break;

    case 'toggle_setor':
        $id    = (int)($_POST['id'] ?? 0);
        $setor = mb_strtoupper(trim((string)($_POST['setor'] ?? '')));

        if ($id <= 0 || !in_array($setor, ['LAB', 'RET', 'IQF'], true)) {
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'Parâmetros inválidos.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT local FROM reprovas WHERE id = ? AND ativo = 1");
            $stmt->execute([$id]);
            $currentLocal = $stmt->fetchColumn();

            if ($currentLocal === false) {
                http_response_code(404);
                echo json_encode(['sucesso' => false, 'erro' => 'Reprova não encontrada.']);
                exit;
            }

            $currentLocal = strtoupper(trim((string)$currentLocal));
            $locais = [];
            if ($currentLocal === 'GER') {
                $locais = ['LAB', 'RET', 'IQF'];
            } elseif ($currentLocal !== '') {
                $locais = array_values(array_filter(array_map('trim', explode(',', $currentLocal))));
            }

            if (in_array($setor, $locais, true)) {
                // Remover setor
                $locais = array_values(array_filter($locais, fn($s) => $s !== $setor));
            } else {
                // Adicionar setor
                $locais[] = $setor;
            }

            // Manter ordem padrão LAB, RET, IQF
            $ordemPadrao = ['LAB', 'RET', 'IQF'];
            $locaisFinal = [];
            foreach ($ordemPadrao as $op) {
                if (in_array($op, $locais, true)) {
                    $locaisFinal[] = $op;
                }
            }

            $novoLocal = count($locaisFinal) === 3 ? 'GER' : implode(',', $locaisFinal);

            $stmt = $pdo->prepare("UPDATE reprovas SET local = ? WHERE id = ?");
            $stmt->execute([$novoLocal, $id]);

            echo json_encode([
                'sucesso' => true,
                'locais' => $locaisFinal,
                'novo_local' => $novoLocal
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['sucesso' => false, 'erro' => 'Erro ao alternar setor.']);
        }
        break;

    case 'excluir_reprova':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'ID inválido.']);
            exit;
        }

        try {
            // Soft delete
            $stmt = $pdo->prepare("UPDATE reprovas SET ativo = 0 WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Reprova desativada com sucesso.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['sucesso' => false, 'erro' => 'Erro interno ao excluir.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'erro' => 'Ação inválida.']);
        break;
}
