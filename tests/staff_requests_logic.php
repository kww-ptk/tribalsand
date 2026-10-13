<?php
declare(strict_types=1);
// Staff requests — who may act at each step, what approval writes into the
// attendance, and that cancelling puts every day back.
// Run: php tests/staff_requests_logic.php
// DB part runs inside ONE transaction that is ROLLED BACK; it SKIPs without a
// database or before add_staff_requests.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/staff-requests.php';
require_once __DIR__ . '/../includes/hr-balances.php';
require_once __DIR__ . '/../includes/hr-documents.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function refused(callable $fn): bool {
    try { $fn(); return false; } catch (StaffRequestRefusal $e) { return true; }
}

// ── Pure ────────────────────────────────────────────────────────────────────
check('steps: a manager acts at step 1 only', staff_request_can_act('pending', 'manager', true) && !staff_request_can_act('pending_hr', 'manager', true));
check('steps: HR and the owner act at both', staff_request_can_act('pending', 'hr', true) && staff_request_can_act('pending_hr', 'owner', true));
check('steps: nobody acts out of scope or on a closed request', !staff_request_can_act('pending', 'owner', false) && !staff_request_can_act('approved', 'hr', true));
check('stamp days: the weekly off day is left alone', staff_request_stamp_days('2026-10-19', '2026-10-25', 'SUN') === ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24']);
check('types: leave codes', staff_request_types()['annual'][1] === 'LV' && staff_request_types()['day_off'][1] === 'REC' && staff_request_types()['unpaid'][1] === 'UL' && staff_request_types()['correction'][1] === null);

// ── DB ──────────────────────────────────────────────────────────────────────
try { db(); $haveDb = staff_requests_supported(); } catch (Throwable $e) { $haveDb = false; }
if (!$haveDb) {
    echo "SKIP  DB checks (no database or add_staff_requests not applied)\n";
} else {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        unset($_SESSION['admin_id']);
        db_query("INSERT INTO hr_staff (full_name, position, department, status, off_day) VALUES ('REQUEST TEST', 'COOK', 'Kitchen', 'active', 'SUN')");
        $sid = (int)$pdo->lastInsertId();
        db_query("INSERT INTO hr_staff (full_name, position, department, status, off_day) VALUES ('REQUEST COLLEAGUE', 'COOK', 'Kitchen', 'active', 'MON')");
        $col = (int)$pdo->lastInsertId();
        $att = fn(string $d) => db_query('SELECT * FROM attendance WHERE hr_staff_id = :s AND work_date = :d', [':s' => $sid, ':d' => $d])->fetch() ?: null;

        // Days that exist before the leave: one worked (must not be stamped), one typed OFF (stamped, then restored).
        attendance_upsert($sid, '2026-10-20', ['in1' => '08:00', 'out1' => '17:00'], null, 'kiosk');
        attendance_upsert($sid, '2026-10-21', ['status' => 'OFF'], null, 'manual');

        check('create: needs a type and dates', refused(fn() => staff_request_create($sid, ['type' => 'nope', 'from' => '2026-10-19'], null))
            && refused(fn() => staff_request_create($sid, ['type' => 'annual', 'from' => ''], null)));
        check('create: the last day before the first refuses', refused(fn() => staff_request_create($sid, ['type' => 'annual', 'from' => '2026-10-22', 'to' => '2026-10-19'], null)));
        $id = staff_request_create($sid, ['type' => 'annual', 'from' => '2026-10-19', 'to' => '2026-10-25', 'reason' => 'family'], null);
        $r = staff_request_fetch($id);
        check('create: waits for the supervisor, nothing written yet', $r['status'] === 'pending' && $att('2026-10-19') === null);
        check('create: an overlapping leave is refused', refused(fn() => staff_request_create($sid, ['type' => 'sick', 'from' => '2026-10-24'], null)));

        check('approve: a manager out of scope refuses', refused(fn() => staff_request_approve($id, 'manager', null, false)));
        check('approve: manager → waiting for HR', staff_request_approve($id, 'manager', null, true) === 'pending_hr' && $att('2026-10-19') === null);
        check('approve: the manager cannot give the final step', refused(fn() => staff_request_approve($id, 'manager', null, true)));
        check('approve: HR → approved', staff_request_approve($id, 'hr', null, true) === 'approved');
        $r = staff_request_fetch($id);
        check('apply: leave stamped on working days', ($att('2026-10-19')['status'] ?? '') === 'LV' && ($att('2026-10-22')['status'] ?? '') === 'LV'
            && (int)$att('2026-10-22')['request_id'] === $id && $att('2026-10-22')['source'] === 'request');
        check('apply: the Sunday off is left alone', $att('2026-10-25') === null);
        check('apply: a day already worked is skipped and named', ($att('2026-10-20')['status'] ?? null) === null && (int)$att('2026-10-20')['in1'] === 480 && $r['details']['skipped'] === ['2026-10-20']);
        check('apply: a typed OFF is stamped, its old value kept', $att('2026-10-21')['status'] === 'LV' && ($r['details']['replaced']['2026-10-21']['status'] ?? '') === 'OFF');
        $f = hr_bal_month_figures(array_column(db_query("SELECT * FROM attendance WHERE hr_staff_id = :s AND work_date BETWEEN '2026-10-01' AND '2026-10-31'", [':s' => $sid])->fetchAll(), null, 'work_date'), 'SUN', '2026-10', []);
        check('balances: the leave days count as leave taken', (float)$f['leave'] === 5.0);

        check('cancel: a manager cannot cancel an approved request', refused(fn() => staff_request_cancel($id, 'manager', null, true, 'x')));
        check('cancel: an approved request needs a reason', refused(fn() => staff_request_cancel($id, 'hr', null, true, '')));
        staff_request_cancel($id, 'hr', null, true, 'trip moved');
        check('cancel: stamped days removed, the typed OFF back, the worked day untouched',
            $att('2026-10-19') === null && $att('2026-10-21')['status'] === 'OFF' && $att('2026-10-21')['source'] === 'manual' && (int)$att('2026-10-20')['in1'] === 480);
        check('cancel: status + reason recorded', ($c = staff_request_fetch($id))['status'] === 'cancelled' && $c['decline_reason'] === 'trip moved');

        // Correction: keeps the original times, cancel restores them.
        attendance_upsert($sid, '2026-10-14', ['in1' => '08:00', 'out1' => ''], null, 'kiosk');
        check('correction: needs times + a reason', refused(fn() => staff_request_create($sid, ['type' => 'correction', 'from' => '2026-10-14', 'in1' => '08:00'], null))
            && refused(fn() => staff_request_create($sid, ['type' => 'correction', 'from' => '2026-10-14', 'in1' => '08:00', 'out1' => '17:00'], null)));
        $cid = staff_request_create($sid, ['type' => 'correction', 'from' => '2026-10-14', 'in1' => '08:00', 'out1' => '17:30', 'reason' => 'forgot to clock out'], null, 'owner');
        $c = staff_request_fetch($cid);
        check('correction: submit and approve records both steps', $c['status'] === 'approved' && $c['supervisor_at'] !== null && $c['hr_at'] !== null);
        check('correction: new times written', (int)$att('2026-10-14')['out1'] === 1050 && attendance_hours($att('2026-10-14')) === 9.5);
        check('correction: the original is kept', array_key_exists('2026-10-14', $c['details']['replaced']) && $c['details']['replaced']['2026-10-14']['out1'] === null);
        staff_request_cancel($cid, 'owner', null, true, 'wrong day');
        check('correction: cancel restores the original', $att('2026-10-14')['out1'] === null && $att('2026-10-14')['source'] === 'kiosk');

        // Half day, decline, swap, overtime.
        $hid = staff_request_create($sid, ['type' => 'annual', 'from' => '2026-10-27', 'half_day' => '1'], null, 'hr');
        $f = hr_bal_month_figures(['2026-10-27' => $att('2026-10-27')], 'SUN', '2026-10', [], '2026-10-27', '2026-10-27');
        check('half day: counts 0.5 leave', (float)$f['leave'] === 0.5);
        check('half day: one day only', refused(fn() => staff_request_create($sid, ['type' => 'annual', 'from' => '2026-11-02', 'to' => '2026-11-03', 'half_day' => '1'], null)));
        $did = staff_request_create($sid, ['type' => 'unpaid', 'from' => '2026-11-10'], null);
        check('decline: needs a reason', refused(fn() => staff_request_decline($did, 'manager', null, true, '')));
        staff_request_decline($did, 'manager', null, true, 'busy week');
        check('decline: recorded at the supervisor step', ($d = staff_request_fetch($did))['status'] === 'declined' && $d['supervisor_at'] !== null && $att('2026-11-10') === null);
        check('swap: needs a colleague', refused(fn() => staff_request_create($sid, ['type' => 'swap', 'from' => '2026-11-12'], null)));
        $wid = staff_request_create($sid, ['type' => 'swap', 'from' => '2026-11-12', 'with_staff_id' => $col, 'accepted' => '1'], null);
        check('swap: colleague + agreement kept', ($w = staff_request_fetch($wid))['details']['with_staff_id'] === $col && !empty($w['details']['accepted_at']));
        $oid = staff_request_create($sid, ['type' => 'overtime', 'from' => '2026-11-13', 'hours' => '3', 'worked' => '1'], null, 'owner');
        check('overtime: recorded, attendance untouched', staff_request_fetch($oid)['status'] === 'approved' && $att('2026-11-13') === null);
        // Who is emailed: step 1 → the property's managers, step 2 → HR; never the one who acted.
        $vid = (int)db_query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchColumn();
        db_query('UPDATE hr_staff SET venue_id = :v WHERE id = :s', [':v' => $vid, ':s' => $sid]);
        $mkU = function (string $email, string $role) use ($pdo): int {
            db_query("INSERT INTO admin_users (name, email, password_hash, role, is_active) VALUES (:n, :e, 'x', :r, TRUE)", [':n' => $email, ':e' => $email, ':r' => $role]);
            return (int)$pdo->lastInsertId();
        };
        $mgr = $mkU('mgr-reqtest@tribalsand.test', 'manager');
        db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a, :v)', [':a' => $mgr, ':v' => $vid]);
        $hrU = $mkU('hr-reqtest@tribalsand.test', 'hr');
        $e1 = staff_request_approver_emails($sid, 'pending');
        check('notify: step 1 goes to the property manager', in_array('mgr-reqtest@tribalsand.test', $e1, true) && !in_array('hr-reqtest@tribalsand.test', $e1, true));
        check('notify: never to the one who acted', !in_array('mgr-reqtest@tribalsand.test', staff_request_approver_emails($sid, 'pending', $mgr), true));
        check('notify: step 2 goes to HR', in_array('hr-reqtest@tribalsand.test', staff_request_approver_emails($sid, 'pending_hr'), true));
        require_once __DIR__ . '/../includes/mail.php';
        $nid = staff_request_create($sid, ['type' => 'sick', 'from' => '2026-11-20'], null);
        $cap = mail_capture(fn() => staff_request_notify($nid, null));
        $mine = array_values(array_filter($cap, fn($m) => $m['to'] === 'mgr-reqtest@tribalsand.test'));
        check('notify: the email names the person and the request', $mine && str_contains($mine[0]['subject'], 'REQUEST TEST') && str_contains($mine[0]['html'], 'Sick leave'));
        // Policy: a type HR does not need is final at the supervisor.
        $keepPolicy = setting('staff_request_hr_types', '');
        staff_request_set_hr_types(array_values(array_diff(array_keys(staff_request_types()), ['overtime'])));
        check('policy: overtime no longer needs HR', !staff_request_needs_hr('overtime') && staff_request_needs_hr('annual'));
        $pol = staff_request_create($sid, ['type' => 'overtime', 'from' => '2026-11-21', 'hours' => '2'], null);
        check('policy: the supervisor approval is final', staff_request_approve($pol, 'manager', null, true) === 'approved' && staff_request_fetch($pol)['hr_at'] === null);
        set_setting('staff_request_hr_types', $keepPolicy);

        // Check before submit.
        attendance_upsert($sid, '2026-11-24', ['in1' => '08:00', 'out1' => '17:00'], null, 'kiosk');
        $ck = staff_request_check($sid, 'annual', '2026-11-23', '2026-11-29');
        check('check: working days, off day and a worked day told apart', count($ck['days']) === 5 && $ck['off'] === ['2026-11-29'] && $ck['worked'] === ['2026-11-24']);
        check('check: a correction marks no leave days', staff_request_check($sid, 'correction', '2026-11-24', '2026-11-24')['days'] === []);
        check('check: overlaps are listed', count(staff_request_check($sid, 'annual', '2026-11-20', '2026-11-20')['overlap']) >= 1);

        // Reminders: only requests waiting 2+ days, once a day.
        $old = staff_request_create($sid, ['type' => 'unpaid', 'from' => '2026-12-01'], null);
        db_query("UPDATE leave_requests SET updated_at = now() - interval '3 days' WHERE id = :id", [':id' => $old]);
        $sent = mail_capture(function () use (&$first) { $first = staff_requests_remind(2); });
        $second = staff_requests_remind(2);
        check('remind: a request waiting 3 days is reminded', $first >= 1 && $sent);
        check('remind: not twice the same day', $second === 0);

        // Medical notes.
        if (hr_staff_documents_supported()) {
            db_query("INSERT INTO hr_staff_documents (hr_staff_id, file_key, filename, content_type, size_bytes) VALUES (:s, 'hr/x/note.pdf', 'note.pdf', 'application/pdf', 10)", [':s' => $sid]);
            $doc = (int)$pdo->lastInsertId();
            $mid = staff_request_create($sid, ['type' => 'sick', 'from' => '2026-12-03', 'doc_id' => $doc], null);
            check('medical: a sick note is medical', staff_request_doc_is_medical($doc) && staff_request_fetch($mid)['doc_id'] == $doc);
        }
        check('waiting: a manager sees step-1 items, HR both', staff_requests_waiting(null, null, 'hr') >= staff_requests_waiting(null, null, 'manager'));
    } finally {
        $pdo->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
