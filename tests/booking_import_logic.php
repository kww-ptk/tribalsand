<?php
declare(strict_types=1);
// Ezee booking importer — parse, map (per property), resolve, commit.
// Run: php tests/booking_import_logic.php
// DB writes run inside a rolled-back transaction — nothing persists.
require_once __DIR__ . '/../includes/booking-import.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure helpers ──
check('norm collapses + lowercases', import_norm("  Master   DOUBLE  Suite ") === 'master double suite');

// Maps are per property now. Zuri keeps the built-in seed; every other property
// starts empty, so nothing can be imported into it until it is mapped.
$zuriV  = import_zuri_venue_id();
$otherV = (int) db_query("SELECT id FROM venues WHERE id <> :z ORDER BY id LIMIT 1", [':z' => $zuriV])->fetchColumn();

check('map exact',        import_map_room_slug('Standard Garden View Suite', $zuriV) === 'zuri-maji');
check('map buyout',       import_map_room_slug('Entire Retreat Buyout', $zuriV) === 'zuri-buyout');
check('map twin→mwezi',   import_map_room_slug('Tropical Pool View Twin Suite', $zuriV) === 'zuri-mwezi');
check('map is tolerant',  import_map_room_slug('  master double suite ', $zuriV) === 'zuri-anga');
check('unmapped → null',  import_map_room_slug('Presidential Yacht', $zuriV) === null);
check('another property starts unmapped',
      !$otherV || import_map_room_slug('Standard Garden View Suite', $otherV) === null);
check('venue 0 maps nothing', import_map_room_slug('Standard Garden View Suite', 0) === null);

check('date DD/MM/YYYY',  import_parse_date('05/09/2026') === '2026-09-05');
check('date D/M/YYYY',    import_parse_date('5/9/2026') === '2026-09-05');
check('date DD-MM-YYYY',  import_parse_date('09-01-2026') === '2026-01-09');
check('date D-M-YYYY',    import_parse_date('9-1-2026')   === '2026-01-09');
check('date dash impossible', import_parse_date('31-02-2026') === null);
check('date mixed seps',  import_parse_date('09-01/2026') === null);
check('date ISO',         import_parse_date('2026-09-05') === '2026-09-05');
check('date ISO not day-first', import_parse_date('2026-01-09') === '2026-01-09');
check('date Excel serial',import_parse_date('43831') === '2020-01-01');
check('date invalid',     import_parse_date('not a date') === null);
check('date impossible',  import_parse_date('31/02/2026') === null);
check('date empty',       import_parse_date('') === null);

// ── Amount parsing ──
check('amount plain',        import_parse_amount('1500') === 1500.0);
check('amount thousands',    import_parse_amount('12,500') === 12500.0);
check('amount currency code', import_parse_amount('KES 12,500') === 12500.0);
check('amount symbol+decimals', import_parse_amount('$1,234.50') === 1234.50);
check('amount euro style',    import_parse_amount('1.200,00') === 1200.0);
check('amount blank → null',  import_parse_amount('') === null);
check('amount dash → null',   import_parse_amount('-') === null);
check('amount junk → null',   import_parse_amount('n/a') === null);

// ── Header-mapped extraction (CSV via a temp file) ──
$tmp = sys_get_temp_dir() . '/ts_import_test_' . getmypid() . '.csv';
file_put_contents($tmp,
    "Hotel Name,Booking Date,Guest Name,Arrival,Dept,Room,,Rate Type,Total,Paid,Total Charges,Travelagent\n" .
    "Zuri | Watamu,02/09/2026,Jane Doe,05/09/2030,07/09/2030,Standard Garden View Suite,,Breakfast,1,0,1,-\n" .
    "Zuri | Watamu,02/09/2026,Sub Label,08/09/2030,10/09/2030,Family Garden View Suite,Anga Suite,Breakfast,1,0,1,Booking.com\n"
);
$parsed = import_read_file($tmp, 'csv');
@unlink($tmp);
check('extract found guest/room/dates', $parsed['fields']['guest'] && $parsed['fields']['room'] && $parsed['fields']['arrival'] && $parsed['fields']['dept']);
check('extract row count', count($parsed['rows']) === 2);
check('extract guest value', $parsed['rows'][0]['guest'] === 'Jane Doe');
check('extract unit sub-name', $parsed['rows'][1]['unit_label'] === 'Anga Suite');
check('extract agent value', $parsed['rows'][1]['agent'] === 'Booking.com');
check('extract found amount column', $parsed['fields']['amount'] === true);
check('extract amount raw value', ($parsed['rows'][0]['amount_raw'] ?? '') === '1');

