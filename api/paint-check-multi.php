<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/vsat-num-series.php';
require_once __DIR__ . '/../includes/concessionaria-regras.php';

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

$input = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!$payload || empty($payload)) {
    echo json_encode(['success' => false, 'error' => 'Nenhuma imagem recebida.']);
    exit;
}

// 'identificar' (Passo 1 do fluxo em etapas — fallback do Passo 0 de
// bipar/digitar etiqueta): só usa tampa+gancho pra achar o transformador e
// devolver a regra da concessionária, sem cobrar ainda as evidências extras
// (isso fica pra etapa 2, na chamada final em modo 'validar').
$modo = (string) ($payload['_modo'] ?? 'validar');
unset($payload['_modo']);

// NS já identificado pelo Passo 0/Passo 1 (etiqueta bipada, digitada ou OCR
// reverso de tampa+gancho) — quando presente, a etapa 2 não busca mais um
// candidato no VSAT inteiro: valida CADA campo individualmente contra ESSE
// transformador já conhecido, devolvendo um checklist por campo em vez de um
// resultado tudo-ou-nada. Isso é o que sustenta a validação manual: o
// operador só precisa conferir visualmente os campos que a IA não confirmou.
$numSerieIdentificado = trim((string) ($payload['_num_serie_identificado'] ?? ''));
unset($payload['_num_serie_identificado']);

// Helper para chamar Python OCR (motor único: PaddleOCR, ver paint-check-robo/server.py).
function callPythonOcr($base64, bool $vertical = false) {
    if (strpos($base64, ',') !== false) {
        $base64 = explode(',', $base64)[1];
    }

    // Como o PHP e o Python rodam na mesma máquina, o PHP pode falar direto com o Python via localhost!
    $python_url = 'http://127.0.0.1:5000/analisar';

    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n" .
                         "ngrok-skip-browser-warning: true\r\n",
            'method'  => 'POST',
            'content' => json_encode(['image' => $base64, 'vertical' => $vertical]),
            'timeout' => 60,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];
    $context = stream_context_create($options);

    // Captura a resposta (agora ignora HTTP Errors para podermos ver o que o servidor disse)
    $resp = @file_get_contents($python_url, false, $context);

    $status_line = $http_response_header[0] ?? 'Sem resposta HTTP';

    if ($resp === false || strpos($status_line, '200') === false) {
        $err = error_get_last();
        $resp_clean = substr($resp ?: '', 0, 100); // Primeiros 100 caracteres da resposta
        return ['ERRO_API: ' . $status_line . ' | Body: ' . $resp_clean . ' | Sys: ' . ($err['message'] ?? '')];
    }

    $decoded = json_decode($resp, true);
    if (is_array($decoded)) {
        return $decoded['textos_encontrados'] ?? [];
    }

    return [];
}

function filterSubstrings($textos_brutos) {
    $textos = [];
    foreach ($textos_brutos as $t1) {
        $is_substring = false;
        foreach ($textos_brutos as $t2) {
            if ($t1 !== $t2 && strpos($t2, $t1) !== false) {
                $is_substring = true;
                break;
            }
        }
        if (!$is_substring) {
            $textos[] = $t1;
        }
    }
    return $textos;
}

/**
 * Gera hipóteses candidatas a partir de um texto lido pelo OCR:
 * 1. Texto original limpo
 * 2. Inversão semântica a 180° (ordem reversa com substituição de 6<->9, 2<->5, 0<->0, 8<->8, 1<->1)
 */
function gerarHipotesesOcr(string $texto): array {
    $candidatos = [];
    $limpo = trim($texto);
    if ($limpo === '') return [];

    $candidatos[] = $limpo;

    // mb_str_split (não str_split): o texto pode conter acentos (ex.: mensagem
    // de erro do robô OCR indisponível) — dividir por byte em vez de por
    // caractere corrompe UTF-8 multibyte ao inverter a ordem, o que faz
    // json_encode() falhar silenciosamente mais adiante (resposta 200 vazia,
    // sem nenhuma pista do que deu errado pro operador).
    $chars = mb_str_split($limpo);
    $invertido = '';
    $map180 = [
        '0' => '0', '1' => '1', '2' => '5', '5' => '2',
        '6' => '9', '8' => '8', '9' => '6',
        'O' => '0', 'o' => '0', 'I' => '1', 'l' => '1',
        '-' => '-', '.' => '.', ' ' => ' ',
        'X' => 'X', 'x' => 'x', 'H' => 'H', 'N' => 'N', 'Z' => 'Z', 'S' => 'S', 's' => 's'
    ];

    for ($i = count($chars) - 1; $i >= 0; $i--) {
        $c = $chars[$i];
        $invertido .= $map180[$c] ?? $c;
    }

    if ($invertido !== '' && $invertido !== $limpo) {
        $candidatos[] = $invertido;
    }

    return array_values(array_unique($candidatos));
}

