<?php
header('Content-Type: application/json');
$secret = $_SERVER['HTTP_X_MERIDIAN_SECRET'] ?? '';
if ($secret !== (getenv('MERIDIAN_INTERNAL_SECRET') ?: '')) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }
$parts = parse_url(getenv('DATABASE_URL'));
$pdo = new PDO("pgsql:host={$parts['host']};port={$parts['port']};dbname=".ltrim($parts['path'],'/')."sslmode=require", $parts['user'], $parts['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

// Show current columns so we know exactly what's there
$cols = $pdo->query("
    SELECT column_name, data_type, is_nullable, column_default
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'meridian_admin_sessions'
    ORDER BY ordinal_position
")->fetchAll(PDO::FETCH_ASSOC);

$fixes = [
    // The 'email' column was created by an earlier partial migration and is blocking inserts
    // Our controller uses 'admin_email' — make 'email' nullable so it doesn't block
    "ALTER TABLE meridian_admin_sessions ALTER COLUMN email DROP NOT NULL",
    "ALTER TABLE meridian_admin_sessions ALTER COLUMN email SET DEFAULT ''",
];

$results = [];
foreach ($fixes as $sql) {
    try { $pdo->exec($sql); $results[] = 'ok: '.$sql; }
    catch(Exception $e) { $results[] = 'err: '.$e->getMessage(); }
}

echo json_encode([
    'status'  => 'done',
    'columns' => $cols,
    'results' => $results,
    'message' => 'DELETE THIS FILE NOW.',
], JSON_PRETTY_PRINT);