// ── eZee CRS bookings report (.xls that is really tab-separated text) ──
check('clean unwraps ="…"',   import_clean_cell('="05/10/2026"') === '05/10/2026');
check('clean dash is empty',  import_clean_cell('-') === '');
check('clean drops BOM',      import_clean_cell("\xEF\xBB\xBFHotel Name") === 'Hotel Name');
check('skip released',        import_skip_reason('Active', 'Released') !== null);
check('skip cancelled',       import_skip_reason('Cancelled', 'Confirm Booking') !== null);
check('skip inquiry',         import_skip_reason('Active', 'Unconfirmed Booking Inquiry') !== null);
check('keep confirm',         import_skip_reason('Active', 'Confirm Booking') === null);
check('keep hold confirm',    import_skip_reason('Active', 'Hold Confirm Booking') === null);

// Synthetic copy of the real layout: one row per payment (Res 139 twice), a
// quoted multi-line Remark (Booking.com notes), "-" empties, ="…" wrapped values.
$crsHead = ['Hotel Name','Res No.','Booking Date','Booking Time','Guest Name','User','Arrival','Dept','Room','Rate Type','Pax','Total','ADR','Paid','Transaction ID','Receipt Date','Source','Total Tax','Total Charges','Commission','Voucher','Status','Balance','Email','Mobile No','City','Country','Zip Code','State','Folio No','Preference','Travelagent','Salesperson','Remark','Reservation Type','Market Segment','Payment Type','No of Nights','Cancellation Date','Last Modified Date','Last Modified By','Number of rooms booked'];
$crsRow = function (string $res, string $guest, string $ci, string $co, string $room, string $total, string $paid, string $agent, string $remark, string $type) {
    $q = fn($v) => '"' . str_replace('"', '""', $v) . '"';
    $x = fn($v) => $q('="' . $v . '"');
    return implode("\t", [
        $q('Zuri | Watamu'), $x($res), $x('14/05/2026'), $q('10:46:29 AM'), $q($guest), $q('Staff'),
        $x($ci), $x($co), $q($room), $q('Breakfast'), $q('2 \\ 0'), $q($total), $q('1.00'), $q($paid),
        $q('117'), $q('28/05/2026'), $q($agent), $q('0'), $q('0'), $q('0'), '-', $q('Active'), $q('0.0000'),
        '-', '-', '-', '-', '-', '-', $q('125'), '-', $agent === 'Walk-in' ? '-' : $q($agent), '-',
        $remark === '' ? '-' : $q($remark), $q($type), '-', '-', $q('3'), '-', $x('22/08/2026'), $q('Staff'), $q('1'),
    ]);
};
$tmp = sys_get_temp_dir() . '/ts_crs_test_' . getmypid() . '.xls';
file_put_contents($tmp, implode("\n", [
    implode("\t", array_map(fn($h) => '"' . $h . '"', $crsHead)),
    $crsRow('139', 'Ms. Kelsey', '05/10/2030', '08/10/2030', 'Master Double Suite', '111435.00', '20718.00', 'Alpine Ltd', '', 'Confirm Booking'),
    $crsRow('139', 'Ms. Kelsey', '05/10/2030', '08/10/2030', 'Master Double Suite', '111435.00', '35000.00', 'Alpine Ltd', '', 'Confirm Booking'),
    $crsRow('189', 'Thomas C', '17/10/2030', '22/10/2030', 'Standard Garden View Suite', '170820.00', '0', 'Booking.com',
            "Reservation has a cancellation grace period.\n,\n\nbooked rate: Non-refundable, \"Genius\" booker", 'Confirm Booking'),
    $crsRow('106', 'Ms. Roukounis', '20/12/2030', '27/12/2030', 'Entire Retreat Buyout', '380450.00', '0', 'Walk-in', '', 'Released'),
]) . "\n");
$crs = import_read_file($tmp, 'xls');
@unlink($tmp);
$cr = $crs['rows'];
check('crs: payment rows collapse to one per reservation', count($cr) === 3);
check('crs: res no unwrapped',     ($cr[0]['res_no'] ?? '') === '139');
check('crs: dates unwrapped',      $cr[0]['arrival_raw'] === '05/10/2030' && import_parse_date($cr[0]['dept_raw']) === '2030-10-08');
check('crs: room',                 $cr[0]['room_raw'] === 'Master Double Suite');
check('crs: total is the amount',  import_parse_amount($cr[0]['amount_raw']) === 111435.0);
check('crs: rate type is not a unit', $cr[0]['unit_label'] === '');
check('crs: agent',                $cr[0]['agent'] === 'Alpine Ltd');
check('crs: multi-line remark kept in one row', $cr[1]['guest'] === 'Thomas C' && $cr[1]['res_type'] === 'Confirm Booking');
check('crs: dash agent is empty',  $cr[2]['agent'] === '');
check('crs: reservation type read', $cr[2]['res_type'] === 'Released');
check('crs: Source column read', ($cr[1]['source_raw'] ?? '') === 'Booking.com' && !empty($cr[1]['has_source_column']));
check('crs: Commission column read', ($cr[0]['commission_raw'] ?? null) === '0');
check('crs: Booking.com row files as OTA', bookings_classify_source($cr[1]['source_raw'], $cr[1]['agent'], true)['source'] === 'ota');
check('crs: named agent files as agent', bookings_classify_source($cr[0]['source_raw'], $cr[0]['agent'], true)['source'] === 'agent');
check('crs: walk-in files as direct', bookings_classify_source($cr[2]['source_raw'], $cr[2]['agent'], true)['source'] === 'direct');
check('crs: released row resolves as skipped',
      import_resolve_row($cr[2], $zuriV)['status'] === 'skipped');

