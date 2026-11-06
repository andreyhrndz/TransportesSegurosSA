<?php
declare(strict_types=1);

/**
 * Prueba funcional HTTP del módulo de Usuarios y Permisos Individuales:
 * alta, login real con bcrypt, edición, restablecimiento de contraseña,
 * activación/desactivación, concesión/revocación individual, CSRF, permisos
 * insuficientes, autoprotección, último gestor activo y ausencia de secretos en HTML/logs.
 * Opera exclusivamente sobre registros efímeros en TransportesSegurosSA_Test.
 */
putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';

function uh_fail(string $message): never
{
    fwrite(STDERR, "FALLO: {$message}\n");
    exit(1);
}

function uh_assert(bool $condition, string $message): void
{
    if (!$condition) {
        uh_fail($message);
    }
}

function uh_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        uh_fail('No se pudo reservar puerto local.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

/** @return array{0:int,1:string,2:array<int,string>} */
function uh_request(string $url, string $cookie = '', bool $htmx = true, ?array $postFields = null): array
{
    $headers = "Accept: text/html\r\n";
    if ($cookie !== '') {
        $headers .= "Cookie: {$cookie}\r\n";
    }
    if ($htmx) {
        $headers .= "HX-Request: true\r\n";
    }
    $options = [
        'header' => $headers,
        'ignore_errors' => true,
        'timeout' => 8,
        'follow_location' => 0,
    ];
    if ($postFields !== null) {
        $options['method'] = 'POST';
        $options['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $options['content'] = http_build_query($postFields);
    }
    $context = stream_context_create(['http' => $options]);
    $body = file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = (int)(preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match) ? $match[1] : 0);
    return [$status, $body === false ? '' : $body, $responseHeaders];
}

/** @return array{0:int,1:string} */
function uh_attempt_login(string $baseUrl, string $username, string $password): array
{
    [$getStatus, $getBody, $getHeaders] = uh_request($baseUrl . '/auth/login.php', '', false);
    uh_assert($getStatus === 200, 'GET /auth/login.php debe responder 200.');

    $cookiePair = '';
    foreach ($getHeaders as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $m)) {
            $cookiePair = trim($m[1]);
            break;
        }
    }
    uh_assert($cookiePair !== '', 'Login debe emitir cookie de sesión.');
    uh_assert(preg_match('/name="csrf_token"\s+value="([a-f0-9]{64})"/i', $getBody, $csrfMatch) === 1, 'Login debe incluir token CSRF.');

    [$postStatus, $postBody] = uh_request($baseUrl . '/auth/login.php', $cookiePair, false, [
        'csrf_token' => $csrfMatch[1],
        'usuario' => $username,
        'password' => $password,
    ]);
    return [$postStatus, $postBody];
}

if (configured_database_name() !== 'TransportesSegurosSA_Test') {
    uh_fail('Se requiere TSSA_DB_DATABASE=TransportesSegurosSA_Test.');
}
$pdo = db();
require_test_database($pdo);

$suffix = bin2hex(random_bytes(4));
$targetUsername = 'http.usr.' . $suffix;
$editedUsername = 'http.edit.' . $suffix;
$viewerUsername = 'http.view.' . $suffix;
$peerManagerUsername = 'http.peer.' . $suffix;
$initialSecret = 'ClaveInicialSegura2026!' . $suffix;
$resetSecret = 'NuevaClaveSegura2026#' . $suffix;

$ephemeralUserIds = [];
$targetUserId = 0;

$sessionName = 'TSSA_TRANSPORTES_SESSION';
$adminSessionId = bin2hex(random_bytes(24));
$adminCsrf = bin2hex(random_bytes(32));
$viewerSessionId = bin2hex(random_bytes(24));
$viewerCsrf = bin2hex(random_bytes(32));
$peerSessionId = bin2hex(random_bytes(24));
$peerCsrf = bin2hex(random_bytes(32));

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Limpiar restos huérfanos de pruebas previas en Test
$pdo->exec("DELETE FROM dbo.usuarios_permisos WHERE usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario LIKE 'http.%')");
$pdo->exec("DELETE FROM dbo.auditorias WHERE usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario LIKE 'http.%')");
$pdo->exec("DELETE FROM dbo.usuarios WHERE nombre_usuario LIKE 'http.%'");

// Crear usuario lector (solo USUARIOS_VER) y gestor par (solo USUARIOS_VER + USUARIOS_GESTIONAR_PERMISOS)
$insUser = $pdo->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) OUTPUT INSERTED.usuario_id VALUES (?, ?, ?, 1)');

