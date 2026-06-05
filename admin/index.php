<?php
require_once __DIR__ . '/../includes/auth.php';
session_init();

if (!empty($_SESSION['admin_id'])) {
    header('Location: /admin/dashboard.php');
} else {
    header('Location: /admin/login.php');
}
exit;
