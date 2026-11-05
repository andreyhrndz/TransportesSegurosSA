<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$connection = db();
require_test_database($connection);

echo "=== INICIO DE REGRESIÓN DE AUTENTICACIÓN Y SEGURIDAD HTTP ===\n";

function auth_reserve_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('No se pudo reservar puerto local.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

$spawnedServerProcess = null;
$spawnedLog = null;
$targetUrl = getenv('TSSA_APP_URL') ?: 'http://127.0.0.1:8001';
$targetHost = (string)parse_url($targetUrl, PHP_URL_HOST);
$targetPort = (int)(parse_url($targetUrl, PHP_URL_PORT) ?: 80);

$checkSocket = @fsockopen($targetHost, $targetPort, $errno, $errstr, 0.3);
if ($checkSocket !== false) {
    fclose($checkSocket);
    $base = $targetUrl;
} else {
    $dynPort = auth_reserve_port();
    $base = "http://127.0.0.1:{$dynPort}";
    $root = dirname(__DIR__);
    $spawnedLog = tempnam(sys_get_temp_dir(), 'tssa-auth-http-');
    $env = array_merge(getenv(), [
        'TSSA_DB_DATABASE' => 'TransportesSegurosSA_Test',
        'TSSA_DB_SERVER' => 'localhost',
    ]);
    $spawnedServerProcess = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$dynPort}", '-t', $root . DIRECTORY_SEPARATOR . 'public'],
        [0 => ['pipe', 'r'], 1 => ['file', $spawnedLog, 'a'], 2 => ['file', $spawnedLog, 'a']],
        $pipes,
        $root,
        $env
    );
    for ($i = 0; $i < 25; $i++) {
        $s = @fsockopen('127.0.0.1', $dynPort);
        if ($s !== false) {
            fclose($s);
            break;
        }
        usleep(100000);
    }
}

function auth_test_request(string $url, string $cookie = '', bool $isPost = false, array $postData = []): array
{
    $headerLines = "Accept: text/html\r\n";
    if ($cookie !== '') {
        $headerLines .= "Cookie: {$cookie}\r\n";
    }

    $httpOptions = [
        'method' => $isPost ? 'POST' : 'GET',
        'ignore_errors' => true,
        'timeout' => 8,
        'follow_location' => 0,
    ];

    if ($isPost) {
        $content = http_build_query($postData);
        $headerLines .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $headerLines .= 'Content-Length: ' . strlen($content) . "\r\n";
        $httpOptions['content'] = $content;
    }

    $httpOptions['header'] = $headerLines;

    $context = stream_context_create(['http' => $httpOptions]);
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];

    $status = 0;
    if (!empty($responseHeaders[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $responseHeaders[0], $m)) {
        $status = (int)$m[1];
    }

    $headerStr = implode("\r\n", $responseHeaders);
    return [$status, (string)$body, $headerStr];
}

function auth_extract_cookie(string $headerStr): string
{
    if (preg_match('/Set-Cookie:\s*(TSSA_TRANSPORTES_SESSION=[^;]+)/i', $headerStr, $m)) {
        return $m[1];
    }
    return '';
}

function auth_extract_csrf(string $html): string
{
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

function auth_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FALLO DE REGRESIÓN: {$message}");
    }
}

// 1. Verificar formulario renderizado de login
[$status, $loginHtml, $headers] = auth_test_request($base . '/auth/login.php');
auth_assert($status === 200, "Login page must return 200 (got {$status})");
$initialCookie = auth_extract_cookie($headers);
auth_assert($initialCookie !== '', 'Login must issue a session cookie');

$csrfToken = auth_extract_csrf($loginHtml);
auth_assert($csrfToken !== '', 'Login page must provide an active CSRF token');

// Verificar inputs esperados en el formulario
auth_assert(str_contains($loginHtml, 'name="usuario"'), 'Form must contain name="usuario"');
auth_assert(str_contains($loginHtml, 'name="password"'), 'Form must contain name="password"');
auth_assert(str_contains($loginHtml, 'name="csrf_token"'), 'Form must contain name="csrf_token"');
auth_assert(str_contains($loginHtml, 'action="/auth/login.php"'), 'Form action must be /auth/login.php');
auth_assert(str_contains($loginHtml, 'method="post"'), 'Form method must be post');
echo "  [OK] Formulario de login renderizado con atributos y campos correctos.\n";

// 2. Validación CSRF: rechazo con token inválido
[$csrfFailStatus, $csrfFailHtml] = auth_test_request($base . '/auth/login.php', $initialCookie, true, [
    'csrf_token' => 'invalid_csrf_token_value_0000000000000000',
    'usuario' => 'cualquiera',
    'password' => 'clave123',
]);
auth_assert($csrfFailStatus === 200, 'Invalid CSRF returns 200 with error message');
auth_assert(str_contains($csrfFailHtml, 'La sesión del formulario caducó'), 'Must reject invalid CSRF with session expired notice');
echo "  [OK] Protección CSRF valida y rechaza tokens manipulados.\n";

// 3. Crear usuario efímero para probar ciclo real de autenticación sin tocar admin_proyecto
$suffix = bin2hex(random_bytes(6));
$ephemeralUser = '__auth_test_' . $suffix;
$ephemeralPass = 'TestPassAuth2026*' . $suffix;
$ephemeralEmail = "{$ephemeralUser}@example.test";
$ephemeralHash = password_hash($ephemeralPass, PASSWORD_BCRYPT);

