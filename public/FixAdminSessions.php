<?php
header('Content-Type: application/json');

$secret = $_SERVER['HTTP_X_MERIDIAN_SECRET'] ?? '';
if ($secret !== (getenv('MERIDIAN_INTERNAL_SECRET') ?: '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$parts  = parse_url(getenv('DATABASE_URL'));
$host   = $parts['host'];
$port   = $parts['port'] ?? 5432;
$dbname = ltrim($parts['path'], '/');
$user   = $parts['user'];
$pass   = $parts['pass'];
$dsn    = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    echo json_encode(['error' => 'DB failed: ' . $e->getMessage()]);
    exit;
}

// Show current columns
$cols = $pdo->query("
    SELECT column_name, is_nullable, column_default
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'meridian_admin_sessions'
    ORDER BY ordinal_position
")->fetchAll(PDO::FETCH_ASSOC);

$fixes = [
    "ALTER TABLE meridian_admin_sessions ALTER COLUMN email DROP NOT NULL",
    "ALTER TABLE meridian_admin_sessions ALTER COLUMN email SET DEFAULT ''",
];

$results = [];
foreach ($fixes as $sql) {
    try {
        $pdo->exec($sql);
        $results[] = 'ok: ' . $sql;
    } catch (Exception $e) {
        $results[] = 'err: ' . $e->getMessage();
    }
}

echo json_encode([
    'status'  => 'done',
    'columns' => $cols,
    'results' => $results,
    'message' => 'DELETE THIS FILE NOW.',
], JSON_PRETTY_PRINT);
