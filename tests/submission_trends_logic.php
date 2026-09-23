<?php
declare(strict_types=1);
// Submission trends — Source + Children dimensions. Run: php tests/submission_trends_logic.php
// Pure bucketing/classification always; the DB round-trip runs inside a rolled-back
// transaction when a database is reachable (else SKIP).
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/submission-trends.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Children buckets ─────────────────────────────────────────────
check('0 children → No children',   subtrends_children_bucket(0) === 'No children');
check('negative → No children',     subtrends_children_bucket(-1) === 'No children');
check('1 → 1 child',                subtrends_children_bucket(1) === '1 child');
check('2 → 2 children',             subtrends_children_bucket(2) === '2 children');
check('5 → 3+ children',            subtrends_children_bucket(5) === '3+ children');
foreach ([0, 1, 2, 3, 9] as $n) {
    check("bucket for {$n} is in the display order", in_array(subtrends_children_bucket($n), subtrends_children_order(), true));
}

// ── Source classification ────────────────────────────────────────
$own = ['tribalsand.com', 'localhost'];
check('agent link wins over everything', subtrends_source_label('google', 'cpc', 'https://google.com', true, $own) === 'Travel agent portal');
check('utm trade-portal → agent portal', subtrends_source_label('trade-portal', '', '', false, $own) === 'Travel agent portal');
check('google + cpc → Google Ads',       subtrends_source_label('google', 'cpc', '', false, $own) === 'Google Ads');
check('google organic utm → Google',     subtrends_source_label('Google', 'organic', '', false, $own) === 'Google');
check('facebook paid → FB/IG ads',       subtrends_source_label('facebook', 'paid_social', '', false, $own) === 'Facebook / Instagram ads');
check('instagram → Instagram',           subtrends_source_label('ig', '', '', false, $own) === 'Instagram');
check('newsletter → Email campaign',     subtrends_source_label('newsletter', 'email', '', false, $own) === 'Email campaign');
check('unknown utm → Campaign: x',       subtrends_source_label('kenyabuzz', '', '', false, $own) === 'Campaign: kenyabuzz');
check('utm beats referrer',              subtrends_source_label('facebook', '', 'https://www.google.com/', false, $own) === 'Facebook');
check('google referrer → Google',        subtrends_source_label('', '', 'https://www.google.co.ke/search?q=x', false, $own) === 'Google');
check('bing referrer → Bing',            subtrends_source_label('', '', 'https://www.bing.com/', false, $own) === 'Bing');
check('l.facebook.com → Facebook',       subtrends_source_label('', '', 'https://l.facebook.com/l.php', false, $own) === 'Facebook');
check('tripadvisor referrer',            subtrends_source_label('', '', 'https://www.tripadvisor.com/Hotel', false, $own) === 'TripAdvisor');
check('our own site → Direct',           subtrends_source_label('', '', 'https://tribalsand.com/zuri', false, $own) === 'Direct / unknown');
check('our own subdomain → Direct',      subtrends_source_label('', '', 'https://www.tribalsand.com/', false, $own) === 'Direct / unknown');
check('empty everything → Direct',       subtrends_source_label('', '', '', false, $own) === 'Direct / unknown');
check('garbage referrer → Direct',       subtrends_source_label('', '', 'not a url', false, $own) === 'Direct / unknown');
check('other site → Website: host',      subtrends_source_label('', '', 'https://www.kenyatravel.org/list', false, $own) === 'Website: kenyatravel.org');
check('lookalike domain is NOT ours',    subtrends_source_label('', '', 'https://nottribalsand.com/', false, $own) === 'Website: nottribalsand.com');

// ── DB round-trip (rolled back) ──────────────────────────────────
$dbOk = false;
try { db(); $dbOk = true; } catch (Throwable $e) { echo "SKIP  DB unreachable — pure checks only\n"; }

if ($dbOk) {
    db()->beginTransaction();
    try {
        $mk = function (int $children, string $utm, string $ref) {
            db_query(
                "INSERT INTO submissions (type, guest_name, guest_email, guests_adults, guests_children, utm_source, referrer, created_at)
                 VALUES ('contact', 'ZZ Trend', 'zz-trend@example.com', 2, :c, :u, :r, NOW() + INTERVAL '400 days')",
                [':c' => $children, ':u' => $utm, ':r' => $ref]
            );
        };
        $mk(0, 'google', ''); $mk(2, 'google', ''); $mk(1, '', 'https://www.bing.com/'); $mk(0, '', '');
        $day = date('Y-m-d', strtotime('+400 days'));
        $t = submission_trends('', [], $day, $day, 'contact');

        check('DB: total = 4',                 $t['total'] === 4);
        check('DB: with_children = 2',         $t['with_children'] === 2);
        $bc = array_column($t['by_children'], 'count', 'label');
        check('DB: 2 with no children',        ($bc['No children'] ?? 0) === 2);
        check('DB: 1 with 1 child',            ($bc['1 child'] ?? 0) === 1);
        check('DB: 1 with 2 children',         ($bc['2 children'] ?? 0) === 1);
        check('DB: empty buckets are hidden',  !isset($bc['3+ children']));
        $bs = array_column($t['by_source'], 'count', 'label');
        check('DB: Google = 2 (top)',          ($bs['Google'] ?? 0) === 2 && $t['by_source'][0]['label'] === 'Google');
        check('DB: Bing = 1',                  ($bs['Bing'] ?? 0) === 1);
        check('DB: Direct = 1',                ($bs['Direct / unknown'] ?? 0) === 1);
    } finally {
        db()->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
