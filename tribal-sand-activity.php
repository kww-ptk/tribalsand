<?php
// The activities listing now lives at /activities (DB-driven, managed in admin → Tours).
header('Location: /activities.php', true, 301);
exit;
