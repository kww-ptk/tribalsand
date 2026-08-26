<?php
// Lightweight liveness probe for the load balancer / ECS Express Mode health
// check. Deliberately does NOT touch the database or any external service, so
// the container is reported healthy as soon as PHP/Apache is serving — letting
// the app boot and surface real errors in the logs instead of crash-looping on
// a failed homepage render. Reachable at /health (the .htaccess strip-.php rule
// serves this file for the extensionless path).
http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';