$insUser->execute([$viewerUsername, $viewerUsername . '@example.test', password_hash('VisorTemporal2026!' . $suffix, PASSWORD_BCRYPT)]);
$viewerUserId = (int)$insUser->fetchColumn();
$insUser->closeCursor();
$ephemeralUserIds[] = $viewerUserId;

$insUser->execute([$peerManagerUsername, $peerManagerUsername . '@example.test', password_hash('GestorTemporal2026!' . $suffix, PASSWORD_BCRYPT)]);
$peerUserId = (int)$insUser->fetchColumn();
$insUser->closeCursor();
$ephemeralUserIds[] = $peerUserId;

$grantSql = $pdo->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo = ?');
$grantSql->execute([$viewerUserId, 'USUARIOS_VER']);
$grantSql->closeCursor();
$grantSql->execute([$peerUserId, 'USUARIOS_VER']);
$grantSql->closeCursor();
$grantSql->execute([$peerUserId, 'USUARIOS_GESTIONAR_PERMISOS']);
$grantSql->closeCursor();

ini_set('session.use_strict_mode', '0');
foreach ([
    [$adminSessionId, 1, $adminCsrf],
    [$viewerSessionId, $viewerUserId, $viewerCsrf],
    [$peerSessionId, $peerUserId, $peerCsrf],
] as [$sid, $uid, $csrf]) {
    session_name($sessionName);
    session_id($sid);
    session_start();
    $_SESSION = [
        'usuario_id' => $uid,
        'authenticated_at' => time(),
        'last_activity_at' => time(),
        'csrf_token' => $csrf,
    ];
    session_write_close();
}
ini_set('session.use_strict_mode', '1');

$port = uh_port();
$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'tssa-users-http-');
$env = array_merge(getenv(), ['TSSA_DB_DATABASE' => 'TransportesSegurosSA_Test']);
$process = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . DIRECTORY_SEPARATOR . 'public'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    $root,
    $env
);
if (!is_resource($process)) {
    @unlink($log);
    uh_fail('No se pudo iniciar servidor HTTP local.');
}

$collectedHtml = '';

