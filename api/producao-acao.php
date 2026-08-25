<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/planilha-ns-of.php';

header('Content-Type: application/json; charset=utf-8');

// ─── Autenticação e Autorização ───────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

if (!hasAcesso('tab:laboratorio') && !hasAcesso('tab:inspecao_final') && !hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Sem permissão de acesso ao módulo de produção']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido']);
    exit;
}

$acao   = trim((string) ($_POST['acao'] ?? ''));
$userId = (int) (currentUser()['id'] ?? 0);

// Verificação de permissão de escrita para ações de modificação
if (in_array($acao, ['confirmar', 'remover_etapa'], true)) {
    if (!podeEditar('lab.reg') && !podeEditar('iqf.reg') && !podeEditar('lab.lis') && !podeEditar('iqf.lis') && !podeEditar('tab:laboratorio') && !podeEditar('tab:inspecao_final') && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['sucesso' => false, 'erro' => 'Apenas consulta: você não tem permissão para confirmar ou remover etapas de produção.']);
        exit;
    }
}

$ESTACOES_VALIDAS = ['IQF', 'LAB', 'GER'];

/** Item em_andamento (qualquer estação) para um N° de série, se houver. */
function buscarEmAndamento(PDO $pdo, string $ns): ?array
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
function buscarEmRetrabalho(PDO $pdo, string $ns): bool
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
 * Resolve um código escaneado/digitado em toda a informação que a Área de Leitura
 * precisa — usada tanto por 'ler' (prévia) quanto por 'confirmar' (gravação), para
 * que as duas ações nunca divirjam sobre o que é o projeto/pedido de um código.
 *
 * N° de série, Descrição e Cliente vêm sempre da planilha NS.OF (quando a etiqueta
 * resolve um cd_of). Projeto e Pedido: se o transformador já estiver cadastrado em
 * producao_transformadores, prevalece o vínculo já salvo; senão, busca-se um Projeto
 * já existente por `projetos.codigo = cd_Referencia da planilha`; se nenhum dos dois
 * existir mas a planilha traz tanto o Projeto (cd_Referencia) quanto o Pedido
 * (cdPedido) dessa OF, `precisa_criar` volta true — o cadastro é a própria planilha,
 * então 'confirmar' cria Pedido+Projeto na hora de registrar (nunca na prévia 'ler',
 * pra não gravar cadastro por causa de um scan que o operador só olhou e cancelou).
 * Sem nada disso, id_projeto fica null e a tela mostra "não registrado", sem opção
 * de o operador escolher/editar manualmente.
 */
function resolverTransformador(PDO $pdo, string $codigoBruto): array
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
        // Busca na planilha pelo Número de Série direto
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
    $transf = $stmt->fetch() ?: null; // fetch() devolve false (não null) sem linhas — normaliza antes do !== null abaixo

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
            // Projeto identificado pela planilha NS.OF (com ou sem pedido)
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

/**
 * Garante que existam o Pedido e o Projeto identificados pela planilha NS.OF,
 * criando o que faltar. Só é chamada por 'confirmar' (resolverTransformador com
 * precisa_criar=true) — nunca durante a prévia. Único (numero/codigo) protege
 * contra duplicidade se dois tablets registrarem a mesma OF nova ao mesmo tempo.
 */
