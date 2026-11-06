<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/mail/BrevoApiSender.php';
require_once __DIR__ . '/../app/mail/mail_service.php';

echo "=== INICIO DE PRUEBAS DE ADAPTADOR BREVO API ===\n";

function brevo_assert(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException("FALLO BREVO TEST: $msg");
    }
}

// 1. Caso configuración inválida: clave vacía o remitente inválido
$senderBad = new BrevoApiSender('', 'remitente@test.cr');
$res = $senderBad->send('destino@test.cr', 'Asunto', '<p>Hola</p>');
brevo_assert(!$res, 'Debe fallar si la API key está vacía');
brevo_assert($senderBad->getLastCategory() === 'config_invalid', 'Categoría debe ser config_invalid');

$senderBadSender = new BrevoApiSender('clave_dummy', 'remitente_no_valido');
$res2 = $senderBadSender->send('destino@test.cr', 'Asunto', '<p>Hola</p>');
brevo_assert(!$res2, 'Debe fallar si el email remitente es inválido');
brevo_assert($senderBadSender->getLastCategory() === 'config_invalid', 'Categoría debe ser config_invalid');

// 2. Levantar un servidor HTTP ephemeral local para simular las respuestas de la API de Brevo
$stubPort = 8092;
$stubScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tssa_brevo_stub_' . getmypid() . '.php';

$stubCode = <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '';

if (str_contains($uri, 'scen_201')) {
    http_response_code(201);
    header('Content-Type: application/json');
    echo json_encode(['messageId' => '<test-brevo-message-id-123@smtp-relay.mailin.fr>']);
    exit;
}

if (str_contains($uri, 'scen_400')) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'invalid_parameter', 'message' => 'Invalid email address']);
    exit;
}

if (str_contains($uri, 'scen_401')) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'unauthorized', 'message' => 'Key not authorized']);
    exit;
}

if (str_contains($uri, 'scen_403')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'permission_denied', 'message' => 'Resource forbidden']);
    exit;
}

if (str_contains($uri, 'scen_429')) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'reseller_rate_limit_exceeded', 'message' => 'Too many requests']);
    exit;
}

if (str_contains($uri, 'scen_500')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'internal_error', 'message' => 'Server error']);
    exit;
}

http_response_code(404);
PHP;

file_put_contents($stubScript, $stubCode);

$phpBinary = PHP_BINARY;
$cmd = "\"{$phpBinary}\" -S 127.0.0.1:{$stubPort} \"{$stubScript}\"";
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
brevo_assert(is_resource($proc), 'Debe iniciar proceso stub HTTP local');
usleep(250000); // 250ms para enlace de socket

try {
    $baseUrl = "http://127.0.0.1:{$stubPort}";

    // Escenario 201 Accepted
    $sender = new BrevoApiSender('test_key_valid', 'notificaciones@transportesseguros.cr', 'Transportes Seguros', $baseUrl . '/scen_201');
    $ok = $sender->send('dest@example.com', 'Prueba Brevo', '<p>Contenido</p>');
    brevo_assert($ok === true, 'Escenario 201 debe retornar true');
    brevo_assert($sender->getLastCategory() === 'accepted', 'Categoría debe ser accepted');
    brevo_assert($sender->getLastHttpCode() === 201, 'Código HTTP debe ser 201');
    brevo_assert($sender->getLastMessageId() !== null, 'Debe capturar messageId');

    // Escenario 400 Provider Reject
    $sender400 = new BrevoApiSender('test_key_valid', 'notificaciones@transportesseguros.cr', 'Transportes Seguros', $baseUrl . '/scen_400');
    $ok400 = $sender400->send('bad_email', 'Prueba', '<p>Contenido</p>');
    brevo_assert($ok400 === false, 'Escenario 400 debe retornar false');
    brevo_assert($sender400->getLastCategory() === 'provider_reject', 'Categoría debe ser provider_reject');
    brevo_assert($sender400->getLastHttpCode() === 400, 'Código HTTP debe ser 400');

    // Escenario 401 Unauthorized
    $sender401 = new BrevoApiSender('test_key_invalid', 'notificaciones@transportesseguros.cr', 'Transportes Seguros', $baseUrl . '/scen_401');
    $ok401 = $sender401->send('dest@example.com', 'Prueba', '<p>Contenido</p>');
    brevo_assert($ok401 === false, 'Escenario 401 debe retornar false');
    brevo_assert($sender401->getLastCategory() === 'provider_reject', 'Categoría 401 debe ser provider_reject');
    brevo_assert($sender401->getLastHttpCode() === 401, 'Código HTTP debe ser 401');

    // Escenario 403 Forbidden
    $sender403 = new BrevoApiSender('test_key_perm', 'notificaciones@transportesseguros.cr', 'Transportes Seguros', $baseUrl . '/scen_403');
    $ok403 = $sender403->send('dest@example.com', 'Prueba', '<p>Contenido</p>');
    brevo_assert($ok403 === false, 'Escenario 403 debe retornar false');
    brevo_assert($sender403->getLastCategory() === 'provider_reject', 'Categoría 403 debe ser provider_reject');
    brevo_assert($sender403->getLastHttpCode() === 403, 'Código HTTP debe ser 403');

    // Escenario 429 Rate Limit
    $sender429 = new BrevoApiSender('test_key_rate', 'notificaciones@transportesseguros.cr', 'Transportes Seguros', $baseUrl . '/scen_429');
    $ok429 = $sender429->send('dest@example.com', 'Prueba', '<p>Contenido</p>');
    brevo_assert($ok429 === false, 'Escenario 429 debe retornar false');
    brevo_assert($sender429->getLastCategory() === 'provider_reject', 'Categoría 429 debe ser provider_reject');
    brevo_assert($sender429->getLastHttpCode() === 429, 'Código HTTP debe ser 429');

    // Escenario 500 Server Error
    $sender500 = new BrevoApiSender('test_key_err', 'notificaciones@transportesseguros.cr', 'Transportes Seguros', $baseUrl . '/scen_500');
    $ok500 = $sender500->send('dest@example.com', 'Prueba', '<p>Contenido</p>');
    brevo_assert($ok500 === false, 'Escenario 500 debe retornar false');
    brevo_assert($sender500->getLastCategory() === 'provider_reject', 'Categoría 500 debe ser provider_reject');
    brevo_assert($sender500->getLastHttpCode() === 500, 'Código HTTP debe ser 500');

    // Escenario DNS / Host inexistente
    $senderDns = new BrevoApiSender('test_key', 'notificaciones@transportesseguros.cr', 'Transportes Seguros', 'http://dominio-inexistente-123456789.test');
    $okDns = $senderDns->send('dest@example.com', 'Prueba', '<p>Contenido</p>');
    brevo_assert($okDns === false, 'Error DNS debe retornar false');
    brevo_assert($senderDns->getLastCategory() === 'dns', 'Categoría debe ser dns');

    echo "OK: Todos los escenarios de BrevoApiSender (201, 400, 401, 403, 429, 500, DNS, config) probados exitosamente.\n";

} finally {
    if (is_resource($proc)) {
        $st = proc_get_status($proc);
        if (!empty($st['pid']) && strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            @exec("taskkill /F /T /PID " . (int)$st['pid'] . " 2>&1");
        } else {
            proc_terminate($proc);
        }
        @proc_close($proc);
    }
    if (file_exists($stubScript)) {
        @unlink($stubScript);
    }
}
