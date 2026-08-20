<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/planilha-ns-of.php';

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

// Helper para chamar Python OCR
function callPythonOcr($base64) {
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
            'content' => json_encode(['image' => $base64]),
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

// 1. Processar imagens individualmente (Sem Pool, Lógica Estrita por Slot)
$ocr_results_por_foto = [];
foreach ($payload as $key => $base64) {
    $texts = callPythonOcr($base64);
    $ocr_results_por_foto[$key] = filterSubstrings($texts);
}

// 2. Carregar banco
$indice = carregarIndicePlanilhaOF();

// 3. Cruzar dados 
$candidatos = [];

foreach ($indice as $row) {
    $matches = true;

    // Extrair apenas o número da Potência do ds_prod (ex: "Transformador... 75kVA")
    $potencia_db = '';
    if (preg_match('/(\d+)\s*[kK][vV][aA]/i', (string)$row['descricao'], $m)) {
        $potencia_db = $m[1];
    }

    // Função Validadora
    $checkMatch = function($key, $dbValue, $isPotencia = false, $isPuzzle = false) use ($ocr_results_por_foto, $payload) {
        if (!isset($payload[$key])) return true; // Slot inativo
        if (empty($ocr_results_por_foto[$key])) return false; // Slot ativo, mas OCR retornou nada
        
        $dbValueStr = preg_replace('/[^a-zA-Z0-9]/', '', (string)$dbValue); // Ex: '0210021-027348-3' -> '02100210273483'
        
        // --- ESTRATÉGIA QUEBRA-CABEÇA (SÉRIE CLIENTE TANQUE) ---
        if ($isPuzzle) {
            $matchedPieces = 0;
            $totalLength = 0;
            $puzzlePieces = []; // Apenas para exibição no erro

            foreach ($ocr_results_por_foto[$key] as $t) {
                $tClean = preg_replace('/[^a-zA-Z0-9]/', '', $t);
                if (empty($tClean)) continue;
                
                $puzzlePieces[] = $tClean;
                
                // Verifica se a peça (ex: '021' ou '002') existe DENTRO do valor do banco (ex: '02100210273483')
                if (strpos($dbValueStr, $tClean) !== false) {
                    $matchedPieces++;
                    $totalLength += strlen($tClean);
                }
            }
            
            // Se encontrou as peças dentro da string do banco e formam um tamanho aceitável
            if ($matchedPieces > 0 && $totalLength >= 3) {
                return true;
            }
            return false;
        }

        // --- ESTRATÉGIA NORMAL / POTÊNCIA ---
        foreach ($ocr_results_por_foto[$key] as $t) {
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
    };

    // Mapeamento Estrito Conforme Planejamento:
    if (!$checkMatch('tampa_serie', $row['num_serie'])) $matches = false;
    if (!$checkMatch('tanque_serie', $row['num_serie'])) $matches = false;
    if (!$checkMatch('gancho_serie', $row['num_serie'])) $matches = false;
    
    if (!$checkMatch('tampa_codigo', $row['observacao'])) $matches = false;
    
    if (!$checkMatch('tanque_cliente', $row['num_serie_cliente'], false, true)) $matches = false; // PUZZLE MODE
    
    if (!$checkMatch('tanque_potencia', $potencia_db, true)) $matches = false;
    if (!$checkMatch('tampa_potencia', $potencia_db, true)) $matches = false;

    if ($matches) {
        $candidatos[] = $row;
    }
}

if (count($candidatos) > 0) {
    // Pegar o primeiro candidato
    $transformador = $candidatos[0];
    echo json_encode([
        'success' => true,
        'num_serie' => $transformador['num_serie'],
        'cliente' => $transformador['cliente']
    ]);
} else {
    // Montar aviso amigável
    $leiturasHTML = "<ul style='text-align:left; margin-top:10px;'>";
    $labelMap = [
        'tampa_serie' => 'Nº Série Tampa',
        'tampa_codigo' => 'Código Tampa',
        'tampa_potencia' => 'Potência Tampa',
        'tanque_serie' => 'Nº Série Tanque',
        'tanque_cliente' => 'Série Cliente',
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
    ]);
}
