<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if (!hasAcesso('pin.pai') && !hasAcesso('tab:pintura') && !hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão de acesso']);
    exit;
}

// Recebe requisição da UI (com base64 da imagem e série esperada)
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || empty($data['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nenhuma imagem enviada no payload']);
    exit;
}

$serieEsperada = $data['itemId'] ?? '';

// Dispara requisição CURL para o Microserviço Python Local
$python_url = 'http://127.0.0.1:5000/analisar';

$ch = curl_init($python_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['image' => $data['image']]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 120s de timeout para o OCR (1a vez demora mais)

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['error' => "Falha ao conectar com o robô Python (Servidor rodando?). Erro: $error"]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode(['error' => "Erro no robô Python: $result"]);
    exit;
}

$pythonResponse = json_decode($result, true);

if (!$pythonResponse || !$pythonResponse['success']) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha na resposta do robô Python']);
    exit;
}

$textos_brutos = $pythonResponse['textos_encontrados'] ?? [];

// Filtra pedaços: remove textos que são apenas substrings de outros textos maiores lidos
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
$textos = array_values(array_unique($textos));

// Tenta verificar se a série esperada está nos textos encontrados
$validado = false;
foreach ($textos as $t) {
    // Se a série digitada for encontrada exatamente no texto extraído
    if ($serieEsperada !== '' && strpos($t, $serieEsperada) !== false) {
        $validado = true;
        break;
    }
}

// Se não bateu exatamente, a tela de validação pode exigir aprovação manual,
// Mas para este POC, retornamos todos os textos para o usuário ver.
echo json_encode([
    'success' => true,
    'textos' => $textos,
    'serie_esperada' => $serieEsperada,
    'detalhes' => [
        'encontrou_serie' => $validado,
        'leitura_bruta' => implode(', ', $textos)
    ]
]);
