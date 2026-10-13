<?php
/**
 * Daily reminder for staff requests still waiting for a decision after 2 days:
 * emails the approver(s) of the step they wait at (staff_request_notify()). Each
 * request is reminded at most once a day (claimed atomically), so several ECS
 * tasks running this is harmless. Quiet no-op before add_staff_requests.sql.
 *
 *   php bin/staff-request-reminders.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/staff-requests.php';
$n = staff_requests_remind(2);
echo date('c') . " staff-request reminders: {$n}\n";
