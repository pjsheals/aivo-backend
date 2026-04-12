<?php
/**
 * AIVO Meridian — Superadmin Account Seeder
 * Creates the AIVO Edge agency and paul@aivoedge.net admin user.
 * DELETE THIS FILE IMMEDIATELY AFTER RUNNING.
 */

header('Content-Type: application/json');

$secret   = $_SERVER['HTTP_X_MERIDIAN_SECRET'] ?? '';
$expected = getenv('MERIDIAN_INTERNAL_SECRET') ?: '';
if (!$secret || $secret !== $expected) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$dbUrl = getenv('DATABASE_URL');
try {
    $parts  = parse_url($dbUrl);
    $host   = $parts['host'];
    $port   = $parts['port'] ?? 5432;
    $dbname = ltrim($parts['path'], '/');
    $user   = $parts['user'];
    $pass   = $parts['pass'];
    $dsn    = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
    $pdo    = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Check if agency already exists
    $existing = $pdo->query("SELECT id FROM meridian_agencies WHERE slug = 'aivo-edge' LIMIT 1")->fetch();

    if ($existing) {
        $pdo->rollBack();
        echo json_encode(['status' => 'skipped', 'message' => 'Agency aivo-edge already exists.']);
        exit;
    }

    // Create agency
    $stmt = $pdo->prepare("
        INSERT INTO meridian_agencies
            (deployment_id, name, slug, contact_email, billing_email, plan_type, plan_status,
             max_clients, max_brands, max_users, monthly_audit_allowance, created_at, updated_at)
        VALUES
            (1, 'AIVO Edge', 'aivo-edge', 'paul@aivoedge.net', 'paul@aivoedge.net',
             'enterprise_unlimited', 'active', 999, 999, 10, 999, NOW(), NOW())
        RETURNING id
    ");
    $stmt->execute();
    $agencyId = $stmt->fetchColumn();

    // Create white-label config
    $pdo->prepare("
        INSERT INTO meridian_white_label_configs
            (agency_id, agency_display_name, show_aivo_branding, created_at, updated_at)
        VALUES (?, 'AIVO Edge', true, NOW(), NOW())
    ")->execute([$agencyId]);

    // Create paul@aivoedge.net user
    $hash = password_hash('L3adbank1@64', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare("
        INSERT INTO meridian_agency_users
            (agency_id, email, password_hash, first_name, last_name, role, is_active, created_at, updated_at)
        VALUES (?, 'paul@aivoedge.net', ?, 'Paul', 'Sheals', 'admin', true, NOW(), NOW())
        RETURNING id
    ");
    $stmt->execute([$agencyId, $hash]);
    $userId = $stmt->fetchColumn();

    // Create tim@aivoedge.net user
    $stmt = $pdo->prepare("
        INSERT INTO meridian_agency_users
            (agency_id, email, password_hash, first_name, last_name, role, is_active, created_at, updated_at)
        VALUES (?, 'tim@aivoedge.net', ?, 'Tim', 'de Rosen', 'admin', true, NOW(), NOW())
        RETURNING id
    ");
    $stmt->execute([$agencyId, $hash]);
    $timId = $stmt->fetchColumn();

    $pdo->commit();

    echo json_encode([
        'status'    => 'success',
        'agencyId'  => $agencyId,
        'paulId'    => $userId,
        'timId'     => $timId,
        'message'   => 'Agency and users created. DELETE THIS FILE NOW.',
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
