<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/agent.php';
agent_logout();
header('Location: /agent/login.php');
exit;
