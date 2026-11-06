<?php
declare(strict_types=1);

/**
 * Prueba funcional HTTP del módulo de Flota y Datos Maestros:
 * ubicaciones, catálogos (mercancía, incidencia, documento), vehículos,
 * conductores y rutas; verificación de selectores operativos activos en
 * solicitudes, viajes, incidencias y documentos; permisos granulares, CSRF,
 * validaciones, duplicados y bloqueos por viaje/ruta activa.
 * Opera exclusivamente sobre registros efímeros en TransportesSegurosSA_Test.
 */
putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';

function mh_fail(string $message): never
{
    fwrite(STDERR, "FALLO: {$message}\n");
    exit(1);
}

function mh_assert(bool $condition, string $message): void
{
    if (!$condition) {
        mh_fail($message);
    }
}

function mh_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        mh_fail('No se pudo reservar puerto local.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

/** @return array{0:int,1:string,2:array<int,string>} */
function mh_request(string $url, string $cookie, bool $htmx = true, ?array $postFields = null): array
{
    $headers = "Cookie: {$cookie}\r\nAccept: text/html\r\n" . ($htmx ? "HX-Request: true\r\n" : '');
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

if (configured_database_name() !== 'TransportesSegurosSA_Test') {
    mh_fail('Se requiere TSSA_DB_DATABASE=TransportesSegurosSA_Test.');
}
$pdo = db();
require_test_database($pdo);

$suffix = bin2hex(random_bytes(4));
$ubiOrigenName = "__HTTP_UBI_OR_{$suffix}";
$ubiDestinoName = "__HTTP_UBI_DE_{$suffix}";
$tmName = "__HTTP_TM_{$suffix}";
$tiName = "__HTTP_TI_{$suffix}";
$tdName = "__HTTP_TD_{$suffix}";
$placa = '__HV' . strtoupper(substr($suffix, 0, 6));
$placaBusy = '__HB' . strtoupper(substr($suffix, 0, 6));
$condIdent = "__HTTP_CI_{$suffix}";
$condLic = '__HL_' . strtoupper($suffix);
$condBusyIdent = "__HTTP_CB_{$suffix}";
$condBusyLic = '__HB_' . strtoupper($suffix);
$rutaName = "__HTTP_RUTA_{$suffix}";
$rutaBusyName = "__HTTP_RBUSY_{$suffix}";
$limitedUser = '__http_md_lim_' . $suffix;

$cleanup = [
    'viaje_id' => null,
    'ruta_ids' => [],
    'vehiculo_ids' => [],
    'conductor_ids' => [],
    'ubicacion_ids' => [],
    'tm_id' => null,
    'ti_id' => null,
    'td_id' => null,
    'user_id' => null,
];

$sessionName = 'TSSA_TRANSPORTES_SESSION';
$adminSessionId = bin2hex(random_bytes(24));
$adminCsrf = bin2hex(random_bytes(32));
$limitedSessionId = bin2hex(random_bytes(24));
$limitedCsrf = bin2hex(random_bytes(32));

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Crear usuario limitado únicamente con UBICACIONES_VER
$insU = $pdo->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) OUTPUT INSERTED.usuario_id VALUES (?, ?, ?, 1)');
$insU->execute([$limitedUser, "{$limitedUser}@example.test", password_hash('ClaveLimitada2026!' . $suffix, PASSWORD_BCRYPT)]);
$cleanup['user_id'] = (int)$insU->fetchColumn();
$insU->closeCursor();

$grantU = $pdo->prepare("INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo = N'UBICACIONES_VER'");
$grantU->execute([$cleanup['user_id']]);
$grantU->closeCursor();

ini_set('session.use_strict_mode', '0');
foreach ([
    [$adminSessionId, 1, $adminCsrf],
    [$limitedSessionId, $cleanup['user_id'], $limitedCsrf],
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

$port = mh_port();
$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'tssa-master-http-');
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
    mh_fail('No se pudo iniciar servidor HTTP local.');
}

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
    $limitedCookie = "{$sessionName}={$limitedSessionId}";
    $futureLicense = gmdate('Y-m-d', time() + 86400 * 365);

    // 1. Alta HTTP de ubicaciones, catálogos, vehículos, conductores y rutas
    foreach ([
        [$ubiOrigenName, 'Cartago, Parque Industrial', '9.864440', '-83.919440'],
        [$ubiDestinoName, 'Limón, Terminal Portuaria', '9.990740', '-83.035960'],
    ] as [$uName, $uDir, $uLat, $uLon]) {
        [$status, $body] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
            'csrf_token' => $adminCsrf,
            'entidad' => 'ubicacion',
            'nombre' => $uName,
            'direccion' => $uDir,
            'latitud' => $uLat,
            'longitud' => $uLon,
        ]);
        mh_assert($status === 200 && str_contains($body, 'registrado correctamente'), "Alta de ubicación {$uName} debe responder 200.");
    }

    $stUbi = $pdo->prepare('SELECT ubicacion_id, nombre FROM dbo.ubicaciones WHERE nombre IN (?, ?)');
    $stUbi->execute([$ubiOrigenName, $ubiDestinoName]);
    $ubiMap = [];
    foreach ($stUbi->fetchAll() as $r) {
        $uid = (int)$r['ubicacion_id'];
        $cleanup['ubicacion_ids'][] = $uid;
        $ubiMap[(string)$r['nombre']] = $uid;
    }
    $stUbi->closeCursor();
    $origenId = $ubiMap[$ubiOrigenName] ?? 0;
    $destinoId = $ubiMap[$ubiDestinoName] ?? 0;
    mh_assert($origenId > 0 && $destinoId > 0, 'Ambas ubicaciones deben haberse persistido.');

    foreach ([
        ['tipo_mercancia', $tmName, 'Mercancía delicada'],
        ['tipo_incidencia', $tiName, 'Bloqueo vial'],
        ['tipo_documento', $tdName, 'Certificado sanitario'],
    ] as [$catEntity, $catName, $catDesc]) {
        [$status, $body] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
            'csrf_token' => $adminCsrf,
            'entidad' => $catEntity,
            'nombre' => $catName,
            'descripcion' => $catDesc,
        ]);
        mh_assert($status === 200 && str_contains($body, 'registrado correctamente'), "Alta de {$catEntity} debe responder 200.");
    }

    $stTm = $pdo->prepare('SELECT tipo_mercancia_id FROM dbo.tipos_mercancia WHERE nombre = ?');
    $stTm->execute([$tmName]);
    $cleanup['tm_id'] = (int)$stTm->fetchColumn();
    $stTm->closeCursor();

    $stTi = $pdo->prepare('SELECT tipo_incidencia_id FROM dbo.tipos_incidencia WHERE nombre = ?');
    $stTi->execute([$tiName]);
    $cleanup['ti_id'] = (int)$stTi->fetchColumn();
    $stTi->closeCursor();

    $stTd = $pdo->prepare('SELECT tipo_documento_id FROM dbo.tipos_documento WHERE nombre = ?');
    $stTd->execute([$tdName]);
    $cleanup['td_id'] = (int)$stTd->fetchColumn();
    $stTd->closeCursor();

    // Crear 2 vehículos y 2 conductores (uno para editar/desactivar y otro para comprometer en viaje)
    foreach ([$placa, $placaBusy] as $p) {
        [$status, $body] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
            'csrf_token' => $adminCsrf,
            'entidad' => 'vehiculo',
            'placa' => $p,
            'marca' => 'Freightliner',
            'modelo' => 'M2 106',
            'anio' => '2025',
            'capacidad_carga_kg' => '9500.00',
            'capacidad_volumen_m3' => '42.000',
            'kilometraje_actual' => '1500.0',
            'estado' => 'DISPONIBLE',
        ]);
        mh_assert($status === 200 && str_contains($body, 'registrado correctamente'), "Alta de vehículo {$p} debe responder 200.");
    }

    $stVeh = $pdo->prepare('SELECT vehiculo_id, placa FROM dbo.vehiculos WHERE placa IN (?, ?)');
    $stVeh->execute([$placa, $placaBusy]);
    $vehMap = [];
    foreach ($stVeh->fetchAll() as $r) {
        $vid = (int)$r['vehiculo_id'];
        $cleanup['vehiculo_ids'][] = $vid;
        $vehMap[(string)$r['placa']] = $vid;
    }
    $stVeh->closeCursor();
    $vehId = $vehMap[$placa] ?? 0;
    $vehBusyId = $vehMap[$placaBusy] ?? 0;

    foreach ([
        [$condIdent, $condLic, 'Esteban'],
        [$condBusyIdent, $condBusyLic, 'Mauricio'],
    ] as [$cId, $cLic, $cNom]) {
        [$status, $body] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
            'csrf_token' => $adminCsrf,
            'entidad' => 'conductor',
            'identificacion' => $cId,
            'nombre' => $cNom,
            'primer_apellido' => 'Quesada',
            'segundo_apellido' => 'Araya',
            'telefono' => '8888-5555',
            'correo' => strtolower($cNom) . ".{$suffix}@example.test",
            'numero_licencia' => $cLic,
            'fecha_vencimiento_licencia' => $futureLicense,
            'estado_operativo' => 'DISPONIBLE',
        ]);
        mh_assert($status === 200 && str_contains($body, 'registrado correctamente'), "Alta de conductor {$cId} debe responder 200.");
    }

    $stCond = $pdo->prepare('SELECT conductor_id, identificacion FROM dbo.conductores WHERE identificacion IN (?, ?)');
    $stCond->execute([$condIdent, $condBusyIdent]);
    $condMap = [];
    foreach ($stCond->fetchAll() as $r) {
        $cid = (int)$r['conductor_id'];
        $cleanup['conductor_ids'][] = $cid;
        $condMap[(string)$r['identificacion']] = $cid;
    }
    $stCond->closeCursor();
    $condId = $condMap[$condIdent] ?? 0;
    $condBusyId = $condMap[$condBusyIdent] ?? 0;

    foreach ([$rutaName, $rutaBusyName] as $rName) {
        [$status, $body] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
            'csrf_token' => $adminCsrf,
            'entidad' => 'ruta',
            'nombre' => $rName,
            'origen_id' => $origenId,
            'destino_id' => $destinoId,
            'distancia_km' => '152.40',
            'duracion_estimada_min' => '195',
        ]);
        mh_assert($status === 200 && str_contains($body, 'registrado correctamente'), "Alta de ruta {$rName} debe responder 200.");
    }

    $stRuta = $pdo->prepare('SELECT ruta_id, nombre FROM dbo.rutas WHERE nombre IN (?, ?)');
    $stRuta->execute([$rutaName, $rutaBusyName]);
    $rutaMap = [];
    foreach ($stRuta->fetchAll() as $r) {
        $rid = (int)$r['ruta_id'];
        $cleanup['ruta_ids'][] = $rid;
        $rutaMap[(string)$r['nombre']] = $rid;
    }
    $stRuta->closeCursor();
    $rutaId = $rutaMap[$rutaName] ?? 0;
    $rutaBusyId = $rutaMap[$rutaBusyName] ?? 0;

    // 2. Verificar presencia en selectores operativos mientras están activos y disponibles
    [$status, $solForm] = mh_request($base . '/solicitudes/form.php', $adminCookie, true);
    mh_assert($status === 200 && str_contains($solForm, $ubiOrigenName) && str_contains($solForm, $tmName), 'Formulario de solicitud debe listar ubicaciones y tipos de mercancía activos.');

    [$status, $viajeForm] = mh_request($base . '/viajes/form.php', $adminCookie, true);
    mh_assert($status === 200 && str_contains($viajeForm, $placa) && str_contains($viajeForm, 'Esteban Quesada') && str_contains($viajeForm, $rutaName), 'Formulario de viaje debe listar vehículo, conductor y ruta disponibles.');

    [$status, $incForm] = mh_request($base . '/entregas/incidencia_form.php?accion=registrar', $adminCookie, true);
    mh_assert($status === 200 && str_contains($incForm, $tiName), 'Formulario de incidencia debe listar tipo de incidencia activo.');

    [$status, $docForm] = mh_request($base . '/entregas/documento_form.php', $adminCookie, true);
    mh_assert($status === 200 && str_contains($docForm, $tdName), 'Formulario de documento debe listar tipo de documento activo.');

    // 3. Validaciones de datos inválidos (422) y duplicados (409)
    [$status] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'ubicacion',
        'nombre' => '__UBI_INV_' . $suffix,
        'direccion' => 'Dirección',
        'latitud' => '9.95',
        'longitud' => '',
    ]);
    mh_assert($status === 422, 'Coordenadas incompletas deben responder 422.');

    [$status] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'vehiculo',
        'placa' => '__INV' . substr($suffix, 0, 5),
        'marca' => 'Volvo',
        'modelo' => 'FMX',
        'anio' => '2025',
        'capacidad_carga_kg' => '10000',
        'kilometraje_actual' => '100',
        'estado' => 'ASIGNADO',
    ]);
    mh_assert($status === 422, 'Estado ASIGNADO manual debe responder 422.');

    [$status] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'conductor',
        'identificacion' => '__CINV_' . $suffix,
        'nombre' => 'Mario',
        'primer_apellido' => 'Mora',
        'numero_licencia' => '__LINV_' . $suffix,
        'fecha_vencimiento_licencia' => '2021-01-01',
        'estado_operativo' => 'DISPONIBLE',
    ]);
    mh_assert($status === 422, 'Licencia vencida debe responder 422.');

    [$status] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'ruta',
        'nombre' => '__RINV_' . $suffix,
        'origen_id' => $origenId,
        'destino_id' => $origenId,
        'distancia_km' => '10',
        'duracion_estimada_min' => '20',
    ]);
    mh_assert($status === 422, 'Ruta con origen igual a destino debe responder 422.');

    [$status] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'vehiculo',
        'placa' => $placa,
        'marca' => 'Dup',
        'modelo' => 'Dup',
        'anio' => '2025',
        'capacidad_carga_kg' => '5000',
        'kilometraje_actual' => '10',
        'estado' => 'DISPONIBLE',
    ]);
    mh_assert($status === 409, 'Placa duplicada debe responder 409.');

    // Kilometraje regresivo en edición -> 422
    [$status] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'vehiculo',
        'id' => $vehId,
        'marca' => 'Freightliner',
        'modelo' => 'M2 106',
        'anio' => '2025',
        'capacidad_carga_kg' => '9500',
        'kilometraje_actual' => '1200.0',
        'estado' => 'DISPONIBLE',
    ]);
    mh_assert($status === 422, 'Kilometraje menor al actual debe responder 422.');

    // 4. Edición válida a MANTENIMIENTO y exclusión inmediata del selector de viajes
    [$status, $body] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'vehiculo',
        'id' => $vehId,
        'marca' => 'Freightliner',
        'modelo' => 'M2 106 Plus',
        'anio' => '2025',
        'capacidad_carga_kg' => '9800.00',
        'capacidad_volumen_m3' => '44.000',
        'kilometraje_actual' => '1650.5',
        'estado' => 'MANTENIMIENTO',
    ]);
    mh_assert($status === 200 && str_contains($body, 'actualizado correctamente'), 'Edición de vehículo a MANTENIMIENTO debe responder 200.');

    [$status, $viajeFormAfterMaint] = mh_request($base . '/viajes/form.php', $adminCookie, true);
    mh_assert($status === 200 && !str_contains($viajeFormAfterMaint, $placa), 'Vehículo en MANTENIMIENTO no debe aparecer como disponible en nuevo viaje.');

    // 5. Bloqueos por referencias y viaje activo -> 409
    [$status] = mh_request($base . '/flota/desactivar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'ubicacion',
        'id' => $origenId,
    ]);
    mh_assert($status === 409, 'Ubicación usada por ruta activa debe rechazar desactivación con 409.');

    // Crear viaje planificado sobre vehBusyId, condBusyId y rutaBusyId
    $ctx = $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=1");
    $ctx->execute();
    $ctx->closeCursor();
    $vjStmt = $pdo->prepare('DECLARE @vid INT; EXEC dbo.sp_crear_viaje @codigo=?, @vehiculo_id=?, @conductor_id=?, @ruta_id=?, @fecha_programada=?, @viaje_id=@vid OUTPUT; SELECT @vid AS viaje_id;');
    $vjStmt->execute(['__HTTP_VJ_' . $suffix, $vehBusyId, $condBusyId, $rutaBusyId, gmdate('Y-m-d H:i:s', time() + 86400)]);
    do {
        if ($vjStmt->columnCount() > 0) {
            $r = $vjStmt->fetch();
            if (is_array($r) && isset($r['viaje_id'])) {
                $cleanup['viaje_id'] = (int)$r['viaje_id'];
            }
        }
    } while ($vjStmt->nextRowset());
    $vjStmt->closeCursor();

    foreach ([
        ['vehiculo', $vehBusyId],
        ['conductor', $condBusyId],
        ['ruta', $rutaBusyId],
    ] as [$busyEntity, $busyId]) {
        [$status] = mh_request($base . '/flota/desactivar.php', $adminCookie, true, [
            'csrf_token' => $adminCsrf,
            'entidad' => $busyEntity,
            'id' => $busyId,
        ]);
        mh_assert($status === 409, "Desactivar {$busyEntity} comprometido en viaje planificado debe responder 409.");
    }

    // 6. Desactivación válida de recursos libres y exclusión de selectores operativos
    foreach ([
        ['vehiculo', $vehId],
        ['conductor', $condId],
        ['ruta', $rutaId],
        ['tipo_mercancia', $cleanup['tm_id']],
        ['tipo_incidencia', $cleanup['ti_id']],
        ['tipo_documento', $cleanup['td_id']],
    ] as [$freeEntity, $freeId]) {
        [$status, $modalHtml] = mh_request($base . "/flota/desactivar_form.php?entidad={$freeEntity}&id={$freeId}", $adminCookie, true);
        mh_assert($status === 200 && str_contains($modalHtml, 'Desactivar'), "Modal de desactivación de {$freeEntity} debe responder 200.");

        [$status, $resBody] = mh_request($base . '/flota/desactivar.php', $adminCookie, true, [
            'csrf_token' => $adminCsrf,
            'entidad' => $freeEntity,
            'id' => $freeId,
        ]);
        mh_assert($status === 200 && str_contains($resBody, 'desactivado correctamente'), "Desactivación de {$freeEntity} debe responder 200.");
    }

    [$status, $solFormAfter] = mh_request($base . '/solicitudes/form.php', $adminCookie, true);
    mh_assert($status === 200 && !str_contains($solFormAfter, $tmName), 'Tipo de mercancía desactivado no debe aparecer en selector de solicitud.');

    [$status, $viajeFormAfter] = mh_request($base . '/viajes/form.php', $adminCookie, true);
    mh_assert($status === 200 && !str_contains($viajeFormAfter, 'Esteban Quesada') && !str_contains($viajeFormAfter, $rutaName), 'Conductor y ruta desactivados no deben aparecer en selector de viaje.');

    [$status, $incFormAfter] = mh_request($base . '/entregas/incidencia_form.php?accion=registrar', $adminCookie, true);
    mh_assert($status === 200 && !str_contains($incFormAfter, $tiName), 'Tipo de incidencia desactivado no debe aparecer en selector.');

    [$status, $docFormAfter] = mh_request($base . '/entregas/documento_form.php', $adminCookie, true);
    mh_assert($status === 200 && !str_contains($docFormAfter, $tdName), 'Tipo de documento desactivado no debe aparecer en selector.');

    // 7. Navegación por permisos granulares, CSRF inválido y registro inexistente
    [$status, $limitedPage] = mh_request($base . '/flota.php', $limitedCookie, false);
    mh_assert($status === 200 && str_contains($limitedPage, 'Ubicaciones'), 'Usuario con sólo UBICACIONES_VER debe abrir directamente la sección Ubicaciones.');
    mh_assert(
        !str_contains($limitedPage, 'seccion=vehiculos')
        && !str_contains($limitedPage, 'seccion=rutas')
        && !str_contains($limitedPage, 'Nueva ubicación'),
        'Usuario limitado no debe ver pestañas no autorizadas ni botón de creación.'
    );

    [$status] = mh_request($base . '/flota.php?seccion=vehiculos', $limitedCookie, false);
    mh_assert($status === 403, 'Acceso a sección Vehículos sin FLOTAS_VER debe responder 403.');

    [$status] = mh_request($base . '/flota/guardar.php', $limitedCookie, true, [
        'csrf_token' => $limitedCsrf,
        'entidad' => 'ubicacion',
        'nombre' => '__HACK_' . $suffix,
        'direccion' => 'Sin permiso',
    ]);
    mh_assert($status === 403, 'POST guardar sin permiso de gestión debe responder 403.');

    [$status] = mh_request($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => 'invalido',
        'entidad' => 'ubicacion',
        'nombre' => '__CSRF_' . $suffix,
        'direccion' => 'CSRF inválido',
    ]);
    mh_assert($status === 403, 'POST con CSRF inválido debe responder 403.');

    [$status] = mh_request($base . '/flota/desactivar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'vehiculo',
        'id' => 99999999,
    ]);
    mh_assert($status === 404, 'Desactivar registro maestro inexistente debe responder 404.');

    fwrite(STDOUT, "OK: pruebas funcionales HTTP de Flota y Datos Maestros completadas en TransportesSegurosSA_Test.\n");
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($process);
    proc_close($process);
    @unlink($log);

    foreach ([$adminSessionId, $limitedSessionId] as $sid) {
        session_name($sessionName);
        session_id($sid);
        session_start();
        $_SESSION = [];
        session_destroy();
    }

    // Limpiar únicamente los registros efímeros creados por esta prueba en Test
    require_test_database($pdo);
    if ($cleanup['viaje_id'] !== null) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'viajes' AND registro_id = ?")->execute([(string)$cleanup['viaje_id']]);
        $pdo->prepare('DELETE FROM dbo.viajes WHERE viaje_id = ?')->execute([$cleanup['viaje_id']]);
    }
    foreach ($cleanup['ruta_ids'] as $rid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'rutas' AND registro_id = ?")->execute([(string)$rid]);
        $pdo->prepare('DELETE FROM dbo.rutas WHERE ruta_id = ?')->execute([$rid]);
    }
    foreach ($cleanup['vehiculo_ids'] as $vid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'vehiculos' AND registro_id = ?")->execute([(string)$vid]);
        $pdo->prepare('DELETE FROM dbo.vehiculos WHERE vehiculo_id = ?')->execute([$vid]);
    }
    foreach ($cleanup['conductor_ids'] as $cid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'conductores' AND registro_id = ?")->execute([(string)$cid]);
        $pdo->prepare('DELETE FROM dbo.conductores WHERE conductor_id = ?')->execute([$cid]);
    }
    foreach ($cleanup['ubicacion_ids'] as $uid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'ubicaciones' AND registro_id = ?")->execute([(string)$uid]);
        $pdo->prepare('DELETE FROM dbo.ubicaciones WHERE ubicacion_id = ?')->execute([$uid]);
    }
    if ($cleanup['tm_id']) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'tipos_mercancia' AND registro_id = ?")->execute([(string)$cleanup['tm_id']]);
        $pdo->prepare('DELETE FROM dbo.tipos_mercancia WHERE tipo_mercancia_id = ?')->execute([$cleanup['tm_id']]);
    }
    if ($cleanup['ti_id']) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'tipos_incidencia' AND registro_id = ?")->execute([(string)$cleanup['ti_id']]);
        $pdo->prepare('DELETE FROM dbo.tipos_incidencia WHERE tipo_incidencia_id = ?')->execute([$cleanup['ti_id']]);
    }
    if ($cleanup['td_id']) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'tipos_documento' AND registro_id = ?")->execute([(string)$cleanup['td_id']]);
        $pdo->prepare('DELETE FROM dbo.tipos_documento WHERE tipo_documento_id = ?')->execute([$cleanup['td_id']]);
    }
    if ($cleanup['user_id']) {
        $pdo->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([$cleanup['user_id']]);
        $pdo->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([$cleanup['user_id']]);
    }
}
