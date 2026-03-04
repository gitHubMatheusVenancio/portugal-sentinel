<?php
// ============================================================
//  PORTUGAL SENTINEL — Contador de Visitantes
//  Persiste os dados em contador.txt (mesmo directório)
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-store, no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('COUNTER_FILE', __DIR__ . '/contador.txt');
define('ONLINE_TIMEOUT', 600); // 10 minutos em segundos

// ── LÊ O FICHEIRO ─────────────────────────────────────────────
function readCounter(): array {
    if (!file_exists(COUNTER_FILE)) {
        return ['total' => 0, 'today' => [], 'online' => []];
    }
    $data = json_decode(file_get_contents(COUNTER_FILE), true);
    return $data ?: ['total' => 0, 'today' => [], 'online' => []];
}

// ── ESCREVE O FICHEIRO ────────────────────────────────────────
function writeCounter(array $data): void {
    file_put_contents(COUNTER_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// ── LIMPA SESSÕES ONLINE EXPIRADAS ────────────────────────────
function cleanOnline(array $online): array {
    $now = time();
    return array_filter($online, fn($ts) => ($now - $ts) < ONLINE_TIMEOUT);
}

// ── LIMPA VISITAS DE DIAS ANTERIORES ─────────────────────────
function cleanToday(array $today): array {
    $todayKey = date('Y-m-d');
    return isset($today[$todayKey]) ? [$todayKey => $today[$todayKey]] : [];
}

// ── LÓGICA PRINCIPAL ──────────────────────────────────────────
$data    = readCounter();
$today   = date('Y-m-d');
$method  = $_SERVER['REQUEST_METHOD'];

// Identificador de sessão do visitante
$session_id = $_GET['sid'] ?? $_POST['sid'] ?? null;

if ($method === 'POST' && $session_id) {
    // Nova visita — regista sessão
    $data['online']         = cleanOnline($data['online'] ?? []);
    $data['today']          = cleanToday($data['today'] ?? []);
    $isNew = !isset($data['online'][$session_id]);

    // Actualiza timestamp online (heartbeat)
    $data['online'][$session_id] = time();

    if ($isNew) {
        // Incrementa total e hoje apenas na primeira vez
        $data['total'] = ($data['total'] ?? 0) + 1;
        $data['today'][$today] = ($data['today'][$today] ?? 0) + 1;
    }

    writeCounter($data);
}

if ($method === 'GET' && $session_id) {
    // Heartbeat — apenas actualiza timestamp, não incrementa contadores
    $data['online'] = cleanOnline($data['online'] ?? []);
    if (isset($data['online'][$session_id])) {
        $data['online'][$session_id] = time();
        writeCounter($data);
    }
}

// ── RESPOSTA ──────────────────────────────────────────────────
$data['online'] = cleanOnline($data['online'] ?? []);
$data['today']  = cleanToday($data['today'] ?? []);

echo json_encode([
    'online' => count($data['online']),
    'today'  => $data['today'][$today] ?? 0,
    'total'  => $data['total'] ?? 0,
]);