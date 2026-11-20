<?php
declare(strict_types=1);

/**
 * Suite funcional HTTP, transaccional y de seguridad: Ubicaciones híbridas en Nueva solicitud.
 * Opera EXCLUSIVAMENTE en TransportesSegurosSA_Test con servidor PHP aislado, cuentas y registros efímeros.
 */

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';

function hyb_fail(string $message): never
{
    throw new RuntimeException("FALLO HYBRID SUITE: {$message}");
}

function hyb_assert(bool $condition, string $message): void
{
    if (!$condition) {
        hyb_fail($message);
    }
}

function hyb_free_port(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        hyb_fail("No se pudo reservar puerto efímero: {$errstr}");
    }
    $name = (string)stream_socket_get_name($sock, false);
    fclose($sock);
    return (int)substr($name, strrpos($name, ':') + 1);
}

/** @return array{process:resource,log:string,port:int,base:string} */
function hyb_start_server(string $docRoot, string $sessDir): array
{
    $port = hyb_free_port();
    $base = "http://127.0.0.1:{$port}";
    $log = (string)tempnam(sys_get_temp_dir(), 'tssa-hyb-srv-');
    $env = array_merge(getenv(), [
        'TSSA_DB_DATABASE' => 'TransportesSegurosSA_Test',
        'TSSA_APP_URL' => $base,
    ]);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $sessDir, '-S', "127.0.0.1:{$port}", '-t', $docRoot],
        [0 => ['file', 'NUL', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        dirname(__DIR__),
        $env
    );
    hyb_assert(is_resource($proc), 'No se pudo iniciar servidor PHP aislado.');

    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $s = @fsockopen('127.0.0.1', $port);
        if ($s !== false) {
            fclose($s);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    hyb_assert($ready, "El servidor PHP en {$base} no respondió.");
    return ['process' => $proc, 'log' => $log, 'port' => $port, 'base' => $base];
}

function hyb_stop_server(?array $srv): void
{
    if (!is_array($srv)) {
        return;
    }
    if (isset($srv['process']) && is_resource($srv['process'])) {
        $st = proc_get_status($srv['process']);
        if (!empty($st['pid']) && strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            @exec('taskkill /F /T /PID ' . (int)$st['pid'] . ' 2>&1');
        } else {
            @proc_terminate($srv['process']);
        }
        @proc_close($srv['process']);
    }
    if (!empty($srv['log']) && file_exists($srv['log'])) {
        @unlink($srv['log']);
    }
}

/** @return array{0:int,1:string,2:array<string,string>} */
function hyb_http(string $url, string $cookie, ?array $postFields = null, bool $htmx = true): array
{
    $ch = curl_init($url);
    hyb_assert($ch !== false, 'No se pudo inicializar cURL.');
    $respHeaders = [];
    $reqHeaders = [
        'Cookie: ' . $cookie,
        'Connection: close',
    ];
    if ($htmx) {
        $reqHeaders[] = 'HX-Request: true';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $k = strtolower(trim(substr($line, 0, $pos)));
                $v = trim(substr($line, $pos + 1));
                $respHeaders[$k] = $v;
            }
            return strlen($line);
        },
    ]);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, is_string($body) ? $body : '', $respHeaders];
}

function hyb_extract_redirect_id(array $headers): int
{
    $loc = $headers['hx-redirect'] ?? $headers['location'] ?? '';
    if (preg_match('/[?&]id=(\d+)/', $loc, $m) === 1) {
        return (int)$m[1];
    }
    return 0;
}

// =========================================================================
// PREFLIGHT ESTRICTO: SOLO TransportesSegurosSA_Test
// =========================================================================
hyb_assert(configured_database_name() === 'TransportesSegurosSA_Test', 'TSSA_DB_DATABASE debe ser TransportesSegurosSA_Test.');
$pdo = db();
require_test_database($pdo);
$dbName = (string)$pdo->query('SELECT DB_NAME()')->fetchColumn();
hyb_assert($dbName === 'TransportesSegurosSA_Test', "DB_NAME() inesperado: {$dbName}");
echo "Preflight DB_NAME() verificado: {$dbName}\n";

$suffix = bin2hex(random_bytes(4));
$sessionName = session_cookie_name();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tssa-hyb-sess-' . $suffix;
mkdir($sessDir, 0700, true);

$cleanup = [
    'viaje_ids' => [],
    'ruta_ids' => [],
    'vehiculo_ids' => [],
    'conductor_ids' => [],
    'solicitud_ids' => [],
    'ubicacion_ids' => [],
    'cliente_ids' => [],
    'usuario_ids' => [],
    'session_files' => [],
    'temp_dirs' => [$sessDir],
];

