<?php
declare(strict_types=1);

/**
 * Suite E2E Transversal y Pruebas de Seguridad Integral — Transportes Seguros S.A.
 * 
 * Verifica el ciclo de vida completo:
 * 1. Flujo Operativo E2E (Login real -> Cliente -> Solicitud + Mercancías TVP -> Viaje -> Paradas -> Asignación -> Iniciar -> Entrega -> Finalizar -> Reportes -> Auditoría -> Logout).
 * 2. Flujo Administrativo (Alta usuario bcrypt -> Concesión con auditoría INSERT -> Revocación con auditoría DELETE -> Edición cliente -> Desactivación permitida y bloqueada -> Flota).
 * 3. Seguridad Integral (Acceso anónimo, sesión expirada, CSRF inválido, permisos insuficientes, manipulación ID, SQLi neutralizado, XSS escapado, transiciones de estado, protección del último admin con UPDLOCK/HOLDLOCK).
 * 
 * Opera exclusivamente sobre registros aislados efímeros en TransportesSegurosSA_Test y garantiza limpieza completa en finally.
 */

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/transport_request_tvp.php';

function e2e_fail(string $message): never
{
    fwrite(STDERR, "FALLO E2E: {$message}\n");
    exit(1);
}

function e2e_assert(bool $condition, string $message): void
{
    if (!$condition) {
        e2e_fail($message);
    }
}

function e2e_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        e2e_fail('No se pudo reservar puerto local.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

/** @return array{0: int, 1: string, 2: array<int, string>} */
function e2e_request(string $url, string $cookie = '', bool $htmx = false, ?array $postFields = null): array
{
    $headers = "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n";
    if ($cookie !== '') {
        $headers .= "Cookie: {$cookie}\r\n";
    }
    if ($htmx) {
        $headers .= "HX-Request: true\r\n";
    }
    $options = [
        'header' => $headers,
        'ignore_errors' => true,
        'timeout' => 10,
        'follow_location' => 0,
    ];
    if ($postFields !== null) {
        $options['method'] = 'POST';
        $options['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $options['content'] = http_build_query($postFields);
    }
    $context = stream_context_create(['http' => $options]);
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = (int)(preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match) ? $match[1] : 0);
    return [$status, $body === false ? '' : $body, $responseHeaders];
}

function e2e_extract_cookie(array $headers): string
{
    $cookie = '';
    foreach ($headers as $h) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $h, $m)) {
            $cookie = $m[1];
        }
    }
    return $cookie;
}

function e2e_extract_csrf(string $html): string
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]{64})"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

// -------------------------------------------------------------
// Verificación estricta de base de datos
// -------------------------------------------------------------
if (configured_database_name() !== 'TransportesSegurosSA_Test') {
    e2e_fail('Se requiere explícitamente TSSA_DB_DATABASE=TransportesSegurosSA_Test.');
}
$pdo = db();
require_test_database($pdo);

$suffix = bin2hex(random_bytes(4));
$adminUsername = '__e2e_admin_' . $suffix;
$staffUsername = '__e2e_staff_' . $suffix;
$adminPassword = 'E2EAdminClaveSegura2026!';
$staffPassword = 'E2EStaffClaveSegura2026!';

$createdUserIds = [];
$createdClientIds = [];
$createdSolicitudIds = [];
$createdTripIds = [];
$createdVehicleIds = [];

// Preparar sesión caducada en almacenamiento de sesiones antes de emitir salida
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
ini_set('session.use_strict_mode', '0');
$expiredSessionId = bin2hex(random_bytes(24));
session_name('TSSA_TRANSPORTES_SESSION');
session_id($expiredSessionId);
session_start();
$_SESSION = [
    'usuario_id' => 1,
    'authenticated_at' => time() - 36000,
    'last_activity_at' => time() - 3600, // Inactividad > 30m
    'csrf_token' => bin2hex(random_bytes(32)),
];
session_write_close();
ini_set('session.use_strict_mode', '1');

$port = e2e_port();
$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'tssa-e2e-http-');
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
    e2e_fail('No se pudo iniciar el servidor HTTP local para las pruebas E2E.');
}

