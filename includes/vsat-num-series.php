<?php
declare(strict_types=1);

/**
 * Índice num_serie -> {num_serie_cliente, observacao, cd_referencia, descricao,
 * cd_pedido, pedido_cliente, cliente, cd_of, data_pcp}, lido da tabela local
 * `vsat_num_series_previo` — cache MySQL populado por
 * scripts/sincronizar_vsat_num_series.ps1 a partir da view VSAT ao vivo
 * `dw.vw_relacao_num_series_previo`.
 *
 * Substitui `includes/planilha-ns-of.php` (NS.OF.xlsx via Power Query) como
 * fonte primária do Paint Check: a planilha ficou confirmadamente desatualizada
 * pra alguns clientes (Copel, Energisa — o campo NumSerieCliente/"Patrimônio"
 * vinha vazio nela, mas existe e bate na view VSAT ao vivo). Mesma assinatura
 * de planilha-ns-of.php (buscarPlanilhaOFPorNs -> buscarVsatPorNs,
 * carregarIndicePlanilhaOF -> carregarIndiceVsat) pra minimizar o diff em
 * quem consome — troca de fonte, mesma interface de retorno.
 *
 * Fallback: se a tabela estiver vazia ou não sincronizada há mais de
 * VSAT_NUM_SERIES_LIMIAR_DIAS dias (ex.: primeira instalação, ou VPN/rede da
 * Trael fora do ar há muito tempo), cai para o índice antigo da planilha —
 * nada quebra pra quem ainda não rodou a sincronização nova.
 */

define('VSAT_NUM_SERIES_LIMIAR_DIAS', 30);

/** Índice completo num_serie -> dados, com fallback pra planilha-ns-of.php se a tabela local estiver vazia/velha. */
function carregarIndiceVsat(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;

    $pdo = getDB();

    $statusStmt = $pdo->query("
        SELECT COUNT(*) AS total, MAX(atualizado_em) AS ultima_sincronizacao
        FROM vsat_num_series_previo
    ");
    $status = $statusStmt->fetch();

    $tabelaUtilizavel = false;
    if ($status && (int) $status['total'] > 0) {
        $ultimaSincronizacao = $status['ultima_sincronizacao'] ? new DateTime((string) $status['ultima_sincronizacao']) : null;
        if ($ultimaSincronizacao !== null) {
            $limite = (new DateTime())->modify('-' . VSAT_NUM_SERIES_LIMIAR_DIAS . ' days');
            $tabelaUtilizavel = $ultimaSincronizacao >= $limite;
        }
    }

    if (!$tabelaUtilizavel) {
        require_once __DIR__ . '/planilha-ns-of.php';
        return $memo = carregarIndicePlanilhaOFPorNs();
    }

    $stmt = $pdo->query("
        SELECT num_serie, num_serie_cliente, observacao, cd_referencia, descricao,
               cd_pedido, pedido_cliente, cliente, cd_of, data_pcp
        FROM vsat_num_series_previo
    ");

    $indice = [];
    foreach ($stmt->fetchAll() as $linha) {
        $indice[(string) $linha['num_serie']] = [
            'num_serie'         => (string) $linha['num_serie'],
            'num_serie_cliente' => (string) ($linha['num_serie_cliente'] ?? ''),
            'observacao'        => (string) ($linha['observacao'] ?? ''),
            'cd_referencia'     => (string) ($linha['cd_referencia'] ?? ''),
            'descricao'         => (string) ($linha['descricao'] ?? ''),
            'cd_pedido'         => (string) ($linha['cd_pedido'] ?? ''),
            'pedido_cliente'    => (string) ($linha['pedido_cliente'] ?? ''),
            'cliente'           => (string) ($linha['cliente'] ?? ''),
            'cd_of'             => (string) ($linha['cd_of'] ?? ''),
            'data_pcp'          => $linha['data_pcp'] !== null ? (string) $linha['data_pcp'] : null,
        ];
    }

    return $memo = $indice;
}

/** Busca um N° de série no índice do VSAT. Devolve null se não constar. */
function buscarVsatPorNs(string $ns): ?array
{
    $ns = trim($ns);
    if ($ns === '') return null;
    $indice = carregarIndiceVsat();
    return $indice[$ns] ?? null;
}

/**
 * Índice cd_of -> dados, construído em cima do mesmo `carregarIndiceVsat()`
 * (sem query nova) — usado pra resolver a etiqueta de produção (código de
 * barras prefixo(5)+cd_of+sufixo(1), ver `extrairCdOfDaEtiqueta()` em
 * `includes/planilha-ns-of.php`) contra o VSAT em vez da planilha NS.OF.xlsx,
 * que ficou confirmadamente desatualizada pra alguns clientes (Copel, Energisa).
 */
function carregarIndiceVsatPorCdOf(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;

    $indice = [];
    foreach (carregarIndiceVsat() as $linha) {
        $cdOf = (string) ($linha['cd_of'] ?? '');
        if ($cdOf !== '' && is_numeric($cdOf)) {
            $indice[(string) (int) $cdOf] = $linha;
        }
    }

    return $memo = $indice;
}

/** Busca um cd_of (miolo da etiqueta de produção) no índice do VSAT. Devolve null se não constar. */
function buscarVsatPorCdOf(string $cdOf): ?array
{
    $cdOf = trim($cdOf);
    if ($cdOf === '' || !is_numeric($cdOf)) return null;
    $indice = carregarIndiceVsatPorCdOf();
    return $indice[(string) (int) $cdOf] ?? null;
}

/**
 * Resolve o `id` numérico de `projetos` a partir do código (`cd_referencia`
 * vindo do VSAT) — exigido pela ação `registrar` de api/retrabalho-acao.php
 * (usada pelo modal "Reprovar" do Paint Check). VSAT e `projetos` são
 * catálogos de sistemas diferentes: nem todo transformador identificado no
 * VSAT necessariamente tem um projeto já cadastrado no SGT — devolve null
 * nesse caso, tratado no frontend orientando o operador a contatar o
 * administrador (caso raro).
 */
function resolverIdProjetoPorCodigo(string $cdReferencia): ?int
{
    $cdReferencia = trim($cdReferencia);
    if ($cdReferencia === '') return null;

    $stmt = getDB()->prepare('SELECT id FROM projetos WHERE codigo = ? AND deleted_at IS NULL');
    $stmt->execute([$cdReferencia]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}