try {
    for ($i = 0; $i < 25; $i++) {
        $sock = @fsockopen('127.0.0.1', $port);
        if ($sock !== false) {
            fclose($sock);
            break;
        }
        usleep(100000);
    }

    $base = "http://127.0.0.1:{$port}";
    $adminCookie = "{$sessionName}={$adminSessionId}";
    $viewerCookie = "{$sessionName}={$viewerSessionId}";
    $peerCookie = "{$sessionName}={$peerSessionId}";

    // 1. Alta válida de usuario y login real con password_verify()
    [$status, $body] = uh_request($base . '/usuarios/form.php', $adminCookie, true);
    uh_assert($status === 200 && str_contains($body, 'Nuevo usuario'), 'Modal de nuevo usuario debe responder 200.');
    $collectedHtml .= $body;

    [$status, $body, $headers] = uh_request($base . '/usuarios/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'nombre_usuario' => $targetUsername,
        'correo_acceso' => "{$targetUsername}@example.test",
        'password' => $initialSecret,
        'password_confirmacion' => $initialSecret,
    ]);
    uh_assert($status === 200 && str_contains($body, 'Usuario creado correctamente'), 'Alta válida de usuario debe responder 200.');
    $collectedHtml .= $body;
    $triggerHeader = implode("\n", array_filter($headers, static fn (string $h): bool => str_starts_with(strtolower($h), 'hx-trigger-after-settle:')));
    uh_assert(str_contains($triggerHeader, 'usuarioActualizado'), 'Alta debe disparar evento HTMX usuarioActualizado.');

    $findStmt = $pdo->prepare('SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = ?');
    $findStmt->execute([$targetUsername]);
    $targetUserId = (int)$findStmt->fetchColumn();
    $findStmt->closeCursor();
    uh_assert($targetUserId > 0, 'El usuario creado debe existir en Test.');
    $ephemeralUserIds[] = $targetUserId;

    [$loginStatus] = uh_attempt_login($base, $targetUsername, $initialSecret);
    uh_assert($loginStatus === 302, 'El usuario recién creado debe poder autenticarse en /auth/login.php.');

    // 2. Duplicado y validaciones de entrada (contraseña corta, confirmación distinta, contiene usuario)
    [$status] = uh_request($base . '/usuarios/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'nombre_usuario' => $targetUsername,
        'correo_acceso' => "otro.{$suffix}@example.test",
        'password' => $initialSecret,
        'password_confirmacion' => $initialSecret,
    ]);
    uh_assert($status === 409, 'Usuario duplicado debe responder 409.');

    [$status] = uh_request($base . '/usuarios/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'nombre_usuario' => 'corto.' . $suffix,
        'correo_acceso' => "corto.{$suffix}@example.test",
        'password' => 'Corta123!',
        'password_confirmacion' => 'Corta123!',
    ]);
    uh_assert($status === 422, 'Contraseña menor de 12 caracteres debe responder 422.');

    [$status] = uh_request($base . '/usuarios/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'nombre_usuario' => 'mismatch.' . $suffix,
        'correo_acceso' => "mismatch.{$suffix}@example.test",
        'password' => 'ClaveValidaUno2026!',
        'password_confirmacion' => 'ClaveValidaDos2026!',
    ]);
    uh_assert($status === 422, 'Confirmación no coincidente debe responder 422.');

    [$status] = uh_request($base . '/usuarios/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'nombre_usuario' => 'contexto.' . $suffix,
        'correo_acceso' => "contexto.{$suffix}@example.test",
        'password' => 'Clave_' . 'contexto.' . $suffix . '_2026!',
        'password_confirmacion' => 'Clave_' . 'contexto.' . $suffix . '_2026!',
    ]);
    uh_assert($status === 422, 'Contraseña que contiene el nombre de usuario debe responder 422.');

    // 3. Edición de cuenta
    [$status, $body] = uh_request($base . '/usuarios/form.php?id=' . $targetUserId, $adminCookie, true);
    uh_assert($status === 200 && str_contains($body, $targetUsername), 'Formulario de edición debe cargar datos actuales.');
    $collectedHtml .= $body;

    [$status, $body] = uh_request($base . '/usuarios/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $targetUserId,
        'nombre_usuario' => $editedUsername,
        'correo_acceso' => "{$editedUsername}@example.test",
    ]);
    uh_assert($status === 200 && str_contains($body, 'actualizada correctamente'), 'Edición válida debe responder 200.');
    $collectedHtml .= $body;

    // 4. Restablecimiento de contraseña y comprobación de login
    [$status, $body] = uh_request($base . '/usuarios/password_form.php?id=' . $targetUserId, $adminCookie, true);
    uh_assert($status === 200 && str_contains($body, 'Restablecer contraseña'), 'Modal de restablecimiento debe responder 200.');
    $collectedHtml .= $body;

    [$status, $body] = uh_request($base . '/usuarios/password.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $targetUserId,
        'password' => $resetSecret,
        'password_confirmacion' => $resetSecret,
    ]);
    uh_assert($status === 200 && str_contains($body, 'restablecida correctamente'), 'Restablecimiento válido debe responder 200.');
    $collectedHtml .= $body;

    [$oldLoginStatus] = uh_attempt_login($base, $editedUsername, $initialSecret);
    uh_assert($oldLoginStatus === 200, 'La contraseña anterior ya no debe autenticar.');

    [$newLoginStatus] = uh_attempt_login($base, $editedUsername, $resetSecret);
    uh_assert($newLoginStatus === 302, 'La contraseña restablecida debe autenticar.');

    // 5. Concesión y revocación de permisos individuales
    [$status, $body] = uh_request($base . "/usuarios/permiso_form.php?id={$targetUserId}&codigo=CLIENTES_VER&accion=conceder", $adminCookie, true);
    uh_assert($status === 200 && str_contains($body, 'Asignar'), 'Modal de asignación de permiso debe responder 200.');
    $collectedHtml .= $body;

    [$status, $body, $headers] = uh_request($base . '/usuarios/permiso.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $targetUserId,
        'codigo' => 'CLIENTES_VER',
        'accion' => 'conceder',
    ]);
    uh_assert($status === 200 && str_contains($body, 'asignado correctamente'), 'Concesión individual debe responder 200.');
    $collectedHtml .= $body;
    $permTrigger = implode("\n", array_filter($headers, static fn (string $h): bool => str_starts_with(strtolower($h), 'hx-trigger-after-settle:')));
    uh_assert(str_contains($permTrigger, 'permisoActualizado'), 'Debe emitir trigger permisoActualizado.');

    // Re-asignar permiso ya asignado -> 409
    [$status] = uh_request($base . '/usuarios/permiso.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $targetUserId,
        'codigo' => 'CLIENTES_VER',
        'accion' => 'conceder',
    ]);
    uh_assert($status === 409, 'Conceder permiso ya asignado debe responder 409.');

    // Revocar permiso
    [$status, $body] = uh_request($base . '/usuarios/permiso.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $targetUserId,
        'codigo' => 'CLIENTES_VER',
        'accion' => 'revocar',
    ]);
    uh_assert($status === 200 && str_contains($body, 'revocado correctamente'), 'Revocación individual debe responder 200.');
    $collectedHtml .= $body;

    // 6. Desactivación y reactivación de cuenta
    [$status, $body] = uh_request($base . "/usuarios/estado_form.php?id={$targetUserId}&accion=desactivar", $adminCookie, true);
    uh_assert($status === 200 && str_contains($body, 'Desactivar usuario'), 'Modal de desactivación debe responder 200.');
    $collectedHtml .= $body;

    [$status, $body] = uh_request($base . '/usuarios/estado.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $targetUserId,
        'accion' => 'desactivar',
    ]);
    uh_assert($status === 200 && str_contains($body, 'desactivada correctamente'), 'Desactivación debe responder 200.');
    $collectedHtml .= $body;

    [$inactiveLoginStatus] = uh_attempt_login($base, $editedUsername, $resetSecret);
    uh_assert($inactiveLoginStatus === 200, 'Usuario desactivado no debe poder iniciar sesión.');

    [$status, $body] = uh_request($base . '/usuarios/estado.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $targetUserId,
        'accion' => 'activar',
    ]);
    uh_assert($status === 200 && str_contains($body, 'activada correctamente'), 'Reactivación debe responder 200.');
    $collectedHtml .= $body;

    // 7. Autoprotección y protección del último gestor activo
    [$status] = uh_request($base . '/usuarios/estado_form.php?id=1&accion=desactivar', $adminCookie, true);
    uh_assert($status === 409, 'GET estado_form de auto-desactivación debe responder 409.');

    [$status] = uh_request($base . '/usuarios/estado.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => 1,
        'accion' => 'desactivar',
    ]);
    uh_assert($status === 409, 'POST auto-desactivación debe responder 409.');

    [$status] = uh_request($base . '/usuarios/permiso_form.php?id=1&codigo=USUARIOS_GESTIONAR_PERMISOS&accion=revocar', $adminCookie, true);
    uh_assert($status === 409, 'GET auto-revocación de permiso crítico debe responder 409.');

    [$status] = uh_request($base . '/usuarios/permiso.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => 1,
        'codigo' => 'USUARIOS_GESTIONAR_PERMISOS',
        'accion' => 'revocar',
    ]);
    uh_assert($status === 409, 'POST auto-revocación de permiso crítico debe responder 409.');

    // peerUserId tiene USUARIOS_GESTIONAR_PERMISOS, pero admin (id=1) es el único titular activo de USUARIOS_GESTIONAR_CUENTAS
    $holdersStmt = $pdo->query("SELECT COUNT(*) FROM dbo.usuarios_permisos up JOIN dbo.usuarios u ON u.usuario_id = up.usuario_id JOIN dbo.permisos p ON p.permiso_id = up.permiso_id WHERE p.codigo = N'USUARIOS_GESTIONAR_CUENTAS' AND u.activo = 1");
    $holdersCount = (int)$holdersStmt->fetchColumn();
    if ($holdersCount === 1) {
        [$status, $body] = uh_request($base . '/usuarios/permiso.php', $peerCookie, true, [
            'csrf_token' => $peerCsrf,
            'usuario_id' => 1,
            'codigo' => 'USUARIOS_GESTIONAR_CUENTAS',
            'accion' => 'revocar',
        ]);
        uh_assert($status === 409 && str_contains($body, 'último titular activo'), 'Revocar el último titular activo de permiso crítico debe responder 409.');
    }

    // 8. Permisos insuficientes, CSRF inválido y registro inexistente
    [$status, $viewerDetail] = uh_request($base . '/usuarios/detalle.php?id=' . $targetUserId, $viewerCookie, false);
    uh_assert($status === 200, 'Usuario con USUARIOS_VER puede consultar detalle.');
    uh_assert(
        !str_contains($viewerDetail, '/usuarios/form.php')
        && !str_contains($viewerDetail, '/usuarios/password_form.php')
        && !str_contains($viewerDetail, '/usuarios/permiso_form.php'),
        'Usuario sin permisos de gestión no debe ver acciones administrativas.'
    );
    $collectedHtml .= $viewerDetail;

    [$status] = uh_request($base . '/usuarios/guardar.php', $viewerCookie, true, [
        'csrf_token' => $viewerCsrf,
        'usuario_id' => $targetUserId,
        'nombre_usuario' => 'sinperm.' . $suffix,
        'correo_acceso' => "sinperm.{$suffix}@example.test",
    ]);
    uh_assert($status === 403, 'POST guardar sin permiso debe responder 403.');

    [$status] = uh_request($base . '/usuarios/password.php', $viewerCookie, true, [
        'csrf_token' => $viewerCsrf,
        'usuario_id' => $targetUserId,
        'password' => 'IntentoSinPermiso2026!',
        'password_confirmacion' => 'IntentoSinPermiso2026!',
    ]);
    uh_assert($status === 403, 'POST password sin permiso debe responder 403.');

    [$status] = uh_request($base . '/usuarios/permiso.php', $viewerCookie, true, [
        'csrf_token' => $viewerCsrf,
        'usuario_id' => $targetUserId,
        'codigo' => 'CLIENTES_VER',
        'accion' => 'conceder',
    ]);
    uh_assert($status === 403, 'POST permiso sin permiso debe responder 403.');

    [$status] = uh_request($base . '/usuarios/guardar.php', $adminCookie, true, [
        'csrf_token' => 'token-invalido',
        'usuario_id' => $targetUserId,
        'nombre_usuario' => $editedUsername,
        'correo_acceso' => "{$editedUsername}@example.test",
    ]);
    uh_assert($status === 403, 'CSRF inválido debe responder 403.');

    [$status] = uh_request($base . '/usuarios/estado.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => 99999999,
        'accion' => 'desactivar',
    ]);
    uh_assert($status === 404, 'Cambiar estado de usuario inexistente debe responder 404.');

    // Consultar listado y detalle completo como admin para auditar ausencia de hashes
    [$status, $listHtml] = uh_request($base . '/usuarios/lista.php?buscar=' . urlencode($editedUsername), $adminCookie, true);
    uh_assert($status === 200 && str_contains($listHtml, $editedUsername), 'Listado filtrado debe incluir usuario editado.');
    $collectedHtml .= $listHtml;

    [$status, $adminDetailHtml] = uh_request($base . '/usuarios/detalle.php?id=' . $targetUserId, $adminCookie, false);
    uh_assert($status === 200, 'Detalle de usuario para admin debe responder 200.');
    $collectedHtml .= $adminDetailHtml;

    // 9. Verificar ausencia total de hashes bcrypt y contraseñas en HTML, logs y auditorías
    $serverLogContent = is_file($log) ? (string)file_get_contents($log) : '';
    foreach ([$collectedHtml, $serverLogContent] as $surface) {
        uh_assert(!preg_match('/\$2[abxy]\$\d{2}\$/', $surface), 'Ningún hash bcrypt puede aparecer en HTML o logs.');
        uh_assert(!str_contains($surface, $initialSecret) && !str_contains($surface, $resetSecret), 'Ninguna contraseña puede aparecer en HTML o logs.');
    }

    $auditStmt = $pdo->prepare("SELECT valores_anteriores, valores_nuevos FROM dbo.auditorias WHERE entidad IN (N'usuarios', N'usuarios_permisos') AND registro_id LIKE ?");
    $auditStmt->execute([(string)$targetUserId . '%']);
    $auditJson = json_encode($auditStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    $auditStmt->closeCursor();
    uh_assert(!preg_match('/\$2[abxy]\$\d{2}\$/', (string)$auditJson), 'dbo.auditorias no debe almacenar hashes bcrypt.');
    uh_assert(!str_contains((string)$auditJson, $initialSecret) && !str_contains((string)$auditJson, $resetSecret), 'dbo.auditorias no debe almacenar contraseñas.');

    fwrite(STDOUT, "OK: pruebas funcionales HTTP de Usuarios y Permisos completadas en TransportesSegurosSA_Test.\n");
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($process);
    proc_close($process);
    @unlink($log);

    foreach ([$adminSessionId, $viewerSessionId, $peerSessionId] as $sid) {
        session_name($sessionName);
        session_id($sid);
        session_start();
        $_SESSION = [];
        session_destroy();
    }

    // Limpiar únicamente los usuarios y auditorías efímeros creados en esta prueba en Test
    require_test_database($pdo);
    foreach ($ephemeralUserIds as $uid) {
        if ($uid > 1) {
            $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'usuarios' AND registro_id = ?")->execute([(string)$uid]);
            $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'usuarios_permisos' AND registro_id LIKE ?")->execute([(string)$uid . ':%']);
            $pdo->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([$uid]);
            $pdo->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([$uid]);
        }
    }
}
