<?php
declare(strict_types=1);

/**
 * Suite funcional HTTP y de seguridad del cierre YAGNI:
 * - Licencia única visible en conductores sin default B4 y resolución de categorías heredadas.
 * - Vehículos sin odómetro ni selector ordinario de homologación, preservación de km y NO_CONFORME, coherencia PMA.
 * - Modales coherentes (cierre solo tras éxito confirmado, Brevo 201 vs Brevo fallo vs Fake).
 * - Invitaciones revocables (PENDIENTE -> REVOCADA), expiradas cancelables (EXPIRADA -> CANCELADA),
 *   protección de COMPLETADA (409), permisos planificados en reenvío, CSRF y permisos granulares (403).
 *
 * Opera EXCLUSIVAMENTE sobre registros efímeros en TransportesSegurosSA_Test.
 */

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';

function yg_fail(string $message): never
{
    throw new RuntimeException("FALLO YAGNI SUITE: {$message}");
}

function yg_assert(bool $condition, string $message): void
{
    if (!$condition) {
        yg_fail($message);
    }
}

function yg_free_port(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        yg_fail('No se pudo reservar puerto local.');
    }
    $name = (string)stream_socket_get_name($sock, false);
    fclose($sock);
    return (int)substr($name, strrpos($name, ':') + 1);
}

/** @return array{0:int,1:string,2:array<int,string>} */
function yg_http(string $url, string $cookie, bool $htmx = true, ?array $postFields = null): array
{
    $headers = "Cookie: {$cookie}\r\nAccept: text/html\r\nConnection: close\r\n" . ($htmx ? "HX-Request: true\r\n" : '');
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
    $ctx = stream_context_create(['http' => $options]);
    $body = @file_get_contents($url, false, $ctx);
    $respHeaders = $http_response_header ?? [];
    $status = (int)(preg_match('/\s(\d{3})\s/', $respHeaders[0] ?? '', $m) ? $m[1] : 0);
    return [$status, $body === false ? '' : $body, $respHeaders];
}

function yg_header_value(array $headers, string $name): ?string
{
    $prefix = strtolower($name) . ':';
    foreach ($headers as $h) {
        if (str_starts_with(strtolower($h), $prefix)) {
            return trim(substr($h, strlen($prefix)));
        }
    }
    return null;
}

