<?php
declare(strict_types=1);
/**
 * Calendar → Ilai unit map: the live aerial view of Maya Ilai (every villa bedroom
 * and studio) for one day. READ-ONLY — reads the same calendar as the Gantt via
 * mi_unit_map() (includes/maya-ilai-unitmap.php). Moved here from the Maya Ilai
 * rates page (Oct 2026, owner).
 *
 * Who: maya_ilai_tool_mode() — the owner, and managers / reception whose
 * properties include Maya Ilai. The map shows guest names, so an account without
 * Maya Ilai in its properties never sees it. The JSON handler is the page's own:
 * same gate + CSRF in the body.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/maya-ilai-pricing.php';
require_once __DIR__ . '/../includes/maya-ilai-unitmap.php';
require_login();

if (maya_ilai_tool_mode() === null) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'The Ilai unit map is only available to Maya Ilai managers and reception.'];
    header('Location: ' . admin_home_url()); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data  = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = (string)($data['csrf_token'] ?? '');
    if (($_SESSION['csrf_token'] ?? '') === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Invalid session token. Please reload.']));
    }
    if (($data['action'] ?? '') !== 'unitmap') { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'Unknown action.'])); }
    try {
        require_once __DIR__ . '/../includes/rates.php';   // rates_window_ymd()
        $d = rates_window_ymd((string)($data['date'] ?? ''));
        if ($d === null) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'Please choose a valid date.'])); }
        exit(json_encode(['ok' => true, 'map' => mi_unit_map($d)]));
    } catch (Throwable $e) {
        error_log('[unit-map] ' . $e->getMessage());
        http_response_code(500); exit(json_encode(['ok' => false, 'error' => 'Could not load the unit map.']));
    }
}

$pageTitle  = 'Ilai unit map';
$activeMenu = 'gantt';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Ilai unit map</h1>
</div>
<?php $um_endpoint = '/admin/unit-map.php'; include __DIR__ . '/../includes/maya-ilai-unitmap-view.php'; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
