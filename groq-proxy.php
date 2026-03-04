<?php
// ============================================================
//  PORTUGAL SENTINEL — Groq API Proxy  (com cache de 1 hora)
// ============================================================

// ── CARREGA .env (se existir) ─────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$name] = $value;
        }
    }
}

$GROQ_API_KEYS = [
    $_ENV['GROQ_API_KEY_1'] ?? '',
    $_ENV['GROQ_API_KEY_2'] ?? '',
    $_ENV['GROQ_API_KEY_3'] ?? '',
];
define('GROQ_MODEL', $_ENV['GROQ_MODEL'] ?? 'llama-3.3-70b-versatile');
define('GROQ_MAX_TOKENS', $_ENV['GROQ_MAX_TOKENS'] ?? 4096);
define('GROQ_TEMPERATURE', $_ENV['GROQ_TEMPERATURE'] ?? 0.3);
define('OPENROUTER_API_KEY', $_ENV['OPENROUTER_API_KEY'] ?? '');

// ── CACHE ─────────────────────────────────────────────────────
define('CACHE_FILE', __DIR__ . '/sentinel-cache.json');
define('CACHE_MAX_AGE', 3600); // 1 hora

$allowed_origins = [
    'https://sentinel.geschaft.com.br',
    'https://www.geschaft.com.br',
    'null',
];

// ── CORS ──────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? 'null';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed_origins) ? $origin : 'null'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// ── VALIDA KEYS ───────────────────────────────────────────────
$activeKeys = array_values(array_filter($GROQ_API_KEYS, fn($k) =>
!empty($k) && strpos($k, 'gsk_') === 0
&& !in_array($k, ['gsk_COLOQUE_AQUI_A_KEY_1', 'gsk_COLOQUE_AQUI_A_KEY_2', 'gsk_COLOQUE_AQUI_A_KEY_3'])
));

if (empty($activeKeys)) {
    http_response_code(500);
    echo json_encode(['error' => 'Nenhuma API key Groq configurada.']);
    exit;
}

// ── BODY ──────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!isset($body['prompt']) || empty($body['prompt'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "prompt" em falta']);
    exit;
}

// ── VERIFICA CACHE (ignorado se force=1) ──────────────────────
$forceRefresh = !empty($body['force']) && $body['force'] == 1;

if (!$forceRefresh && file_exists(CACHE_FILE)) {
    $cache = json_decode(file_get_contents(CACHE_FILE), true);
    if (json_last_error() === JSON_ERROR_NONE
    && isset($cache['_cached_at'])
    && (time() - $cache['_cached_at']) < CACHE_MAX_AGE
    ) {
        $cache['_cached'] = true;
        $cache['_cache_age'] = time() - $cache['_cached_at'];
        $cache['_next_update'] = $cache['_cached_at'] + CACHE_MAX_AGE;
        http_response_code(200);
        echo json_encode($cache);
        exit;
    }
}

// ── SISTEMA E PROMPT ──────────────────────────────────────────
$system_prompt = 'Você é um analista sênior de inteligência e segurança nacional '
    . 'especializado em riscos para Portugal e Europa. '
    . 'A data atual é março de 2026. '
    . 'Existe um conflito armado ativo na Europa (guerra Rússia-Ucrânia, com escalada). '
    . 'Responda APENAS com um objeto JSON válido, sem texto antes ou depois, '
    . 'sem markdown, sem backticks. O JSON deve ter exatamente a estrutura especificada.';

$payload = json_encode([
    'model' => GROQ_MODEL,
    'max_tokens' => GROQ_MAX_TOKENS,
    'temperature' => GROQ_TEMPERATURE,
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user', 'content' => $body['prompt']],
    ],
]);

// ── GROQ COM ROTAÇÃO DE KEYS ──────────────────────────────────
$text = null;
$lastError = 'Erro desconhecido';

