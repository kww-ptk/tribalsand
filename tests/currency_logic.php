<?php
declare(strict_types=1);
// Multi-currency display helpers (display-only). Run: php tests/currency_logic.php
// Pure logic — no DB writes, no migration required. See MULTICURRENCY-PLAN.md.
require_once __DIR__ . '/../includes/db.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── is_supported_currency ──────────────────────────────────────────────────
check('USD supported',                 is_supported_currency('USD'));
check('kes supported (case-insens.)',  is_supported_currency('kes'));
check(' EUR trims/supported',          is_supported_currency(' EUR '));
check('JPY not supported',             !is_supported_currency('JPY'));
check('empty not supported',           !is_supported_currency(''));

// ── resolve_currency (pure) ────────────────────────────────────────────────
check('param wins when valid',         resolve_currency('EUR', 'GBP') === 'EUR');
check('param lowercased',              resolve_currency('gbp', null)  === 'GBP');
check('invalid param → cookie',        resolve_currency('JPY', 'KES') === 'KES');
check('no param → cookie',             resolve_currency('', 'EUR')    === 'EUR');
check('invalid cookie → default',      resolve_currency('', 'ZZZ')    === TS_CURRENCY_DEFAULT);
check('nothing → default USD',         resolve_currency(null, null)   === 'USD');

// ── format_money ───────────────────────────────────────────────────────────
check('KES grouping',   format_money(400000, 'KES') === 'KES 400,000');
check('USD symbol',     format_money(3100,   'USD') === '$3,100');
check('EUR symbol',     format_money(2850,   'EUR') === '€2,850');
check('GBP symbol',     format_money(2450,   'GBP') === '£2,450');
check('unknown cur falls back to code prefix', format_money(100, 'JPY') === 'JPY 100');

// ── convert_price: identity + fallback (deterministic, rate-independent) ─────
$same = convert_price(1000, 'USD', 'USD');
check('same-currency identity amount', $same['amount'] === 1000.0);
check('same-currency converted=true',  $same['converted'] === true);

$badFrom = convert_price(1000, 'XYZ', 'USD');   // source rate missing
check('missing source rate → original amount',   $badFrom['amount'] === 1000.0);
check('missing source rate → original currency', $badFrom['currency'] === 'XYZ');
check('missing source rate → converted=false',   $badFrom['converted'] === false);

$badTo = convert_price(1000, 'USD', 'ZZZ');     // unsupported target → coerced to default (USD)
check('unsupported target coerced to default', $badTo['currency'] === TS_CURRENCY_DEFAULT);

// ── convert_price: real conversion using whatever fx_rates() currently returns ──
$rates = fx_rates()['rates'];
check('rate table has all 4 currencies',
      isset($rates['USD'], $rates['KES'], $rates['EUR'], $rates['GBP']));
check('all rates positive',
      $rates['USD'] > 0 && $rates['KES'] > 0 && $rates['EUR'] > 0 && $rates['GBP'] > 0);

$usdToKes = convert_price(100, 'USD', 'KES');
check('USD→KES converted=true',  $usdToKes['converted'] === true);
check('USD→KES currency is KES', $usdToKes['currency'] === 'KES');
check('USD→KES amount positive', $usdToKes['amount'] > 0);
check('KES rounded to nearest 100', fmod($usdToKes['amount'], 100.0) === 0.0);

// Round-trip: KES → USD → KES should land within one KES rounding step (100).
$kes0 = 400000.0;
$toUsd = convert_price($kes0, 'KES', 'USD');
$back  = convert_price($toUsd['amount'], 'USD', 'KES');
check('KES→USD→KES round-trips within rounding', abs($back['amount'] - $kes0) <= 100.0);

// ── money_html: converted span carries base data-* attributes ──────────────
$html = money_html(400000, 'KES');
check('money_html has ts-price class',       str_contains($html, 'class="ts-price"'));
check('money_html carries base amount',       str_contains($html, 'data-base-amount="400000"'));
check('money_html carries base currency',     str_contains($html, 'data-base-cur="KES"'));
check('money_html renders a formatted price', (bool)preg_match('/>[^<]*\d{1,3}(,\d{3})*[^<]*<\/span>/', $html));

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