$makeSession = static function (int $uid) use ($sessionName, $sessDir, &$cleanup): array {
    $sid = 'hyb' . bin2hex(random_bytes(10));
    $csrf = bin2hex(random_bytes(16));
    $now = time();
    $encoded = sprintf(
        'usuario_id|i:%d;authenticated_at|i:%d;last_activity_at|i:%d;csrf_token|s:%d:"%s";',
        $uid,
        $now,
        $now,
        strlen($csrf),
        $csrf
    );
    $file = rtrim($sessDir, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    $written = file_put_contents($file, $encoded);
    hyb_assert($written !== false && file_exists($file), 'No se pudo inicializar sesión aislada.');
    $cleanup['session_files'][] = $file;
    return ["{$sessionName}={$sid}", $csrf];
};

$createActor = static function (string $prefix, array $permCodes) use ($pdo, $suffix, &$cleanup): int {
    $uname = "__hyb_{$prefix}_{$suffix}";
    $email = "{$uname}@example.test";
    $hash = password_hash('ClaveSeguraHyb!' . $suffix, PASSWORD_DEFAULT);
    $st = $pdo->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) VALUES (?, ?, ?, 1); SELECT CAST(SCOPE_IDENTITY() AS INT);');
    $st->execute([$uname, $email, $hash]);
    $st->nextRowset();
    $uid = (int)$st->fetchColumn();
    $st->closeCursor();
    $cleanup['usuario_ids'][] = $uid;

    if ($permCodes) {
        $in = implode(',', array_fill(0, count($permCodes), '?'));
        $stP = $pdo->prepare("INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo IN ({$in})");
        $stP->execute(array_merge([$uid], $permCodes));
    }
    return $uid;
};

$srv1 = null;
$srv2 = null;
$exitCode = 0;

