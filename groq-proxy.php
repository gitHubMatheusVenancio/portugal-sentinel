<?php
// ============================================================
//  PORTUGAL SENTINEL — Groq API Proxy
//  Coloque este ficheiro no servidor (Hostinger)
//  A sua Groq API key fica APENAS no servidor, nunca exposta
// ============================================================

// ── CONFIGURAÇÃO ──────────────────────────────────────────────
define('GROQ_API_KEY', 'gordo');   // <- substitua
define('GROQ_MODEL',   'llama-3.3-70b-versatile');        // modelo gratuito
define('GROQ_MAX_TOKENS', 4096);
define('GROQ_TEMPERATURE', 0.3);

// Domínios autorizados a chamar este proxy (coloque o seu domínio)
$allowed_origins = [
    'https://seudominio.com',
    'https://www.seudominio.com',
    'http://localhost',       // para testes locais
    'null',                   // para abrir o HTML directamente no browser
];

// ── CORS ──────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? 'null';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
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

// ── CHAMA A API GROQ ──────────────────────────────────────────
$payload = json_encode([
    'model'       => GROQ_MODEL,
    'max_tokens'  => GROQ_MAX_TOKENS,
    'temperature' => GROQ_TEMPERATURE,
    'messages'    => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user',   'content' => $body['prompt']],
    ],
]);

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . GROQ_API_KEY,
    ],
]);

$response    = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error  = curl_error($ch);
curl_close($ch);

// ── TRATA ERROS DE REDE ───────────────────────────────────────
if ($curl_error) {
    http_response_code(502);
    echo json_encode(['error' => 'Erro de rede: ' . $curl_error]);
    exit;
}

$groq_data = json_decode($response, true);

// ── EXTRAI O TEXTO DA RESPOSTA ────────────────────────────────
if (!isset($groq_data['choices'][0]['message']['content'])) {
    http_response_code($http_status ?: 500);
    echo json_encode([
        'error'    => 'Resposta inesperada da Groq',
        'raw'      => $groq_data,
    ]);
    exit;
}

$text = $groq_data['choices'][0]['message']['content'];

// Extrai bloco JSON da resposta (ignora qualquer texto extra)
$first = strpos($text, '{');
$last  = strrpos($text, '}');

if ($first === false || $last === false) {
    http_response_code(500);
    echo json_encode(['error' => 'JSON não encontrado na resposta', 'raw' => $text]);
    exit;
}

$json_str = substr($text, $first, $last - $first + 1);

// Valida o JSON antes de devolver
$parsed = json_decode($json_str, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode([
        'error'     => 'JSON inválido: ' . json_last_error_msg(),
        'raw'       => $json_str,
    ]);
    exit;
}

// ── DEVOLVE AO FRONTEND ───────────────────────────────────────
http_response_code(200);
echo json_encode($parsed);