foreach ($activeKeys as $keyIndex => $apiKey) {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
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
    if (isset($groq_data['choices'][0]['message']['content'])) {
        $text = $groq_data['choices'][0]['message']['content'];
        break;
    }

    $groq_error = $groq_data['error']['message'] ?? ($groq_data['error']['type'] ?? '');
    $isRateLimit = $http_status === 429 || stripos($groq_error, 'rate limit') !== false || stripos($groq_error, 'tokens per day') !== false;
    $lastError = 'Groq (key ' . ($keyIndex + 1) . '): ' . ($groq_error ?: 'HTTP ' . $http_status);
    if ($isRateLimit)
        continue;

    http_response_code($http_status ?: 500);
    echo json_encode(['error' => $lastError, 'raw' => $groq_data]);
    exit;
}

// ── OPENROUTER FALLBACK ───────────────────────────────────────
if ($text === null) {
    $orKey = OPENROUTER_API_KEY;
    $isORConfigured = !empty($orKey) && strpos($orKey, 'sk-or-') === 0 && $orKey !== 'sk-or-v1-COLOQUE_AQUI_A_SUA_KEY';

    if (!$isORConfigured) {
        http_response_code(429);
        echo json_encode(['error' => 'Todas as keys Groq atingiram o limite e OpenRouter não configurado. ' . $lastError]);
        exit;
    }

    $orModels = [
        'meta-llama/llama-3.1-8b-instruct:free', 'mistralai/mistral-7b-instruct:free',
        'google/gemma-2-9b-it:free', 'qwen/qwen-2.5-7b-instruct:free',
        'deepseek/deepseek-r1-distill-qwen-7b:free', 'nousresearch/hermes-3-llama-3.1-405b:free',
    ];
    $orLastErr = 'Nenhum modelo OpenRouter disponível';

    foreach ($orModels as $orModel) {
        $orBody = json_encode(['model' => $orModel, 'max_tokens' => GROQ_MAX_TOKENS, 'temperature' => GROQ_TEMPERATURE,
            'messages' => [['role' => 'system', 'content' => $system_prompt], ['role' => 'user', 'content' => $body['prompt']]]]);
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $orBody, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $orKey,
                'HTTP-Referer: https://geschaft.com.br', 'X-Title: Portugal Sentinel']]);
        $orResponse = curl_exec($ch);
        $orStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $orCurlErr = curl_error($ch);
        curl_close($ch);
        if ($orCurlErr) {
            $orLastErr = 'Rede: ' . $orCurlErr;
            continue;
        }
        $orData = json_decode($orResponse, true);
        if ($orData['choices'][0]['message']['content'] ?? null) {
            $text = $orData['choices'][0]['message']['content'];
            break;
        }
        $orLastErr = 'OpenRouter (' . $orModel . '): ' . ($orData['error']['message'] ?? 'HTTP ' . $orStatus);
    }
    if (!$text) {
        http_response_code(503);
        echo json_encode(['error' => $orLastErr]);
        exit;
    }
}

// ── EXTRAI JSON DA RESPOSTA ───────────────────────────────────
$first = strpos($text, '{');
$last = strrpos($text, '}');
if ($first === false || $last === false) {
    http_response_code(500);
    echo json_encode(['error' => 'JSON não encontrado', 'raw' => $text]);
    exit;
}
$parsed = json_decode(substr($text, $first, $last - $first + 1), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode(['error' => 'JSON inválido: ' . json_last_error_msg()]);
    exit;
}

// ── ADICIONA METADADOS E GUARDA CACHE ─────────────────────────
$now = time();
$parsed['_cached'] = false;
$parsed['_cached_at'] = $now;
$parsed['_cache_age'] = 0;
$parsed['_next_update'] = $now + CACHE_MAX_AGE;

@file_put_contents(CACHE_FILE, json_encode($parsed), LOCK_EX);

http_response_code(200);
echo json_encode($parsed);