/**
 * Verifica se algum texto no pool de leituras bate com o regex de formato
 * fornecido (ex.: tombamento Neoenergia "XXXXXX - Z"). Testa contra o texto
 * "cru" (sem stripar separadores) já que o formato depende deles.
 */
/**
 * Compara o pool de leituras OCR de um slot contra o valor esperado do VSAT
 * pra esse mesmo slot. Usada tanto na busca por candidatos (varrendo o VSAT
 * inteiro, quando ainda não sabemos o transformador — modo 'identificar')
 * quanto na checklist por campo contra um transformador já identificado
 * (Passo 0/Passo 1 já resolveram o NS antes da etapa 2 chegar aqui).
 */
function checkMatchCampo(array $payload, array $ocrResultsPorFoto, string $key, $dbValue, bool $isPotencia = false, bool $isPuzzle = false): bool
{
    if (!isset($payload[$key])) return true; // Slot inativo
    if (empty($ocrResultsPorFoto[$key])) return false; // Slot ativo, mas OCR retornou nada

    $dbValueStr = preg_replace('/[^a-zA-Z0-9]/', '', (string) $dbValue); // Ex: '0210021-027348-3' -> '02100210273483'

    // --- ESTRATÉGIA QUEBRA-CABEÇA (SÉRIE CLIENTE TANQUE) ---
    if ($isPuzzle) {
        $matchedPieces = 0;
        $totalLength = 0;

        foreach ($ocrResultsPorFoto[$key] as $t) {
            $tClean = preg_replace('/[^a-zA-Z0-9]/', '', $t);
            if (empty($tClean)) continue;

            // Verifica se a peça (ex: '021' ou '002') existe DENTRO do valor do banco (ex: '02100210273483')
            if (strpos($dbValueStr, $tClean) !== false) {
                $matchedPieces++;
                $totalLength += strlen($tClean);
            }
        }

        return $matchedPieces > 0 && $totalLength >= 3;
    }

    // --- ESTRATÉGIA NORMAL / POTÊNCIA ---
    foreach ($ocrResultsPorFoto[$key] as $t) {
        $tClean = preg_replace('/[^a-zA-Z0-9]/', '', $t);
        if (strlen($tClean) === 0) continue;

        if ($isPotencia) {
            // Potência exige bater o valor exato isolado
            if ($tClean === $dbValueStr || strpos($t, $dbValueStr) !== false) {
                return true;
            }
        } else {
            // Match perfeito
            if (strpos($tClean, $dbValueStr) !== false || strpos($dbValueStr, $tClean) !== false) {
                return true;
            }
        }
    }
    return false;
}

/** Extrai o número da potência do ds_prod (ex: "Transformador... 75kVA" -> "75"). */
function extrairPotenciaDaDescricao(string $descricao): string
{
    if (preg_match('/(\d+)\s*[kK][vV][aA]/i', $descricao, $m)) {
        return $m[1];
    }
    return '';
}