/** @return array{process:resource,pipes:array<int,resource>,log:string,port:int,base:string} */
function yg_start_server(string $docRoot, array $extraEnv = []): array
{
    $port = yg_free_port();
    $base = "http://127.0.0.1:{$port}";
    $log = (string)tempnam(sys_get_temp_dir(), 'tssa-yagni-srv-');
    $env = array_merge(getenv(), [
        'TSSA_DB_DATABASE' => 'TransportesSegurosSA_Test',
        'TSSA_APP_URL' => $base,
    ], $extraEnv);
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docRoot],
        [0 => ['file', 'NUL', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        dirname(__DIR__),
        $env
    );
    yg_assert(is_resource($proc), 'No se pudo iniciar servidor PHP aislado.');

    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        $s = @fsockopen('127.0.0.1', $port);
        if ($s !== false) {
            fclose($s);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    yg_assert($ready, "El servidor PHP en puerto {$port} no respondió.");
    return ['process' => $proc, 'pipes' => $pipes, 'log' => $log, 'port' => $port, 'base' => $base];
}

function yg_stop_server(?array $srv): void
{
    if (!is_array($srv)) {
        return;
    }
    foreach ($srv['pipes'] ?? [] as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
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

// =========================================================================
// PREFLIGHT CRÍTICO DE BASE DE DATOS TEST
// =========================================================================
yg_assert(configured_database_name() === 'TransportesSegurosSA_Test', 'TSSA_DB_DATABASE debe apuntar a TransportesSegurosSA_Test.');
$pdo = db();
require_test_database($pdo);
$effectiveDb = (string)$pdo->query('SELECT DB_NAME()')->fetchColumn();
yg_assert($effectiveDb === 'TransportesSegurosSA_Test', "DB_NAME() efectivo inesperado: {$effectiveDb}");
echo "Preflight DB_NAME() verificado: {$effectiveDb}\n";

$suffix = bin2hex(random_bytes(4));
$sessionName = session_cookie_name();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$cleanup = [
    'viaje_ids' => [],
    'vehiculo_ids' => [],
    'conductor_ids' => [],
    'invitacion_ids' => [],
    'usuario_ids' => [],
    'session_ids' => [],
    'temp_dirs' => [],
];

$makeSession = static function (int $uid) use ($sessionName, &$cleanup): array {
    $savePath = session_save_path() ?: sys_get_temp_dir();
    $sid = bin2hex(random_bytes(16));
    $csrf = bin2hex(random_bytes(32));
    $content = sprintf(
        'usuario_id|i:%d;authenticated_at|i:%d;last_activity_at|i:%d;csrf_token|s:64:"%s";',
        $uid,
        time(),
        time(),
        $csrf
    );
    $filePath = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    file_put_contents($filePath, $content);
    $cleanup['session_ids'][] = $filePath;
    return ["{$sessionName}={$sid}", $csrf];
};

$srvFake = null;
$srvBrevo = null;
$srvMockBrevo = null;

try {
    // Crear dos usuarios efímeros (NO modificar admin_proyecto):
    // 1) Admin efímero con todos los permisos activos
    // 2) Usuario restringido sin permisos de escritura
    $adminEphName = "__yagni_adm_{$suffix}";
    $limEphName = "__yagni_lim_{$suffix}";

    $insU = $pdo->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) OUTPUT INSERTED.usuario_id VALUES (?, ?, ?, 1)');
    $insU->execute([$adminEphName, "{$adminEphName}@example.test", password_hash('ClaveAdminEfimera2026!' . $suffix, PASSWORD_BCRYPT)]);
    $adminEphId = (int)$insU->fetchColumn();
    $insU->closeCursor();
    $cleanup['usuario_ids'][] = $adminEphId;

    $pdo->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE activo = 1')->execute([$adminEphId]);

    $insU->execute([$limEphName, "{$limEphName}@example.test", password_hash('ClaveLimitadaEfimera2026!' . $suffix, PASSWORD_BCRYPT)]);
    $limEphId = (int)$insU->fetchColumn();
    $insU->closeCursor();
    $cleanup['usuario_ids'][] = $limEphId;

    $pdo->prepare("INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo IN (N'FLOTAS_VER', N'USUARIOS_VER')")->execute([$limEphId]);

    [$adminCookie, $adminCsrf] = $makeSession($adminEphId);
    [$limCookie, $limCsrf] = $makeSession($limEphId);

    $publicDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';
    $srvFake = yg_start_server($publicDir, ['TSSA_MAIL_TRANSPORT' => 'fake']);
    $base = $srvFake['base'];

    // =========================================================================
    // 1. CONDUCTORES Y LICENCIA ÚNICA SIN B4 POR DEFECTO
    // =========================================================================
    [$st, $condFormHtml] = yg_http($base . '/flota/form.php?entidad=conductor', $adminCookie);
    yg_assert($st === 200, "GET form conductor debe responder 200 (obtenido {$st}).");
    yg_assert(!str_contains($condFormHtml, 'license-selector-table'), 'No debe renderizar la tabla legacy de 14 licencias.');
    yg_assert(!str_contains($condFormHtml, 'licencia_estado['), 'No debe renderizar selectores de verificación por fila.');
    yg_assert(str_contains($condFormHtml, 'name="codigo_licencia"'), 'Debe incluir un único selector codigo_licencia.');
    yg_assert(!preg_match('/value="B4"[^>]*selected/', $condFormHtml), 'Al crear conductor NO debe venir B4 preseleccionada.');
    yg_assert(str_contains($condFormHtml, 'name="fecha_vencimiento_licencia"') && str_contains($condFormHtml, 'value=""'), 'La fecha de vencimiento debe iniciar vacía.');

    $future1 = gmdate('Y-m-d', time() + 86400 * 365);
    $future2 = gmdate('Y-m-d', time() + 86400 * 500);
    $condIdent = '__YGC_' . strtoupper($suffix);
    $condLic = '__YGL_' . strtoupper($suffix);

    // 1.1 Alta sin categoría -> 422, sin insertar B4
    [$st, $body] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'conductor',
        'identificacion' => $condIdent,
        'nombre' => 'Sofía',
        'primer_apellido' => 'Calvo',
        'numero_licencia' => $condLic,
        'codigo_licencia' => '',
        'fecha_vencimiento_licencia' => $future1,
        'estado_operativo' => 'DISPONIBLE',
    ]);
    yg_assert($st === 422, "Alta conductor sin categoría debe responder 422 (obtenido {$st}).");

    // 1.2 Categoría inexistente / manipulada -> 422
    [$st] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'conductor',
        'identificacion' => $condIdent,
        'nombre' => 'Sofía',
        'primer_apellido' => 'Calvo',
        'numero_licencia' => $condLic,
        'codigo_licencia' => 'Z9',
        'fecha_vencimiento_licencia' => $future1,
        'estado_operativo' => 'DISPONIBLE',
    ]);
    yg_assert($st === 422, 'Categoría inválida Z9 debe responder 422.');

    // 1.3 Fecha vencida y formato inválido -> 422
    foreach (['2020-01-01', '31/12/2029', '2029-02-30', ''] as $badDate) {
        [$st] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
            'csrf_token' => $adminCsrf,
            'entidad' => 'conductor',
            'identificacion' => $condIdent,
            'nombre' => 'Sofía',
            'primer_apellido' => 'Calvo',
            'numero_licencia' => $condLic,
            'codigo_licencia' => 'B2',
            'fecha_vencimiento_licencia' => $badDate,
            'estado_operativo' => 'DISPONIBLE',
        ]);
        yg_assert($st === 422, "Fecha inválida/vencida '{$badDate}' debe responder 422 (obtenido {$st}).");
    }

    // 1.4 Alta válida con B2 + fecha futura
    [$st, $body, $hdrs] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'conductor',
        'identificacion' => $condIdent,
        'nombre' => 'Sofía',
        'primer_apellido' => 'Calvo',
        'numero_licencia' => $condLic,
        'codigo_licencia' => 'B2',
        'fecha_vencimiento_licencia' => $future1,
        'estado_operativo' => 'DISPONIBLE',
    ]);
    yg_assert($st === 200, "Alta conductor B2 debe responder 200 (obtenido {$st}): {$body}");
    $triggerRaw = yg_header_value($hdrs, 'HX-Trigger-After-Settle');
    $triggerJson = json_decode((string)$triggerRaw, true);
    yg_assert(($triggerJson['maestroActualizado'] ?? false) === true && ($triggerJson['tssaNotice']['closeModal'] ?? false) === true, 'Éxito de conductor debe emitir tssaNotice con closeModal=true.');

    $stFindC = $pdo->prepare('SELECT conductor_id, fecha_vencimiento_licencia FROM dbo.conductores WHERE identificacion = ?');
    $stFindC->execute([$condIdent]);
    $cRow = $stFindC->fetch();
    $stFindC->closeCursor();
    yg_assert(is_array($cRow), 'Conductor B2 debe existir en BD.');
    $condId = (int)$cRow['conductor_id'];
    $cleanup['conductor_ids'][] = $condId;

    $stLics = $pdo->prepare('SELECT codigo_licencia, fecha_vencimiento, estado_verificacion FROM dbo.conductores_licencias WHERE conductor_id = ?');
    $stLics->execute([$condId]);
    $licRows = $stLics->fetchAll();
    $stLics->closeCursor();
    yg_assert(count($licRows) === 1 && $licRows[0]['codigo_licencia'] === 'B2' && substr((string)$licRows[0]['fecha_vencimiento'], 0, 10) === $future1, 'Conductor debe tener exactamente 1 categoría B2 con fecha sincronizada.');

    // 1.5 Reabrir edición: B2 preseleccionada y fecha cargada; editar solo fecha conserva B2
    [$st, $editCondHtml] = yg_http($base . "/flota/form.php?entidad=conductor&id={$condId}", $adminCookie);
    yg_assert($st === 200 && preg_match('/value="B2"[^>]*selected/', $editCondHtml) === 1 && str_contains($editCondHtml, $future1), 'Edición debe precargar B2 y su fecha.');

    [$st] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'conductor',
        'id' => $condId,
        'nombre' => 'Sofía',
        'primer_apellido' => 'Calvo',
        'numero_licencia' => $condLic,
        'codigo_licencia' => 'B2',
        'fecha_vencimiento_licencia' => $future2,
        'estado_operativo' => 'DISPONIBLE',
    ]);
    yg_assert($st === 200, 'Editar solo vencimiento con B2 debe responder 200.');

    // 1.6 Cambiar B2 -> B1: verificar que B2 desaparece y no habilita camión de 7.500 kg
    [$st] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'conductor',
        'id' => $condId,
        'nombre' => 'Sofía',
        'primer_apellido' => 'Calvo',
        'numero_licencia' => $condLic,
        'codigo_licencia' => 'B1',
        'fecha_vencimiento_licencia' => $future2,
        'estado_operativo' => 'DISPONIBLE',
    ]);
    yg_assert($st === 200, 'Cambio B2->B1 con nueva fecha debe responder 200.');

    $stLics->execute([$condId]);
    $licRowsAfter = $stLics->fetchAll();
    $stLics->closeCursor();
    yg_assert(count($licRowsAfter) === 1 && $licRowsAfter[0]['codigo_licencia'] === 'B1', 'Tras cambiar a B1 no debe quedar ninguna fila B2 oculta.');

    // 1.7 Conductor con múltiples categorías heredadas (B1 + B4) y conductor solo PENDIENTE
    $pdo->prepare("INSERT INTO dbo.conductores_licencias (conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento, estado_verificacion, fecha_verificacion, usuario_verificador_id) VALUES (?, 'B4', ?, ?, 'VERIFICADA', SYSUTCDATETIME(), ?)")->execute([$condId, $condLic, $future2, $adminEphId]);

    [$st, $multiHtml] = yg_http($base . "/flota/form.php?entidad=conductor&id={$condId}", $adminCookie);
    yg_assert($st === 200 && str_contains($multiHtml, 'varias categorías heredadas'), 'Conductor multicategoría heredado debe mostrar advertencia explícita.');
    yg_assert(!preg_match('/value="B[14]"[^>]*selected/', $multiHtml), 'Conductor multicategoría NO debe preseleccionar arbitrariamente ninguna categoría.');

    // Resolver eligiendo explícitamente B1
    [$st] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'conductor',
        'id' => $condId,
        'nombre' => 'Sofía',
        'primer_apellido' => 'Calvo',
        'numero_licencia' => $condLic,
        'codigo_licencia' => 'B1',
        'fecha_vencimiento_licencia' => $future2,
        'estado_operativo' => 'DISPONIBLE',
    ]);
    yg_assert($st === 200, 'Resolución de conductor heredado a B1 debe responder 200.');
    $stLics->execute([$condId]);
    $licRowsResolved = $stLics->fetchAll();
    $stLics->closeCursor();
    yg_assert(count($licRowsResolved) === 1 && $licRowsResolved[0]['codigo_licencia'] === 'B1', 'Tras resolución solo debe permanecer B1.');

    // Verificar que una fila PENDIENTE única no se preselecciona ni se promociona sola
    $pdo->prepare("UPDATE dbo.conductores_licencias SET estado_verificacion = 'PENDIENTE' WHERE conductor_id = ?")->execute([$condId]);
    [$st, $pendHtml] = yg_http($base . "/flota/form.php?entidad=conductor&id={$condId}", $adminCookie);
    yg_assert($st === 200 && !preg_match('/value="B1"[^>]*selected/', $pendHtml) && str_contains($pendHtml, 'no tiene una categoría habilitada vigente'), 'Fila PENDIENTE no debe preseleccionarse ni promocionarse en silencio.');

    // Restaurar a B1 mediante flujo autorizado
    [$st] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'conductor',
        'id' => $condId,
        'nombre' => 'Sofía',
        'primer_apellido' => 'Calvo',
        'numero_licencia' => $condLic,
        'codigo_licencia' => 'B1',
        'fecha_vencimiento_licencia' => $future2,
        'estado_operativo' => 'DISPONIBLE',
    ]);
    yg_assert($st === 200, 'Reconfirmación explícita a B1 debe responder 200.');
    echo "[OK] Sección 1: Conductores, licencia única, resolución heredada y sin default B4.\n";

    // =========================================================================
    // 2. VEHÍCULOS: PMA, COHERENCIA, PRESERVACIÓN DE KM Y NO_CONFORME, VIAJES
    // =========================================================================
    [$st, $vehFormHtml] = yg_http($base . '/flota/form.php?entidad=vehiculo', $adminCookie);
    yg_assert($st === 200, 'GET form vehículo debe responder 200.');
    yg_assert(!str_contains($vehFormHtml, 'kilometraje_actual'), 'El formulario de vehículo no debe mostrar odómetro/kilometraje.');
    yg_assert(!str_contains($vehFormHtml, 'estado_homologacion'), 'El formulario ordinario de vehículo no debe mostrar selector de homologación.');
    yg_assert(substr_count($vehFormHtml, 'pma-help-callout') === 1, 'Debe mostrar exactamente una ayuda breve sobre Carga útil y PMA.');
    yg_assert(str_contains($vehFormHtml, 'id="veh-clase" name="clase_legal" data-combobox') && str_contains($vehFormHtml, 'id="veh-estado" name="estado" data-combobox'), 'Clasificación legal y estado operativo deben usar data-combobox.');

    $placa7500 = 'YG' . strtoupper(substr($suffix, 0, 5)) . '1';
    $placaArt = 'YG' . strtoupper(substr($suffix, 0, 5)) . '2';

    // Coherencia inválida: carga útil > PMA -> 422; articulado con LIVIANO -> 422; LIVIANO con PMA 7500 -> 422
    foreach ([
        ['carga' => '8000', 'pma' => '7500', 'clase' => 'MEDIANO_RIGIDO', 'art' => '0'],
        ['carga' => '1000', 'pma' => '3500', 'clase' => 'LIVIANO', 'art' => '1'],
        ['carga' => '2000', 'pma' => '7500', 'clase' => 'LIVIANO', 'art' => '0'],
        ['carga' => '5000', 'pma' => '12000', 'clase' => 'PESADO_ARTICULADO', 'art' => '0'],
    ] as $badVeh) {
        [$st] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
            'csrf_token' => $adminCsrf,
            'entidad' => 'vehiculo',
            'placa' => $placa7500,
            'marca' => 'Isuzu',
            'modelo' => 'NPR',
            'anio' => '2025',
            'capacidad_carga_kg' => $badVeh['carga'],
            'pma_kg' => $badVeh['pma'],
            'clase_legal' => $badVeh['clase'],
            'es_articulado' => $badVeh['art'] === '1' ? '1' : '',
            'estado' => 'DISPONIBLE',
        ]);
        yg_assert($st === 422, 'Vehículo con coherencia inválida debe responder 422.');
    }

    // Crear camión 7.500 kg (MEDIANO_RIGIDO)
    [$st, $body] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'vehiculo',
        'placa' => $placa7500,
        'marca' => 'Isuzu',
        'modelo' => 'NPR 7500',
        'anio' => '2025',
        'capacidad_carga_kg' => '4000',
        'pma_kg' => '7500',
        'clase_legal' => 'MEDIANO_RIGIDO',
        'estado' => 'DISPONIBLE',
    ]);
    yg_assert($st === 200, "Alta vehículo 7500 kg debe responder 200: {$body}");

    $stFindV = $pdo->prepare('SELECT vehiculo_id, kilometraje_actual, estado_homologacion FROM dbo.vehiculos WHERE placa = ?');
    $stFindV->execute([$placa7500]);
    $vRow = $stFindV->fetch();
    $stFindV->closeCursor();
    yg_assert(is_array($vRow), 'Vehículo 7500 kg debe existir en BD.');
    $veh7500Id = (int)$vRow['vehiculo_id'];
    $cleanup['vehiculo_ids'][] = $veh7500Id;
    yg_assert($vRow['estado_homologacion'] === 'PENDIENTE_VERIFICACION', 'Nuevo vehículo no debe fingir homologación oficial MOPT (debe iniciar en PENDIENTE_VERIFICACION).');

    // Simular kilometraje previo 15432.5 y bloqueo NO_CONFORME
    $pdo->prepare("UPDATE dbo.vehiculos SET kilometraje_actual = 15432.5, estado_homologacion = 'NO_CONFORME' WHERE vehiculo_id = ?")->execute([$veh7500Id]);

    // Verificar aviso en formulario de edición y que editar sin odómetro ni levantar bloqueo (aunque se intente forjar estado_homologacion=VERIFICADO) preserva km y NO_CONFORME
    [$st, $editVehHtml] = yg_http($base . "/flota/form.php?entidad=vehiculo&id={$veh7500Id}", $adminCookie);
    yg_assert($st === 200 && !str_contains($editVehHtml, 'Odómetro registrado') && str_contains($editVehHtml, 'Unidad bloqueada para viajes'), 'Edición de vehículo NO_CONFORME debe mostrar aviso de bloqueo sin odómetro.');

    [$st, $body] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'vehiculo',
        'id' => $veh7500Id,
        'marca' => 'Isuzu Edit',
        'modelo' => 'NPR 7500',
        'anio' => '2025',
        'capacidad_carga_kg' => '4100',
        'pma_kg' => '7500',
        'clase_legal' => 'MEDIANO_RIGIDO',
        'estado_homologacion' => 'VERIFICADO', // Intento malicioso de forjar promoción
        'estado' => 'DISPONIBLE',
    ]);
    yg_assert($st === 200, "Edición ordinaria de vehículo debe responder 200: {$body}");

    $stFindV->execute([$placa7500]);
    $vAfterEdit = $stFindV->fetch();
    $stFindV->closeCursor();
    yg_assert((float)$vAfterEdit['kilometraje_actual'] === 15432.5, 'Editar vehículo sin odómetro NO debe reiniciar kilometraje_actual.');
    yg_assert($vAfterEdit['estado_homologacion'] === 'NO_CONFORME', 'Editar vehículo NO_CONFORME NO debe promocionarlo en silencio aunque se envíe estado_homologacion en POST.');

    // Listado de vehículos: sin "Capacidad y odómetro" ni "Homol:" pero mostrando bloqueo real
    [$st, $vehListHtml] = yg_http($base . "/flota/lista.php?seccion=vehiculos&buscar={$placa7500}", $adminCookie);
    yg_assert($st === 200 && str_contains($vehListHtml, 'Capacidad de carga') && !str_contains($vehListHtml, 'Capacidad y odómetro') && !str_contains($vehListHtml, 'Homol:') && str_contains($vehListHtml, 'Bloqueado (No conforme)'), 'Listado de vehículos debe mostrar Capacidad de carga y aviso de bloqueo sin odómetro ni Homol.');

    // Levantar bloqueo explícitamente
    [$st] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'vehiculo',
        'id' => $veh7500Id,
        'marca' => 'Isuzu Edit',
        'modelo' => 'NPR 7500',
        'anio' => '2025',
        'capacidad_carga_kg' => '4100',
        'pma_kg' => '7500',
        'clase_legal' => 'MEDIANO_RIGIDO',
        'levantar_bloqueo_no_conforme' => '1',
        'estado' => 'DISPONIBLE',
    ]);
    yg_assert($st === 200, 'Levantamiento administrativo explícito debe responder 200.');
    $stFindV->execute([$placa7500]);
    $vUnblocked = $stFindV->fetch();
    $stFindV->closeCursor();
    yg_assert($vUnblocked['estado_homologacion'] === 'PENDIENTE_VERIFICACION' && (float)$vUnblocked['kilometraje_actual'] === 15432.5, 'Levantamiento explícito debe habilitar operación preservando kilometraje.');

    // 2.2 Compatibilidad de viajes vía HTTP: B1 -> camión 7500 kg RECHAZA (422); B2 -> camión 7500 kg ACEPTA (200)
    $tripDate = (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
    [$st, $tripBadBody] = yg_http($base . '/viajes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'vehiculo_id' => $veh7500Id,
        'conductor_id' => $condId,
        'ruta_id' => 1,
        'fecha_programada' => $tripDate,
    ]);
    yg_assert($st === 422, "B1 frente a PMA 7500 kg debe rechazar viaje con 422 (obtenido {$st}).");

    // Actualizar conductor a B2 y reintentar viaje -> 200
    [$st] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'conductor',
        'id' => $condId,
        'nombre' => 'Sofía',
        'primer_apellido' => 'Calvo',
        'numero_licencia' => $condLic,
        'codigo_licencia' => 'B2',
        'fecha_vencimiento_licencia' => $future2,
        'estado_operativo' => 'DISPONIBLE',
    ]);
    yg_assert($st === 200, 'Actualización a B2 debe responder 200.');

    [$st, $tripOkBody, $tripHdrs] = yg_http($base . '/viajes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'vehiculo_id' => $veh7500Id,
        'conductor_id' => $condId,
        'ruta_id' => 1,
        'fecha_programada' => $tripDate,
    ]);
    yg_assert($st === 200, "B2 frente a PMA 7500 kg debe crear viaje con 200 (obtenido {$st}): {$tripOkBody}");
    $hxRedirect = (string)yg_header_value($tripHdrs, 'HX-Redirect');
    if (preg_match('/id=(\d+)/', $hxRedirect, $mTrip)) {
        $cleanup['viaje_ids'][] = (int)$mTrip[1];
    }

    // Conductor asignado en viaje activo NO puede cambiar de licencia -> 409
    [$st] = yg_http($base . '/flota/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'entidad' => 'conductor',
        'id' => $condId,
        'nombre' => 'Sofía',
        'primer_apellido' => 'Calvo',
        'numero_licencia' => $condLic,
        'codigo_licencia' => 'B1',
        'fecha_vencimiento_licencia' => $future2,
        'estado_operativo' => 'DISPONIBLE',
    ]);
    yg_assert($st === 409, "Cambiar licencia de conductor con viaje activo debe responder 409 (obtenido {$st}).");
    echo "[OK] Sección 2: Vehículos, coherencia PMA, preservación km/NO_CONFORME y viajes B1/B2.\n";

    // =========================================================================
    // 3. INVITACIONES: FAKE, BREVO 201, BREVO FALLO, REVOCAR, EXPIRADA, COMPLETADA
    // =========================================================================
    $invEmailFake = "yagni.fake.{$suffix}@example.test";
    [$st, $invBody, $invHdrs] = yg_http($base . '/usuarios/invitar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'correo' => $invEmailFake,
        'permisos' => ['FLOTAS_VER', 'CLIENTES_VER'],
    ]);
    yg_assert($st === 200, "Invitar en modo Fake debe responder 200 (obtenido {$st}): {$invBody}");
    $trigFake = json_decode((string)yg_header_value($invHdrs, 'HX-Trigger-After-Settle'), true);
    yg_assert(($trigFake['tssaNotice']['closeModal'] ?? false) === true, 'Invitación Fake exitosa debe cerrar modal automáticamente.');
    yg_assert(str_contains((string)($trigFake['tssaNotice']['message'] ?? ''), 'modo prueba') && !str_contains(strtolower((string)($trigFake['tssaNotice']['message'] ?? '')), 'correo enviado'), 'Modo Fake debe indicar modo prueba y no correo enviado.');

    $stFindInv = $pdo->prepare('SELECT invitacion_id, token_hash, estado, estado_envio FROM dbo.invitaciones_usuario WHERE correo_destino = ? ORDER BY invitacion_id DESC');
    $stFindInv->execute([$invEmailFake]);
    $invFakeRow = $stFindInv->fetch();
    $stFindInv->closeCursor();
    $invFakeId = (int)$invFakeRow['invitacion_id'];
    $cleanup['invitacion_ids'][] = $invFakeId;

    // 3.1 Revocar invitación PENDIENTE vigente -> REVOCADA, token inválido, toast closeModal=false
    [$st, $revBody, $revHdrs] = yg_http($base . '/usuarios/revocar_invitacion.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'invitacion_id' => $invFakeId,
    ]);
    yg_assert($st === 200, "Revocar invitación pendiente debe responder 200 (obtenido {$st}).");
    $trigRev = json_decode((string)yg_header_value($revHdrs, 'HX-Trigger-After-Settle'), true);
    yg_assert(($trigRev['tssaNotice']['closeModal'] ?? true) === false, 'Revocar en lista debe emitir toast con closeModal=false.');

    $stFindInv->execute([$invEmailFake]);
    $invRevRow = $stFindInv->fetch();
    $stFindInv->closeCursor();
    yg_assert($invRevRow['estado'] === 'REVOCADA', 'Invitación pendiente revocada debe quedar REVOCADA.');

    $stValTok = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
    $stValTok->execute([$invRevRow['token_hash']]);
    $valRes = $stValTok->fetch();
    $stValTok->closeCursor();
    yg_assert(($valRes['resultado'] ?? '') === 'revocada', 'Token de invitación revocada debe quedar inválido.');

    // 3.2 Invitación EXPIRADA -> lista muestra botón Cancelar + Reenviar; revocar/descartar -> CANCELADA
    $invEmailExp = "yagni.exp.{$suffix}@example.test";
    [$st] = yg_http($base . '/usuarios/invitar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'correo' => $invEmailExp,
        'permisos' => ['CLIENTES_VER'],
    ]);
    yg_assert($st === 200, 'Crear invitación para prueba expirada debe responder 200.');
    $stFindInv->execute([$invEmailExp]);
    $invExpRow = $stFindInv->fetch();
    $stFindInv->closeCursor();
    $invExpId = (int)$invExpRow['invitacion_id'];
    $cleanup['invitacion_ids'][] = $invExpId;

    $pdo->prepare("UPDATE dbo.invitaciones_usuario SET estado = 'EXPIRADA', fecha_expiracion = DATEADD(HOUR, -3, SYSUTCDATETIME()) WHERE invitacion_id = ?")->execute([$invExpId]);

    [$st, $invListHtml] = yg_http($base . '/usuarios/invitaciones_lista.php', $adminCookie);
    yg_assert($st === 200 && str_contains($invListHtml, 'Venció:') && str_contains($invListHtml, 'Reenviar') && !str_contains($invListHtml, '>Cancelar<'), 'Listado debe mostrar fecha real de expiración y solo acción Reenviar (sin botón Cancelar) para invitaciones expiradas.');
    yg_assert(!str_contains($invListHtml, (string)$invExpRow['token_hash']), 'El listado jamás debe exponer hashes ni tokens.');

    [$st, $cancelExpBody] = yg_http($base . '/usuarios/revocar_invitacion.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'invitacion_id' => $invExpId,
    ]);
    yg_assert($st === 200, 'Descartar invitación expirada debe responder 200.');
    $stFindInv->execute([$invEmailExp]);
    $invExpAfter = $stFindInv->fetch();
    $stFindInv->closeCursor();
    yg_assert($invExpAfter['estado'] === 'CANCELADA', 'Invitación expirada descartada debe quedar CANCELADA sin borrarse.');

    // 3.3 Invitación COMPLETADA: imposible revocar (HTTP 409 + HX-Retarget:#invitaciones-feedback)
    $invEmailComp = "yagni.comp.{$suffix}@example.test";
    $pdo->prepare("INSERT INTO dbo.invitaciones_usuario (correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id, fecha_completada, completado_usuario_id) VALUES (?, ?, 'COMPLETADA', DATEADD(HOUR, 24, SYSUTCDATETIME()), ?, SYSUTCDATETIME(), ?)")
        ->execute([$invEmailComp, hash('sha256', 'comp_' . $suffix), $adminEphId, $limEphId]);
    $stFindInv->execute([$invEmailComp]);
    $invCompId = (int)$stFindInv->fetch()['invitacion_id'];
    $stFindInv->closeCursor();
    $cleanup['invitacion_ids'][] = $invCompId;

    [$st, $compRevBody, $compRevHdrs] = yg_http($base . '/usuarios/revocar_invitacion.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'invitacion_id' => $invCompId,
    ]);
    yg_assert($st === 409, "Revocar invitación completada debe responder 409 (obtenido {$st}).");
    yg_assert(yg_header_value($compRevHdrs, 'HX-Retarget') === '#invitaciones-feedback', 'Error al revocar debe retargetear a #invitaciones-feedback sin borrar la tabla.');

    // 3.4 Mock local de Brevo: caso HTTP 201 (aceptado -> cierra modal) vs HTTP 500 (fallido -> modal abierto sin falso éxito)
    $mockDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tssa-brevo-mock-' . $suffix;
    mkdir($mockDir, 0700, true);
    $cleanup['temp_dirs'][] = $mockDir;
    $mockModeFile = $mockDir . DIRECTORY_SEPARATOR . 'mode.txt';
    file_put_contents($mockModeFile, '201');
    file_put_contents($mockDir . DIRECTORY_SEPARATOR . 'index.php', <<<'PHP'
<?php
$mode = trim((string)@file_get_contents(__DIR__ . '/mode.txt'));
header('Content-Type: application/json');
if ($mode === '201') {
    http_response_code(201);
    echo json_encode(['messageId' => '<mock-ok-201@brevo.test>']);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Simulated provider failure']);
}
PHP);

    $srvMockBrevo = yg_start_server($mockDir);
    $srvBrevo = yg_start_server($publicDir, [
        'TSSA_MAIL_TRANSPORT' => 'brevo',
        'BREVO_API_BASE_URL' => $srvMockBrevo['base'],
        'BREVO_API_KEY' => 'mock-test-key-' . $suffix,
        'BREVO_SENDER_EMAIL' => 'notificaciones@transportesseguros.cr',
        'BREVO_SENDER_NAME' => 'Transportes Seguros S.A.',
    ]);

    // 3.4a Brevo 201 -> cierra modal + toast + estado_envio = ENVIADO
    $invEmailBrevoOk = "yagni.brevo.ok.{$suffix}@example.test";
    [$st, $bOkBody, $bOkHdrs] = yg_http($srvBrevo['base'] . '/usuarios/invitar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'correo' => $invEmailBrevoOk,
        'permisos' => ['FLOTAS_VER'],
    ]);
    yg_assert($st === 200, "Brevo 201 debe responder 200 (obtenido {$st}): {$bOkBody}");
    $trigBrevoOk = json_decode((string)yg_header_value($bOkHdrs, 'HX-Trigger-After-Settle'), true);
    yg_assert(($trigBrevoOk['tssaNotice']['closeModal'] ?? false) === true, 'Brevo 201 debe cerrar el modal automáticamente.');
    yg_assert(str_contains((string)($trigBrevoOk['tssaNotice']['message'] ?? ''), 'Invitación enviada'), 'Brevo 201 debe mostrar toast Invitación enviada (aceptada por proveedor).');

    $stFindInv->execute([$invEmailBrevoOk]);
    $bOkRow = $stFindInv->fetch();
    $stFindInv->closeCursor();
    $cleanup['invitacion_ids'][] = (int)$bOkRow['invitacion_id'];
    yg_assert($bOkRow['estado_envio'] === 'ENVIADO', 'Brevo 201 debe registrar estado_envio = ENVIADO.');

    // 3.4b Brevo 500 -> NO cierra modal, mantiene advertencia + data-disable-parent-form + estado_envio = FALLIDO
    file_put_contents($mockModeFile, '500');
    $invEmailBrevoFail = "yagni.brevo.fail.{$suffix}@example.test";
    [$st, $bFailBody, $bFailHdrs] = yg_http($srvBrevo['base'] . '/usuarios/invitar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'correo' => $invEmailBrevoFail,
        'permisos' => ['FLOTAS_VER'],
    ]);
    yg_assert($st === 200, "Brevo fallo controlado debe responder 200 con advertencia (obtenido {$st}).");
    yg_assert(yg_header_value($bFailHdrs, 'HX-Trigger-After-Settle') === null, 'Brevo fallo NO debe enviar tssaNotice de cierre de modal.');
    yg_assert(str_contains($bFailBody, 'data-disable-parent-form="true"') && str_contains($bFailBody, 'Fallido') && str_contains($bFailBody, 'Reenviar invitación'), 'Brevo fallo debe deshabilitar reenvío ciego del alta y mostrar acción explícita de reenvío.');

    $stFindInv->execute([$invEmailBrevoFail]);
    $bFailRow = $stFindInv->fetch();
    $stFindInv->closeCursor();
    $cleanup['invitacion_ids'][] = (int)$bFailRow['invitacion_id'];
    yg_assert($bFailRow['estado_envio'] === 'FALLIDO', 'Brevo fallo debe conservar estado_envio = FALLIDO.');
    echo "[OK] Sección 3: Invitaciones Fake, Brevo 201, Brevo fallo, Revocar, Expirada->Cancelada y Completada.\n";

    // =========================================================================
    // 4. SEGURIDAD: CSRF INVÁLIDO Y USUARIO SIN PERMISO EN TODOS LOS ENDPOINTS
    // =========================================================================
    foreach ([
        ['/flota/guardar.php', ['entidad' => 'conductor', 'identificacion' => 'X']],
        ['/usuarios/invitar.php', ['correo' => 'sec@example.test']],
        ['/usuarios/revocar_invitacion.php', ['invitacion_id' => $invCompId]],
        ['/usuarios/reenviar_invitacion.php', ['invitacion_id' => $invExpId]],
    ] as [$endpoint, $payload]) {
        // Sin permiso -> 403
        [$stNoPerm] = yg_http($base . $endpoint, $limCookie, true, array_merge(['csrf_token' => $limCsrf], $payload));
        yg_assert($stNoPerm === 403, "Endpoint {$endpoint} sin permiso debe responder 403 (obtenido {$stNoPerm}).");

        // Con permiso pero CSRF inválido -> 403
        [$stBadCsrf] = yg_http($base . $endpoint, $adminCookie, true, array_merge(['csrf_token' => 'csrf_invalido'], $payload));
        yg_assert($stBadCsrf === 403, "Endpoint {$endpoint} con CSRF inválido debe responder 403 (obtenido {$stBadCsrf}).");
    }
    echo "[OK] Sección 4: Seguridad CSRF y autorización granular (403) en todos los endpoints intervenidos.\n";

    echo "=== SUITE YAGNI HTTP Y SEGURIDAD COMPLETADA AL 100% EN TransportesSegurosSA_Test ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $ygExitCode = 1;
} finally {
    yg_stop_server($srvFake);
    yg_stop_server($srvBrevo);
    yg_stop_server($srvMockBrevo);

    foreach ($cleanup['session_ids'] as $sessionFile) {
        if (is_string($sessionFile) && file_exists($sessionFile)) {
            @unlink($sessionFile);
        }
    }

    foreach ($cleanup['temp_dirs'] as $td) {
        if (is_dir($td)) {
            foreach (glob($td . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($td);
        }
    }

    require_test_database($pdo);
    foreach ($cleanup['viaje_ids'] as $vid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'viajes' AND registro_id = ?")->execute([(string)$vid]);
        $pdo->prepare('DELETE FROM dbo.viajes WHERE viaje_id = ?')->execute([$vid]);
    }
    foreach ($cleanup['vehiculo_ids'] as $vehId) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'vehiculos' AND registro_id = ?")->execute([(string)$vehId]);
        $pdo->prepare('DELETE FROM dbo.vehiculos WHERE vehiculo_id = ?')->execute([$vehId]);
    }
    foreach ($cleanup['conductor_ids'] as $cid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'conductores', N'conductores_licencias') AND (registro_id = ? OR registro_id LIKE ?)")->execute([(string)$cid, "{$cid}:%"]);
        $pdo->prepare('DELETE FROM dbo.conductores_licencias WHERE conductor_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM dbo.conductores WHERE conductor_id = ?')->execute([$cid]);
    }
    foreach ($cleanup['invitacion_ids'] as $iid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'invitaciones_usuario', N'invitaciones_permisos') AND (registro_id = ? OR registro_id LIKE ?)")->execute([(string)$iid, "{$iid}:%"]);
        $pdo->prepare('DELETE FROM dbo.invitaciones_permisos WHERE invitacion_id = ?')->execute([$iid]);
        $pdo->prepare('DELETE FROM dbo.invitaciones_usuario WHERE invitacion_id = ?')->execute([$iid]);
    }
    foreach ($cleanup['usuario_ids'] as $uid) {
        $pdo->prepare('DELETE FROM dbo.auditorias WHERE usuario_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([$uid]);
    }
}
exit($ygExitCode ?? 0);