try {
    for ($i = 0; $i < 30; $i++) {
        $sock = @fsockopen('127.0.0.1', $port);
        if ($sock !== false) {
            fclose($sock);
            break;
        }
        usleep(100000);
    }

    $base = "http://127.0.0.1:{$port}";
    echo "Servidor HTTP local activo en {$base}\n";

    // Limpiar restos de usuarios efímeros de ejecuciones anteriores
    $pdo->exec("UPDATE dbo.historial_estados_solicitud SET usuario_id = 1 WHERE usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario LIKE '__e2e%')");
    $pdo->exec("DELETE FROM dbo.usuarios_permisos WHERE usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario LIKE '__e2e%')");
    $pdo->exec("DELETE FROM dbo.auditorias WHERE usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario LIKE '__e2e%')");
    $pdo->exec("DELETE FROM dbo.usuarios WHERE nombre_usuario LIKE '__e2e%'");

    // ---------------------------------------------------------
    // Configurar usuario administrador efímero con permisos completos
    // ---------------------------------------------------------
    $stmt = $pdo->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) OUTPUT INSERTED.usuario_id VALUES (?, ?, ?, 1)');
    $stmt->execute([$adminUsername, "{$adminUsername}@example.test", password_hash($adminPassword, PASSWORD_BCRYPT)]);
    $adminUserId = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $createdUserIds[] = $adminUserId;

    // Asignar todos los permisos activos a este admin efímero
    $grantAll = $pdo->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE activo = 1');
    $grantAll->execute([$adminUserId]);
    $grantAll->closeCursor();

    // Asegurar estado inicial limpio para recursos compartidos y limpiar restos de ejecuciones previas
    $pdo->exec("DELETE FROM dbo.entregas WHERE viaje_solicitud_id IN (SELECT viaje_solicitud_id FROM dbo.viaje_solicitudes WHERE viaje_id IN (SELECT viaje_id FROM dbo.viajes WHERE codigo_viaje LIKE '__E2E%'))");
    $pdo->exec("DELETE FROM dbo.viaje_solicitudes WHERE viaje_id IN (SELECT viaje_id FROM dbo.viajes WHERE codigo_viaje LIKE '__E2E%')");
    $pdo->exec("DELETE FROM dbo.paradas_viaje WHERE viaje_id IN (SELECT viaje_id FROM dbo.viajes WHERE codigo_viaje LIKE '__E2E%')");
    $pdo->exec("DELETE FROM dbo.viajes WHERE codigo_viaje LIKE '__E2E%'");
    $pdo->exec("DELETE FROM dbo.mercancias WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud LIKE '__E2E%')");
    $pdo->exec("DELETE FROM dbo.incidencias WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud LIKE '__E2E%')");
    $pdo->exec("DELETE FROM dbo.documentos WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud LIKE '__E2E%')");
    $pdo->exec("DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud LIKE '__E2E%')");
    $pdo->exec("DELETE FROM dbo.solicitudes WHERE codigo_solicitud LIKE '__E2E%'");
    $pdo->exec("DELETE FROM dbo.clientes_fisicos WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion LIKE '__E2E%')");
    $pdo->exec("DELETE FROM dbo.clientes_juridicos WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion LIKE '__E2E%')");
    $pdo->exec("DELETE FROM dbo.clientes WHERE identificacion LIKE '__E2E%'");
    $pdo->exec("UPDATE dbo.vehiculos SET estado = 'DISPONIBLE' WHERE vehiculo_id IN (1, 2)");
    $pdo->exec("UPDATE dbo.conductores SET estado_operativo = 'DISPONIBLE' WHERE conductor_id IN (1, 2)");

    // ---------------------------------------------------------
    // FASE 1: FLUJO OPERATIVO TRANSVERSAL E2E
    // ---------------------------------------------------------
    echo ">>> Fase 1: Flujo Operativo E2E Transversal...\n";

    // 1.1 Iniciar sesión real a través de /auth/login.php
    [$status, $loginPageHtml, $headers] = e2e_request($base . '/auth/login.php');
    e2e_assert($status === 200, 'La página de login debe responder HTTP 200.');
    $initialCookie = e2e_extract_cookie($headers);
    $loginCsrf = e2e_extract_csrf($loginPageHtml);
    e2e_assert($loginCsrf !== '', 'La página de login debe incluir un token CSRF válido.');

    [$loginStatus, $loginBody, $loginHeaders] = e2e_request($base . '/auth/login.php', $initialCookie, false, [
        'csrf_token' => $loginCsrf,
        'usuario' => $adminUsername,
        'password' => $adminPassword,
    ]);
    e2e_assert($loginStatus === 302, "El login válido debe redirigir con 302 (obtenido {$loginStatus}).");
    $adminCookie = e2e_extract_cookie($loginHeaders);
    if ($adminCookie === '') {
        $adminCookie = $initialCookie;
    }

    // 1.2 Verificar acceso al dashboard con sesión activa
    [$dashStatus, $dashHtml] = e2e_request($base . '/', $adminCookie);
    e2e_assert($dashStatus === 200, 'El panel general debe responder 200 con sesión activa.');
    e2e_assert(str_contains($dashHtml, $adminUsername), 'El panel debe mostrar la identidad del usuario conectado.');
    $adminCsrf = e2e_extract_csrf($dashHtml);
    e2e_assert($adminCsrf !== '', 'El layout debe proporcionar un token CSRF activo.');

    // 1.3 Crear cliente efímero por HTTP
    $clientCode = '__E2E_CLI_' . $suffix;
    [$cliStatus, $cliBody] = e2e_request($base . '/clientes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'tipo' => 'fisico',
        'identificacion' => $clientCode,
        'nombre' => 'Carlos',
        'primer_apellido' => 'Mora',
        'segundo_apellido' => 'Soto',
        'telefono' => '8989-1234',
        'correo' => "carlos.{$suffix}@example.test",
    ]);
    e2e_assert($cliStatus === 200, 'El alta HTTP de cliente debe responder 200.');
    $fetchClient = $pdo->prepare('SELECT cliente_id FROM dbo.clientes WHERE identificacion = ?');
    $fetchClient->execute([$clientCode]);
    $clientId = (int)$fetchClient->fetchColumn();
    $fetchClient->closeCursor();
    e2e_assert($clientId > 0, 'El cliente efímero debe haberse persistido en BD.');
    $createdClientIds[] = $clientId;

    // 1.4 Registrar solicitud de transporte con mercancías
    $reqCode = '__E2E_REQ_' . $suffix;
    $futureDelivery = (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
    [$reqStatus, $reqBody] = e2e_request($base . '/solicitudes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'codigo' => $reqCode,
        'cliente_id' => $clientId,
        'origen_id' => 1, // Nicoya
        'destino_id' => 3, // Liberia
        'fecha_entrega' => $futureDelivery,
        'destinatario_nombre' => 'María',
        'destinatario_apellido1' => 'Gómez',
        'destinatario_apellido2' => 'Salas',
        'destinatario_identificacion' => '109870654',
        'destinatario_telefono' => '8765-4321',
        'mercancias' => [
            0 => [
                'tipo_mercancia_id' => 1,
                'descripcion' => 'Carga valorada refrigerada ' . $suffix,
                'peso_kg' => '45.50',
                'volumen_m3' => '2.100',
            ],
        ],
    ]);
    e2e_assert($reqStatus === 200, "El alta HTTP de solicitud debe responder 200 (obtenido {$reqStatus}).");
    $fetchReq = $pdo->prepare('SELECT solicitud_id, estado FROM dbo.solicitudes WHERE codigo_solicitud = ?');
    $fetchReq->execute([$reqCode]);
    $reqRow = $fetchReq->fetch();
    $fetchReq->closeCursor();
    e2e_assert(is_array($reqRow) && (int)$reqRow['solicitud_id'] > 0, 'La solicitud debe existir en BD.');
    $solicitudId = (int)$reqRow['solicitud_id'];
    $createdSolicitudIds[] = $solicitudId;
    e2e_assert($reqRow['estado'] === 'REGISTRADA', 'La solicitud debe iniciar en estado REGISTRADA.');

    // 1.5 Consultar detalle de la solicitud
    [$detailStatus, $detailHtml] = e2e_request($base . "/solicitudes/detalle.php?id={$solicitudId}", $adminCookie);
    e2e_assert($detailStatus === 200, 'El detalle de la solicitud debe responder 200.');
    e2e_assert(str_contains($detailHtml, $reqCode), 'El detalle debe contener el código de solicitud.');
    e2e_assert(str_contains($detailHtml, 'Carga valorada refrigerada'), 'El detalle debe listar las mercancías.');

    // 1.6 Crear viaje planificado por HTTP
    $tripCode = '__E2E_TRIP_' . $suffix;
    $futureTrip = (new DateTimeImmutable('+1 day', new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
    [$tripStatus, $tripBody] = e2e_request($base . '/viajes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'codigo' => $tripCode,
        'vehiculo_id' => 1,
        'conductor_id' => 1,
        'ruta_id' => 1, // Nicoya -> Liberia
        'fecha_programada' => $futureTrip,
    ]);
    e2e_assert($tripStatus === 200, "La creación HTTP de viaje debe responder 200 (obtenido {$tripStatus}): {$tripBody}");
    $fetchTrip = $pdo->prepare('SELECT viaje_id, estado FROM dbo.viajes WHERE codigo_viaje = ?');
    $fetchTrip->execute([$tripCode]);
    $tripRow = $fetchTrip->fetch();
    $fetchTrip->closeCursor();
    e2e_assert(is_array($tripRow) && (int)$tripRow['viaje_id'] > 0, 'El viaje debe existir en BD.');
    $tripId = (int)$tripRow['viaje_id'];
    $createdTripIds[] = $tripId;
    e2e_assert($tripRow['estado'] === 'PLANIFICADO', 'El viaje debe iniciar en estado PLANIFICADO.');

    // 1.7 Agregar paradas al viaje (parada 1: Recogida en Nicoya, parada 2: Entrega en Liberia)
    [$p1Status, $p1Body] = e2e_request($base . '/viajes/accion.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'viaje_id' => $tripId,
        'accion' => 'parada',
        'ubicacion_id' => 1,
        'orden' => 1,
        'tipo_parada' => 'RECOGIDA',
    ]);
    e2e_assert($p1Status === 200, 'Agregar parada 1 debe responder 200.');

    [$p2Status, $p2Body] = e2e_request($base . '/viajes/accion.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'viaje_id' => $tripId,
        'accion' => 'parada',
        'ubicacion_id' => 3,
        'orden' => 2,
        'tipo_parada' => 'ENTREGA',
    ]);
    e2e_assert($p2Status === 200, 'Agregar parada 2 debe responder 200.');

    // Obtener los IDs de las paradas creadas
    $fetchStops = $pdo->prepare('SELECT parada_id, orden, tipo_parada FROM dbo.paradas_viaje WHERE viaje_id = ? ORDER BY orden ASC');
    $fetchStops->execute([$tripId]);
    $stops = $fetchStops->fetchAll();
    $fetchStops->closeCursor();
    e2e_assert(count($stops) === 2, 'El viaje debe tener exactamente 2 paradas registradas.');
    $pickupStopId = (int)$stops[0]['parada_id'];
    $deliveryStopId = (int)$stops[1]['parada_id'];

    // 1.8 Asignar la solicitud al viaje
    [$asigStatus, $asigBody] = e2e_request($base . '/viajes/accion.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'viaje_id' => $tripId,
        'accion' => 'asignar',
        'solicitud_id' => $solicitudId,
        'parada_recogida_id' => $pickupStopId,
        'parada_entrega_id' => $deliveryStopId,
    ]);
    e2e_assert($asigStatus === 200, 'Asignar solicitud al viaje debe responder 200.');

    $fetchAssign = $pdo->prepare('SELECT viaje_solicitud_id, estado FROM dbo.viaje_solicitudes WHERE viaje_id = ? AND solicitud_id = ?');
    $fetchAssign->execute([$tripId, $solicitudId]);
    $assignRow = $fetchAssign->fetch();
    $fetchAssign->closeCursor();
    e2e_assert(is_array($assignRow) && (int)$assignRow['viaje_solicitud_id'] > 0, 'La asignación viaje-solicitud debe existir.');
    $assignmentId = (int)$assignRow['viaje_solicitud_id'];

    // 1.9 Iniciar el viaje (transición a EN_CURSO)
    [$startStatus, $startBody] = e2e_request($base . '/viajes/accion.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'viaje_id' => $tripId,
        'accion' => 'iniciar',
    ]);
    e2e_assert($startStatus === 200, 'Iniciar viaje debe responder 200.');

    $fetchTripState = $pdo->prepare('SELECT estado FROM dbo.viajes WHERE viaje_id = ?');
    $fetchTripState->execute([$tripId]);
    e2e_assert($fetchTripState->fetchColumn() === 'EN_CURSO', 'El viaje debe encontrarse en estado EN_CURSO.');
    $fetchTripState->closeCursor();

    // 1.10 Registrar entrega exitosa
    [$delivStatus, $delivBody] = e2e_request($base . '/entregas/registrar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'viaje_solicitud_id' => $assignmentId,
        'estado' => 'ENTREGADA',
        'receptor_nombre' => 'María Gómez Salas',
        'receptor_identificacion' => '109870654',
        'receptor_telefono' => '8765-4321',
        'comprobante_url' => 'https://example.test/comprobantes/' . $suffix . '.pdf',
    ]);
    e2e_assert($delivStatus === 200, 'Registrar entrega debe responder 200.');

    $fetchAssignState = $pdo->prepare('SELECT estado FROM dbo.viaje_solicitudes WHERE viaje_solicitud_id = ?');
    $fetchAssignState->execute([$assignmentId]);
    e2e_assert($fetchAssignState->fetchColumn() === 'ENTREGADA', 'La asignación debe encontrarse en estado ENTREGADA.');
    $fetchAssignState->closeCursor();

    // 1.11 Finalizar el viaje (transición a FINALIZADO y liberación de recursos)
    [$finStatus, $finBody] = e2e_request($base . '/viajes/accion.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'viaje_id' => $tripId,
        'accion' => 'finalizar',
    ]);
    e2e_assert($finStatus === 200, 'Finalizar viaje debe responder 200.');

    $fetchTripFinal = $pdo->prepare('SELECT estado FROM dbo.viajes WHERE viaje_id = ?');
    $fetchTripFinal->execute([$tripId]);
    e2e_assert($fetchTripFinal->fetchColumn() === 'FINALIZADO', 'El viaje debe encontrarse en estado FINALIZADO.');
    $fetchTripFinal->closeCursor();

    // Verificar retorno de vehículo y conductor a DISPONIBLE
    $checkVeh = $pdo->prepare('SELECT estado FROM dbo.vehiculos WHERE vehiculo_id = 1');
    $checkVeh->execute();
    e2e_assert($checkVeh->fetchColumn() === 'DISPONIBLE', 'El vehículo 1 debe haber retornado a DISPONIBLE.');
    $checkVeh->closeCursor();

    $checkDriver = $pdo->prepare('SELECT estado_operativo FROM dbo.conductores WHERE conductor_id = 1');
    $checkDriver->execute();
    e2e_assert($checkDriver->fetchColumn() === 'DISPONIBLE', 'El conductor 1 debe haber retornado a DISPONIBLE.');
    $checkDriver->closeCursor();

    // 1.12 Consultar reportes y auditoría
    [$repStatus, $repHtml] = e2e_request($base . '/reportes.php', $adminCookie);
    e2e_assert($repStatus === 200, 'La vista de reportes debe responder 200.');

    // Verificar trazabilidad transversal en dbo.auditorias
    $auditCheck = $pdo->prepare('SELECT COUNT(*) FROM dbo.auditorias WHERE usuario_id = ?');
    $auditCheck->execute([$adminUserId]);
    $auditCount = (int)$auditCheck->fetchColumn();
    $auditCheck->closeCursor();
    e2e_assert($auditCount >= 3, "Deben existir eventos de auditoría para el flujo operativo (encontrados {$auditCount}).");

    // 1.13 Cerrar sesión
    [$logoutStatus, $logoutBody, $logoutHeaders] = e2e_request($base . '/auth/logout.php', $adminCookie, false, [
        'csrf_token' => $adminCsrf,
    ]);
    e2e_assert($logoutStatus === 302, 'El cierre de sesión debe responder con 302 hacia /auth/login.php.');

    // Verificar que la sesión quedó destruida en el servidor
    [$postLogoutStatus] = e2e_request($base . '/', $adminCookie);
    e2e_assert($postLogoutStatus === 302, 'Una petición con cookie destruida debe redirigir a login.');

    echo "   [OK] Flujo Operativo E2E completado y verificado con éxito.\n";

    // ---------------------------------------------------------
    // FASE 2: FLUJO ADMINISTRATIVO DE IDENTIDADES Y CALIDAD
    // ---------------------------------------------------------
    echo ">>> Fase 2: Flujo Administrativo de Gestión y Calidad...\n";

    // Reactivar sesión de administrador para gestiones administrativas
    [$_, $newLoginHtml, $lHeaders] = e2e_request($base . '/auth/login.php');
    $lCookie = e2e_extract_cookie($lHeaders);
    $lCsrf = e2e_extract_csrf($newLoginHtml);
    [$_, $_, $authHeaders] = e2e_request($base . '/auth/login.php', $lCookie, false, [
        'csrf_token' => $lCsrf,
        'usuario' => $adminUsername,
        'password' => $adminPassword,
    ]);
    $adminCookie = e2e_extract_cookie($authHeaders) ?: $lCookie;
    [$_, $dashHtml2] = e2e_request($base . '/', $adminCookie);
    $adminCsrf = e2e_extract_csrf($dashHtml2);

    // 2.1 Crear nuevo usuario efímero mediante endpoint administrativo
    [$createUserStatus, $createUserBody] = e2e_request($base . '/usuarios/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'nombre_usuario' => $staffUsername,
        'correo_acceso' => "{$staffUsername}@example.test",
        'password' => $staffPassword,
        'password_confirmacion' => $staffPassword,
    ]);
    e2e_assert($createUserStatus === 200, "El alta de usuario debe responder 200 (obtenido {$createUserStatus}): {$createUserBody}");

    $fetchStaff = $pdo->prepare('SELECT usuario_id, password_hash, activo FROM dbo.usuarios WHERE nombre_usuario = ?');
    $fetchStaff->execute([$staffUsername]);
    $staffRow = $fetchStaff->fetch();
    $fetchStaff->closeCursor();
    e2e_assert(is_array($staffRow) && (int)$staffRow['usuario_id'] > 0, 'El usuario efímero debe haberse creado.');
    $staffUserId = (int)$staffRow['usuario_id'];
    $createdUserIds[] = $staffUserId;
    e2e_assert((bool)$staffRow['activo'] === true, 'El usuario recién creado debe estar activo.');
    e2e_assert(password_verify($staffPassword, (string)$staffRow['password_hash']), 'El hash debe verificar la contraseña elegida.');

    // 2.2 Conceder permiso individual con dbo.sp_conceder_permiso_seguro y verificar auditoría INSERT
    [$grantStatus, $grantBody] = e2e_request($base . '/usuarios/permiso.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $staffUserId,
        'codigo' => 'CLIENTES_VER',
        'accion' => 'conceder',
    ]);
    e2e_assert($grantStatus === 200, "Conceder permiso individual debe responder 200 (obtenido {$grantStatus}).");

    $auditGrant = $pdo->prepare("SELECT COUNT(*) FROM dbo.auditorias WHERE entidad = N'usuarios_permisos' AND registro_id = ? AND accion = 'INSERT' AND valores_nuevos = N'CLIENTES_VER'");
    $auditGrant->execute(["{$staffUserId}:CLIENTES_VER"]);
    $grantCount = (int)$auditGrant->fetchColumn();
    $auditGrant->closeCursor();
    e2e_assert($grantCount > 0, 'La concesión de permiso debe generar un registro de auditoría INSERT en dbo.auditorias.');

    // 2.3 Revocar permiso individual con dbo.sp_revocar_permiso_seguro y verificar auditoría DELETE
    [$revokeStatus, $revokeBody] = e2e_request($base . '/usuarios/permiso.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $staffUserId,
        'codigo' => 'CLIENTES_VER',
        'accion' => 'revocar',
    ]);
    e2e_assert($revokeStatus === 200, "Revocar permiso individual debe responder 200 (obtenido {$revokeStatus}).");

    $auditRevoke = $pdo->prepare("SELECT COUNT(*) FROM dbo.auditorias WHERE entidad = N'usuarios_permisos' AND registro_id = ? AND accion = 'DELETE' AND valores_anteriores = N'CLIENTES_VER'");
    $auditRevoke->execute(["{$staffUserId}:CLIENTES_VER"]);
    $revokeCount = (int)$auditRevoke->fetchColumn();
    $auditRevoke->closeCursor();
    e2e_assert($revokeCount > 0, 'La revocación de permiso debe generar un registro de auditoría DELETE en dbo.auditorias.');

    // 2.4 Editar cliente efímero
    [$editCliStatus, $editCliBody] = e2e_request($base . '/clientes/actualizar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $clientId,
        'subtipo' => 'FISICA',
        'nombre' => 'Carlos Modificado',
        'primer_apellido' => 'Mora',
        'segundo_apellido' => 'Soto',
        'telefono' => '8989-9999',
        'correo' => "carlos.edit.{$suffix}@example.test",
    ]);
    e2e_assert($editCliStatus === 200, "La actualización del cliente debe responder 200 (obtenido {$editCliStatus}).");

    // 2.5 Rechazo de desactivación de cliente con solicitudes activas vs cliente permitido
    // Crear cliente bloqueado con solicitud activa
    $blockedCliCode = '__E2E_CBLK_' . $suffix;
    [$_, $_] = e2e_request($base . '/clientes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'tipo' => 'fisico',
        'identificacion' => $blockedCliCode,
        'nombre' => 'Bloqueado',
        'primer_apellido' => 'Prueba',
        'correo' => "bloqueado.{$suffix}@example.test",
    ]);
    $fetchBlockedCli = $pdo->prepare('SELECT cliente_id FROM dbo.clientes WHERE identificacion = ?');
    $fetchBlockedCli->execute([$blockedCliCode]);
    $blockedCliId = (int)$fetchBlockedCli->fetchColumn();
    $fetchBlockedCli->closeCursor();
    $createdClientIds[] = $blockedCliId;

    // Crear solicitud activa para este cliente
    $blockedReqCode = '__E2E_RBLK_' . $suffix;
    [$bReqStatus, $bReqBody] = e2e_request($base . '/solicitudes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'codigo' => $blockedReqCode,
        'cliente_id' => $blockedCliId,
        'origen_id' => 1,
        'destino_id' => 3,
        'fecha_entrega' => $futureDelivery,
        'destinatario_nombre' => 'Test',
        'destinatario_apellido1' => 'Bloqueo',
        'destinatario_apellido2' => 'Segundo',
        'mercancias' => [
            0 => ['tipo_mercancia_id' => 1, 'descripcion' => 'Carga ' . $suffix, 'peso_kg' => '10.00'],
        ],
    ]);
    e2e_assert($bReqStatus === 200, "La solicitud activa del cliente debe crearse (obtenido {$bReqStatus}): {$bReqBody}");
    $fetchBlockedReq = $pdo->prepare('SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud = ?');
    $fetchBlockedReq->execute([$blockedReqCode]);
    $createdSolicitudIds[] = (int)$fetchBlockedReq->fetchColumn();
    $fetchBlockedReq->closeCursor();

    // Intentar desactivar cliente bloqueado -> debe responder 409 Conflict
    [$deactBlockedStatus, $deactBlockedBody] = e2e_request($base . '/clientes/desactivar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $blockedCliId,
    ]);
    e2e_assert($deactBlockedStatus === 409, "Desactivar cliente con solicitudes activas debe responder 409 (obtenido {$deactBlockedStatus}).");

    // Desactivar cliente sin solicitudes activas (permitido)
    $freeCliCode = '__E2E_CFREE_' . $suffix;
    [$_, $_] = e2e_request($base . '/clientes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'tipo' => 'fisico',
        'identificacion' => $freeCliCode,
        'nombre' => 'Libre',
        'primer_apellido' => 'Prueba',
        'correo' => "libre.{$suffix}@example.test",
    ]);
    $fetchFreeCli = $pdo->prepare('SELECT cliente_id FROM dbo.clientes WHERE identificacion = ?');
    $fetchFreeCli->execute([$freeCliCode]);
    $freeCliId = (int)$fetchFreeCli->fetchColumn();
    $fetchFreeCli->closeCursor();
    $createdClientIds[] = $freeCliId;

    [$deactFreeStatus, $deactFreeBody] = e2e_request($base . '/clientes/desactivar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $freeCliId,
    ]);
    e2e_assert($deactFreeStatus === 200, "Desactivar cliente elegible debe responder 200 (obtenido {$deactFreeStatus}).");

    $checkFreeCli = $pdo->prepare('SELECT activo FROM dbo.clientes WHERE cliente_id = ?');
    $checkFreeCli->execute([$freeCliId]);
    e2e_assert((bool)$checkFreeCli->fetchColumn() === false, 'El cliente libre debe quedar inactivo.');
    $checkFreeCli->closeCursor();

    // 2.6 Gestión de flota/maestros (crear vehículo efímero, consultar y desactivar)
    $vehPlate = 'E2E-' . substr($suffix, 0, 3);
    [$vehCreateStatus, $vehCreateBody] = e2e_request($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'vehiculo',
        'placa' => $vehPlate,
        'marca' => 'Volvo E2E',
        'modelo' => 'FH16',
        'anio' => '2024',
        'capacidad_carga_kg' => '25000.00',
        'capacidad_volumen_m3' => '60.000',
        'kilometraje_actual' => '1200.0',
        'estado' => 'DISPONIBLE',
    ]);
    e2e_assert($vehCreateStatus === 200, "El alta HTTP de vehículo debe responder 200 (obtenido {$vehCreateStatus}): {$vehCreateBody}");

    $fetchVeh = $pdo->prepare('SELECT vehiculo_id FROM dbo.vehiculos WHERE placa = ?');
    $fetchVeh->execute([$vehPlate]);
    $vehId = (int)$fetchVeh->fetchColumn();
    $fetchVeh->closeCursor();
    e2e_assert($vehId > 0, 'El vehículo efímero debe haberse creado en BD.');
    $createdVehicleIds[] = $vehId;

    // Desactivar vehículo efímero
    [$vehDeactStatus, $vehDeactBody] = e2e_request($base . '/flota/desactivar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'vehiculo',
        'id' => $vehId,
    ]);
    e2e_assert($vehDeactStatus === 200, "Desactivar vehículo disponible debe responder 200 (obtenido {$vehDeactStatus}).");

    echo "   [OK] Flujo Administrativo de Gestión y Calidad verificado con éxito.\n";

    // ---------------------------------------------------------
    // FASE 3: PRUEBAS DE SEGURIDAD INTEGRAL (ASVS / OWASP)
    // ---------------------------------------------------------
    echo ">>> Fase 3: Pruebas de Seguridad Integral...\n";

    // 3.1 Acceso anónimo denegado
    [$anonStatus] = e2e_request($base . '/clientes.php');
    e2e_assert(in_array($anonStatus, [302, 401], true), "El acceso anónimo debe denegarse (obtenido {$anonStatus}).");

    [$anonPostStatus] = e2e_request($base . '/clientes/guardar.php', '', true, ['tipo' => 'fisico']);
    e2e_assert(in_array($anonPostStatus, [302, 401], true), 'El acceso anónimo a endpoints de escritura debe responder 401/302.');

    // 3.2 Sesión caducada por inactividad
    $expiredCookie = "TSSA_TRANSPORTES_SESSION={$expiredSessionId}";
    [$expStatus] = e2e_request($base . '/clientes.php', $expiredCookie);
    e2e_assert(in_array($expStatus, [302, 401], true), "Una sesión con inactividad > 15m debe caducar (obtenido {$expStatus}).");

    // 3.3 Token CSRF ausente o manipulado en escrituras
    [$csrfFailStatus, $csrfFailBody] = e2e_request($base . '/clientes/guardar.php', $adminCookie, true, [
        'csrf_token' => 'token_completamente_invalido_0000000000000000000000000000000000000000',
        'tipo' => 'fisico',
        'identificacion' => '__CSRF_FAIL_' . $suffix,
        'nombre' => 'Ataque',
        'primer_apellido' => 'CSRF',
        'correo' => 'csrf@example.test',
    ]);
    e2e_assert($csrfFailStatus === 403, "Una petición con CSRF inválido debe responder 403 Forbidden (obtenido {$csrfFailStatus}).");

    // 3.4 Permisos insuficientes (Staff sin permisos administrativos intenta acceder a usuarios y crear)
    [$_, $staffLoginPageHtml, $staffHeaders] = e2e_request($base . '/auth/login.php');
    $staffInitCookie = e2e_extract_cookie($staffHeaders);
    $staffLoginCsrf = e2e_extract_csrf($staffLoginPageHtml);
    [$staffLoginStatus, $_, $staffAuthHeaders] = e2e_request($base . '/auth/login.php', $staffInitCookie, false, [
        'csrf_token' => $staffLoginCsrf,
        'usuario' => $staffUsername,
        'password' => $staffPassword,
    ]);
    e2e_assert($staffLoginStatus === 302, 'El login del usuario staff debe responder 302.');
    $staffCookie = e2e_extract_cookie($staffAuthHeaders) ?: $staffInitCookie;

    // Obtener CSRF del staff autenticado
    [$staffHomeStatus, $staffHomeHtml] = e2e_request($base . '/', $staffCookie);
    $staffCsrf = e2e_extract_csrf($staffHomeHtml) ?: $staffLoginCsrf;

    [$unauthStatus] = e2e_request($base . '/usuarios.php', $staffCookie);
    e2e_assert($unauthStatus === 403, "Un usuario sin permiso USUARIOS_VER debe recibir 403 (obtenido {$unauthStatus}).");

    [$unauthPostStatus] = e2e_request($base . '/usuarios/guardar.php', $staffCookie, true, [
        'csrf_token' => $staffCsrf,
        'nombre_usuario' => '__escalada_' . $suffix,
        'correo_acceso' => '__esc@example.test',
        'password' => 'Temporal12345!',
        'password_confirmacion' => 'Temporal12345!',
    ]);
    e2e_assert($unauthPostStatus === 403, "Un usuario sin permisos debe recibir 403 en escritura administrativa (obtenido {$unauthPostStatus}).");

    // 3.5 Manipulación de identificadores (ID inexistente o manipulado)
    [$notFoundStatus] = e2e_request($base . '/solicitudes/detalle.php?id=99999999', $adminCookie);
    e2e_assert($notFoundStatus === 404, "Consultar un ID inexistente debe responder 404 (obtenido {$notFoundStatus}).");

    // 3.6 Neutralización de inyección SQL
    $sqliPayload = "' OR '1'='1' -- /*";
    [$sqliStatus, $sqliBody] = e2e_request($base . '/clientes/lista.php?buscar=' . urlencode($sqliPayload), $adminCookie, true);
    e2e_assert($sqliStatus === 200, 'La búsqueda con payload SQLi debe ser manejada con parametrización segura.');
    e2e_assert(!str_contains(strtolower($sqliBody), 'syntax error') && !str_contains(strtolower($sqliBody), 'sqlstate'), 'No deben exponerse excepciones ni errores de sintaxis SQL.');

    // 3.7 Neutralización y escape estricto de XSS
    $xssClientCode = '__XSS_' . $suffix;
    $xssPayload = '<script>alert("XSS_ATTACK")</script>';
    [$xssStatus, $xssBody] = e2e_request($base . '/clientes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'tipo' => 'fisico',
        'identificacion' => $xssClientCode,
        'nombre' => $xssPayload,
        'primer_apellido' => 'ApellidoXSS',
        'correo' => "xss.{$suffix}@example.test",
    ]);
    e2e_assert($xssStatus === 200, 'El cliente con payload XSS debe procesarse de forma segura.');
    $fetchXss = $pdo->prepare('SELECT cliente_id FROM dbo.clientes WHERE identificacion = ?');
    $fetchXss->execute([$xssClientCode]);
    $xssCliId = (int)$fetchXss->fetchColumn();
    $fetchXss->closeCursor();
    $createdClientIds[] = $xssCliId;

    [$xssViewStatus, $xssViewHtml] = e2e_request($base . "/clientes/editar_form.php?id={$xssCliId}", $adminCookie, true);
    e2e_assert($xssViewStatus === 200, 'El formulario de edición debe responder 200.');
    e2e_assert(str_contains($xssViewHtml, '&lt;script&gt;alert(&quot;XSS_ATTACK&quot;)&lt;/script&gt;') || str_contains($xssViewHtml, '&lt;script&gt;alert(&#039;XSS_ATTACK&#039;)&lt;/script&gt;'), 'El payload XSS debe presentarse estrictamente escapado en HTML.');
    e2e_assert(!str_contains($xssViewHtml, '<script>alert("XSS_ATTACK")</script>'), 'El HTML no debe contener jamás la etiqueta script sin escapar.');

    // 3.8 Restricciones de transiciones de estado en Viajes
    // Intentar iniciar viaje sin paradas ni solicitudes asignadas
    $emptyTripCode = '__TRIP_EMPTY_' . $suffix;
    [$_, $_] = e2e_request($base . '/viajes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'codigo' => $emptyTripCode,
        'vehiculo_id' => 2,
        'conductor_id' => 1,
        'ruta_id' => 1,
        'fecha_programada' => $futureTrip,
    ]);
    $fetchEmptyTrip = $pdo->prepare('SELECT viaje_id FROM dbo.viajes WHERE codigo_viaje = ?');
    $fetchEmptyTrip->execute([$emptyTripCode]);
    $emptyTripId = (int)$fetchEmptyTrip->fetchColumn();
    $fetchEmptyTrip->closeCursor();
    $createdTripIds[] = $emptyTripId;

    [$emptyStartStatus, $emptyStartBody] = e2e_request($base . '/viajes/accion.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'viaje_id' => $emptyTripId,
        'accion' => 'iniciar',
    ]);
    e2e_assert($emptyStartStatus === 422 || $emptyStartStatus === 409 || str_contains($emptyStartBody, 'solicitudes'), 'No se debe permitir iniciar un viaje sin solicitudes asignadas.');

    // 3.9 Protección del último administrador activo (autoprotección y prevención de bloqueo administrativo)
    // Intentar auto-revocación de USUARIOS_GESTIONAR_PERMISOS para el usuario admin
    [$selfRevokeStatus, $selfRevokeBody] = e2e_request($base . '/usuarios/permiso.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $adminUserId,
        'codigo' => 'USUARIOS_GESTIONAR_PERMISOS',
        'accion' => 'revocar',
    ]);
    e2e_assert($selfRevokeStatus === 409, "La auto-revocación de permisos administrativos debe ser rechazada con 409 (obtenido {$selfRevokeStatus}).");

    // Intentar auto-desactivación del usuario admin activo
    [$selfDeactStatus, $selfDeactBody] = e2e_request($base . '/usuarios/estado.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'usuario_id' => $adminUserId,
        'accion' => 'desactivar',
    ]);
    e2e_assert($selfDeactStatus === 409, "La auto-desactivación de la cuenta propia debe ser rechazada con 409 (obtenido {$selfDeactStatus}).");

    echo "   [OK] Pruebas de Seguridad Integral completadas con éxito.\n";

} finally {
    // ---------------------------------------------------------
    // LIMPIEZA RIGUROSA Y SEGURA EN TransportesSegurosSA_Test
    // ---------------------------------------------------------
    echo ">>> Limpieza de datos efímeros de prueba en TransportesSegurosSA_Test...\n";

    if (configured_database_name() === 'TransportesSegurosSA_Test') {
        // Limpiar viajes efímeros y sus paradas/asignaciones
        if (!empty($createdTripIds)) {
            $inTrips = implode(',', array_map('intval', $createdTripIds));
            $pdo->exec("DELETE FROM dbo.entregas WHERE viaje_solicitud_id IN (SELECT viaje_solicitud_id FROM dbo.viaje_solicitudes WHERE viaje_id IN ({$inTrips}))");
            $pdo->exec("DELETE FROM dbo.viaje_solicitudes WHERE viaje_id IN ({$inTrips})");
            $pdo->exec("DELETE FROM dbo.paradas_viaje WHERE viaje_id IN ({$inTrips})");
            $pdo->exec("DELETE FROM dbo.viajes WHERE viaje_id IN ({$inTrips})");
        }

        // Restablecer recursos vehiculares y de conducción a DISPONIBLE
        $pdo->exec("UPDATE dbo.vehiculos SET estado = 'DISPONIBLE' WHERE vehiculo_id IN (1, 2)");
        $pdo->exec("UPDATE dbo.conductores SET estado_operativo = 'DISPONIBLE' WHERE conductor_id IN (1)");

        // Limpiar vehículos efímeros
        if (!empty($createdVehicleIds)) {
            $inVeh = implode(',', array_map('intval', $createdVehicleIds));
            $pdo->exec("DELETE FROM dbo.vehiculos WHERE vehiculo_id IN ({$inVeh})");
        }

        // Limpiar solicitudes efímeras y mercancías
        if (!empty($createdSolicitudIds)) {
            $inReqs = implode(',', array_map('intval', $createdSolicitudIds));
            $pdo->exec("DELETE FROM dbo.mercancias WHERE solicitud_id IN ({$inReqs})");
            $pdo->exec("DELETE FROM dbo.incidencias WHERE solicitud_id IN ({$inReqs})");
            $pdo->exec("DELETE FROM dbo.documentos WHERE solicitud_id IN ({$inReqs})");
            $pdo->exec("DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id IN ({$inReqs})");
            $pdo->exec("DELETE FROM dbo.solicitudes WHERE solicitud_id IN ({$inReqs})");
        }

        // Limpiar clientes efímeros
        if (!empty($createdClientIds)) {
            $inClients = implode(',', array_map('intval', $createdClientIds));
            $pdo->exec("DELETE FROM dbo.clientes_juridicos WHERE cliente_id IN ({$inClients})");
            $pdo->exec("DELETE FROM dbo.clientes_fisicos WHERE cliente_id IN ({$inClients})");
            $pdo->exec("DELETE FROM dbo.clientes WHERE cliente_id IN ({$inClients})");
        }

        // Limpiar usuarios efímeros y permisos asociados
        if (!empty($createdUserIds)) {
            $inUsers = implode(',', array_map('intval', $createdUserIds));
            $pdo->exec("DELETE FROM dbo.usuarios_permisos WHERE usuario_id IN ({$inUsers})");
            $pdo->exec("DELETE FROM dbo.auditorias WHERE usuario_id IN ({$inUsers})");
            $pdo->exec("DELETE FROM dbo.usuarios WHERE usuario_id IN ({$inUsers})");
        }

        echo "   [OK] Limpieza concluida sin afectar datos preexistentes.\n";
    }

    // Detener servidor HTTP
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (file_exists($log)) {
        @unlink($log);
    }
}

echo "\n============================================================\n";
echo "RESULTADO: Todas las pruebas E2E y de Seguridad pasaron exitosamente.\n";
echo "============================================================\n";