/** Extrai o número de fases do ds_prod (ex: "...45kVA 15kV 3F 380/220V..." -> "3"). */
function extrairFasesDaDescricao(string $descricao): ?string
{
    if (preg_match('/\b([13])\s*F\b/i', $descricao, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Tabela oficial de elo fusível da Equatorial por potência/fases (pintura.md
 * 3.1.B, Tabelas 16 e 17 da NT.005.EQTL — 15kV). Só a Equatorial tem essa
 * tabela documentada; Amazonas também exige elo fusível (pintura.md 3.9.B)
 * mas sem tabela de referência — ali só dá pra exigir a foto, não validar o
 * valor exato (ver bloco do elo_fusivel mais abaixo).
 */
const ELO_FUSIVEL_EQUATORIAL_15KV = [
    '1' => ['5' => '0,5H', '10' => '1H', '15' => '2H', '25' => '3H', '37.5' => '5H', '37,5' => '5H'],
    '3' => ['45' => '2H', '75' => '3H', '112.5' => '5H', '112,5' => '5H', '150' => '5H', '225' => '10K', '300' => '15K'],
];

/** Devolve o elo fusível esperado (Equatorial, 15kV) pra uma potência/fases, ou null se não estiver na tabela. */
function calcularEloFusivelEquatorial(string $potenciaKva, ?string $fases): ?string
{
    if ($fases === null || !isset(ELO_FUSIVEL_EQUATORIAL_15KV[$fases])) return null;
    return ELO_FUSIVEL_EQUATORIAL_15KV[$fases][$potenciaKva] ?? null;
}

/**
 * Classifica um campo não confirmado como 'divergente' (leitura clara, mas
 * diferente do esperado — possível erro real de gravação) ou 'ilegivel'
 * (sem leitura aproveitável) via distância de Levenshtein. Mesma lógica usada
 * pra todos os campos do checklist — extraída aqui pra também servir o
 * elo fusível da Equatorial, que não vem de SLOT_CHECKS_VSAT.
 */
function classificarStatusCampo(bool $ok, string $dbValueStr, array $leituras): array
{
    $status = $ok ? 'confirmado' : 'ilegivel';
    $leituraProxima = null;
    $distancia = null;

    if (!$ok && strlen($dbValueStr) >= 4) {
        foreach ($leituras as $t) {
            $tClean = preg_replace('/[^a-zA-Z0-9]/', '', (string) $t);
            if (strlen($tClean) < 4) continue;
            $d = levenshtein($tClean, $dbValueStr);
            if ($distancia === null || $d < $distancia) {
                $distancia = $d;
                $leituraProxima = $tClean;
            }
        }
        if ($distancia !== null && $distancia <= 2) {
            $status = 'divergente';
        }
    }

    return [$status, $leituraProxima, $distancia];
}

/** Mapa slot -> {campo VSAT, label amigável, flags de estratégia de match} usado pela checklist por campo. */
const SLOT_CHECKS_VSAT = [
    'tampa_serie'     => ['campo' => 'num_serie', 'label' => 'Nº Série Tampa'],
    'tanque_serie'    => ['campo' => 'num_serie', 'label' => 'Nº Série Tanque'],
    'gancho_serie'    => ['campo' => 'num_serie', 'label' => 'Nº Série Gancho'],
    'tampa_codigo'    => ['campo' => 'observacao', 'label' => 'Patrimônio Tampa'],
    'tanque_cliente'  => ['campo' => 'num_serie_cliente', 'label' => 'Patrimônio Tanque', 'puzzle' => true],
    'tanque_potencia' => ['campo' => 'potencia', 'label' => 'Potência Tanque', 'potencia' => true],
    'tampa_potencia'  => ['campo' => 'potencia', 'label' => 'Potência Tampa', 'potencia' => true],
];

// 1. Processar imagens individualmente (Sem Pool, Lógica Estrita por Slot)
// Campos pintados como coluna de dígitos empilhados (não como linha) — pede
// pro server.py também tentar as orientações 90º/270º (mais lento, por isso
// só liga aqui, não pra tudo — ver paint-check-robo/server.py).
$slotsTextoVertical = ['tanque_cliente'];
$ocr_results_por_foto = [];
foreach ($payload as $key => $base64) {
    $texts = callPythonOcr($base64, in_array($key, $slotsTextoVertical, true));
    $textosFiltrados = filterSubstrings($texts);

    $todosCandidatos = [];
    foreach ($textosFiltrados as $tf) {
        foreach (gerarHipotesesOcr($tf) as $hip) {
            $todosCandidatos[] = $hip;
        }
    }
    $ocr_results_por_foto[$key] = array_values(array_unique($todosCandidatos));
}

// 2. Carregar índice de números de série — fonte primária: VSAT ao vivo
//    (vsat_num_series_previo, sincronizado por scripts/sincronizar_vsat_num_series.ps1),
//    com fallback automático pra NS.OF.xlsx se a tabela local ainda não foi
//    sincronizada (ver includes/vsat-num-series.php).
$indice = carregarIndiceVsat();

// 2a. Caminho novo: NS já identificado pelo Passo 0/Passo 1 — valida cada
// campo individualmente contra ESSE transformador (sem buscar candidato) e
// devolve um checklist por campo, sustentando a validação manual no front:
// o operador só precisa conferir visualmente o que a IA não confirmou.
if ($numSerieIdentificado !== '') {
    $transformador = $indice[$numSerieIdentificado] ?? null;

    if ($transformador === null) {
        echo json_encode([
            'success' => false,
            'error' => 'Transformador identificado (NS ' . $numSerieIdentificado . ') não encontrado no VSAT — tente identificar de novo no Passo 0.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $regra = buscarRegraConcessionaria((string) ($transformador['cliente'] ?? ''));
    $potencia_db = extrairPotenciaDaDescricao((string) $transformador['descricao']);

    $campos = [];
    foreach (SLOT_CHECKS_VSAT as $slot => $meta) {
        if (!isset($payload[$slot])) continue; // slot não ativo nesta submissão, nem entra na checklist
        $dbValue = $meta['campo'] === 'potencia' ? $potencia_db : ($transformador[$meta['campo']] ?? '');
        $ok = checkMatchCampo($payload, $ocr_results_por_foto, $slot, $dbValue, !empty($meta['potencia']), !empty($meta['puzzle']));
        $detalhe = null;

        // Classificação da falha — separa "não consegui ler" de "li com clareza
        // algo DIFERENTE do esperado". São situações opostas: a primeira é foto/
        // marcação ruim (conferência visual resolve), a segunda é possível erro
        // REAL de gravação, que é justamente o que o Paint Check existe pra pegar.
        //
        // Quando $detalhe já foi preenchido acima (NS bateu certinho, mas falta o
        // ano junto / o código não bate no formato), o valor lido É IGUAL ao
        // esperado — comparar os dois por Levenshtein daria distância 0 e
        // rotularia como "diverge" com "Esperado: X | Lido: X", o que é
        // enganoso (parece erro de gravação, mas o problema é outro, já
        // explicado em $detalhe). Nesse caso força "ilegível" pra não mostrar
        // um "Lido" idêntico ao "Esperado".
        $dbValueStr = preg_replace('/[^a-zA-Z0-9]/', '', (string) $dbValue);
        if ($detalhe !== null) {
            $status = 'ilegivel';
            $leituraProxima = null;
            $distancia = null;
        } else {
            [$status, $leituraProxima, $distancia] = classificarStatusCampo($ok, $dbValueStr, $ocr_results_por_foto[$slot] ?? []);
        }

        $campos[] = [
            'slot' => $slot,
            'label' => $meta['label'],
            'ok' => $ok,
            'status' => $status, // confirmado | divergente | ilegivel
            'esperado' => $dbValueStr,
            'leitura_proxima' => $leituraProxima,
            'distancia' => $distancia,
            'leituras' => $ocr_results_por_foto[$slot] ?? [],
            'detalhe' => $detalhe,
        ];
    }

    // Elo fusível — não está em SLOT_CHECKS_VSAT porque o valor esperado não vem
    // de uma coluna do VSAT, e sim de uma tabela por concessionária (pintura.md).
    // Só a Equatorial tem tabela documentada (15kV, potência + fases); Amazonas
    // também exige a evidência mas sem tabela — ali só cobra que a foto exista e
    // tenha alguma leitura, sem checar o valor exato.
    if (isset($payload['elo_fusivel'])) {
        $leiturasElo = $ocr_results_por_foto['elo_fusivel'] ?? [];
        $nomeGrupo = $regra['nome_grupo'];

        if ($nomeGrupo === 'Equatorial') {
            $fases = extrairFasesDaDescricao((string) $transformador['descricao']);
            $eloEsperado = calcularEloFusivelEquatorial($potencia_db, $fases);

            if ($eloEsperado === null) {
                $campos[] = [
                    'slot' => 'elo_fusivel', 'label' => 'Elo Fusível', 'ok' => false, 'status' => 'ilegivel',
                    'esperado' => null, 'leitura_proxima' => null, 'distancia' => null,
                    'leituras' => $leiturasElo,
                    'detalhe' => 'Não achei ' . $potencia_db . 'kVA/' . ($fases ?? '?') . 'F na tabela de elo fusível da Equatorial (15kV) — confira manualmente.',
                ];
            } else {
                $eloEsperadoLimpo = preg_replace('/[^a-zA-Z0-9]/', '', $eloEsperado);
                $okElo = checkMatchCampo($payload, $ocr_results_por_foto, 'elo_fusivel', $eloEsperado);
                [$statusElo, $leituraProximaElo, $distanciaElo] = classificarStatusCampo($okElo, $eloEsperadoLimpo, $leiturasElo);
                $campos[] = [
                    'slot' => 'elo_fusivel', 'label' => 'Elo Fusível', 'ok' => $okElo, 'status' => $statusElo,
                    'esperado' => $eloEsperadoLimpo, 'leitura_proxima' => $leituraProximaElo, 'distancia' => $distanciaElo,
                    'leituras' => $leiturasElo, 'detalhe' => null,
                ];
            }
        } else {
            // Sem tabela pra essa concessionária (ex.: Amazonas) — só exige que a
            // foto tenha alguma leitura aproveitável, não valida o valor.
            $temLeitura = !empty($leiturasElo);
            $campos[] = [
                'slot' => 'elo_fusivel', 'label' => 'Elo Fusível', 'ok' => $temLeitura,
                'status' => $temLeitura ? 'confirmado' : 'ilegivel',
                'esperado' => null, 'leitura_proxima' => null, 'distancia' => null,
                'leituras' => $leiturasElo,
                'detalhe' => $temLeitura ? 'Sem tabela de referência pra essa concessionária — confira o valor manualmente.' : null,
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'num_serie' => $transformador['num_serie'],
        'cliente' => $transformador['cliente'],
        'cd_referencia' => (string) ($transformador['cd_referencia'] ?? ''),
        'id_projeto' => resolverIdProjetoPorCodigo((string) ($transformador['cd_referencia'] ?? '')),
        'descricao' => (string) ($transformador['descricao'] ?? ''),
        'cd_pedido' => (string) ($transformador['cd_pedido'] ?? ''),
        'concessionaria' => [
            'nome_grupo' => $regra['nome_grupo'],
            'fallback' => $regra['fallback'],
        ],
        'campos' => $campos,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// 3. Cruzar dados — só chega aqui em modo 'identificar' (Passo 1: ainda não
// sabemos o NS, precisa varrer o VSAT procurando um candidato que bata com
// tampa+gancho).
$candidatos = [];

foreach ($indice as $row) {
    $matches = true;
    $potencia_db = extrairPotenciaDaDescricao((string) $row['descricao']);

    if (!checkMatchCampo($payload, $ocr_results_por_foto, 'tampa_serie', $row['num_serie'])) $matches = false;
    if (!checkMatchCampo($payload, $ocr_results_por_foto, 'tanque_serie', $row['num_serie'])) $matches = false;
    if (!checkMatchCampo($payload, $ocr_results_por_foto, 'gancho_serie', $row['num_serie'])) $matches = false;

    if (!checkMatchCampo($payload, $ocr_results_por_foto, 'tampa_codigo', $row['observacao'])) $matches = false;

    if (!checkMatchCampo($payload, $ocr_results_por_foto, 'tanque_cliente', $row['num_serie_cliente'], false, true)) $matches = false; // PUZZLE MODE

    if (!checkMatchCampo($payload, $ocr_results_por_foto, 'tanque_potencia', $potencia_db, true)) $matches = false;
    if (!checkMatchCampo($payload, $ocr_results_por_foto, 'tampa_potencia', $potencia_db, true)) $matches = false;

    if ($matches) {
        $candidatos[] = $row;
    }
}

if (count($candidatos) > 0) {
    // Pegar o primeiro candidato
    $transformador = $candidatos[0];

    // 4. Regra de validação específica da concessionária desse candidato — o
    //    mesmo passo de busca reversa que achou o NS já trouxe o cliente, então
    //    a regra só é resolvida agora que sabemos qual concessionária é (não dá
    //    pra pré-selecionar antes das fotos).
    $regra = buscarRegraConcessionaria((string) ($transformador['cliente'] ?? ''));

    $checagensRegra = [];
    $regraOk = true;

    // Blocos 4a/4b/4c só fazem sentido em modo 'validar' (etapa 2, com as
    // evidências extras já enviadas) — em modo 'identificar' (Passo 1) ainda
    // não pedimos nada disso, então não há o que reprovar aqui.
    if ($modo !== 'identificar') {
        // 4c. Locais obrigatórios de puncionamento não enviados como foto
        $slotPorLocal = ['tampa' => 'tampa_serie', 'tanque' => 'tanque_serie', 'gancho' => 'gancho_serie'];
        foreach ($regra['locais_obrigatorios'] as $local) {
            $slot = $slotPorLocal[$local] ?? null;
            if ($slot === null) continue;
            $enviado = isset($payload[$slot]);
            if (!$enviado) {
                $checagensRegra[] = [
                    'label' => 'Evidência de ' . $local,
                    'ok' => false,
                    'detalhe' => ucfirst($local) . ' é obrigatória para esta concessionária e não foi enviada.',
                ];
                $regraOk = false;
            }
        }
    }

    $respostaBase = [
        'num_serie' => $transformador['num_serie'],
        'cliente' => $transformador['cliente'],
        'cd_referencia' => (string) ($transformador['cd_referencia'] ?? ''),
        'id_projeto' => resolverIdProjetoPorCodigo((string) ($transformador['cd_referencia'] ?? '')),
        'descricao' => (string) ($transformador['descricao'] ?? ''),
        'cd_pedido' => (string) ($transformador['cd_pedido'] ?? ''),
        'concessionaria' => [
            'nome_grupo' => $regra['nome_grupo'],
            'fallback' => $regra['fallback'],
        ],
        'regras_checadas' => $checagensRegra,
        'regra' => [
            'locais_obrigatorios' => $regra['locais_obrigatorios'],
            'local_codigo_adicional' => $regra['local_codigo_adicional'],
            'local_potencia' => $regra['local_potencia'],
            'exige_elo_fusivel' => $regra['exige_elo_fusivel'],
        ],
    ];

    // JSON_INVALID_UTF8_SUBSTITUTE: rede de segurança — os textos aqui vêm do
    // OCR/robô Python e não são garantidamente UTF-8 válido; sem essa flag,
    // json_encode() falha silenciosamente (retorna false) e o endpoint
    // responderia 200 com corpo vazio, sem nenhuma pista do que deu errado.
    if ($regraOk) {
        echo json_encode(array_merge(['success' => true], $respostaBase), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } else {
        echo json_encode(array_merge(['success' => false, 'error' => 'As evidências identificaram o transformador correto, mas não atendem às regras específicas da concessionária ' . $regra['nome_grupo'] . '. Confira os detalhes.'], $respostaBase), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
} else {
    // Montar aviso amigável
    $leiturasHTML = "<ul style='text-align:left; margin-top:10px;'>";
    $labelMap = [
        'tampa_serie' => 'Nº Série Tampa',
        'tampa_codigo' => 'Patrimônio Tampa',
        'tampa_potencia' => 'Potência Tampa',
        'tanque_serie' => 'Nº Série Tanque',
        'tanque_cliente' => 'Patrimônio Tanque',
        'tanque_potencia' => 'Potência Tanque',
        'gancho_serie' => 'Nº Série Gancho',
        'elo_fusivel' => 'Elo Fusível'
    ];

    foreach ($ocr_results_por_foto as $key => $texts) {
        $lbl = $labelMap[$key] ?? $key;
        if (empty($texts)) {
            $leiturasHTML .= "<li><b>{$lbl}:</b> <i>(Vazio/Ilegível)</i></li>";
        } else {
            // Se for puzzle, mostra também como o sistema reagrupou pra facilitar o debug
            if ($key === 'tanque_cliente') {
                $puzzlePieces = [];
                foreach ($texts as $t) {
                    $puzzlePieces[] = preg_replace('/[^a-zA-Z0-9]/', '', $t);
                }
                $puzzleString = implode('', $puzzlePieces);
                $leiturasHTML .= "<li><b>{$lbl}:</b> " . implode(', ', $texts) . " <i>(Juntado: {$puzzleString})</i></li>";
            } else {
                $leiturasHTML .= "<li><b>{$lbl}:</b> " . implode(', ', $texts) . "</li>";
            }
        }
    }
    $leiturasHTML .= "</ul>";

    echo json_encode([
        'success' => false,
        'error' => "As fotos enviadas falharam na validação estrita (nenhum transformador atendeu perfeitamente a todos os requisitos).<br>Confira o que o robô leu:" . $leiturasHTML
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
