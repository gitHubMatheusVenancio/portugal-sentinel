<?php
// ============================================================
//  PORTUGAL SENTINEL — Groq API Proxy
//  Coloque este ficheiro no servidor (Hostinger)
//  A sua Groq API key fica APENAS no servidor, nunca exposta
// ============================================================

// ── CONFIGURAÇÃO ──────────────────────────────────────────────
// Cole as suas keys Groq abaixo. O proxy tenta a próxima automaticamente
// se a atual atingir o limite diário ou de rate limit.
$GROQ_API_KEYS = [
    '',
    '',
    '',
];
define('GROQ_MODEL', 'llama-3.3-70b-versatile');
define('GROQ_MAX_TOKENS', 4096);
define('GROQ_TEMPERATURE', 0.3);

// Domínios autorizados a chamar este proxy (coloque o seu domínio)
$allowed_origins = [
    'https://sentinel.geschaft.com.br',
    'https://www.sentinel.geschaft.com.br',
    'null', // para abrir o HTML directamente no browser
];

// ── CORS ──────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? 'null';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}
else {
    header("Access-Control-Allow-Origin: null");
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Responde ao preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apenas POST permitido
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// ── VALIDA E FILTRA KEYS ─────────────────────────────────────────
$activeKeys = array_values(array_filter($GROQ_API_KEYS, function ($k) {
    return !empty($k) && strpos($k, 'gsk_') === 0 && $k !== 'gsk_COLOQUE_AQUI_A_KEY_1'
    && $k !== 'gsk_COLOQUE_AQUI_A_KEY_2' && $k !== 'gsk_COLOQUE_AQUI_A_KEY_3';
}));

if (empty($activeKeys)) {
    http_response_code(500);
    echo json_encode(['error' => 'Nenhuma API key Groq configurada. Edite groq-proxy.php e substitua os placeholders pelas suas keys.']);
    exit;
}

// ── LÊ O BODY DO PEDIDO ───────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

if (!isset($body['prompt']) || empty($body['prompt'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "prompt" em falta']);
    exit;
}

// ── SISTEMA E PROMPT ──────────────────────────────────────────
$system_prompt = 'Você é um analista sênior de inteligência e segurança nacional '
    . 'especializado em riscos para Portugal e Europa. '
    . 'A data atual é março de 2026. '
    . 'Existe um conflito armado ativo na Europa (guerra Rússia-Ucrânia, com escalada). '
    . 'Responda APENAS com um objeto JSON válido, sem texto antes ou depois, '
    . 'sem markdown, sem backticks. O JSON deve ter exatamente a estrutura especificada.';

// ── CHAMA A API GROQ COM ROTAÇÃO DE KEYS ─────────────────────
$payload = json_encode([
    'model' => GROQ_MODEL,
    'max_tokens' => GROQ_MAX_TOKENS,
    'temperature' => GROQ_TEMPERATURE,
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user', 'content' => $body['prompt']],
    ],
]);

$text = null;
$lastError = 'Erro desconhecido';

foreach ($activeKeys as $keyIndex => $apiKey) {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        $lastError = 'Erro de rede: ' . $curl_error;
        continue;
    }

    $groq_data = json_decode($response, true);

    // ── Sucesso ────────────────────────────────────────────────
    if (isset($groq_data['choices'][0]['message']['content'])) {
        $text = $groq_data['choices'][0]['message']['content'];
        break;
    }

    // ── Verifica se é rate limit → tenta próxima key ──────────
    $groq_error = $groq_data['error']['message'] ?? ($groq_data['error']['type'] ?? '');
    $isRateLimit = $http_status === 429
        || stripos($groq_error, 'rate limit') !== false
        || stripos($groq_error, 'tokens per day') !== false
        || stripos($groq_error, 'tpd') !== false;

    $lastError = 'Groq (key ' . ($keyIndex + 1) . '): ' . ($groq_error ?: 'HTTP ' . $http_status);

    if ($isRateLimit && $keyIndex < count($activeKeys) - 1) {
        continue; // tenta a próxima
    }

    // Erro não-recuperável ou última key
    http_response_code($http_status ?: 500);
    echo json_encode(['error' => $lastError, 'raw' => $groq_data]);
    exit;
}

// ── Todas as keys esgotadas ───────────────────────────────────
if ($text === null) {
    http_response_code(429);
    echo json_encode(['error' => 'Todas as keys atingiram o limite diário. ' . $lastError]);
    exit;
}

// ── Extrai bloco JSON da resposta ─────────────────────────────
$first = strpos($text, '{');
$last = strrpos($text, '}');

if ($first === false || $last === false) {
    http_response_code(500);
    echo json_encode(['error' => 'JSON não encontrado na resposta', 'raw' => $text]);
    exit;
}

$json_str = substr($text, $first, $last - $first + 1);

$parsed = json_decode($json_str, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode([
        'error' => 'JSON inválido: ' . json_last_error_msg(),
        'raw' => $json_str,
    ]);
    exit;
}

// ── Devolve ao frontend ───────────────────────────────────────
http_response_code(200);
echo json_encode($parsed);