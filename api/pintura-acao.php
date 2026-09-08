<?php
declare(strict_types=1);

/**
 * Backend de apontamento da estação PIN (Pintura) — dedicado e isolado de
 * api/producao-acao.php (LAB/IQF): nenhuma função compartilhada, mesma
 * lógica de resolução/gravação replicada aqui hardcoded pra `estacao='PIN'`
 * (ver pages/pintura/index.php e pages/pintura/lista.php).
 *
 * Ações de reprova/retorno (registrar reprova, aprovar/reprovar/excluir
 * retorno) NÃO vivem aqui — ficam em api/pintura-retornos-acao.php, mesma
 * separação de responsabilidade que LAB/IQF já têm entre
 * api/producao-acao.php (apontamento) e api/retrabalho-acao.php (retrabalho).
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/planilha-ns-of.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

if (!hasAcesso('tab:pintura') && !hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Sem permissão de acesso ao módulo de Pintura']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido']);
    exit;
}

$acao   = trim((string) ($_POST['acao'] ?? ''));
$userId = (int) (currentUser()['id'] ?? 0);

if (in_array($acao, ['confirmar', 'remover_etapa'], true)) {
    if (!podeEditar('pin.reg') && !podeEditar('pin.lis') && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['sucesso' => false, 'erro' => 'Apenas consulta: você não tem permissão para confirmar ou remover etapas de produção.']);
        exit;
    }
}

const PIN_ESTACAO = 'PIN';

/** Item em_andamento (qualquer estação) para um N° de série, se houver. */
function pinBuscarEmAndamento(PDO $pdo, string $ns): ?array
{
    $stmt = $pdo->prepare("
        SELECT pe.*, pr.codigo AS projeto_codigo, ped.numero AS pedido_numero
        FROM producao_etapas pe
        JOIN projetos pr ON pr.id = pe.id_projeto
        JOIN pedidos ped ON ped.id = pr.id_pedido
        WHERE pe.ns_transformador = ? AND pe.status = 'em_andamento' AND pe.deleted_at IS NULL
        ORDER BY pe.data_inicio DESC
        LIMIT 1
    ");
    $stmt->execute([$ns]);
    return $stmt->fetch() ?: null;
}

/** Retorna true se o transformador já estiver no Retrabalho em aberto. */
function pinBuscarEmRetrabalho(PDO $pdo, string $ns): bool
{
    $stmt = $pdo->prepare("
        SELECT id FROM retrabalhos
        WHERE ns_transformador = ? AND deleted_at IS NULL AND status != 'finalizado'
        LIMIT 1
    ");
    $stmt->execute([$ns]);
    return $stmt->fetch() !== false;
}

/**
 * Resolve um código escaneado/digitado — mesma lógica de
 * api/producao-acao.php::resolverTransformador(), replicada aqui (não
 * compartilhada) pra manter os dois backends de apontamento totalmente
 * isolados. Fonte: NS.OF.xlsx (includes/planilha-ns-of.php), mesma que
 * LAB/IQF usam — não é o VSAT que o Paint Check usa.
 */
function pinResolverTransformador(PDO $pdo, string $codigoBruto): array
{
    $codigo = trim($codigoBruto);
    $cdOf   = extrairCdOfDaEtiqueta($codigo);
    $ns     = $codigo;
    $descricao        = null;
    $cliente          = null;
    $cdReferenciaPlan = null;
    $cdPedidoPlan     = null;

    if ($cdOf !== null) {
        $dadosOF = buscarPlanilhaOF($cdOf);
        if (!$dadosOF || $dadosOF['num_serie'] === '') {
            return ['erro' => 'OF ' . $cdOf . ' não encontrada na planilha NS.OF.'];
        }
        $ns               = $dadosOF['num_serie'];
        $cdReferenciaPlan = $dadosOF['cd_referencia'] !== '' ? $dadosOF['cd_referencia'] : null;
        $cdPedidoPlan     = $dadosOF['cd_pedido'] !== '' ? $dadosOF['cd_pedido'] : null;
        $descricao        = $dadosOF['descricao'] !== '' ? $dadosOF['descricao'] : null;
        $cliente          = $dadosOF['cliente'] !== '' ? $dadosOF['cliente'] : null;
    } else {
        $dadosNS = buscarPlanilhaOFPorNs($ns);
        if ($dadosNS) {
            $cdReferenciaPlan = $dadosNS['cd_referencia'] !== '' ? $dadosNS['cd_referencia'] : null;
            $cdPedidoPlan     = $dadosNS['cd_pedido'] !== '' ? $dadosNS['cd_pedido'] : null;
            $descricao        = $dadosNS['descricao'] !== '' ? $dadosNS['descricao'] : null;
            $cliente          = $dadosNS['cliente'] !== '' ? $dadosNS['cliente'] : null;
            if (!empty($dadosNS['cd_of'])) {
                $cdOf = $dadosNS['cd_of'];
            }
        }
    }

    $stmt = $pdo->prepare("
        SELECT pt.ns_transformador, pt.id_projeto, pr.codigo AS projeto_codigo, ped.numero AS pedido_numero
        FROM producao_transformadores pt
        JOIN projetos pr  ON pr.id  = pt.id_projeto
        JOIN pedidos ped  ON ped.id = pr.id_pedido
        WHERE pt.ns_transformador = ? AND pt.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$ns]);
    $transf = $stmt->fetch() ?: null;

    $idProjeto     = null;
    $projetoCodigo = null;
    $pedidoNumero  = null;
    $precisaCriar  = false;

    if ($transf) {
        $idProjeto     = (int) $transf['id_projeto'];
        $projetoCodigo = $transf['projeto_codigo'];
        $pedidoNumero  = $transf['pedido_numero'];
    } elseif ($cdReferenciaPlan !== null) {
        $sq = $pdo->prepare("
            SELECT pr.id AS id_projeto, pr.codigo AS projeto_codigo, ped.numero AS pedido_numero
            FROM projetos pr
            JOIN pedidos ped ON ped.id = pr.id_pedido
            WHERE pr.codigo = ? AND pr.deleted_at IS NULL AND ped.deleted_at IS NULL
            LIMIT 1
        ");
        $sq->execute([$cdReferenciaPlan]);
        if ($achado = $sq->fetch()) {
            $idProjeto     = (int) $achado['id_projeto'];
            $projetoCodigo = $achado['projeto_codigo'];
            $pedidoNumero  = $achado['pedido_numero'];
        } else {
            $precisaCriar  = true;
            $projetoCodigo = $cdReferenciaPlan;
            $pedidoNumero  = ($cdPedidoPlan !== null && $cdPedidoPlan !== '') ? $cdPedidoPlan : 'S/P';
        }
    }

    return [
        'erro'               => null,
        'codigo'             => $codigo,
        'ns'                 => $ns,
        'cd_of'              => $cdOf,
        'descricao'          => $descricao,
        'cliente'            => $cliente,
        'ja_cadastrado'      => $transf !== null,
        'id_projeto'         => $idProjeto,
        'projeto_codigo'     => $projetoCodigo,
        'pedido_numero'      => $pedidoNumero,
        'precisa_criar'      => $precisaCriar,
        'cd_referencia_plan' => $cdReferenciaPlan,
        'cd_pedido_plan'     => $cdPedidoPlan,
    ];
}

/** Garante Pedido+Projeto identificados pela planilha, criando o que faltar — mesma lógica de api/producao-acao.php. */
function pinCriarPedidoProjetoDaPlanilha(PDO $pdo, string $codigoProjeto, string $numeroPedido, ?string $descricao): ?int
{
    $numeroPedido = trim($numeroPedido) !== '' ? trim($numeroPedido) : 'S/P';
    $pq = $pdo->prepare("SELECT id FROM pedidos WHERE numero = ? AND deleted_at IS NULL LIMIT 1");
    $pq->execute([$numeroPedido]);
    $pedido = $pq->fetch();
    if (!$pedido) {
        try {
            $pdo->prepare("INSERT INTO pedidos (numero) VALUES (?)")->execute([$numeroPedido]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
        }
        $pq->execute([$numeroPedido]);
        $pedido = $pq->fetch();
    }
    if (!$pedido) return null;

    $rq = $pdo->prepare("SELECT id FROM projetos WHERE codigo = ? AND deleted_at IS NULL LIMIT 1");
    $rq->execute([$codigoProjeto]);
    $projeto = $rq->fetch();
    if (!$projeto) {
        try {
            $pdo->prepare("INSERT INTO projetos (codigo, descricao, id_pedido) VALUES (?, ?, ?)")
                ->execute([$codigoProjeto, $descricao, (int) $pedido['id']]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
        }
        $rq->execute([$codigoProjeto]);
        $projeto = $rq->fetch();
    }
    return $projeto ? (int) $projeto['id'] : null;
}

try {
    $pdo = getDB();

    switch ($acao) {

        case 'ler': {
            $codigo = trim((string) ($_POST['codigo'] ?? ''));
            if ($codigo === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Informe o N° de série.']);
                exit;
            }

            $res = pinResolverTransformador($pdo, $codigo);
            if ($res['erro'] !== null) {
                echo json_encode(['sucesso' => false, 'erro' => $res['erro']]);
                exit;
            }

            $base = [
                'sucesso'        => true,
                'codigo'         => $res['codigo'],
                'ns'             => $res['ns'],
                'cd_of'          => $res['cd_of'],
                'descricao'      => $res['descricao'],
                'cliente'        => $res['cliente'],
                'projeto_codigo' => $res['projeto_codigo'],
                'pedido_numero'  => $res['pedido_numero'],
            ];

            if ($res['id_projeto'] === null && !$res['precisa_criar']) {
                echo json_encode($base + ['status' => 'nao_registrado']);
                exit;
            }

            if ($aberto = pinBuscarEmAndamento($pdo, $res['ns'])) {
                echo json_encode($base + [
                    'status'        => 'conflict',
                    'estacao_atual' => $aberto['estacao'],
                    'data_inicio'   => isoComOffset($aberto['data_inicio']),
                ]);
                exit;
            }

            if (pinBuscarEmRetrabalho($pdo, $res['ns'])) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'O número de série já se encontra em retrabalho.']);
                exit;
            }

            echo json_encode($base + ['status' => 'ok']);
            break;
        }

        case 'confirmar': {
            $codigo = trim((string) ($_POST['codigo'] ?? ''));

            if ($codigo === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'N° de série inválido.']);
                exit;
            }

            $res = pinResolverTransformador($pdo, $codigo);
            if ($res['erro'] !== null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $res['erro']]);
                exit;
            }
            $idProjeto = $res['id_projeto'];

            if ($idProjeto === null && $res['precisa_criar']) {
                $pedNum = ($res['cd_pedido_plan'] !== null && $res['cd_pedido_plan'] !== '') ? $res['cd_pedido_plan'] : 'S/P';
                $idProjeto = pinCriarPedidoProjetoDaPlanilha($pdo, $res['cd_referencia_plan'], $pedNum, $res['descricao']);
            }

            if ($idProjeto === null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Projeto não cadastrado no sistema — cadastre o Pedido/Projeto antes de registrar.']);
                exit;
            }

            $ns = $res['ns'];

            if (!$res['ja_cadastrado']) {
                try {
                    $ins = $pdo->prepare("INSERT INTO producao_transformadores (ns_transformador, id_projeto) VALUES (?, ?)");
                    $ins->execute([$ns, $idProjeto]);
                } catch (\PDOException $e) {
                    if ($e->getCode() !== '23000') throw $e;
                }
            }

            if ($aberto = pinBuscarEmAndamento($pdo, $ns)) {
                http_response_code(400);
                echo json_encode([
                    'sucesso' => false,
                    'erro'    => 'Este transformador já está em andamento na estação ' . $aberto['estacao'] . '.',
                ]);
                exit;
            }

            if (pinBuscarEmRetrabalho($pdo, $ns)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'O número de série já se encontra em retrabalho.']);
                exit;
            }

            $metodoInsercao = trim((string) ($_POST['metodo_insercao'] ?? 'scanner'));
            if (!in_array($metodoInsercao, ['scanner', 'manual'], true)) $metodoInsercao = 'scanner';

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO producao_etapas
                        (ns_transformador, id_projeto, estacao, metodo_insercao, `status`, data_inicio, id_responsavel, id_criador)
                    VALUES (?, ?, ?, ?, 'em_andamento', NOW(), ?, ?)
                ");
                $stmt->execute([$ns, $idProjeto, PIN_ESTACAO, $metodoInsercao, $userId, $userId]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    http_response_code(400);
                    echo json_encode(['sucesso' => false, 'erro' => 'Este transformador já está em andamento em outra estação.']);
                    exit;
                }
                throw $e;
            }
            $novoId = (int) $pdo->lastInsertId();

            // Resolve sozinho qualquer "aguardando retorno" pendente deste N° de série
            // na Pintura — o operador não precisa fazer nada além do apontamento normal.
            $pdo->prepare("
                UPDATE producao_etapas SET deleted_at = NOW()
                WHERE ns_transformador = ? AND estacao = ? AND status = 'aguardando_retorno' AND deleted_at IS NULL
            ")->execute([$ns, PIN_ESTACAO]);

            $stmt = $pdo->prepare("
                SELECT pe.*, pr.codigo AS projeto_codigo, ped.numero AS pedido_numero
                FROM producao_etapas pe
                JOIN projetos pr ON pr.id = pe.id_projeto
                JOIN pedidos ped ON ped.id = pr.id_pedido
                WHERE pe.id = ?
            ");
            $stmt->execute([$novoId]);
            $item = $stmt->fetch();
            $item['data_inicio'] = isoComOffset($item['data_inicio']);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Entrada registrada.', 'item' => $item]);
            break;
        }

        case 'status': {
            $stmt = $pdo->prepare("
                SELECT pe.*, pr.codigo AS projeto_codigo, ped.numero AS pedido_numero
                FROM producao_etapas pe
                JOIN projetos pr ON pr.id = pe.id_projeto
                JOIN pedidos ped ON ped.id = pr.id_pedido
                WHERE pe.estacao = ? AND pe.status = 'em_andamento' AND pe.deleted_at IS NULL
                ORDER BY pe.data_inicio DESC
                LIMIT 1
            ");
            $stmt->execute([PIN_ESTACAO]);
            $item = $stmt->fetch() ?: null;
            if ($item) $item['data_inicio'] = isoComOffset($item['data_inicio']);
            echo json_encode(['sucesso' => true, 'item' => $item]);
            break;
        }

        case 'remover_etapa': {
            $ns = trim((string) ($_POST['ns_transformador'] ?? ''));
            if ($ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'N° de série inválido.']);
                exit;
            }
            $stmt = $pdo->prepare("
                UPDATE producao_etapas SET deleted_at = NOW()
                WHERE ns_transformador = ? AND estacao = ? AND status IN ('em_andamento', 'aguardando_retorno') AND deleted_at IS NULL
            ");
            $stmt->execute([$ns, PIN_ESTACAO]);
            echo json_encode(['sucesso' => true, 'removidos' => $stmt->rowCount()]);
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
