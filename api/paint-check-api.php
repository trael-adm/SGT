<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

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

// Permite requisições mais pesadas (base64)
ini_set('memory_limit', '256M');

// Tenta carregar a chave da API do Gemini do ambiente ou arquivo de configuração local (se existir)
$defaultApiKey = getenv('GEMINI_API_KEY');

// Leitura do payload JSON
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$action = $_GET['action'] ?? '';
$apiKey = !empty($data['apiKey']) ? $data['apiKey'] : $defaultApiKey;

if (empty($apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Gemini API key is missing. Configure no .env ou nas configurações do app.']);
    exit;
}

function callGemini($apiKey, $model, $parts, $config) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    
    $payload = json_encode([
        'contents' => [
            [
                'role' => 'user',
                'parts' => $parts
            ]
        ],
        'generationConfig' => $config
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'User-Agent: aistudio-build-php']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    // Tempo limite estendido pois imagens grandes demoram para serem processadas
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("CURL Error: $error");
    }

    if ($httpCode >= 400) {
        $errData = json_decode($result, true);
        $errMsg = $errData['error']['message'] ?? $result;
        throw new Exception("Gemini API Error ($httpCode): $errMsg");
    }

    return json_decode($result, true);
}

if ($action === 'test') {
    try {
        $parts = [['text' => 'ping']];
        $config = ['maxOutputTokens' => 1];
        callGemini($apiKey, 'gemini-3.5-flash', $parts, $config);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        // Tenta fallback
        try {
            callGemini($apiKey, 'gemini-3.5-flash-lite', $parts, $config);
            echo json_encode(['success' => true]);
        } catch (Exception $e2) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    exit;
}

if ($action === 'analyze') {
    try {
        $labeledImages = $data['labeledImages'] ?? [];
        $enabledFields = $data['enabledFields'] ?? [];
        $pdfStandard = $data['pdfStandard'] ?? null;
        $model = ($data['model'] ?? '') === 'flash' ? 'gemini-3.5-flash-lite' : 'gemini-3.5-flash';

        $fieldDescriptions = [
            'serial' => '- "serialNumber": Extraia o número de SÉRIE principal. Procure pelo número gravado em placa metálica ou estêncil puncionado no tanque. Ignore marcações secundárias (giz, etiquetas azuis) se a série oficial estiver visível.',
            'client' => '- "clientNumber": Extraia o número de PATRIMÔNIO ou identificação do Cliente (geralmente pintado em destaque).',
            'cover' => '- "cover": Extraia o código longo ou identificação alfa-numérica puncionada diretamente no metal da TAMPA (ex: "PC1234567890").',
            'power' => '- "power": Extraia a POTÊNCIA nominal em kVA (ex: 15, 30, 45, 75).',
            'coverSerial' => '- "coverSerial": Número de série gravado no metal da tampa (deve ser similar ao do tanque).',
            'coverPower' => '- "coverPower": Potência gravada no metal da tampa.',
            'fuseLink' => '- "fuseLink": Identificação do elo fusível na imagem específica.',
            'hookSerial' => '- "hookSerial": Número de série gravado no gancho.'
        ];

        $prompt = "Extraia os dados técnicos dos transformadores das imagens informadas:\n";
        $prompt .= "- \"serialNumber\": Extraia da imagem \"PLACA_SERIAL\".\n";
        $prompt .= "- \"cover\": Extraia da imagem \"TAMPA_DETALHE\".\n";
        $prompt .= "- \"clientNumber\": Extraia da imagem \"TAMPA_CLIENTE\".\n";
        $prompt .= "- \"power\": Extraia da imagem \"POTENCIA_PLACA\".\n";
        $prompt .= "- \"coverSerial\": Extraia da imagem \"SERIE_TAMPA\".\n";
        $prompt .= "- \"coverPower\": Extraia da imagem \"POTENCIA_TAMPA\".\n";
        $prompt .= "- \"fuseLink\": Extraia da imagem \"ELO_FUSIVEL\".\n";
        $prompt .= "- \"hookSerial\": Extraia da imagem \"SERIE_GANCHO\".\n\n";
        $prompt .= "Instruções: Retorne APENAS um array JSON [ { ... } ] contendo os campos solicitados. Sem explicações.";

        $parts = [['text' => $prompt]];

        $addImagePart = function($label, $base64) use (&$parts) {
            if (empty($base64) || strlen($base64) < 50) return;
            $mimeType = 'image/jpeg';
            $imgData = $base64;
            
            if (strpos($base64, ';base64,') !== false) {
                $pieces = explode(';base64,', $base64);
                $imgData = $pieces[1];
                if (preg_match('/data:(.*?)$/', $pieces[0], $match)) {
                    $mimeType = $match[1];
                }
            } elseif (strpos($base64, ',') !== false) {
                $pieces = explode(',', $base64);
                $imgData = $pieces[1];
            }
            
            $parts[] = ['text' => "IMAGE LABEL: $label"];
            $parts[] = ['inlineData' => ['mimeType' => $mimeType, 'data' => $imgData]];
        };

        if (!empty($enabledFields['serial'])) addImagePart("PLACA_SERIAL", $labeledImages['serial'] ?? null);
        if (!empty($enabledFields['client'])) addImagePart("TAMPA_CLIENTE", $labeledImages['client'] ?? null);
        if (!empty($enabledFields['cover'])) addImagePart("TAMPA_DETALHE", $labeledImages['cover'] ?? null);
        if (!empty($enabledFields['power'])) addImagePart("POTENCIA_PLACA", $labeledImages['power'] ?? null);
        if (!empty($enabledFields['coverSerial'])) addImagePart("SERIE_TAMPA", $labeledImages['coverSerial'] ?? null);
        if (!empty($enabledFields['coverPower'])) addImagePart("POTENCIA_TAMPA", $labeledImages['coverPower'] ?? null);
        if (!empty($enabledFields['fuseLink'])) addImagePart("ELO_FUSIVEL", $labeledImages['fuseLink'] ?? null);
        if (!empty($enabledFields['hookSerial'])) addImagePart("SERIE_GANCHO", $labeledImages['hookSerial'] ?? null);

        if (!empty($pdfStandard['url'])) {
            $pdfData = $pdfStandard['url'];
            if (strpos($pdfData, ',') !== false) {
                $pdfData = explode(',', $pdfData)[1];
            }
            $parts[] = ['text' => "DOCUMENT REFERENCE: " . ($pdfStandard['name'] ?? 'pdf') . "."];
            $parts[] = ['inlineData' => ['mimeType' => 'application/pdf', 'data' => $pdfData]];
        }

        // Simplificado, a resposta não usa strict schema definitions do SDK,
        // apenas pede um JSON em array format via prompt.
        $config = [
            'temperature' => 0.1,
            'maxOutputTokens' => 800,
            'responseMimeType' => 'application/json'
        ];

        try {
            $response = callGemini($apiKey, $model, $parts, $config);
        } catch (Exception $e) {
            // Tenta fallback
            $response = callGemini($apiKey, 'gemini-3.5-flash-lite', $parts, $config);
        }

        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
        $text = trim($text);

        // Limpeza simples de JSON (remove blocos de código markdown)
        if (strpos($text, '```') !== false) {
            if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
                $text = trim($matches[1]);
            } else {
                $text = trim(str_replace(['```json', '```'], '', $text));
            }
        }

        $parsedData = json_decode($text, true);
        if ($parsedData === null) {
            throw new Exception("Falha ao analisar a resposta JSON da IA: " . substr($text, 0, 200));
        }

        if (!is_array($parsedData) || (isset($parsedData['serialNumber']) && !isset($parsedData[0]))) {
            $parsedData = [$parsedData];
        }

        $tokens = $response['usageMetadata']['totalTokenCount'] ?? 0;

        echo json_encode([
            'data' => $parsedData,
            'usage' => ['totalTokens' => $tokens]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Ação inválida.']);
