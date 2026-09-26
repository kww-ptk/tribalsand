<?php
declare(strict_types=1);
/**
 * POS till — walk-in customers seen before (GET ?q=<name or phone>) →
 * {ok, results:[{id,name,phone}]}. Needs at least 2 characters; max 8 results.
 */
require_once __DIR__ . '/../../includes/pos-auth.php';

pos_api_guard(false);
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) exit(json_encode(['ok' => true, 'results' => []]));
$like = '%' . mb_substr($q, 0, 60) . '%';
$rows = db_query(
    "SELECT id, name, phone FROM pos_customers WHERE name ILIKE :a OR phone ILIKE :b ORDER BY created_at DESC LIMIT 8",
    [':a' => $like, ':b' => $like]
)->fetchAll();
echo json_encode(['ok' => true, 'results' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'phone' => (string)($r['phone'] ?? '')], $rows)]);
