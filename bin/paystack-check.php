#!/usr/bin/env php
<?php
/**
 * Payment links — ask Paystack about every link a guest has opened and not yet
 * paid, record the ones that are paid, and expire links past their time. There is
 * no Paystack webhook (the company's other system owns it), so this loop is what
 * catches a guest who paid and closed the page.
 *
 * Run every 5 minutes via the in-container scheduler (docker/scheduler.sh, Job 12):
 *   php bin/paystack-check.php
 * Idempotent: pay_link_mark_paid() records a link once under a row lock, so several
 * ECS tasks running this is harmless. Quiet no-op until add_paystack.sql has run.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/acct.php';
require_once __DIR__ . '/../includes/pay-links.php';

$stamp = '[' . date('Y-m-d H:i:s') . '] ';
if (!paystack_supported()) { echo $stamp . "Paystack not enabled (add_paystack.sql not run) — nothing to check.\n"; exit(0); }
[$checked, $paid, $expired] = pay_link_check_due();
if ($checked || $paid || $expired) echo $stamp . "Payment links: {$checked} checked, {$paid} paid, {$expired} expired.\n";