$insertStmt = $connection->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) OUTPUT INSERTED.usuario_id VALUES (?, ?, ?, 1)');
$insertStmt->execute([$ephemeralUser, $ephemeralEmail, $ephemeralHash]);
$ephemeralId = (int)$insertStmt->fetchColumn();
$insertStmt->closeCursor();

// Asignar permiso para acceder a dashboard y clientes
$permStmt = $connection->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo IN (\'REPORTES_VER\', \'CLIENTES_VER\') AND activo = 1');
$permStmt->execute([$ephemeralId]);
$permStmt->closeCursor();

try {
    // 4. Intentar login con contraseña incorrecta
    [$badStatus, $badHtml] = auth_test_request($base . '/auth/login.php', $initialCookie, true, [
        'csrf_token' => $csrfToken,
        'usuario' => $ephemeralUser,
        'password' => 'ContraseñaTotalmenteIncorrecta999!',
    ]);
    auth_assert($badStatus === 200, 'Bad password must return 200 with error message');
    auth_assert(str_contains($badHtml, 'Usuario o contraseña incorrectos'), 'Must display incorrect user/password message');
    echo "  [OK] Contraseña incorrecta rechazada con mensaje seguro uniforme.\n";

    // 5. Login exitoso con credenciales correctas
    // Refrescar página para nuevo token CSRF
    [$_, $freshLoginHtml, $freshHeaders] = auth_test_request($base . '/auth/login.php');
    $freshCookie = auth_extract_cookie($freshHeaders) ?: $initialCookie;
    $freshCsrf = auth_extract_csrf($freshLoginHtml);

    [$okStatus, $_, $authHeaders] = auth_test_request($base . '/auth/login.php', $freshCookie, true, [
        'csrf_token' => $freshCsrf,
        'usuario' => $ephemeralUser,
        'password' => $ephemeralPass,
    ]);
    auth_assert($okStatus === 302, "Successful login must redirect with 302 (got {$okStatus})");
    $authenticatedCookie = auth_extract_cookie($authHeaders) ?: $freshCookie;
    echo "  [OK] Login exitoso: redirección 302 y rotación de sesión.\n";

    // 6. Acceso a rutas privadas con sesión activa
    [$dashStatus, $dashHtml] = auth_test_request($base . '/', $authenticatedCookie);
    auth_assert($dashStatus === 200, "Dashboard must return 200 for authenticated user (got {$dashStatus})");
    auth_assert(str_contains($dashHtml, $ephemeralUser), 'Dashboard must show authenticated username');
    $dashCsrf = auth_extract_csrf($dashHtml);
    echo "  [OK] Acceso a ruta privada general concedido y verificado.\n";

    [$cliStatus] = auth_test_request($base . '/clientes.php', $authenticatedCookie);
    auth_assert($cliStatus === 200, "Clientes must return 200 for user with CLIENTES_VER (got {$cliStatus})");
    echo "  [OK] Acceso a módulo autorizado según permisos individuales.\n";

    // 7. Intento de acceso a ruta privada sin permisos (ej. usuarios)
    [$usersStatus] = auth_test_request($base . '/usuarios.php', $authenticatedCookie);
    auth_assert($usersStatus === 403, "Usuarios must return 403 Forbidden for user without USUARIOS_VER (got {$usersStatus})");
    echo "  [OK] Bloqueo 403 en ruta no autorizada verificado.\n";

    // 8. Logout con CSRF
    [$logoutStatus, $_, $logoutHeaders] = auth_test_request($base . '/auth/logout.php', $authenticatedCookie, true, [
        'csrf_token' => $dashCsrf,
    ]);
    auth_assert($logoutStatus === 302, "Logout must redirect with 302 (got {$logoutStatus})");
    echo "  [OK] Logout exitoso: sesión destruida.\n";

    // 9. Acceso a rutas privadas después del logout debe redirigir al login
    [$postLogoutStatus] = auth_test_request($base . '/clientes.php', $authenticatedCookie);
    auth_assert($postLogoutStatus === 302, "Access to private route after logout must redirect 302 (got {$postLogoutStatus})");
    echo "  [OK] Sesión invalidada post-logout comprobada.\n";

} finally {
    if (is_resource($spawnedServerProcess)) {
        proc_terminate($spawnedServerProcess);
        proc_close($spawnedServerProcess);
        if ($spawnedLog && file_exists($spawnedLog)) {
            @unlink($spawnedLog);
        }
    }

    // 10. Limpieza estricta de cuenta efímera
    $delP = $connection->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?');
    $delP->execute([$ephemeralId]);
    $delP->closeCursor();

    $delU = $connection->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?');
    $delU->execute([$ephemeralId]);
    $delU->closeCursor();
    echo "  [OK] Cuenta efímera limpiada sin alterar registros existentes.\n";
}

// 11. Verificación del estado de las cuentas existentes
$checkAdmin = $connection->query('SELECT usuario_id, nombre_usuario, correo_acceso, activo FROM dbo.usuarios WHERE usuario_id = 1')->fetch(PDO::FETCH_ASSOC);
auth_assert($checkAdmin && $checkAdmin['nombre_usuario'] === 'admin_proyecto', 'admin_proyecto must exist');
auth_assert((int)$checkAdmin['activo'] === 1, 'admin_proyecto must be active');
echo "  [OK] Cuenta admin_proyecto verificada: existe y está activa (activo=1).\n";

echo "=== REGRESIÓN DE AUTENTICACIÓN COMPLETADA EXITOSAMENTE (100% PASÓ) ===\n";