function criarPedidoProjetoDaPlanilha(PDO $pdo, string $codigoProjeto, string $numeroPedido, ?string $descricao): ?int
{
    $numeroPedido = trim($numeroPedido) !== '' ? trim($numeroPedido) : 'S/P';
    $pq = $pdo->prepare("SELECT id FROM pedidos WHERE numero = ? AND deleted_at IS NULL LIMIT 1");
    $pq->execute([$numeroPedido]);
    $pedido = $pq->fetch();
    if (!$pedido) {
        try {
            $pdo->prepare("INSERT INTO pedidos (numero) VALUES (?)")->execute([$numeroPedido]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') throw $e; // outro tablet criou este pedido ao mesmo tempo
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
            if ($e->getCode() !== '23000') throw $e; // outro tablet criou este projeto ao mesmo tempo
        }
        $rq->execute([$codigoProjeto]);
        $projeto = $rq->fetch();
    }
    return $projeto ? (int) $projeto['id'] : null;
}

try {
    $pdo = getDB();

    switch ($acao) {

        // ─── Ler/resolver um código (N° de série) escaneado ou digitado ───────
        case 'ler': {
            $codigo = trim((string) ($_POST['codigo'] ?? ''));
            if ($codigo === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Informe o N° de série.']);
                exit;
            }

            $res = resolverTransformador($pdo, $codigo);
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

            // Sem projeto resolvido (nem vínculo já cadastrado, nem match da planilha
            // contra projetos.codigo, nem dados suficientes na planilha pra criar) não
            // há o que registrar — a tela mostra os campos como "não registrado", sem
            // opção de o operador escolher manualmente. precisa_criar=true segue como
            // 'ok' normalmente: o Pedido/Projeto (já mostrados, vindos da planilha) só
            // são de fato criados em 'confirmar'.
            if ($res['id_projeto'] === null && !$res['precisa_criar']) {
                echo json_encode($base + ['status' => 'nao_registrado']);
                exit;
            }

            if ($aberto = buscarEmAndamento($pdo, $res['ns'])) {
                echo json_encode($base + [
                    'status'        => 'conflict',
                    'estacao_atual' => $aberto['estacao'],
                    'data_inicio'   => isoComOffset($aberto['data_inicio']),
                ]);
                exit;
            }

            if (buscarEmRetrabalho($pdo, $res['ns'])) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'O número de série já se encontra em retrabalho.']);
                exit;
            }

            echo json_encode($base + ['status' => 'ok']);
            break;
        }

        // ─── Confirmar entrada de um transformador numa estação ───────────────
        case 'confirmar': {
            $codigo  = trim((string) ($_POST['codigo'] ?? ''));
            $estacao = trim((string) ($_POST['estacao'] ?? ''));

            if ($codigo === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'N° de série inválido.']);
                exit;
            }
            if (!in_array($estacao, $ESTACOES_VALIDAS, true)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Estação inválida.']);
                exit;
            }

            // Reresolve do zero a partir do código bruto — nunca confia num id_projeto vindo
            // do cliente. Garante que 'confirmar' enxergue exatamente o mesmo projeto/pedido
            // que 'ler' mostrou na tela (campos fixos, sem edição manual do operador).
            $res = resolverTransformador($pdo, $codigo);
            if ($res['erro'] !== null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $res['erro']]);
                exit;
            }
            $idProjeto = $res['id_projeto'];

            // Pedido/Projeto não existem ainda, mas a planilha trouxe os dois — cria
            // agora, no momento do registro (nunca durante a prévia 'ler').
            if ($idProjeto === null && $res['precisa_criar']) {
                $pedNum = ($res['cd_pedido_plan'] !== null && $res['cd_pedido_plan'] !== '') ? $res['cd_pedido_plan'] : 'S/P';
                $idProjeto = criarPedidoProjetoDaPlanilha($pdo, $res['cd_referencia_plan'], $pedNum, $res['descricao']);
            }

            if ($idProjeto === null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Projeto não cadastrado no sistema — cadastre o Pedido/Projeto antes de registrar.']);
                exit;
            }

            $ns = $res['ns'];

            // Primeira passagem deste N° de série: cria o vínculo N° de série -> Projeto
            // (resolvido acima pela planilha) antes de registrar a etapa.
            if (!$res['ja_cadastrado']) {
                try {
                    $ins = $pdo->prepare("INSERT INTO producao_transformadores (ns_transformador, id_projeto) VALUES (?, ?)");
                    $ins->execute([$ns, $idProjeto]);
                } catch (\PDOException $e) {
                    if ($e->getCode() !== '23000') throw $e; // outro tablet cadastrou este NS ao mesmo tempo — segue com o que já existe
                }
            }

            // Revalida o conflito no servidor — reduz a janela de corrida entre a leitura e a
            // confirmação (duas leituras do mesmo transformador quase ao mesmo tempo, em
            // tablets diferentes). A garantia definitiva contra duas linhas em_andamento
            // simultâneas para o mesmo NS é o índice único uq_prod_etapas_ns_ativo (ver
            // migrar-producao.sql) — capturado no catch abaixo caso esta checagem perca a corrida.
            if ($aberto = buscarEmAndamento($pdo, $ns)) {
                http_response_code(400);
                echo json_encode([
                    'sucesso' => false,
                    'erro'    => 'Este transformador já está em andamento na estação ' . $aberto['estacao'] . '.',
                ]);
                exit;
            }

            if (buscarEmRetrabalho($pdo, $ns)) {
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
                $stmt->execute([$ns, $idProjeto, $estacao, $metodoInsercao, $userId, $userId]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') { // chave duplicada — outro tablet confirmou primeiro
                    http_response_code(400);
                    echo json_encode(['sucesso' => false, 'erro' => 'Este transformador já está em andamento em outra estação.']);
                    exit;
                }
                throw $e;
            }
            $novoId = (int) $pdo->lastInsertId();

            // Resolve sozinho qualquer "aguardando retorno" pendente deste N° de série
            // nesta estação (ver aba Retornos) — o operador não precisa fazer nada além
            // do fluxo de leitura normal que já acabou de rodar acima.
            $pdo->prepare("
                UPDATE producao_etapas SET deleted_at = NOW()
                WHERE ns_transformador = ? AND estacao = ? AND status = 'aguardando_retorno' AND deleted_at IS NULL
            ")->execute([$ns, $estacao]);

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

        // ─── Item em andamento numa estação (carregamento/atualização da tela) ─
        case 'status': {
            $estacao = trim((string) ($_POST['estacao'] ?? ''));
            if (!in_array($estacao, $ESTACOES_VALIDAS, true)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Estação inválida.']);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT pe.*, pr.codigo AS projeto_codigo, ped.numero AS pedido_numero
                FROM producao_etapas pe
                JOIN projetos pr ON pr.id = pe.id_projeto
                JOIN pedidos ped ON ped.id = pr.id_pedido
                WHERE pe.estacao = ? AND pe.status = 'em_andamento' AND pe.deleted_at IS NULL
                ORDER BY pe.data_inicio DESC
                LIMIT 1
            ");
            $stmt->execute([$estacao]);
            $item = $stmt->fetch() ?: null;
            if ($item) $item['data_inicio'] = isoComOffset($item['data_inicio']);
            echo json_encode(['sucesso' => true, 'item' => $item]);
            break;
        }

        // ─── Remover a etapa da Lista (ex.: transformador reprovado, saiu da produção
        // e foi enviado para o Retrabalho) — soft delete, some dos filtros padrão.
        case 'remover_etapa': {
            $ns = trim((string) ($_POST['ns_transformador'] ?? ''));
            if ($ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'N° de série inválido.']);
                exit;
            }
            $stmt = $pdo->prepare("
                UPDATE producao_etapas SET deleted_at = NOW()
                WHERE ns_transformador = ? AND status IN ('em_andamento', 'aguardando_retorno') AND deleted_at IS NULL
            ");
            $stmt->execute([$ns]);
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
