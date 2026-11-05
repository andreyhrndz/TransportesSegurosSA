<?php
declare(strict_types=1);

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
putenv('TSSA_HACIENDA_MOCK=1'); // Modo mock para no depender de internet en la suite automatizada
require_once __DIR__ . '/../app/bootstrap.php';

function hapi_fail(string $message): never
{
    fwrite(STDERR, "FALLO HACIENDA API TEST: {$message}\n");
    exit(1);
}

function hapi_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        hapi_fail($msg);
    }
}

function hapi_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        hapi_fail('No se pudo reservar puerto local.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

/** @return array{0:int,1:string,2:array<int,string>} */
function hapi_request(string $url, ?string $cookie = null): array
{
    $headers = "Accept: application/json\r\n";
    if ($cookie !== null) {
        $headers .= "Cookie: {$cookie}\r\n";
    }
    $options = [
        'header' => $headers,
        'ignore_errors' => true,
        'timeout' => 8,
        'follow_location' => 0,
    ];
    $context = stream_context_create(['http' => $options]);
    $body = file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = (int)(preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match) ? $match[1] : 0);
    return [$status, $body === false ? '' : $body, $responseHeaders];
}

$pdo = db();
require_test_database($pdo);

// 1. Preparar una invitación efímera válida en dbo.invitaciones_usuario
$ctx = $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?, @read_only=0;");
$ctx->execute([1]);
$ctx->closeCursor();

$tokenRaw = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $tokenRaw);
$emailInvitacion = 'hacienda_test_' . time() . '@example.test';

$stmtInv = $pdo->prepare('DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT; SELECT @inv_id AS invitacion_id;');
$stmtInv->execute([$emailInvitacion, $tokenHash]);
$invId = (int)$stmtInv->fetchColumn();
$stmtInv->closeCursor();
hapi_assert($invId > 0, 'Debe crear invitación de prueba en DB');

// 2. Preparar sesión local autoritativa de administrador (usuario_id = 1, con permisos)
$sessionName = session_cookie_name();
$adminSessionId = bin2hex(random_bytes(16));
$adminCsrf = bin2hex(random_bytes(32));

// 3. Preparar sesión local de usuario sin permisos
$noPermSessionId = bin2hex(random_bytes(16));
$noPermCsrf = bin2hex(random_bytes(32));

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
ini_set('session.use_strict_mode', '0');

// Sesión admin
session_name($sessionName);
session_id($adminSessionId);
session_start();
$_SESSION = [
    'usuario_id' => 1,
    'authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => $adminCsrf,
];
session_write_close();

// Sesión usuario sin permisos (usamos usuario activo 1174 sin permisos)
session_name($sessionName);
session_id($noPermSessionId);
session_start();
$_SESSION = [
    'usuario_id' => 1174,
    'authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => $noPermCsrf,
];
session_write_close();
ini_set('session.use_strict_mode', '1');

$adminCookie = "{$sessionName}={$adminSessionId}";
$noPermCookie = "{$sessionName}={$noPermSessionId}";

echo "=== INICIO DE PRUEBAS DEL ENDPOINT /api/hacienda.php ===\n";

// 4. Levantar servidor HTTP local
$port = hapi_port();
$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'tssa-hapi-http-');
$env = array_merge(getenv(), [
    'TSSA_DB_DATABASE' => 'TransportesSegurosSA_Test',
    'TSSA_HACIENDA_MOCK' => '1',
]);
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . DIRECTORY_SEPARATOR . 'public'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    $root,
    $env
);
hapi_assert(is_resource($proc), 'Debe iniciar servidor HTTP local');

for ($i = 0; $i < 25; $i++) {
    $sock = @fsockopen('127.0.0.1', $port);
    if ($sock !== false) {
        fclose($sock);
        break;
    }
    usleep(100000);
}

$base = "http://127.0.0.1:{$port}";

try {
    // Caso 1: Petición anónima sin token ni sesión -> 401
    [$codeAnon, $respAnon] = hapi_request("{$base}/api/hacienda.php?identificacion=102340567");
    hapi_assert($codeAnon === 401, "Petición anónima debe retornar 401 (obtenido: $codeAnon)");
    $dataAnon = json_decode($respAnon, true);
    hapi_assert(($dataAnon['status'] ?? '') === 'error', 'Debe retornar status error');

    // Caso 2: Petición anónima con token inventado/falso -> 401
    [$codeBadTok, $respBadTok] = hapi_request("{$base}/api/hacienda.php?identificacion=102340567&token=token_invalido_falso");
    hapi_assert($codeBadTok === 401, "Token falso debe retornar 401 (obtenido: $codeBadTok)");

    // Caso 3: Petición con token de invitación legítimo (flujo onboarding) -> 200
    [$codeTokOk, $respTokOk] = hapi_request("{$base}/api/hacienda.php?identificacion=102340567&token={$tokenRaw}");
    hapi_assert($codeTokOk === 200, "Token de invitación válido debe retornar 200 (obtenido: $codeTokOk, respuesta: $respTokOk)");
    $dataTokOk = json_decode($respTokOk, true);
    hapi_assert(($dataTokOk['status'] ?? '') === 'encontrado', 'Debe retornar status encontrado con token');
    hapi_assert(!empty($dataTokOk['nombre']), 'Debe retornar nombre');

    // Caso 4: Petición con sesión autenticada sin permisos -> 403
    [$codeNoPerm, $respNoPerm] = hapi_request("{$base}/api/hacienda.php?identificacion=102340567", $noPermCookie);
    hapi_assert($codeNoPerm === 403, "Sesión sin permisos debe retornar 403 (obtenido: $codeNoPerm)");

    // Caso 5: Petición con sesión de administrador con permisos -> 200
    [$codeAdmin, $respAdmin] = hapi_request("{$base}/api/hacienda.php?identificacion=102340567", $adminCookie);
    hapi_assert($codeAdmin === 200, "Sesión de admin debe retornar 200 (obtenido: $codeAdmin)");
    $dataAdmin = json_decode($respAdmin, true);
    hapi_assert(($dataAdmin['status'] ?? '') === 'encontrado', 'Debe retornar status encontrado con admin');

    // Caso 6: Identificación vacía con admin -> 400
    [$codeEmpty, $respEmpty] = hapi_request("{$base}/api/hacienda.php?identificacion=", $adminCookie);
    hapi_assert($codeEmpty === 400, "Identificación vacía debe retornar 400 (obtenido: $codeEmpty)");

    echo "OK: Todas las pruebas de control de acceso y consulta de /api/hacienda.php pasaron exitosamente.\n";

} finally {
    // Limpieza de invitación de prueba
    $pdo->prepare('DELETE FROM dbo.invitaciones_usuario WHERE invitacion_id = ?')->execute([$invId]);

    if (is_resource($proc)) {
        $st = proc_get_status($proc);
        if (!empty($st['pid']) && strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            @exec("taskkill /F /T /PID " . (int)$st['pid'] . " 2>&1");
        } else {
            proc_terminate($proc);
        }
        @proc_close($proc);
    }
    if (file_exists($log)) {
        @unlink($log);
    }
}
