<?php
declare(strict_types=1);
// DB-backed checks for portal v2 helpers. Run: php tests/portal_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

$failures = 0;
function check(string $label, bool $cond): void {
    global $failures;
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$acts = fetch_portal_activities();
check('activities is a list', is_array($acts));
check('activity rows have slug/name/category/hero keys',
      $acts === [] || (array_key_exists('slug',$acts[0]) && array_key_exists('name',$acts[0])
                       && array_key_exists('category',$acts[0]) && array_key_exists('hero',$acts[0])));

$cats = fetch_tour_categories();
check('categories is a list', is_array($cats));
check('categories have key + label',
      $cats === [] || (isset($cats[0]['key'], $cats[0]['label'])));

// addon_label() — pure, DB-free. Guards the "tour details duplicate the name" case.
check('addon_label: details==name shows name once',
      addon_label(['tour_name'=>'Tsavo East','details'=>'Tsavo East']) === 'Tsavo East');
check('addon_label: distinct details are joined',
      addon_label(['tour_name'=>'Tsavo East','details'=>'2 adults']) === 'Tsavo East — 2 adults');
check('addon_label: null tour_name falls back to details',
      addon_label(['tour_name'=>null,'details'=>'Extra towels']) === 'Extra towels');
check('addon_label: empty details with a name shows the name',
      addon_label(['tour_name'=>'Quad Safari','details'=>'']) === 'Quad Safari');
check('addon_label: both empty is an empty string',
      addon_label(['tour_name'=>null,'details'=>'']) === '');

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