try {
    $publicDir = realpath(__DIR__ . '/../public');
    hyb_assert(is_string($publicDir), 'Directorio public no encontrado.');
    $srv1 = hyb_start_server($publicDir, $sessDir);
    $srv2 = hyb_start_server($publicDir, $sessDir);
    $base = $srv1['base'];

    // Actores efímeros con permisos acotados
    $adminId = $createActor('adm', [
        'SOLICITUDES_VER', 'SOLICITUDES_CREAR',
        'CLIENTES_VER', 'CLIENTES_CREAR',
        'UBICACIONES_VER', 'UBICACIONES_GESTIONAR',
        'RUTAS_VER', 'RUTAS_GESTIONAR',
        'FLOTAS_VER', 'FLOTAS_GESTIONAR',
        'VIAJES_VER', 'VIAJES_CREAR', 'VIAJES_PARADAS', 'VIAJES_ASIGNAR',
        'CATALOGOS_VER',
    ]);
    $opSoloSolId = $createActor('op', ['SOLICITUDES_VER', 'SOLICITUDES_CREAR', 'CLIENTES_VER', 'UBICACIONES_VER', 'CATALOGOS_VER']);
    $sinPermId = $createActor('nop', ['SOLICITUDES_VER']);

    [$admCookie, $admCsrf] = $makeSession($adminId);
    [$opCookie, $opCsrf] = $makeSession($opSoloSolId);
    [$nopCookie, $nopCsrf] = $makeSession($sinPermId);

    // Sembrar cliente físico, cliente jurídico, ubicaciones frecuentes (incluyendo par en mismo sitio y 1 inactiva)
    $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?")->execute([$adminId]);
    $tipoMercId = (int)$pdo->query('SELECT TOP 1 tipo_mercancia_id FROM dbo.tipos_mercancia WHERE activo = 1 ORDER BY tipo_mercancia_id')->fetchColumn();
    hyb_assert($tipoMercId > 0, 'Debe existir al menos un tipo de mercancía activo.');

    $stCf = $pdo->prepare("DECLARE @cid INT; EXEC dbo.sp_crear_cliente_fisico @identificacion=?, @nombre=N'Carlos', @primer_apellido=N'Méndez', @segundo_apellido=N'Rojas', @correo=?, @telefono=N'8888-0001', @cliente_id=@cid OUTPUT; SELECT @cid;");
    $stCf->execute(["1-9900-{$suffix}", "hyb.fis.{$suffix}@example.test"]);
    $cliFisId = (int)$stCf->fetchColumn();
    $stCf->closeCursor();
    $cleanup['cliente_ids'][] = $cliFisId;

    $stCj = $pdo->prepare("DECLARE @cid INT; EXEC dbo.sp_crear_cliente_juridico @identificacion=?, @razon_social=?, @nombre_comercial=?, @representante_legal=N'Laura Mora', @correo=?, @cliente_id=@cid OUTPUT; SELECT @cid;");
    $stCj->execute(["3-101-{$suffix}", "Logística Hyb {$suffix} S.A.", "Logística Hyb {$suffix}", "hyb.jur.{$suffix}@example.test"]);
    $cliJurId = (int)$stCj->fetchColumn();
    $stCj->closeCursor();
    $cleanup['cliente_ids'][] = $cliJurId;

    $mkUbi = static function (string $name, string $dir, ?string $lat, ?string $lon) use ($pdo, &$cleanup): int {
        $st = $pdo->prepare('DECLARE @id INT; EXEC dbo.sp_crear_ubicacion @nombre=?, @direccion=?, @latitud=?, @longitud=?, @ubicacion_id=@id OUTPUT; SELECT @id;');
        $st->execute([$name, $dir, $lat, $lon]);
        $id = (int)$st->fetchColumn();
        $st->closeCursor();
        $cleanup['ubicacion_ids'][] = $id;
        return $id;
    };

    $uBaseA = $mkUbi("HYB Bodega A {$suffix}", 'Alajuela centro, bodega A', '10.016250', '-84.211630');
    $uBaseASame = $mkUbi("HYB Bodega A Duplicada {$suffix}", 'Alajuela centro, mismo punto', '10.016255', '-84.211632');
    $uBaseB = $mkUbi("HYB Puerto Caldera {$suffix}", 'Puntarenas, muelle sur', '9.911200', '-84.718500');
    $uInactiva = $mkUbi("HYB Inactiva {$suffix}", 'Ubicación desactivada', '9.850000', '-83.910000');
    $pdo->prepare('EXEC dbo.sp_desactivar_ubicacion @ubicacion_id=?')->execute([$uInactiva]);

    $fechaFut = gmdate('Y-m-d\TH:i', time() + 86400 * 3);
    $mercBase = [['tipo_mercancia_id' => $tipoMercId, 'descripcion' => 'Carga híbrida ' . $suffix, 'peso_kg' => '250.5', 'volumen_m3' => '2.4']];

    // =========================================================================
    // 0. VERIFICAR FORMULARIO HTML SEGÚN PERMISOS
    // =========================================================================
    [$st, $formAdm] = hyb_http($base . '/solicitudes/form.php', $admCookie);
    hyb_assert($st === 200 && str_contains($formAdm, 'data-can-save-frequent="1"') && str_contains($formAdm, 'Elegir en el mapa'), 'Admin con UBICACIONES_GESTIONAR debe ver opción frecuente habilitada.');
    [$st, $formOp] = hyb_http($base . '/solicitudes/form.php', $opCookie);
    hyb_assert($st === 200 && str_contains($formOp, 'data-can-save-frequent="0"') && str_contains($formOp, 'id="solicitud-mapa-frecuente" value="1" disabled'), 'Operador sin UBICACIONES_GESTIONAR debe ver checkbox frecuente deshabilitado.');

    // =========================================================================
    // 1. LAS 6 COMBINACIONES DE ACEPTACIÓN
    // =========================================================================

    // C1: Guardada + Guardada (sin enviar *_modo -> regresión 100% compatible)
    [$st, $body, $hdrs] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, [
        'csrf_token' => $admCsrf,
        'cliente_id' => $cliFisId,
        'origen_id' => $uBaseA,
        'destino_id' => $uBaseB,
        'fecha_entrega' => $fechaFut,
        'modo_destinatario' => 'mismo_cliente',
        'mercancias' => $mercBase,
    ]);
    hyb_assert($st === 200, "C1 Guardada+Guardada debe responder 200 (obtenido {$st}): {$body}");
    $solC1 = hyb_extract_redirect_id($hdrs);
    hyb_assert($solC1 > 0, 'C1 debe devolver HX-Redirect con solicitud_id.');
    $cleanup['solicitud_ids'][] = $solC1;

    // C2: Origen nuevo temporal (mapa, guardar_frecuente=0) + Destino guardado (por operador SIN UBICACIONES_GESTIONAR)
    $refC2 = "HYB_C2_{$suffix} Portón norte bodega 4";
    [$st, $body, $hdrs] = hyb_http($base . '/solicitudes/guardar.php', $opCookie, [
        'csrf_token' => $opCsrf,
        'cliente_id' => $cliFisId,
        'origen_modo' => 'mapa',
        'origen_nombre' => "Portón Norte C2 {$suffix}",
        'origen_direccion' => $refC2,
        'origen_latitud' => '9.998120',
        'origen_longitud' => '-84.116540',
        'origen_guardar_frecuente' => '0',
        'destino_modo' => 'guardada',
        'destino_id' => $uBaseB,
        'fecha_entrega' => $fechaFut,
        'modo_destinatario' => 'mismo_cliente',
        'mercancias' => $mercBase,
    ]);
    hyb_assert($st === 200, "C2 Temporal+Guardada por operador debe responder 200 (obtenido {$st}): {$body}");
    $solC2 = hyb_extract_redirect_id($hdrs);
    hyb_assert($solC2 > 0, 'C2 debe crear solicitud.');
    $cleanup['solicitud_ids'][] = $solC2;
    $origC2Row = $pdo->query("SELECT u.ubicacion_id, u.es_reutilizable, u.latitud, u.longitud FROM dbo.solicitudes s JOIN dbo.ubicaciones u ON u.ubicacion_id = s.origen_id WHERE s.solicitud_id = {$solC2}")->fetch();
    hyb_assert($origC2Row && (int)$origC2Row['es_reutilizable'] === 0, 'C2 origen puntual debe guardarse con es_reutilizable = 0.');
    $origC2Id = (int)$origC2Row['ubicacion_id'];
    $cleanup['ubicacion_ids'][] = $origC2Id;

    // Verificar que C2 puntual NO aparece en catálogo ni combobox normal, SÍ en detalle y contexto=parada
    [$st, $catJson] = hyb_http($base . '/flota/lista.php?seccion=ubicaciones&formato=json&buscar=' . urlencode("C2 {$suffix}"), $admCookie, null, false);
    hyb_assert($st === 200 && !str_contains($catJson, (string)$origC2Id), 'Punto puntual C2 NO debe aparecer en combobox de catálogo.');
    [$st, $catHtml] = hyb_http($base . '/flota/lista.php?seccion=ubicaciones&buscar=' . urlencode("C2 {$suffix}"), $admCookie);
    hyb_assert($st === 200 && !str_contains($catHtml, "Portón Norte C2 {$suffix}"), 'Punto puntual C2 NO debe contaminar listado de Flota > Ubicaciones.');
    [$st, $paradaJson] = hyb_http($base . '/flota/lista.php?seccion=ubicaciones&contexto=parada&formato=json&buscar=' . urlencode("C2 {$suffix}"), $admCookie, null, false);
    hyb_assert($st === 200 && str_contains($paradaJson, (string)$origC2Id) && str_contains($paradaJson, 'Punto puntual'), 'Punto puntual C2 SÍ debe aparecer en selector de paradas de viajes.');
    [$st, $detC2] = hyb_http($base . "/solicitudes/detalle.php?id={$solC2}", $admCookie, null, false);
    hyb_assert($st === 200 && str_contains($detC2, '9.998120%2C-84.116540') && str_contains($detC2, 'Punto puntual de esta solicitud'), 'Detalle de solicitud C2 debe mostrar enlace Google Maps del punto puntual.');

    // C3: Origen guardado + Destino nuevo temporal (sin alias -> derivado seguro)
    $refC3 = "HYB_C3_{$suffix} Frente al parque central de Cartago";
    [$st, $body, $hdrs] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, [
        'csrf_token' => $admCsrf,
        'cliente_id' => $cliFisId,
        'origen_modo' => 'guardada',
        'origen_id' => $uBaseA,
        'destino_modo' => 'mapa',
        'destino_nombre' => '',
        'destino_direccion' => $refC3,
        'destino_latitud' => '9.864400',
        'destino_longitud' => '-83.919400',
        'destino_guardar_frecuente' => '0',
        'fecha_entrega' => $fechaFut,
        'modo_destinatario' => 'mismo_cliente',
        'mercancias' => $mercBase,
    ]);
    hyb_assert($st === 200, "C3 Guardada+Temporal debe responder 200 (obtenido {$st}): {$body}");
    $solC3 = hyb_extract_redirect_id($hdrs);
    $cleanup['solicitud_ids'][] = $solC3;
    $destC3Id = (int)$pdo->query("SELECT destino_id FROM dbo.solicitudes WHERE solicitud_id = {$solC3}")->fetchColumn();
    $cleanup['ubicacion_ids'][] = $destC3Id;
    hyb_assert((int)$pdo->query("SELECT es_reutilizable FROM dbo.ubicaciones WHERE ubicacion_id = {$destC3Id}")->fetchColumn() === 0, 'C3 destino puntual debe ser es_reutilizable = 0.');

    // C4: Origen nuevo temporal + Destino nuevo temporal -> asignar ambos a un viaje y verificar Google Maps en paradas
    [$st, $body, $hdrs] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, [
        'csrf_token' => $admCsrf,
        'cliente_id' => $cliFisId,
        'origen_modo' => 'mapa',
        'origen_nombre' => "Origen C4 {$suffix}",
        'origen_direccion' => "HYB_C4O_{$suffix} Planta Escazú",
        'origen_latitud' => '9.918900',
        'origen_longitud' => '-84.139800',
        'origen_guardar_frecuente' => '0',
        'destino_modo' => 'mapa',
        'destino_nombre' => "Destino C4 {$suffix}",
        'destino_direccion' => "HYB_C4D_{$suffix} Local Liberia",
        'destino_latitud' => '10.634500',
        'destino_longitud' => '-85.440700',
        'destino_guardar_frecuente' => '0',
        'fecha_entrega' => $fechaFut,
        'modo_destinatario' => 'mismo_cliente',
        'mercancias' => $mercBase,
    ]);
    hyb_assert($st === 200, "C4 Temporal+Temporal debe responder 200 (obtenido {$st}): {$body}");
    $solC4 = hyb_extract_redirect_id($hdrs);
    $cleanup['solicitud_ids'][] = $solC4;
    $c4Row = $pdo->query("SELECT origen_id, destino_id FROM dbo.solicitudes WHERE solicitud_id = {$solC4}")->fetch();
    $c4OrigId = (int)$c4Row['origen_id'];
    $c4DestId = (int)$c4Row['destino_id'];
    $cleanup['ubicacion_ids'][] = $c4OrigId;
    $cleanup['ubicacion_ids'][] = $c4DestId;

    // Planificar viaje, agregar ambas paradas puntuales y asignar C4
    $stR = $pdo->prepare('DECLARE @rid INT; EXEC dbo.sp_crear_ruta @nombre=?, @origen_id=?, @destino_id=?, @distancia_km=210, @duracion_estimada_min=240, @ruta_id=@rid OUTPUT; SELECT @rid;');
    $stR->execute(["HYB Ruta {$suffix}", $uBaseA, $uBaseB]);
    $rutaId = (int)$stR->fetchColumn();
    $stR->closeCursor();
    $cleanup['ruta_ids'][] = $rutaId;

    $stV = $pdo->prepare("DECLARE @vid INT; EXEC dbo.sp_crear_vehiculo @placa=?, @marca=N'Hino', @modelo=N'300', @anio=2025, @capacidad_carga_kg=3000, @capacidad_volumen_m3=15, @kilometraje_actual=0, @estado='DISPONIBLE', @pma_kg=4000, @clase_legal='LIVIANO', @es_articulado=0, @estado_homologacion='VERIFICADO', @vehiculo_id=@vid OUTPUT; SELECT @vid;");
    $stV->execute(["HYB{$suffix}"]);
    $vehId = (int)$stV->fetchColumn();
    $stV->closeCursor();
    $cleanup['vehiculo_ids'][] = $vehId;

    $stC = $pdo->prepare("DECLARE @cid INT; EXEC dbo.sp_crear_conductor @identificacion=?, @nombre=N'Mario', @primer_apellido=N'Solano', @segundo_apellido=NULL, @telefono=NULL, @correo=NULL, @numero_licencia=?, @fecha_vencimiento_licencia=?, @estado_operativo='DISPONIBLE', @usuario_id=NULL, @codigo_licencia_inicial='B1', @conductor_id=@cid OUTPUT; SELECT @cid;");
    $stC->execute(["HYB-C-{$suffix}", "HYB-L-{$suffix}", gmdate('Y-m-d', time() + 86400 * 365)]);
    $condId = (int)$stC->fetchColumn();
    $stC->closeCursor();
    $cleanup['conductor_ids'][] = $condId;

    $stVj = $pdo->prepare('DECLARE @vjid INT; EXEC dbo.sp_crear_viaje @codigo=?, @vehiculo_id=?, @conductor_id=?, @ruta_id=?, @fecha_programada=?, @viaje_id=@vjid OUTPUT; SELECT @vjid;');
    $stVj->execute(["HYB-VJ-{$suffix}", $vehId, $condId, $rutaId, gmdate('Y-m-d H:i:s', time() + 86400 * 2)]);
    $viajeId = (int)$stVj->fetchColumn();
    $stVj->closeCursor();
    $cleanup['viaje_ids'][] = $viajeId;

    $stP1 = $pdo->prepare("DECLARE @pid INT; EXEC dbo.sp_agregar_parada @viaje_id=?, @ubicacion_id=?, @orden=1, @tipo_parada='RECOGIDA', @parada_id=@pid OUTPUT; SELECT @pid;");
    $stP1->execute([$viajeId, $c4OrigId]);
    $p1Id = (int)$stP1->fetchColumn();
    $stP1->closeCursor();

    $stP2 = $pdo->prepare("DECLARE @pid INT; EXEC dbo.sp_agregar_parada @viaje_id=?, @ubicacion_id=?, @orden=2, @tipo_parada='ENTREGA', @parada_id=@pid OUTPUT; SELECT @pid;");
    $stP2->execute([$viajeId, $c4DestId]);
    $p2Id = (int)$stP2->fetchColumn();
    $stP2->closeCursor();

    [$stAsig, $bodyAsig] = hyb_http($base . '/viajes/accion.php', $admCookie, [
        'csrf_token' => $admCsrf,
        'viaje_id' => $viajeId,
        'accion' => 'asignar',
        'solicitud_id' => $solC4,
        'parada_recogida_id' => $p1Id,
        'parada_entrega_id' => $p2Id,
    ]);
    hyb_assert($stAsig === 200, "Asignación de solicitud C4 con paradas puntuales debe responder 200 (obtenido {$stAsig}): {$bodyAsig}");

    [$stVjDet, $vjDetHtml] = hyb_http($base . "/viajes/detalle.php?id={$viajeId}", $admCookie, null, false);
    hyb_assert($stVjDet === 200 && str_contains($vjDetHtml, '9.918900%2C-84.139800') && str_contains($vjDetHtml, '10.634500%2C-85.440700'), 'Detalle de viaje debe mostrar enlaces Google Maps para ambas paradas puntuales.');

    // C5: Origen nuevo marcado FRECUENTE (es_reutilizable=1) + Destino nuevo NO frecuente (es_reutilizable=0)
    $frecNameC5 = "HYB Frecuente C5 {$suffix}";
    [$st, $body, $hdrs] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, [
        'csrf_token' => $admCsrf,
        'cliente_id' => $cliFisId,
        'origen_modo' => 'mapa',
        'origen_nombre' => $frecNameC5,
        'origen_direccion' => 'Zona Franca Coyol nave 12',
        'origen_latitud' => '10.002300',
        'origen_longitud' => '-84.259100',
        'origen_guardar_frecuente' => '1',
        'destino_modo' => 'mapa',
        'destino_nombre' => "Puntual C5 {$suffix}",
        'destino_direccion' => 'Entrega puntual Desamparados',
        'destino_latitud' => '9.898000',
        'destino_longitud' => '-84.063000',
        'destino_guardar_frecuente' => '0',
        'fecha_entrega' => $fechaFut,
        'modo_destinatario' => 'mismo_cliente',
        'mercancias' => $mercBase,
    ]);
    hyb_assert($st === 200, "C5 Frecuente+Puntual debe responder 200 (obtenido {$st}): {$body}");
    $solC5 = hyb_extract_redirect_id($hdrs);
    $cleanup['solicitud_ids'][] = $solC5;
    $c5Row = $pdo->query("SELECT origen_id, destino_id FROM dbo.solicitudes WHERE solicitud_id = {$solC5}")->fetch();
    $c5OrigId = (int)$c5Row['origen_id'];
    $c5DestId = (int)$c5Row['destino_id'];
    $cleanup['ubicacion_ids'][] = $c5OrigId;
    $cleanup['ubicacion_ids'][] = $c5DestId;

    [$st, $catC5] = hyb_http($base . '/flota/lista.php?seccion=ubicaciones&formato=json&buscar=' . urlencode("C5 {$suffix}"), $admCookie, null, false);
    hyb_assert($st === 200 && str_contains($catC5, $frecNameC5) && !str_contains($catC5, "Puntual C5 {$suffix}"), 'C5: origen frecuente debe aparecer en catálogo y destino puntual NO.');

    // C6: Cliente jurídico + destinatario tercero + ubicación híbrida (destino frecuente)
    $frecNameC6 = "HYB Juridico C6 {$suffix}";
    [$st, $body, $hdrs] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, [
        'csrf_token' => $admCsrf,
        'cliente_id' => $cliJurId,
        'origen_modo' => 'guardada',
        'origen_id' => $uBaseA,
        'destino_modo' => 'mapa',
        'destino_nombre' => $frecNameC6,
        'destino_direccion' => 'Oficentro Lindora edificio B',
        'destino_latitud' => '9.953100',
        'destino_longitud' => '-84.191200',
        'destino_guardar_frecuente' => '1',
        'fecha_entrega' => $fechaFut,
        'modo_destinatario' => 'tercero',
        'destinatario_identificacion' => '1-0555-0666',
        'destinatario_nombre' => 'Elena',
        'destinatario_apellido1' => 'Vargas',
        'destinatario_apellido2' => 'Castro',
        'destinatario_telefono' => '8888-5555',
        'mercancias' => $mercBase,
    ]);
    hyb_assert($st === 200, "C6 Cliente jurídico + tercero + destino frecuente debe responder 200 (obtenido {$st}): {$body}");
    $solC6 = hyb_extract_redirect_id($hdrs);
    $cleanup['solicitud_ids'][] = $solC6;
    $cleanup['ubicacion_ids'][] = (int)$pdo->query("SELECT destino_id FROM dbo.solicitudes WHERE solicitud_id = {$solC6}")->fetchColumn();

    echo "[OK] Sección 1: Las 6 combinaciones híbridas + planificación de viaje/paradas + Google Maps aprobadas.\n";

    // =========================================================================
    // 2. VALIDACIONES NEGATIVAS, SEGURIDAD, ANTI-ESCALACIÓN Y ROLLBACK ATÓMICO
    // =========================================================================
    $baseValid = [
        'csrf_token' => $admCsrf,
        'cliente_id' => $cliFisId,
        'origen_modo' => 'guardada',
        'origen_id' => $uBaseA,
        'destino_modo' => 'guardada',
        'destino_id' => $uBaseB,
        'fecha_entrega' => $fechaFut,
        'modo_destinatario' => 'mismo_cliente',
        'mercancias' => $mercBase,
    ];

    // 2.1 Punto sin referencia -> 422
    [$st] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, array_merge($baseValid, [
        'origen_modo' => 'mapa', 'origen_direccion' => '   ', 'origen_latitud' => '9.93', 'origen_longitud' => '-84.08',
    ]));
    hyb_assert($st === 422, "Punto sin referencia debe responder 422 (obtenido {$st}).");

    // 2.2 Coordenadas fuera de rango -> 422
    [$st] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, array_merge($baseValid, [
        'origen_modo' => 'mapa', 'origen_direccion' => 'Sitio inválido', 'origen_latitud' => '95.123456', 'origen_longitud' => '-84.08',
    ]));
    hyb_assert($st === 422, "Latitud > 90 debe responder 422 (obtenido {$st}).");

    // 2.3 Solo una coordenada -> 422
    [$st] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, array_merge($baseValid, [
        'origen_modo' => 'mapa', 'origen_direccion' => 'Sitio incompleto', 'origen_latitud' => '9.930000', 'origen_longitud' => '',
    ]));
    hyb_assert($st === 422, "Solo una coordenada debe responder 422 (obtenido {$st}).");

    // 2.4 Modo malicioso -> 422
    [$st] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, array_merge($baseValid, [
        'origen_modo' => 'admin_bypass',
    ]));
    hyb_assert($st === 422, "Modo malicioso debe responder 422 (obtenido {$st}).");

    // 2.5 Checkbox reutilizable falsificado por operador SIN UBICACIONES_GESTIONAR -> 403 + 0 huérfanos
    $hackName = "HYB_HACK_{$suffix}";
    [$st] = hyb_http($base . '/solicitudes/guardar.php', $opCookie, array_merge($baseValid, [
        'csrf_token' => $opCsrf,
        'origen_modo' => 'mapa',
        'origen_nombre' => $hackName,
        'origen_direccion' => 'Intento escalación HTTP',
        'origen_latitud' => '9.920000',
        'origen_longitud' => '-84.100000',
        'origen_guardar_frecuente' => '1',
    ]));
    hyb_assert($st === 403, "Falsificar guardar_frecuente=1 sin UBICACIONES_GESTIONAR debe responder 403 (obtenido {$st}).");
    $cntHack = (int)$pdo->query("SELECT COUNT(*) FROM dbo.ubicaciones WHERE nombre = " . $pdo->quote($hackName))->fetchColumn();
    hyb_assert($cntHack === 0, 'Intento de escalación HTTP no debe dejar registros huérfanos.');

    // 2.6 ID inexistente, ID inactivo e ID puntual vía modo guardada -> 422
    foreach ([$uInactiva, 9999999, $origC2Id] as $badId) {
        [$st] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, array_merge($baseValid, [
            'origen_modo' => 'guardada', 'origen_id' => $badId,
        ]));
        hyb_assert($st === 422, "Ubicación guardada inválida ({$badId}) debe responder 422 (obtenido {$st}).");
    }

    // 2.7 Sin permiso SOLICITUDES_CREAR -> 403; CSRF inválido -> 403
    [$st] = hyb_http($base . '/solicitudes/guardar.php', $nopCookie, array_merge($baseValid, ['csrf_token' => $nopCsrf]));
    hyb_assert($st === 403, "Usuario sin SOLICITUDES_CREAR debe responder 403 (obtenido {$st}).");
    [$st] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, array_merge($baseValid, ['csrf_token' => 'csrf_invalido']));
    hyb_assert($st === 403, "CSRF inválido debe responder 403 (obtenido {$st}).");

    // 2.8 Origen y destino iguales: mismo ID, IDs distintos mismo punto (<=25m) y MAPA+MAPA mismo punto (<=25m) -> 422 + 0 huérfanos
    [$st] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, array_merge($baseValid, ['destino_id' => $uBaseA]));
    hyb_assert($st === 422, "Mismo ID origen=destino debe responder 422 (obtenido {$st}).");

    [$st] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, array_merge($baseValid, ['destino_id' => $uBaseASame]));
    hyb_assert($st === 422, "IDs distintos en el mismo punto geográfico (<=25m) deben responder 422 (obtenido {$st}).");

    $orphanGeo = "HYB_ORPHAN_GEO_{$suffix}";
    [$st] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, array_merge($baseValid, [
        'origen_modo' => 'mapa', 'origen_direccion' => $orphanGeo, 'origen_latitud' => '9.934739', 'origen_longitud' => '-84.087502',
        'destino_modo' => 'mapa', 'destino_direccion' => 'Texto distinto mismo sitio', 'destino_latitud' => '9.934745', 'destino_longitud' => '-84.087505',
    ]));
    hyb_assert($st === 422, "MAPA+MAPA a <25m debe responder 422 (obtenido {$st}).");
    hyb_assert((int)$pdo->query("SELECT COUNT(*) FROM dbo.ubicaciones WHERE direccion = " . $pdo->quote($orphanGeo))->fetchColumn() === 0, 'Rechazo geográfico no debe dejar huérfanos.');

    // 2.9 Error forzado de TVP/BD (tipo_mercancia_id inexistente) -> 422 + rollback completo (0 huérfanos)
    $orphanTvp = "HYB_ORPHAN_TVP_{$suffix}";
    [$st] = hyb_http($base . '/solicitudes/guardar.php', $admCookie, array_merge($baseValid, [
        'origen_modo' => 'mapa', 'origen_nombre' => $orphanTvp, 'origen_direccion' => 'Punto con fallo TVP',
        'origen_latitud' => '10.111111', 'origen_longitud' => '-84.222222', 'origen_guardar_frecuente' => '1',
        'mercancias' => [['tipo_mercancia_id' => 999999, 'descripcion' => 'Inválida', 'peso_kg' => '10', 'volumen_m3' => '1']],
    ]));
    hyb_assert($st === 422, "Error TVP tipo_mercancia inexistente debe responder 422 (obtenido {$st}).");
    hyb_assert((int)$pdo->query("SELECT COUNT(*) FROM dbo.ubicaciones WHERE nombre = " . $pdo->quote($orphanTvp))->fetchColumn() === 0, 'Fallo de TVP/BD debe revertir completamente sin dejar ubicaciones huérfanas.');

    // 2.10 Doble envío concurrente real (2 servidores PHP paralelos) intentando crear mismo nombre frecuente -> 1 éxito (200), 1 conflicto (409), 0 duplicados
    $raceName = "HYB_RACE_{$suffix}";
    $racePost = http_build_query(array_merge($baseValid, [
        'origen_modo' => 'mapa',
        'origen_nombre' => $raceName,
        'origen_direccion' => 'Prueba concurrencia doble clic',
        'origen_latitud' => '10.050000',
        'origen_longitud' => '-84.150000',
        'origen_guardar_frecuente' => '1',
    ]));
    $mh = curl_multi_init();
    $chs = [];
    foreach ([$srv1['base'], $srv2['base']] as $idx => $srvBase) {
        $ch = curl_init($srvBase . '/solicitudes/guardar.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $racePost,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ["Cookie: {$admCookie}", 'HX-Request: true', 'Connection: close'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $chs[$idx] = $ch;
    }
    do {
        $mrc = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 0.2);
        }
    } while ($active && $mrc === CURLM_OK);

    $raceCodes = [];
    foreach ($chs as $ch) {
        $raceCodes[] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    sort($raceCodes);
    hyb_assert($raceCodes === [200, 409], 'Doble envío concurrente con nombre frecuente repetido debe producir exactamente [200, 409], obtenido: ' . json_encode($raceCodes));

    $raceUbis = $pdo->query("SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = " . $pdo->quote($raceName))->fetchAll(PDO::FETCH_COLUMN);
    hyb_assert(count($raceUbis) === 1, 'Doble envío concurrente debe crear exactamente 1 ubicación frecuente.');
    $raceUbiId = (int)$raceUbis[0];
    $cleanup['ubicacion_ids'][] = $raceUbiId;
    $raceSolId = (int)$pdo->query("SELECT TOP 1 solicitud_id FROM dbo.solicitudes WHERE origen_id = {$raceUbiId} ORDER BY solicitud_id DESC")->fetchColumn();
    if ($raceSolId > 0) {
        $cleanup['solicitud_ids'][] = $raceSolId;
    }

    echo "[OK] Sección 2: Rechazos 422/403/409, anti-escalación, Haversine <=25m, rollback TVP (0 huérfanos) y concurrencia real [200, 409] aprobados.\n";
    echo "=== SUITE PHP UBICACIONES HÍBRIDAS: 100% PASS ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    hyb_stop_server($srv1);
    hyb_stop_server($srv2);

    foreach ($cleanup['session_files'] as $sf) {
        if (is_string($sf) && file_exists($sf)) {
            @unlink($sf);
        }
    }
    foreach ($cleanup['temp_dirs'] ?? [] as $td) {
        if (is_dir($td)) {
            @rmdir($td);
        }
    }

    require_test_database($pdo);
    foreach (array_unique($cleanup['viaje_ids']) as $vid) {
        $pdo->prepare('DELETE FROM dbo.viaje_solicitudes WHERE viaje_id = ?')->execute([$vid]);
        $pdo->prepare('DELETE FROM dbo.paradas_viaje WHERE viaje_id = ?')->execute([$vid]);
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'viajes', N'paradas_viaje', N'viaje_solicitudes') AND registro_id = ?")->execute([(string)$vid]);
        $pdo->prepare('DELETE FROM dbo.viajes WHERE viaje_id = ?')->execute([$vid]);
    }
    foreach (array_unique($cleanup['solicitud_ids']) as $sid) {
        $pdo->prepare('DELETE FROM dbo.viaje_solicitudes WHERE solicitud_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM dbo.mercancias WHERE solicitud_id = ?')->execute([$sid]);
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'solicitudes' AND registro_id = ?")->execute([(string)$sid]);
        $pdo->prepare('DELETE FROM dbo.solicitudes WHERE solicitud_id = ?')->execute([$sid]);
    }
    foreach (array_unique($cleanup['ruta_ids']) as $rid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'rutas' AND registro_id = ?")->execute([(string)$rid]);
        $pdo->prepare('DELETE FROM dbo.rutas WHERE ruta_id = ?')->execute([$rid]);
    }
    foreach (array_unique($cleanup['vehiculo_ids']) as $vehId) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'vehiculos' AND registro_id = ?")->execute([(string)$vehId]);
        $pdo->prepare('DELETE FROM dbo.vehiculos WHERE vehiculo_id = ?')->execute([$vehId]);
    }
    foreach (array_unique($cleanup['conductor_ids']) as $cid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'conductores', N'conductores_licencias') AND (registro_id = ? OR registro_id LIKE ?)")->execute([(string)$cid, "{$cid}:%"]);
        $pdo->prepare('DELETE FROM dbo.conductores_licencias WHERE conductor_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM dbo.conductores WHERE conductor_id = ?')->execute([$cid]);
    }
    foreach (array_unique($cleanup['ubicacion_ids']) as $uid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'ubicaciones' AND registro_id = ?")->execute([(string)$uid]);
        $pdo->prepare('DELETE FROM dbo.ubicaciones WHERE ubicacion_id = ?')->execute([$uid]);
    }
    foreach (array_unique($cleanup['cliente_ids']) as $cid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'clientes', N'clientes_fisicos', N'clientes_juridicos') AND registro_id = ?")->execute([(string)$cid]);
        $pdo->prepare('DELETE FROM dbo.clientes_fisicos WHERE cliente_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM dbo.clientes_juridicos WHERE cliente_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM dbo.clientes WHERE cliente_id = ?')->execute([$cid]);
    }
    foreach (array_unique($cleanup['usuario_ids']) as $uid) {
        $pdo->prepare('DELETE FROM dbo.auditorias WHERE usuario_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([$uid]);
    }
}
exit($exitCode);
