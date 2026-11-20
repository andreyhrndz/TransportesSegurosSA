<?php
declare(strict_types=1);

/**
 * Suite integral HTTP + SQL + Seguridad + DW (solo lectura) para:
 *  - Matriz A: Reordenamiento atómico y edición controlada de paradas de viaje.
 *  - Matriz B: Bitácora independiente (/auditoria.php), permisos y lenguaje humano.
 *  - Matriz C: 3 reportes (operacionales + DW real read-only), CSV seguro e impresión.
 *
 * Ejecutar EXCLUSIVAMENTE contra TransportesSegurosSA_Test.
 */

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
$_ENV['TSSA_DB_DATABASE'] = 'TransportesSegurosSA_Test';
$_SERVER['TSSA_DB_DATABASE'] = 'TransportesSegurosSA_Test';

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/audit_presenter.php';
require_once __DIR__ . '/../app/reports_service.php';

function sar_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('ASSERTION FAILED: ' . $msg);
    }
}

/** @return array{0:int,1:string,2:array<int,string>} */
function sar_http(string $url, string $cookie = '', string $method = 'GET', ?string $body = null, bool $htmx = false): array
{
    $headers = [
        'Connection: close',
        'Accept: text/html,text/csv,*/*',
    ];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($htmx) {
        $headers[] = 'HX-Request: true';
    }
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body ?? '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 15,
        ],
    ]);
    $respBody = @file_get_contents($url, false, $ctx);
    $respHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($respHeaders[0]) && preg_match('/\s(\d{3})\b/', $respHeaders[0], $m) === 1) {
        $status = (int)$m[1];
    }
    return [$status, is_string($respBody) ? $respBody : '', $respHeaders];
}

function sar_header_value(array $headers, string $name): ?string
{
    $prefix = strtolower($name) . ':';
    foreach ($headers as $h) {
        if (str_starts_with(strtolower($h), $prefix)) {
            return trim(substr($h, strlen($prefix)));
        }
    }
    return null;
}

function sar_create_session_cookie(string $sessionDir, string $sessionName, int $userId, string $csrf): string
{
    $sid = bin2hex(random_bytes(16));
    $now = time();
    $payload = sprintf(
        'usuario_id|i:%d;authenticated_at|i:%d;last_activity_at|i:%d;csrf_token|s:%d:"%s";',
        $userId,
        $now,
        $now,
        strlen($csrf),
        $csrf
    );
    file_put_contents($sessionDir . DIRECTORY_SEPARATOR . 'sess_' . $sid, $payload);
    return "{$sessionName}={$sid}";
}

$pdo = db();
require_test_database($pdo);

$exitCode = 0;
$serverProc = null;
$serverPid = null;
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tssa_sar_sess_' . bin2hex(random_bytes(5));
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tssa_sar_srv_' . bin2hex(random_bytes(5)) . '.log';

$createdUsers = [];
$uIds = [];
$rutaId = null;
$vehId = null;
$condId = null;
$viajeId = null;
$solIds = [];
$maxAudId = (int)$pdo->query('SELECT ISNULL(MAX(auditoria_id), 0) FROM dbo.auditorias')->fetchColumn();

