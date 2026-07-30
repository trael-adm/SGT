<?php
declare(strict_types=1);

/**
 * Importa em lote todos os vínculos N° de série -> Projeto/Pedido da planilha
 * "PLANILHA QUE ATUALIZA/NS.OF.xlsx" para o banco (producao_transformadores,
 * criando Pedidos/Projetos que ainda não existirem) — em vez de depender só da
 * criação 1 a 1 que acontece ao confirmar uma leitura de QR em Produção
 * (ver resolverTransformador()/criarPedidoProjetoDaPlanilha() em api/producao-acao.php).
 *
 * Idempotente: usa INSERT IGNORE e só cria o que ainda não existe — rodar de
 * novo depois que a planilha for atualizada só adiciona o que for novo.
 *
 * USO (a partir da raiz do projeto):
 *   php _inicial/importar-planilha-ns-of.php                — simula (não grava nada)
 *   php _inicial/importar-planilha-ns-of.php --commit       — grava de verdade, usando o banco do .env local
 *   php _inicial/importar-planilha-ns-of.php <host> <port> <dbname> <user> <pass> [--commit]
 *                                                            — mesma coisa, mas contra outro banco
 *                                                              (ex.: o do Railway, pra sincronizar produção)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via linha de comando.');
}

require_once __DIR__ . '/../includes/planilha-ns-of.php';

$commit = in_array('--commit', $argv, true);
$argsPosicionais = array_values(array_filter($argv, fn($a, $i) => $i > 0 && $a !== '--commit', ARRAY_FILTER_USE_BOTH));

if (count($argsPosicionais) >= 5) {
    [$host, $port, $dbname, $user, $pass] = $argsPosicionais;
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $alvo = "$dbname @ $host:$port";
} else {
    require_once __DIR__ . '/../config/conexao.php';
    $pdo  = getDB();
    $alvo = (defined('_GFT_ENV') ? _GFT_ENV['DB_NAME'] : 'banco padrão') . ' (config/conexao.php)';
}

echo "=== Alvo: $alvo " . ($commit ? '[GRAVANDO]' : '[SIMULAÇÃO]') . " ===\n";

$indice = carregarIndicePlanilhaOF();

// 1 linha por NS único (a primeira ocorrência, caso o mesmo NS apareça mais de 1 vez).
$porNs = [];
foreach ($indice as $l) {
    $ns  = trim($l['num_serie']);
    $cp  = trim($l['cd_referencia']);
    $ped = trim($l['cd_pedido']);
    if ($ns === '' || $cp === '' || $ped === '' || isset($porNs[$ns])) continue;
    $porNs[$ns] = ['codigo' => $cp, 'pedido' => $ped, 'descricao' => trim($l['descricao'])];
}
echo "N° de série únicos com projeto+pedido resolvidos: " . count($porNs) . "\n";

$pedidosNecessarios  = [];
$projetosNecessarios = []; // codigo -> ['pedido' => numero, 'descricao' => string]
foreach ($porNs as $ns => $d) {
    $pedidosNecessarios[$d['pedido']] = true;
    if (!isset($projetosNecessarios[$d['codigo']])) {
        $projetosNecessarios[$d['codigo']] = ['pedido' => $d['pedido'], 'descricao' => $d['descricao']];
    }
}

$mapaPedidos = [];
foreach ($pdo->query("SELECT id, numero FROM pedidos") as $r) $mapaPedidos[$r['numero']] = (int) $r['id'];
$novosPedidos = array_values(array_diff(array_keys($pedidosNecessarios), array_keys($mapaPedidos)));
echo "Pedidos: " . count($mapaPedidos) . " já existentes | " . count($novosPedidos) . " novos\n";

$mapaProjetos = [];
foreach ($pdo->query("SELECT id, codigo FROM projetos") as $r) $mapaProjetos[$r['codigo']] = (int) $r['id'];
$novosProjetos = array_values(array_diff(array_keys($projetosNecessarios), array_keys($mapaProjetos)));
echo "Projetos: " . count($mapaProjetos) . " já existentes | " . count($novosProjetos) . " novos\n";

$existentesNs = [];
foreach ($pdo->query("SELECT ns_transformador FROM producao_transformadores") as $r) $existentesNs[$r['ns_transformador']] = true;
$novosNs = array_values(array_diff(array_keys($porNs), array_keys($existentesNs)));
echo "Vínculos N° de série -> Projeto: " . count($existentesNs) . " já existentes | " . count($novosNs) . " novos\n";

if (!$commit) {
    echo "\n[SIMULAÇÃO] Nada foi gravado. Rode com --commit pra aplicar de verdade.\n";
    exit;
}

echo "\nGravando...\n";

function inserirEmLotes(PDO $pdo, string $sql, array $tuplas, int $tamanhoLote = 500): void
{
    foreach (array_chunk($tuplas, $tamanhoLote) as $lote) {
        $placeholders = implode(',', array_fill(0, count($lote), '(' . implode(',', array_fill(0, count($lote[0]), '?')) . ')'));
        $params = [];
        foreach ($lote as $tupla) array_push($params, ...$tupla);
        $pdo->prepare(str_replace('__VALUES__', $placeholders, $sql))->execute($params);
    }
}

$pdo->beginTransaction();
try {
    // 1) Pedidos novos
    if ($novosPedidos) {
        inserirEmLotes($pdo, "INSERT IGNORE INTO pedidos (numero) VALUES __VALUES__", array_map(fn($n) => [$n], $novosPedidos));
    }
    $mapaPedidos = [];
    foreach ($pdo->query("SELECT id, numero FROM pedidos") as $r) $mapaPedidos[$r['numero']] = (int) $r['id'];

    // 2) Projetos novos (precisa do id_pedido resolvido)
    if ($novosProjetos) {
        $tuplas = [];
        foreach ($novosProjetos as $codigo) {
            $info = $projetosNecessarios[$codigo];
            $idPedido = $mapaPedidos[$info['pedido']] ?? null;
            if ($idPedido === null) continue; // não deveria acontecer, pedido acabou de ser garantido acima
            $tuplas[] = [$codigo, $info['descricao'] !== '' ? $info['descricao'] : null, $idPedido];
        }
        if ($tuplas) inserirEmLotes($pdo, "INSERT IGNORE INTO projetos (codigo, descricao, id_pedido) VALUES __VALUES__", $tuplas);
    }
    $mapaProjetos = [];
    foreach ($pdo->query("SELECT id, codigo FROM projetos") as $r) $mapaProjetos[$r['codigo']] = (int) $r['id'];

    // 3) Vínculos N° de série -> Projeto novos
    if ($novosNs) {
        $tuplas = [];
        foreach ($novosNs as $ns) {
            $idProjeto = $mapaProjetos[$porNs[$ns]['codigo']] ?? null;
            if ($idProjeto === null) continue;
            $tuplas[] = [$ns, $idProjeto];
        }
        if ($tuplas) inserirEmLotes($pdo, "INSERT IGNORE INTO producao_transformadores (ns_transformador, id_projeto) VALUES __VALUES__", $tuplas);
    }

    $pdo->commit();
    echo "OK — " . count($novosPedidos) . " pedidos, " . count($novosProjetos) . " projetos, " . count($novosNs) . " vínculos gravados.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERRO, desfeito: " . $e->getMessage() . "\n";
    exit(1);
}
