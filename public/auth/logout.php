<?php
require_once __DIR__ . '/../../app/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !valid_csrf($_POST['csrf_token'] ?? null)) {
    error_log('TSSA security: invalid logout request');
    http_response_code(403);
    exit('Solicitud no autorizada.');
}
// ASVS 7.4.1: invalidar datos y cookie en el servidor al cerrar sesión.
destroy_session();
header('Location: /auth/login.php');
exit;