try {
    mkdir($sessionDir, 0700, true);
    $tag = 'SAR_' . strtoupper(bin2hex(random_bytes(3)));
    $adminId = (int)$pdo->query('SELECT TOP (1) usuario_id FROM dbo.usuarios WHERE activo = 1 ORDER BY usuario_id')->fetchColumn();
    $clienteId = (int)$pdo->query('SELECT TOP (1) cliente_id FROM dbo.clientes WHERE activo = 1 ORDER BY cliente_id')->fetchColumn();
    $tipoMercId = (int)$pdo->query('SELECT TOP (1) tipo_mercancia_id FROM dbo.tipos_mercancia WHERE activo = 1 ORDER BY tipo_mercancia_id')->fetchColumn();
    sar_assert($adminId > 0 && $clienteId > 0 && $tipoMercId > 0, 'Fixtures base no encontrados en Test.');

    $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?")->execute([$adminId]);

    // ------------------------------------------------------------------
    // 0. Pruebas unitarias de seguridad (CSV formula injection & audit redaction)
    // ------------------------------------------------------------------
    foreach (['=CMD|\' /C calc\'!A0', '+1+1', '-2+3', '@SUM(1+1)', "\t=1+1", '  =HYPERLINK("x")'] as $evil) {
        $safe = report_csv_safe_cell($evil);
        sar_assert(str_starts_with($safe, "'"), "CSV formula payload no neutralizado: {$evil}");
    }
    sar_assert(report_csv_safe_cell('Normal 123') === 'Normal 123', 'Texto CSV normal alterado innecesariamente.');

    $redacted = audit_redact_detail('hash=$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234 token=0123456789abcdef0123456789abcdef');
    sar_assert($redacted !== null && !str_contains($redacted, '$2y$10$') && !str_contains($redacted, '0123456789abcdef0123456789abcdef'), 'audit_redact_detail debe redactar hashes y tokens.');

    $unknownEv = audit_present_row(['entidad' => 'modulo_futuro', 'accion' => 'CUSTOM_OP', 'registro_id' => '42', 'usuario' => null, 'usuario_id' => null]);
    sar_assert($unknownEv['usuario_nombre'] === 'Sistema' && $unknownEv['descripcion'] !== '', 'Fallback de evento desconocido debe ser legible.');
    echo "[OK] 0. Unitarias de CSV anti-fórmula y redacción/fallback de bitácora.\n";

    // ------------------------------------------------------------------
    // 1. Sembrar usuarios efímeros (solo auditoría, solo reportes, inactivo) y viaje con 4 paradas + 2 solicitudes
    // ------------------------------------------------------------------
    $dummyHash = password_hash('TmpPass!' . bin2hex(random_bytes(4)), PASSWORD_BCRYPT);
    $insertUser = static function (PDO $db, string $name, int $active, array $permCodes) use ($dummyHash, &$createdUsers): int {
        $st = $db->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) VALUES (?, ?, ?, ?); SELECT SCOPE_IDENTITY();');
        $st->execute([$name, strtolower($name) . '@example.test', $dummyHash, $active]);
        $st->nextRowset();
        $uid = (int)$st->fetchColumn();
        $st->closeCursor();
        $createdUsers[] = $uid;
        foreach ($permCodes as $code) {
            $p = $db->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo = ?');
            $p->execute([$uid, $code]);
            $p->closeCursor();
        }
        return $uid;
    };

    $uAuditOnly = $insertUser($pdo, $tag . '_audonly', 1, ['AUDITORIA_VER']);
    $uReportOnly = $insertUser($pdo, $tag . '_reponly', 1, ['REPORTES_VER', 'SOLICITUDES_VER', 'ENTREGAS_VER']);
    $uNoStops = $insertUser($pdo, $tag . '_nostops', 1, ['VIAJES_VER']);
    $uInactive = $insertUser($pdo, $tag . '_inact', 0, []);

    // Sembrar evento de auditoría de usuario inactivo
    $pdo->prepare("INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos) VALUES (?, N'paradas_viaje', N'88888', 'REORDER', N'Orden previo', N'Orden nuevo')")
        ->execute([$uInactive]);

    // Sembrar 4 ubicaciones, ruta, vehículo, conductor, viaje PLANIFICADO, 4 paradas y 2 solicitudes
    $future = (new DateTimeImmutable('+5 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    for ($i = 1; $i <= 4; $i++) {
        $st = $pdo->prepare('DECLARE @id INT; EXEC dbo.sp_crear_ubicacion @nombre=?,@direccion=?,@latitud=?,@longitud=?,@ubicacion_id=@id OUTPUT; SELECT @id;');
        $st->execute(["{$tag}_U{$i}", "Dirección prueba {$i}", 9.90 + ($i * 0.03), -84.05 - ($i * 0.03)]);
        $uIds[$i] = (int)$st->fetchColumn();
        $st->closeCursor();
    }
    $st = $pdo->prepare('DECLARE @id INT; EXEC dbo.sp_crear_ruta @nombre=?,@origen_id=?,@destino_id=?,@distancia_km=45,@duracion_estimada_min=65,@ruta_id=@id OUTPUT; SELECT @id;');
    $st->execute(["{$tag}_RUTA", $uIds[1], $uIds[4]]);
    $rutaId = (int)$st->fetchColumn();
    $st->closeCursor();

    $st = $pdo->prepare("DECLARE @id INT; EXEC dbo.sp_crear_vehiculo @placa=?,@marca=N'Hino',@modelo=N'300',@anio=2025,@capacidad_carga_kg=3500,@capacidad_volumen_m3=18,@kilometraje_actual=0,@estado='DISPONIBLE',@pma_kg=4000,@clase_legal='LIVIANO',@es_articulado=0,@estado_homologacion='VERIFICADO',@vehiculo_id=@id OUTPUT; SELECT @id;");
    $st->execute([substr(str_replace('_', '', $tag), 0, 12)]);
    $vehId = (int)$st->fetchColumn();
    $st->closeCursor();

    $vence = (new DateTimeImmutable('+1 year', new DateTimeZone('UTC')))->format('Y-m-d');
    $st = $pdo->prepare("DECLARE @id INT; EXEC dbo.sp_crear_conductor @identificacion=?,@nombre=N'Mario',@primer_apellido=N'Solis',@segundo_apellido=NULL,@telefono=NULL,@correo=NULL,@numero_licencia=?,@fecha_vencimiento_licencia=?,@estado_operativo='DISPONIBLE',@usuario_id=NULL,@codigo_licencia_inicial='B1',@conductor_id=@id OUTPUT; SELECT @id;");
    $st->execute(["{$tag}_CID", "{$tag}_LIC", $vence]);
    $condId = (int)$st->fetchColumn();
    $st->closeCursor();

    $st = $pdo->prepare('DECLARE @id INT; EXEC dbo.sp_crear_viaje @codigo=?,@vehiculo_id=?,@conductor_id=?,@ruta_id=?,@fecha_programada=?,@viaje_id=@id OUTPUT; SELECT @id;');
    $st->execute(["{$tag}_V1", $vehId, $condId, $rutaId, $future]);
    $viajeId = (int)$st->fetchColumn();
    $st->closeCursor();

    $pIds = [];
    $stopDefs = [[1, $uIds[1], 'RECOGIDA'], [2, $uIds[2], 'MIXTA'], [3, $uIds[4], 'ENTREGA'], [4, $uIds[3], 'INTERMEDIA']];
    foreach ($stopDefs as [$ord, $uid, $tipo]) {
        $st = $pdo->prepare('DECLARE @id INT; EXEC dbo.sp_agregar_parada @viaje_id=?,@ubicacion_id=?,@orden=?,@tipo_parada=?,@parada_id=@id OUTPUT; SELECT @id;');
        $st->execute([$viajeId, $uid, $ord, $tipo]);
        $pIds[$ord] = (int)$st->fetchColumn();
        $st->closeCursor();
    }

    foreach ([[$uIds[1], $uIds[4], $pIds[1], $pIds[3]], [$uIds[1], $uIds[2], $pIds[1], $pIds[2]]] as $idx => [$ori, $des, $pr, $pe]) {
        $st = $pdo->prepare('SET NOCOUNT ON; DECLARE @m dbo.tipo_mercancias, @sid INT; INSERT INTO @m VALUES (?, N\'Carga SAR\', 80, 1.0); EXEC dbo.sp_crear_solicitud @codigo=NULL,@cliente_id=?,@origen_id=?,@destino_id=?,@destinatario_nombre=N\'Test\',@destinatario_apellido1=N\'Dest\',@destinatario_apellido2=N\'Uno\',@destinatario_identificacion=N\'101010101\',@destinatario_telefono=N\'88888888\',@fecha_entrega_requerida=?,@mercancias=@m,@solicitud_id=@sid OUTPUT; SELECT @sid;');
        $st->execute([$tipoMercId, $clienteId, $ori, $des, $future]);
        $sid = 0;
        do {
            if ($st->columnCount() > 0) {
                $val = $st->fetchColumn();
                if ($val !== false) {
                    $sid = (int)$val;
                }
            }
        } while ($st->nextRowset());
        $st->closeCursor();
        sar_assert($sid > 0, 'No se pudo obtener solicitud_id en fixture SAR.');
        $solIds[] = $sid;

        $st2 = $pdo->prepare('SET NOCOUNT ON; DECLARE @vsid INT; EXEC dbo.sp_asignar_solicitud_viaje @viaje_id=?,@solicitud_id=?,@parada_recogida_id=?,@parada_entrega_id=?,@viaje_solicitud_id=@vsid OUTPUT;');
        $st2->execute([$viajeId, $sid, $pr, $pe]);
        $st2->closeCursor();
    }

    // ------------------------------------------------------------------
    // 2. Arrancar servidor PHP efímero aislado sobre TransportesSegurosSA_Test
    // ------------------------------------------------------------------
    $port = random_int(18400, 18999);
    $phpBin = PHP_BINARY;
    $docRoot = realpath(__DIR__ . '/../public');
    $cmd = [
        $phpBin,
        '-d', 'session.save_path=' . $sessionDir,
        '-S', "127.0.0.1:{$port}",
        '-t', $docRoot,
    ];
    $env = array_merge(getenv(), [
        'TSSA_DB_DATABASE' => 'TransportesSegurosSA_Test',
    ]);
    $spec = [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $serverLog, 'w'],
        2 => ['file', $serverLog, 'a'],
    ];
    $serverProc = proc_open($cmd, $spec, $pipes, realpath(__DIR__ . '/..'), $env);
    sar_assert(is_resource($serverProc), 'No se pudo iniciar servidor PHP de prueba.');
    $statusInfo = proc_get_status($serverProc);
    $serverPid = $statusInfo['pid'] ?? null;

    $base = "http://127.0.0.1:{$port}";
    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1.0);
        if ($sock !== false) {
            fclose($sock);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    sar_assert($ready, 'Servidor PHP de prueba no respondió a tiempo. Log: ' . @file_get_contents($serverLog));

    $sessName = session_cookie_name();
    $csrfAdmin = bin2hex(random_bytes(16));
    $csrfNoStops = bin2hex(random_bytes(16));
    $cookieAdmin = sar_create_session_cookie($sessionDir, $sessName, $adminId, $csrfAdmin);
    $cookieAuditOnly = sar_create_session_cookie($sessionDir, $sessName, $uAuditOnly, bin2hex(random_bytes(16)));
    $cookieReportOnly = sar_create_session_cookie($sessionDir, $sessName, $uReportOnly, bin2hex(random_bytes(16)));
    $cookieNoStops = sar_create_session_cookie($sessionDir, $sessName, $uNoStops, $csrfNoStops);

    // ------------------------------------------------------------------
    // 3. MATRIZ A: Paradas editables y reordenamiento
    // ------------------------------------------------------------------
    [$stCode, $detHtml] = sar_http("{$base}/viajes/detalle.php?id={$viajeId}", $cookieAdmin);
    sar_assert($stCode === 200, "Detalle viaje devolvió {$stCode}.");
    sar_assert(str_contains($detHtml, 'Reordenar paradas'), 'Debe mostrar botón Reordenar paradas en viaje PLANIFICADO.');
    sar_assert(str_contains($detHtml, "parada_editar_form.php?viaje_id={$viajeId}&parada_id={$pIds[4]}"), 'Debe mostrar botón Editar en parada no vinculada #4.');
    sar_assert(str_contains($detHtml, 'Vinculada a solicitud'), 'Debe indicar estado Vinculada a solicitud en paradas con solicitudes.');
    sar_assert(str_contains($detHtml, 'no es seguimiento GPS'), 'Debe mostrar nota transparente sobre actualización automática de paradas.');

    // Modal de reordenamiento carga 200
    [$stCode, $ordModal] = sar_http("{$base}/viajes/paradas_orden_form.php?id={$viajeId}", $cookieAdmin, 'GET', null, true);
    sar_assert($stCode === 200 && str_contains($ordModal, 'data-stop-reorder-list'), 'Modal de reordenar paradas debe cargar correctamente.');

    // Reordenar 4 <-> 3 (p1, p2, p4, p3) -> 200 + HX-Redirect
    $postValid = http_build_query([
        'csrf_token' => $csrfAdmin,
        'viaje_id' => $viajeId,
        'orden_paradas' => [$pIds[1], $pIds[2], $pIds[4], $pIds[3]],
    ]);
    [$stCode, , $hdrs] = sar_http("{$base}/viajes/paradas_orden_guardar.php", $cookieAdmin, 'POST', $postValid, true);
    sar_assert($stCode === 200 && sar_header_value($hdrs, 'HX-Redirect') === "/viajes/detalle.php?id={$viajeId}", "Reordenamiento válido 4<->3 falló (HTTP {$stCode}).");

    $ordP4 = (int)$pdo->query("SELECT orden FROM dbo.paradas_viaje WHERE parada_id = {$pIds[4]}")->fetchColumn();
    $ordP3 = (int)$pdo->query("SELECT orden FROM dbo.paradas_viaje WHERE parada_id = {$pIds[3]}")->fetchColumn();
    sar_assert($ordP4 === 3 && $ordP3 === 4, "Orden persistido inesperado: p4={$ordP4}, p3={$ordP3}.");

    // Reordenamiento inválido (entrega p2 antes que recogida p1) -> 409 y sin mutar
    $postInvalid = http_build_query([
        'csrf_token' => $csrfAdmin,
        'viaje_id' => $viajeId,
        'orden_paradas' => [$pIds[2], $pIds[1], $pIds[4], $pIds[3]],
    ]);
    [$stCode, $errBody] = sar_http("{$base}/viajes/paradas_orden_guardar.php", $cookieAdmin, 'POST', $postInvalid, true);
    sar_assert($stCode === 409 && str_contains($errBody, 'recogida'), "Reordenamiento con precedencia inválida debe devolver 409 (recibido {$stCode}).");
    sar_assert((int)$pdo->query("SELECT orden FROM dbo.paradas_viaje WHERE parada_id = {$pIds[1]}")->fetchColumn() === 1, 'Reordenamiento inválido no debe alterar paradas.');

    // Editar parada NO vinculada (p4) -> 200
    $postEditOk = http_build_query([
        'csrf_token' => $csrfAdmin,
        'viaje_id' => $viajeId,
        'parada_id' => $pIds[4],
        'ubicacion_id' => $uIds[2],
        'tipo_parada' => 'MIXTA',
    ]);
    [$stCode] = sar_http("{$base}/viajes/parada_editar_guardar.php", $cookieAdmin, 'POST', $postEditOk, true);
    sar_assert($stCode === 200, "Edición de parada no vinculada falló (HTTP {$stCode}).");

    // Editar parada VINCULADA (p3) -> GET 409 y POST 409
    [$stGetLinked] = sar_http("{$base}/viajes/parada_editar_form.php?viaje_id={$viajeId}&parada_id={$pIds[3]}", $cookieAdmin, 'GET', null, true);
    sar_assert($stGetLinked === 409, "GET editar parada vinculada debe dar 409 (recibido {$stGetLinked}).");
    $postEditLinked = http_build_query([
        'csrf_token' => $csrfAdmin,
        'viaje_id' => $viajeId,
        'parada_id' => $pIds[3],
        'ubicacion_id' => $uIds[3],
        'tipo_parada' => 'INTERMEDIA',
    ]);
    [$stPostLinked] = sar_http("{$base}/viajes/parada_editar_guardar.php", $cookieAdmin, 'POST', $postEditLinked, true);
    sar_assert($stPostLinked === 409, "POST editar parada vinculada debe dar 409 (recibido {$stPostLinked}).");

    // Seguridad paradas: CSRF inválido -> 403, sin permiso VIAJES_PARADAS -> 403
    [$stCsrf] = sar_http("{$base}/viajes/paradas_orden_guardar.php", $cookieAdmin, 'POST', 'csrf_token=bad&viaje_id=' . $viajeId, true);
    sar_assert($stCsrf === 403, "CSRF inválido en reordenar debe dar 403 (recibido {$stCsrf}).");
    [$stNoPerm] = sar_http("{$base}/viajes/paradas_orden_guardar.php", $cookieNoStops, 'POST', http_build_query(['csrf_token' => $csrfNoStops, 'viaje_id' => $viajeId, 'orden_paradas' => [$pIds[1], $pIds[2]]]), true);
    sar_assert($stNoPerm === 403, "Actor sin VIAJES_PARADAS debe recibir 403 (recibido {$stNoPerm}).");

    // Escenario (g) Cambio C: modal asignar + rechazo HTTP/SQL de paradas incompatibles sin escrituras parciales
    $stSol3 = $pdo->prepare("SET NOCOUNT ON; DECLARE @m dbo.tipo_mercancias, @sid INT; INSERT INTO @m VALUES (?, N'Carga SAR3', 50, 0.5); EXEC dbo.sp_crear_solicitud @codigo=NULL,@cliente_id=?,@origen_id=?,@destino_id=?,@destinatario_nombre=N'Test',@destinatario_apellido1=N'Tres',@destinatario_apellido2=N'Ok',@destinatario_identificacion=N'101010103',@destinatario_telefono=N'88888883',@fecha_entrega_requerida=?,@mercancias=@m,@solicitud_id=@sid OUTPUT; SELECT @sid;");
    $stSol3->execute([$tipoMercId, $clienteId, $uIds[1], $uIds[4], $future]);
    $sol3Id = 0;
    do {
        if ($stSol3->columnCount() > 0) {
            $v = $stSol3->fetchColumn();
            if ($v !== false) {
                $sol3Id = (int)$v;
            }
        }
    } while ($stSol3->nextRowset());
    $stSol3->closeCursor();
    sar_assert($sol3Id > 0, 'No se pudo crear sol3 para escenario (g).');
    $solIds[] = $sol3Id;

    [$stAsgForm, $asgFormHtml] = sar_http("{$base}/viajes/accion_form.php?id={$viajeId}&accion=asignar", $cookieAdmin, 'GET', null, true);
    sar_assert($stAsgForm === 200 && str_contains($asgFormHtml, 'data-assign-stops-root') && str_contains($asgFormHtml, 'data-trip-stops='), 'Modal asignar debe incluir catálogo data-trip-stops y raíz reactiva.');

    // Intentar asignar sol3 (u1->u4) con parada orden invertido (p3->p1) y ubicación incompatible (p2->p4) -> 422 / 50312 sin escrituras
    $badAssign1 = http_build_query(['csrf_token' => $csrfAdmin, 'viaje_id' => $viajeId, 'accion' => 'asignar', 'solicitud_id' => $sol3Id, 'parada_recogida_id' => $pIds[3], 'parada_entrega_id' => $pIds[1]]);
    [$stBadAsg1, $badAsgBody1] = sar_http("{$base}/viajes/accion.php", $cookieAdmin, 'POST', $badAssign1, true);
    sar_assert($stBadAsg1 === 422 && str_contains($badAsgBody1, 'Las paradas no corresponden'), "Asignación manipulada con orden invertido debe dar 422 (recibido {$stBadAsg1}).");

    $badAssign2 = http_build_query(['csrf_token' => $csrfAdmin, 'viaje_id' => $viajeId, 'accion' => 'asignar', 'solicitud_id' => $sol3Id, 'parada_recogida_id' => $pIds[2], 'parada_entrega_id' => $pIds[4]]);
    [$stBadAsg2] = sar_http("{$base}/viajes/accion.php", $cookieAdmin, 'POST', $badAssign2, true);
    sar_assert($stBadAsg2 === 422, "Asignación manipulada con ubicaciones incompatibles debe dar 422 (recibido {$stBadAsg2}).");

    $sol3Assigned = (int)$pdo->query("SELECT COUNT(*) FROM dbo.viaje_solicitudes WHERE solicitud_id = {$sol3Id}")->fetchColumn();
    $sol3State = (string)$pdo->query("SELECT estado FROM dbo.solicitudes WHERE solicitud_id = {$sol3Id}")->fetchColumn();
    sar_assert($sol3Assigned === 0 && $sol3State === 'REGISTRADA', 'Rechazo SQL 50312 no debe dejar escrituras parciales.');

    // Sin permiso VIAJES_ASIGNAR -> 403
    $noPermAsg = http_build_query(['csrf_token' => $csrfNoStops, 'viaje_id' => $viajeId, 'accion' => 'asignar', 'solicitud_id' => $sol3Id, 'parada_recogida_id' => $pIds[1], 'parada_entrega_id' => $pIds[3]]);
    [$stNoPermAsg] = sar_http("{$base}/viajes/accion.php", $cookieNoStops, 'POST', $noPermAsg, true);
    sar_assert($stNoPermAsg === 403, "Actor sin VIAJES_ASIGNAR debe recibir 403 (recibido {$stNoPermAsg}).");

    // Iniciar viaje -> detalle oculta controles y POST devuelve 409
    $pdo->prepare('EXEC dbo.sp_iniciar_viaje @viaje_id = ?')->execute([$viajeId]);
    [$stStartedDet, $startedHtml] = sar_http("{$base}/viajes/detalle.php?id={$viajeId}", $cookieAdmin);
    sar_assert($stStartedDet === 200 && !str_contains($startedHtml, 'Reordenar paradas') && !str_contains($startedHtml, 'parada_editar_form.php'), 'Viaje EN_CURSO no debe mostrar botones de editar/reordenar paradas.');
    [$stStartedPost] = sar_http("{$base}/viajes/paradas_orden_guardar.php", $cookieAdmin, 'POST', $postValid, true);
    sar_assert($stStartedPost === 409, "Reordenar viaje EN_CURSO debe devolver 409 (recibido {$stStartedPost}).");

    echo "[OK] 1. Matriz A: Reordenamiento, edición controlada, protección referencial, viaje EN_CURSO y seguridad.\n";

    // ------------------------------------------------------------------
    // 4. MATRIZ B: Bitácora independiente (/auditoria.php)
    // ------------------------------------------------------------------
    // Actor solo AUDITORIA_VER: ve Historial de actividad, NO ve Reportes, redirect /reportes.php?seccion=auditoria -> /auditoria.php, /reportes.php -> 403
    [$stA1, $audHtml] = sar_http("{$base}/auditoria.php", $cookieAuditOnly);
    sar_assert($stA1 === 200 && str_contains($audHtml, 'href="/auditoria.php"') && !str_contains($audHtml, 'href="/reportes.php"'), 'Actor solo AUDITORIA_VER debe ver Historial de actividad y NO Reportes.');
    [$stA2, , $hA2] = sar_http("{$base}/reportes.php?seccion=auditoria", $cookieAuditOnly);
    sar_assert($stA2 === 302 && sar_header_value($hA2, 'Location') === '/auditoria.php', "Redirect /reportes.php?seccion=auditoria falló (HTTP {$stA2}).");
    [$stA3] = sar_http("{$base}/reportes.php", $cookieAuditOnly);
    sar_assert($stA3 === 403, "Actor sin REPORTES_VER debe recibir 403 en /reportes.php (recibido {$stA3}).");

    // Actor solo REPORTES_VER: ve Reportes, NO ve Historial de actividad, 403 en /auditoria.php y en /reportes.php?seccion=auditoria
    [$stR1, $repHtml] = sar_http("{$base}/reportes.php", $cookieReportOnly);
    sar_assert($stR1 === 200 && str_contains($repHtml, 'href="/reportes.php"') && !str_contains($repHtml, 'href="/auditoria.php"'), 'Actor solo REPORTES_VER debe ver Reportes y NO Historial de actividad.');
    [$stR2] = sar_http("{$base}/auditoria.php", $cookieReportOnly);
    sar_assert($stR2 === 403, "Actor sin AUDITORIA_VER debe recibir 403 en /auditoria.php (recibido {$stR2}).");
    [$stR3] = sar_http("{$base}/reportes.php?seccion=auditoria", $cookieReportOnly);
    sar_assert($stR3 === 403, "Actor sin AUDITORIA_VER debe recibir 403 en /reportes.php?seccion=auditoria (recibido {$stR3}).");

    // Filtrar en SQL por usuario inactivo + entidad paradas_viaje + acción REORDER
    $qAud = http_build_query(['usuario' => $tag . '_inact', 'entidad' => 'paradas_viaje', 'accion' => 'REORDER']);
    [$stAudList, $audListHtml] = sar_http("{$base}/auditoria/lista.php?{$qAud}", $cookieAuditOnly, 'GET', null, true);
    sar_assert($stAudList === 200, "Listado auditoría filtrado falló (HTTP {$stAudList}).");
    sar_assert(str_contains($audListHtml, $tag . '_inact') && str_contains($audListHtml, 'Cuenta inactiva'), 'Bitácora debe preservar y marcar usuarios inactivos.');
    sar_assert(str_contains($audListHtml, 'Reordenó las paradas del itinerario de un viaje'), 'Bitácora debe traducir evento REORDER a lenguaje humano.');
    sar_assert(str_contains($audListHtml, '<details class="audit-tech-details">'), 'Bitácora debe ofrecer detalle técnico desplegable.');

    // Rango invertido -> 422
    [$stAudInv] = sar_http("{$base}/auditoria/lista.php?desde=2026-12-31&hasta=2026-01-01", $cookieAuditOnly, 'GET', null, true);
    sar_assert($stAudInv === 422, "Rango invertido en bitácora debe devolver 422 (recibido {$stAudInv}).");

    echo "[OK] 2. Matriz B: Bitácora independiente, aislamiento de permisos, filtros SQL y eventos humanos.\n";

    // ------------------------------------------------------------------
    // 5. MATRIZ C: 3 Reportes + DW real read-only + CSV seguro + Impresión
    // ------------------------------------------------------------------
    // Snapshot DW antes de abrir reportes (para probar 0 escrituras / 0 ETL)
    $dw = db_dw();
    sar_assert((string)$dw->query('SELECT DB_NAME()')->fetchColumn() === 'TransportesSegurosSA_DW', 'db_dw() debe apuntar a TransportesSegurosSA_DW.');
    $dwBefore = $dw->query('SELECT COUNT(*) AS n, MAX(fecha_carga_utc) AS last_load FROM dbo.fact_entregas')->fetch();

    // Pestañas y reporte 1 (Solicitudes) + verificación Cambio A/B (sin botón Aplicar/Filtrar, nombres claros)
    [$stRepPage, $repPageHtml] = sar_http("{$base}/reportes.php?seccion=solicitudes", $cookieReportOnly);
    sar_assert($stRepPage === 200, "Página /reportes.php devolvió {$stRepPage}.");
    sar_assert(str_contains($repPageHtml, '>Solicitudes<') && str_contains($repPageHtml, '>Entregas operativas<') && str_contains($repPageHtml, '>Análisis histórico (DW)<'), 'Reportes debe mostrar las tres etiquetas inequívocas de pestañas.');
    sar_assert(!str_contains($repPageHtml, '<span>Aplicar</span>') && str_contains($repPageHtml, '>Limpiar</a>'), 'Reportes debe omitir el botón Aplicar y conservar Limpiar.');

    [$stAudPage, $audPageHtml] = sar_http("{$base}/auditoria.php", $cookieAuditOnly);
    sar_assert($stAudPage === 200 && !str_contains($audPageHtml, '<span>Filtrar</span>') && str_contains($audPageHtml, '>Limpiar</a>'), 'Historial de actividad debe omitir el botón Filtrar y conservar Limpiar.');

    [$stS1, $solRepHtml] = sar_http("{$base}/reportes/lista.php?seccion=solicitudes", $cookieReportOnly, 'GET', null, true);
    sar_assert($stS1 === 200 && str_contains($solRepHtml, 'Total solicitudes') && str_contains($solRepHtml, 'Base operacional (datos en tiempo real)') && str_contains($solRepHtml, 'data-chart-config'), 'Reporte 1 solicitudes debe mostrar KPIs, origen en tiempo real y gráficos.');

    // Reporte 2 (Entregas operativas) + caso vacío sin división por cero
    [$stE1, $entRepHtml] = sar_http("{$base}/reportes/lista.php?seccion=entregas", $cookieReportOnly, 'GET', null, true);
    sar_assert($stE1 === 200 && str_contains($entRepHtml, 'Tasa de éxito') && str_contains($entRepHtml, 'Entregadas ÷ (Entregadas + Fallidas)') && str_contains($entRepHtml, 'Base operacional (datos en tiempo real)'), 'Reporte 2 debe mostrar tasa de éxito con denominador explícito y fuente operacional.');

    [$stE0, $entEmptyHtml] = sar_http("{$base}/reportes/lista.php?seccion=entregas&desde=2099-01-01&hasta=2099-01-02", $cookieReportOnly, 'GET', null, true);
    sar_assert($stE0 === 200 && str_contains($entEmptyHtml, 'Sin datos'), 'Reporte 2 sin entregas debe mostrar "Sin datos" sin división por cero.');

    [$stRInv] = sar_http("{$base}/reportes/lista.php?seccion=entregas&desde=2026-12-31&hasta=2026-01-01", $cookieReportOnly, 'GET', null, true);
    sar_assert($stRInv === 422, "Rango invertido en reportes debe devolver 422 (recibido {$stRInv}).");

    // Reporte 3 (DW histórico real read-only)
    [$stDw, $dwRepHtml] = sar_http("{$base}/reportes/lista.php?seccion=historico_dw", $cookieReportOnly, 'GET', null, true);
    sar_assert($stDw === 200, "Reporte 3 DW falló (HTTP {$stDw}).");
    sar_assert(str_contains($dwRepHtml, 'data-dw-source="TransportesSegurosSA_DW"'), 'Reporte 3 debe confirmar origen TransportesSegurosSA_DW.');
    sar_assert(str_contains($dwRepHtml, 'Data Warehouse de solo lectura (última carga conocida:'), 'Reporte 3 debe mostrar explícitamente fuente DW solo lectura y fecha de última carga.');
    sar_assert(str_contains($dwRepHtml, 'puede no coincidir con cambios operativos recientes'), 'Reporte 3 debe aclarar que los datos ETL pueden no reflejar cambios operativos recientes.');

    $dwAfter = $dw->query('SELECT COUNT(*) AS n, MAX(fecha_carga_utc) AS last_load FROM dbo.fact_entregas')->fetch();
    sar_assert((int)$dwAfter['n'] === (int)$dwBefore['n'] && $dwAfter['last_load'] === $dwBefore['last_load'], 'Consultar reporte DW no debe alterar hechos ni disparar ETL.');

    // Exportación CSV (solicitudes, entregas, historico_dw)
    foreach (['solicitudes', 'entregas', 'historico_dw'] as $sec) {
        [$stCsv, $csvBody, $csvHdrs] = sar_http("{$base}/reportes/exportar.php?seccion={$sec}&formato=csv", $cookieReportOnly);
        sar_assert($stCsv === 200, "Exportación CSV {$sec} falló (HTTP {$stCsv}).");
        $ct = sar_header_value($csvHdrs, 'Content-Type') ?? '';
        sar_assert(stripos($ct, 'text/csv') !== false && str_starts_with($csvBody, "\xEF\xBB\xBF"), "CSV {$sec} debe tener text/csv y BOM UTF-8.");
    }

    // Vista de impresión / PDF sin sidebar ni inline scripts
    [$stPrt, $prtHtml] = sar_http("{$base}/reportes/exportar.php?seccion=historico_dw&formato=imprimir", $cookieReportOnly);
    sar_assert($stPrt === 200, "Vista imprimible DW falló (HTTP {$stPrt}).");
    sar_assert(str_contains($prtHtml, 'data-print-report') && str_contains($prtHtml, 'TransportesSegurosSA_DW') && !str_contains($prtHtml, '<aside class="sidebar"'), 'Vista imprimible debe omitir sidebar e incluir fuente DW y botón imprimir.');

    echo "[OK] 3. Matriz C: 3 reportes, conexión DW real solo lectura (0 ETL), CSV BOM seguro e impresión limpia.\n";
    echo "=== SUITE HTTP STOPS + AUDIT + REPORTS + DW: 100% PASS ===\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'FAIL: ' . $ex->getMessage() . "\n");
    $exitCode = 1;
} finally {
    // 1. Apagar servidor PHP efímero
    if (is_resource($serverProc)) {
        if (PHP_OS_FAMILY === 'Windows' && is_int($serverPid) && $serverPid > 0) {
            @exec("taskkill /F /T /PID {$serverPid} >NUL 2>&1");
        }
        @proc_terminate($serverProc);
        @proc_close($serverProc);
    }
    if (is_dir($sessionDir)) {
        foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($sessionDir);
    }
    if (is_file($serverLog)) {
        @unlink($serverLog);
    }

    // 2. Limpieza estricta de todos los fixtures en TransportesSegurosSA_Test
    try {
        if ($viajeId !== null) {
            $pdo->prepare('DELETE FROM dbo.viaje_solicitudes WHERE viaje_id = ?')->execute([$viajeId]);
            $pdo->prepare('DELETE FROM dbo.paradas_viaje WHERE viaje_id = ?')->execute([$viajeId]);
            $pdo->prepare('DELETE FROM dbo.viajes WHERE viaje_id = ?')->execute([$viajeId]);
        }
        foreach ($solIds as $sid) {
            $pdo->prepare('DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM dbo.mercancias WHERE solicitud_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM dbo.solicitudes WHERE solicitud_id = ?')->execute([$sid]);
        }
        if ($rutaId !== null) {
            $pdo->prepare('DELETE FROM dbo.rutas WHERE ruta_id = ?')->execute([$rutaId]);
        }
        if ($vehId !== null) {
            $pdo->prepare('DELETE FROM dbo.vehiculos WHERE vehiculo_id = ?')->execute([$vehId]);
        }
        if ($condId !== null) {
            $pdo->prepare('DELETE FROM dbo.conductores_licencias WHERE conductor_id = ?')->execute([$condId]);
            $pdo->prepare('DELETE FROM dbo.conductores WHERE conductor_id = ?')->execute([$condId]);
        }
        foreach ($uIds as $uid) {
            $pdo->prepare('DELETE FROM dbo.ubicaciones WHERE ubicacion_id = ?')->execute([$uid]);
        }
        $pdo->prepare('DELETE FROM dbo.auditorias WHERE auditoria_id > ?')->execute([$maxAudId]);
        foreach ($createdUsers as $cuid) {
            $pdo->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([$cuid]);
            $pdo->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([$cuid]);
        }
    } catch (Throwable $cleanEx) {
        fwrite(STDERR, 'CLEANUP WARNING: ' . $cleanEx->getMessage() . "\n");
        $exitCode = 1;
    }
}

exit($exitCode);