$bin = sys_get_temp_dir() . '/ts_crs_bin_' . getmypid() . '.xls';
file_put_contents($bin, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 64));
try { import_read_file($bin, 'xls'); $threw = false; } catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), 'save it as .xlsx'); }
@unlink($bin);
check('binary .xls refused with the fix', $threw);

// ── Resolution + commit (rolled-back transaction) ──
$zuri = (int) db_query("SELECT id FROM venues WHERE slug='zuri'")->fetchColumn();
if (!$zuri) { echo "\nSKIP  no Zuri venue seeded\n"; echo ($failures?"{$failures} FAILURE(S)\n":"ALL PASS\n"); exit($failures?1:0); }

$uaUnit    = (int) db_query("SELECT u.id FROM units u JOIN rooms r ON r.id=u.room_id WHERE r.slug='zuri-ua'")->fetchColumn();
$majiUnit  = (int) db_query("SELECT u.id FROM units u JOIN rooms r ON r.id=u.room_id WHERE r.slug='zuri-maji'")->fetchColumn();
$mweziUnit = (int) db_query("SELECT u.id FROM units u JOIN rooms r ON r.id=u.room_id WHERE r.slug='zuri-mwezi'")->fetchColumn();
$juaUnit   = (int) db_query("SELECT u.id FROM units u JOIN rooms r ON r.id=u.room_id WHERE r.slug='zuri-jua'")->fetchColumn();

db()->beginTransaction();
try {
    // ── Editable room map (setting override; rolled back with the tx) ──
    // Remap Twin → Ua, add a brand-new Ezee name, and drop a default row.
    import_room_map_save($zuriV, [
        'Tropical Pool View Twin Suite' => 'zuri-ua',   // changed from mwezi
        'Sunset Villa'                  => 'zuri-anga',  // new name
        'Standard Garden View Suite'    => 'zuri-maji',  // keep
    ]);
    check('override: twin now → ua',   import_map_room_slug('Tropical Pool View Twin Suite', $zuriV) === 'zuri-ua');
    check('override: new name maps',   import_map_room_slug('Sunset Villa', $zuriV) === 'zuri-anga');
    check('override: dropped row unmaps', import_map_room_slug('Master Double Suite', $zuriV) === null);
    import_room_map_save($zuriV, ['Only Valid' => 'zuri-ua', 'Blank Slug' => '']);
    check('save drops blank-slug rows',
        import_map_room_slug('Only Valid', $zuriV) === 'zuri-ua'
        && !array_key_exists('blank slug', import_room_map($zuriV)));

    // Saving one property's map must not disturb another's.
    if ($otherV) {
        import_room_map_save($otherV, ['Shared Name' => 'zuri-maji']);
        check('per-property maps are independent',
            import_map_room_slug('Only Valid', $zuriV) === 'zuri-ua'
            && import_map_room_slug('Only Valid', $otherV) === null);
    }

    // Restore the seed for the remaining resolve/commit assertions.
    set_setting('ezee_room_maps', '');
    set_setting('zuri_room_map', '');
    unset($GLOBALS['__ezee_map_cache']);
    check('cleared setting → back to seed', import_map_room_slug('Tropical Pool View Twin Suite', $zuriV) === 'zuri-mwezi');

    // A map row pointing at another property's room must BLOCK, never import —
    // otherwise a booking silently lands in the wrong property's calendar.
    if ($otherV) {
        import_room_map_save($otherV, ['Cross Property' => 'zuri-maji']);
        $x = import_resolve_row([
            'guest' => 'X', 'arrival_raw' => '01/11/2030', 'dept_raw' => '03/11/2030',
            'room_raw' => 'Cross Property', 'unit_label' => '', 'agent' => '-', 'booking_date' => '',
        ], $otherV);
        check('cross-property mapping is blocked', $x['status'] === 'unmapped');
        check('cross-property reason names the property',
            str_contains((string)$x['detail'], 'different property'));
        set_setting('ezee_room_maps', '');
        unset($GLOBALS['__ezee_map_cache']);
    }

    // Pre-existing 'blocked' block → makes a duplicate for zuri-maji 20-22 Oct.
    db_query("INSERT INTO availability_blocks (unit_id,date_from,date_to,block_type,notes)
              VALUES (:u,'2030-10-20','2030-10-22','blocked','prior import')", [':u'=>$majiUnit]);
    // Pre-existing pending hold on zuri-mwezi 20-22 Oct → makes a conflict.
    create_hold_with_block($mweziUnit, null, '2030-10-20', '2030-10-22', 'Existing Guest', 'e@x.com', 'pending', 24);

    $mk = fn($room,$ci,$co,$guest='G',$agent='-',$unit='') => [
        'guest'=>$guest,'arrival_raw'=>$ci,'dept_raw'=>$co,'room_raw'=>$room,
        'unit_label'=>$unit,'agent'=>$agent,'booking_date'=>'',
    ];
    $rows = [
        $mk('Tropical Garden View Suite','01/10/2030','03/10/2030','Clean Row'),      // ok  → zuri-ua
        $mk('Standard Garden View Suite','20/10/2030','22/10/2030','Dup Row'),        // duplicate
        $mk('Tropical Pool View Double Suite','20/10/2030','22/10/2030','Clash Row'), // conflict (mwezi hold)
        $mk('Unknown Fancy Room','01/10/2030','03/10/2030','Bad Room'),               // unmapped
        $mk('Family Garden View Suite','bogus','also bad','Bad Dates'),               // bad_dates
    ];
    $resolved = import_resolve_all($rows, $zuriV);
    $st = array_column($resolved, 'status');
    check('resolve: clean row ok',        $st[0] === 'ok');
    check('resolve: duplicate detected',  $st[1] === 'duplicate');
    check('resolve: conflict detected',   $st[2] === 'conflict');
    check('resolve: unmapped detected',   $st[3] === 'unmapped');
    check('resolve: bad dates detected',  $st[4] === 'bad_dates');

    $resolved[0]['amount'] = 750.0;   // simulate a staff-typed amount in the preview
    $report = import_commit($resolved);
    check('commit counts',
        $report['imported']===1 && $report['duplicate']===1 && $report['conflict']===1
        && $report['unmapped']===1 && $report['bad_dates']===1);
    check('commit wrote the ok block', import_block_exists($uaUnit, '2030-10-01', '2030-10-03'));

    // Financial ledger: the imported block gets a bookings row carrying the amount
    // (only when the migration is applied — otherwise this path is a safe no-op).
    if (bookings_supported()) {
        $b = db_query(
            "SELECT b.* FROM bookings b JOIN availability_blocks a ON a.id=b.block_id
             WHERE a.unit_id=:u AND a.date_from='2030-10-01'", [':u'=>$uaUnit]
        )->fetch();
        check('import wrote a bookings ledger row', (bool)$b);
        check('ledger captured the typed amount', $b && (float)$b['gross_amount'] === 750.0);
        check('ledger source is ota (no agent)', $b && $b['source'] === 'ota');
        check('ledger nights computed', $b && (int)$b['nights'] === 2);
    } else {
        echo "SKIP  bookings ledger (add_bookings_finance not applied)\n";
    }
    check('commit recorded a channel_conflict',
        (int)db_query("SELECT COUNT(*) FROM channel_conflicts WHERE unit_id=:u AND date_from='2030-10-20'",[':u'=>$mweziUnit])->fetchColumn() === 1);

    // Idempotent re-commit of the same rows adds nothing new — the previously-ok
    // row is now itself a duplicate, so both mapped-valid rows read as duplicates.
    $report2 = import_commit($resolved);
    check('re-commit imports nothing new', $report2['imported']===0 && $report2['duplicate']===2 && $report2['conflict']===1);

    // Buyout mutual-exclusion: a suite hold makes a Buyout row conflict.
    create_hold_with_block($juaUnit, null, '2030-12-01', '2030-12-03', 'Suite Guest', 's@x.com', 'pending', 24);
    $buyout = import_resolve_row($mk('Entire Retreat Buyout','01/12/2030','03/12/2030','Whole Place'), $zuriV);
    check('buyout conflicts with a booked suite', $buyout['status'] === 'conflict');

    // Two rows of one file on the same unit + dates (Double and Twin both map to
    // zuri-mwezi): the second is flagged in the preview, not silently dropped.
    $pair = import_resolve_all([
        $mk('Tropical Pool View Double Suite','10/02/2031','14/02/2031','A'),
        $mk('Tropical Pool View Twin Suite','10/02/2031','14/02/2031','B'),
    ], $zuriV);
    check('in-file same room+dates → second is duplicate',
        $pair[0]['status'] === 'ok' && $pair[1]['status'] === 'duplicate'
        && str_contains($pair[1]['detail'], 'earlier in this file'));
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
