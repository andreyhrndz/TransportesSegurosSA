<?php
declare(strict_types=1);

/**
 * Suite HTTP, Concurrencia y Seguridad — Cierre PR #4
 * Verifica EXCLUSIVAMENTE en TransportesSegurosSA_Test:
 * 1. Invitaciones: 120 registros sintéticos, búsqueda parcial + estado efectivo UTC + paginación SQL real (20/pág),
 *    1 sola insignia visual por fila, acciones YAGNI (sin botón Cancelar), modal Reenviar + corrección atómica de correo,
 *    invalidación del enlace antiguo en onboarding, herencia sin escalamiento, límites 60s y 5/24h sin bypass,
 *    concurrencia real con curl_multi, Brevo mock 201/500, CSRF y 403.
 * 2. Ubicaciones y Google Maps links: fronteras lat/lon, NULL sin punto (nunca 0,0), enlaces validados en servidor
 *    para ubicaciones, detalle de solicitud y paradas de viaje, y CSP estricta sin comodín '*'.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo ejecutable por CLI.');
}

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';

function pr4_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('PR4 TEST FAILED: ' . $msg);
    }
}

function pr4_start_server(int $port, string $docRoot, array $extraEnv = [], array $extraArgs = []): array
{
    $phpBin = PHP_BINARY;
    $cmd = array_merge([$phpBin], $extraArgs, ['-S', "127.0.0.1:{$port}", '-t', $docRoot]);
    $env = array_merge($_ENV, getenv(), [
        'TSSA_DB_DATABASE' => 'TransportesSegurosSA_Test',
        'TSSA_MAIL_TRANSPORT' => 'fake',
        'TSSA_APP_URL' => "http://127.0.0.1:{$port}",
    ], $extraEnv);

    $logFile = (string)tempnam(sys_get_temp_dir(), 'tssa-pr4-srv-');
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), $env);
    pr4_assert(is_resource($proc), "No se pudo iniciar servidor PHP en puerto {$port}.");
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($fp)) {
            fclose($fp);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    pr4_assert($ready, "Servidor PHP no respondió en puerto {$port}.");
    return [$proc, $pipes, $logFile];
}

function pr4_stop_server(mixed $proc, array $pipes, string $logFile = ''): void
{
    foreach ($pipes as $p) {
        if (is_resource($p)) {
            @fclose($p);
        }
    }
    if (is_resource($proc)) {
        $st = proc_get_status($proc);
        if (!empty($st['pid']) && strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            @exec('taskkill /F /T /PID ' . (int)$st['pid'] . ' 2>&1');
        } else {
            @proc_terminate($proc);
        }
        @proc_close($proc);
    }
    if ($logFile !== '' && file_exists($logFile)) {
        @unlink($logFile);
    }
}

function pr4_http(string $url, string $cookie = '', bool $isPost = false, array $postData = [], bool $hx = true): array
{
    $ch = curl_init($url);
    $headers = [];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($hx) {
        $headers[] = 'HX-Request: true';
    }
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
            $len = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }
            return $len;
        },
    ]);
    if ($isPost) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body, $respHeaders];
}

function pr4_header(array $headers, string $name): ?string
{
    $key = strtolower($name);
    return isset($headers[$key][0]) ? (string)$headers[$key][0] : null;
}

function pr4_create_session_cookie(string $savePath, array $userRow, array $perms, string $csrf): array
{
    $sid = 'pr4' . bin2hex(random_bytes(10));
    $uid = (int)$userRow['usuario_id'];
    $now = time();
    $encoded = sprintf(
        'usuario_id|i:%d;authenticated_at|i:%d;last_activity_at|i:%d;csrf_token|s:%d:"%s";',
        $uid,
        $now,
        $now,
        strlen($csrf),
        $csrf
    );
    $tmpSessionFile = rtrim($savePath, "\\/") . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    $written = file_put_contents($tmpSessionFile, $encoded);
    pr4_assert($written !== false && file_exists($tmpSessionFile), 'No se pudo inicializar archivo de sesión aislado.');
    return ['TSSA_TRANSPORTES_SESSION=' . $sid, $tmpSessionFile];
}

$pdo = db();
require_test_database($pdo);
$effectiveDb = (string)$pdo->query('SELECT DB_NAME()')->fetchColumn();
echo "Preflight DB_NAME(): {$effectiveDb}\n";
pr4_assert($effectiveDb === 'TransportesSegurosSA_Test', 'DB efectiva debe ser TransportesSegurosSA_Test.');

$suffix = bin2hex(random_bytes(3));
$prefix = "pr4.{$suffix}";
$cleanup = [
    'invitacion_ids' => [],
    'usuario_ids' => [],
    'ubicacion_ids' => [],
    'ruta_ids' => [],
    'solicitud_ids' => [],
    'viaje_ids' => [],
    'cliente_ids' => [],
    'vehiculo_ids' => [],
    'conductor_ids' => [],
    'session_files' => [],
    'temp_dirs' => [],
];

$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tssa-pr4-sess-' . $suffix;
mkdir($sessDir, 0700, true);
$cleanup['temp_dirs'][] = $sessDir;

$port = 8014;
$port2 = 8017;
$base = "http://127.0.0.1:{$port}";
$base2 = "http://127.0.0.1:{$port2}";
$server = null;
$server2 = null;
$mockServer = null;
$brevoServer = null;

try {
    // Crear 3 usuarios efímeros: admin completo, gestor limitado (sin FLOTAS_VER), y solo lectura (USUARIOS_VER)
    $allPerms = $pdo->query('SELECT codigo FROM dbo.permisos WHERE activo = 1')->fetchAll(PDO::FETCH_COLUMN);
    $insU = $pdo->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) OUTPUT INSERTED.usuario_id VALUES (?, ?, ?, 1)');

    $admUser = "__pr4_adm_{$suffix}";
    $insU->execute([$admUser, "{$prefix}.adm@example.test", password_hash('Pr4AdminPass!2026', PASSWORD_BCRYPT)]);
    $admId = (int)$insU->fetchColumn();
    $insU->closeCursor();
    $cleanup['usuario_ids'][] = $admId;
    $pdo->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE activo = 1')->execute([$admId]);

    $limGestorUser = "__pr4_ges_{$suffix}";
    $insU->execute([$limGestorUser, "{$prefix}.ges@example.test", password_hash('Pr4GestPass!2026', PASSWORD_BCRYPT)]);
    $limGestorId = (int)$insU->fetchColumn();
    $insU->closeCursor();
    $cleanup['usuario_ids'][] = $limGestorId;
    $gestorPerms = ['USUARIOS_VER', 'USUARIOS_GESTIONAR_CUENTAS', 'USUARIOS_GESTIONAR_PERMISOS', 'CLIENTES_VER'];
    $pdo->prepare("INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo IN ('USUARIOS_VER','USUARIOS_GESTIONAR_CUENTAS','USUARIOS_GESTIONAR_PERMISOS','CLIENTES_VER')")->execute([$limGestorId]);

    $roUser = "__pr4_ro_{$suffix}";
    $insU->execute([$roUser, "{$prefix}.ro@example.test", password_hash('Pr4ReadPass!2026', PASSWORD_BCRYPT)]);
    $roId = (int)$insU->fetchColumn();
    $insU->closeCursor();
    $cleanup['usuario_ids'][] = $roId;
    $roPerms = ['USUARIOS_VER', 'UBICACIONES_VER', 'SOLICITUDES_VER', 'VIAJES_VER'];
    $pdo->prepare("INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo IN ('USUARIOS_VER','UBICACIONES_VER','SOLICITUDES_VER','VIAJES_VER')")->execute([$roId]);

    $admCsrf = bin2hex(random_bytes(16));
    $gesCsrf = bin2hex(random_bytes(16));
    $roCsrf = bin2hex(random_bytes(16));

    [$admCookie, $f1] = pr4_create_session_cookie($sessDir, ['usuario_id' => $admId, 'nombre_usuario' => $admUser, 'correo_acceso' => "{$prefix}.adm@example.test"], $allPerms, $admCsrf);
    [$gesCookie, $f2] = pr4_create_session_cookie($sessDir, ['usuario_id' => $limGestorId, 'nombre_usuario' => $limGestorUser, 'correo_acceso' => "{$prefix}.ges@example.test"], $gestorPerms, $gesCsrf);
    [$roCookie, $f3] = pr4_create_session_cookie($sessDir, ['usuario_id' => $roId, 'nombre_usuario' => $roUser, 'correo_acceso' => "{$prefix}.ro@example.test"], $roPerms, $roCsrf);
    array_push($cleanup['session_files'], $f1, $f2, $f3);

    $server = pr4_start_server($port, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public', [
        'TSSA_MAIL_TRANSPORT' => 'fake',
    ], ['-d', 'session.save_path=' . $sessDir]);
    $server2 = pr4_start_server($port2, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public', [
        'TSSA_MAIL_TRANSPORT' => 'fake',
    ], ['-d', 'session.save_path=' . $sessDir]);

    // =========================================================================
    // SECCIÓN 1: 120 INVITACIONES SINTÉTICAS — PAGINACIÓN SQL REAL Y FILTROS
    // =========================================================================
    $insInv = $pdo->prepare(
        'INSERT INTO dbo.invitaciones_usuario (correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id, fecha_creacion, ultimo_envio_en, envios_contador, estado_envio)
         OUTPUT INSERTED.invitacion_id
         VALUES (?, ?, ?, ?, ?, DATEADD(MINUTE, CAST(? AS INT), DATEADD(DAY, -5, SYSUTCDATETIME())), DATEADD(MINUTE, -15, SYSUTCDATETIME()), ?, ?)'
    );

    // Distribución en 120 registros:
    // 1..30: PENDIENTE vigentes (30)
    // 31..55: PENDIENTE con fecha_expiracion en pasado -> estado efectivo EXPIRADA (25)
    // 56..70: EXPIRADA explícita (15) => total EXPIRADA efectivas = 40
    // 71..90: REVOCADA (20)
    // 91..110: COMPLETADA (20)
    // 111..120: CANCELADA histórica (10)
    for ($i = 1; $i <= 120; $i++) {
        $num = str_pad((string)$i, 3, '0', STR_PAD_LEFT);
        $mail = "{$prefix}.batch.{$num}@example.test";
        $hash = hash('sha256', "batch_{$suffix}_{$i}");
        if ($i <= 30) {
            $est = 'PENDIENTE';
            $exp = gmdate('Y-m-d H:i:s', time() + 86400);
        } elseif ($i <= 55) {
            $est = 'PENDIENTE';
            $exp = gmdate('Y-m-d H:i:s', time() - 7200); // vencida UTC
        } elseif ($i <= 70) {
            $est = 'EXPIRADA';
            $exp = gmdate('Y-m-d H:i:s', time() - 10800);
        } elseif ($i <= 90) {
            $est = 'REVOCADA';
            $exp = gmdate('Y-m-d H:i:s', time() + 3600);
        } elseif ($i <= 110) {
            $est = 'COMPLETADA';
            $exp = gmdate('Y-m-d H:i:s', time() + 3600);
        } else {
            $est = 'CANCELADA';
            $exp = gmdate('Y-m-d H:i:s', time() - 3600);
        }
        $insInv->execute([$mail, $hash, $est, $exp, $admId, $i, 1, 'SIMULADO']);
        $cleanup['invitacion_ids'][] = (int)$insInv->fetchColumn();
        $insInv->closeCursor();
    }

    // Página 1 de 6 (20 filas exactas) filtrando por "{$prefix}.batch."
    [$st, $p1Html, $p1Hdrs] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("{$prefix}.batch."), $admCookie);
    pr4_assert($st === 200, "Listado página 1 debe responder 200 (obtenido {$st}).");
    pr4_assert(substr_count($p1Html, '<tr data-invitacion-id=') === 20, 'Página 1 debe renderizar exactamente 20 filas desde SQL.');
    pr4_assert(str_contains($p1Html, '120 invitaciones') && str_contains($p1Html, 'Página 1 de 6'), 'Paginación debe indicar 120 invitaciones y Página 1 de 6.');

    // Verificar CSP estricta (sin '*', img-src limitado a OSM, script-src 'self')
    $csp = pr4_header($p1Hdrs, 'Content-Security-Policy') ?? '';
    pr4_assert(str_contains($csp, "script-src 'self'") && str_contains($csp, 'https://tile.openstreetmap.org') && !str_contains($csp, '*'), 'CSP debe permitir tiles OSM sin comodín * y mantener script-src self. Recibida: ' . $csp);

    // Página 2 y Página 6 (última)
    [$st, $p2Html] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("{$prefix}.batch.") . '&pagina=2', $admCookie);
    pr4_assert($st === 200 && substr_count($p2Html, '<tr data-invitacion-id=') === 20 && str_contains($p2Html, 'Página 2 de 6'), 'Página 2 debe devolver 20 filas.');
    [$st, $p6Html] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("{$prefix}.batch.") . '&pagina=6', $admCookie);
    pr4_assert($st === 200 && substr_count($p6Html, '<tr data-invitacion-id=') === 20 && str_contains($p6Html, 'Página 6 de 6'), 'Página 6 debe devolver 20 filas.');

    // Página fuera de rango (pagina=99) retrocede automáticamente a Página 6 de 6
    [$st, $p99Html] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("{$prefix}.batch.") . '&pagina=99', $admCookie);
    pr4_assert($st === 200 && str_contains($p99Html, 'Página 6 de 6') && substr_count($p99Html, '<tr data-invitacion-id=') === 20, 'Página fuera de rango debe retroceder a la última página válida.');

    // Filtro por estado efectivo UTC: PENDIENTE = 30 (2 páginas), EXPIRADA = 40 (incluye las 25 PENDIENTE vencidas!)
    [$st, $pendHtml] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("{$prefix}.batch.") . '&estado=PENDIENTE', $admCookie);
    pr4_assert($st === 200 && str_contains($pendHtml, '30 invitaciones') && str_contains($pendHtml, 'Página 1 de 2'), 'Filtro PENDIENTE debe contar exactamente 30 vigentes.');
    pr4_assert(str_contains($pendHtml, '>Reenviar<') && str_contains($pendHtml, '>Revocar<') && !str_contains($pendHtml, '>Cancelar<'), 'PENDIENTE vigente muestra Reenviar y Revocar, nunca Cancelar.');

    [$st, $expHtml] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("{$prefix}.batch.") . '&estado=EXPIRADA', $admCookie);
    pr4_assert($st === 200 && str_contains($expHtml, '40 invitaciones') && str_contains($expHtml, 'Página 1 de 2'), 'Filtro EXPIRADA debe detectar las 40 expiradas efectivas (25 UTC + 15 SQL).');
    pr4_assert(str_contains($expHtml, '>Reenviar<') && !str_contains($expHtml, '>Revocar<') && !str_contains($expHtml, '>Cancelar<'), 'EXPIRADA debe mostrar SOLO Reenviar (ni Revocar ni Cancelar).');

    [$st, $revHtml] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("{$prefix}.batch.") . '&estado=REVOCADA', $admCookie);
    pr4_assert($st === 200 && str_contains($revHtml, '20 invitaciones') && str_contains($revHtml, '>Reenviar<') && !str_contains($revHtml, '>Revocar<') && !str_contains($revHtml, '>Cancelar<'), 'REVOCADA debe mostrar SOLO Reenviar.');

    [$st, $compHtml] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("{$prefix}.batch.") . '&estado=COMPLETADA', $admCookie);
    pr4_assert($st === 200 && str_contains($compHtml, '20 invitaciones') && !str_contains($compHtml, '>Reenviar<') && !str_contains($compHtml, '>Revocar<') && !str_contains($compHtml, '>Cancelar<'), 'COMPLETADA no debe mostrar ningún botón de acción.');

    [$st, $cancHtml] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("{$prefix}.batch.") . '&estado=CANCELADA', $admCookie);
    pr4_assert($st === 200 && str_contains($cancHtml, '10 invitaciones') && str_contains($cancHtml, '>Reenviar<') && !str_contains($cancHtml, '>Cancelar<'), 'CANCELADA histórica muestra solo Reenviar y nunca Cancelar.');

    // Verificar 1 SOLA insignia .status-tag por fila!
    preg_match_all('/<tr data-invitacion-id="[^"]+"[^>]*>.*?<\/tr>/s', $pendHtml, $rowsMatch);
    pr4_assert(count($rowsMatch[0]) === 20, 'Debe haber 20 filas en PENDIENTE página 1.');
    foreach ($rowsMatch[0] as $rowHtml) {
        $badgeCount = substr_count($rowHtml, 'class="status-tag');
        pr4_assert($badgeCount === 1, "Cada fila debe tener exactamente 1 insignia status-tag (encontradas: {$badgeCount}).");
    }

    // Búsqueda case-insensitive parcial + 0 resultados diferenciado
    [$st, $ciHtml] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode(strtoupper("{$prefix}.BATCH.005")), $admCookie);
    pr4_assert($st === 200 && str_contains($ciHtml, '1 invitación') && str_contains($ciHtml, "{$prefix}.batch.005@example.test"), 'Búsqueda case-insensitive parcial debe hallar batch.005.');

    [$st, $zeroHtml] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("inexistente.{$suffix}.xyz"), $admCookie);
    pr4_assert($st === 200 && str_contains($zeroHtml, 'Sin resultados') && !str_contains($zeroHtml, 'No se han emitido invitaciones'), 'Filtro sin coincidencias debe mostrar Sin resultados.');

    // Usuario solo lectura (USUARIOS_VER sin USUARIOS_GESTIONAR_CUENTAS): ve lista pero NO ve botones Reenviar/Revocar
    [$st, $roListHtml] = pr4_http($base . '/usuarios/invitaciones_lista.php?buscar=' . urlencode("{$prefix}.batch.001"), $roCookie);
    pr4_assert($st === 200 && !str_contains($roListHtml, '>Reenviar<') && !str_contains($roListHtml, '>Revocar<'), 'Solo lectura no debe ver acciones Reenviar ni Revocar.');

    echo "[OK] Sección 1: 120 invitaciones paginadas en SQL, filtros UTC, 1 insignia/fila y acciones YAGNI.\n";

    // =========================================================================
    // SECCIÓN 2: MODAL REENVIAR, CORRECCIÓN DE CORREO, ONBOARDING, CONCURRENCIA Y BREVO
    // =========================================================================
    $origEmail = "{$prefix}.typo.orig@example.test";
    $corrEmail = "{$prefix}.corregido@example.test";
    $origToken = bin2hex(random_bytes(32));
    $origHash = hash('sha256', $origToken);

    $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?")->execute([$admId]);
    $stCre = $pdo->prepare('DECLARE @id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@id OUTPUT; SELECT @id AS id;');
    $stCre->execute([$origEmail, $origHash]);
    $origInvId = (int)$stCre->fetchColumn();
    $stCre->closeCursor();
    $cleanup['invitacion_ids'][] = $origInvId;

    $pdo->prepare("EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id=?, @codigo=N'CLIENTES_VER';")->execute([$origInvId]);
    $pdo->prepare("EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id=?, @codigo=N'FLOTAS_VER';")->execute([$origInvId]);

    // 2.1 GET modal reenviar_form.php: 200 para pendiente, 403 solo lectura, 409 completada, 404 inexistente
    [$st, $formHtml] = pr4_http($base . "/usuarios/reenviar_form.php?id={$origInvId}", $admCookie);
    pr4_assert($st === 200 && str_contains($formHtml, $origEmail) && str_contains($formHtml, 'name="nuevo_correo"') && str_contains($formHtml, 'Enviar nuevo enlace'), 'Modal Reenviar debe prellenar correo editable y botón Enviar nuevo enlace.');
    pr4_assert(!str_contains($formHtml, $origHash), 'Modal Reenviar nunca debe exponer hashes/tokens.');

    [$st] = pr4_http($base . "/usuarios/reenviar_form.php?id={$origInvId}", $roCookie);
    pr4_assert($st === 403, 'Solo lectura debe recibir 403 al abrir reenviar_form.php.');

    $compId = $cleanup['invitacion_ids'][95];
    [$st] = pr4_http($base . "/usuarios/reenviar_form.php?id={$compId}", $admCookie);
    pr4_assert($st === 409, 'Invitación COMPLETADA debe responder 409 en reenviar_form.php.');

    [$st] = pr4_http($base . '/usuarios/reenviar_form.php?id=99999999', $admCookie);
    pr4_assert($st === 404, 'Invitación inexistente debe responder 404 en reenviar_form.php.');

    // 2.2 Validaciones: CSRF 403, correo inválido 422, cuenta activa 409, cooldown 60s (no bypass por cambiar correo)
    [$st, $csrfBody] = pr4_http($base . '/usuarios/reenviar_invitacion.php', $admCookie, true, [
        'csrf_token' => 'invalido',
        'invitacion_id' => $origInvId,
        'nuevo_correo' => $corrEmail,
        'origen' => 'modal',
    ]);
    pr4_assert($st === 403, "CSRF inválido en reenviar debe responder 403 (obtenido {$st}): " . substr($csrfBody, 0, 200));

    [$st] = pr4_http($base . '/usuarios/reenviar_invitacion.php', $admCookie, true, [
        'csrf_token' => $admCsrf,
        'invitacion_id' => $origInvId,
        'nuevo_correo' => 'correo-mal-formado',
        'origen' => 'modal',
    ]);
    pr4_assert($st === 422, 'Correo inválido en modal Reenviar debe responder 422.');

    [$st] = pr4_http($base . '/usuarios/reenviar_invitacion.php', $admCookie, true, [
        'csrf_token' => $admCsrf,
        'invitacion_id' => $origInvId,
        'nuevo_correo' => "{$prefix}.ro@example.test",
        'origen' => 'modal',
    ]);
    pr4_assert($st === 409, 'Correo de cuenta activa en modal Reenviar debe responder 409.');

    [$st] = pr4_http($base . '/usuarios/reenviar_invitacion.php', $admCookie, true, [
        'csrf_token' => $admCsrf,
        'invitacion_id' => $origInvId,
        'nuevo_correo' => $corrEmail,
        'origen' => 'modal',
    ]);
    pr4_assert($st === 422, 'Cambiar de correo no debe evadir el cooldown de 60 segundos (422).');

    // 2.3 Transcurrido cooldown -> Corrección atómica de correo exitosa desde modal
    $pdo->prepare('UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = ?')->execute([$origInvId]);
    [$st, $corrBody, $corrHdrs] = pr4_http($base . '/usuarios/reenviar_invitacion.php', $admCookie, true, [
        'csrf_token' => $admCsrf,
        'invitacion_id' => $origInvId,
        'nuevo_correo' => strtoupper($corrEmail),
        'origen' => 'modal',
    ]);
    pr4_assert($st === 200, "Corrección de correo válida debe responder 200 (obtenido {$st}): {$corrBody}");
    $corrTrig = json_decode((string)pr4_header($corrHdrs, 'HX-Trigger-After-Settle'), true);
    pr4_assert(($corrTrig['tssaNotice']['closeModal'] ?? false) === true && ($corrTrig['invitacionActualizada'] ?? false) === true, 'Corrección exitosa debe cerrar modal y disparar invitacionActualizada.');

    // Verificar BD: origen conserva correo original y quedó REVOCADA; nueva creada con correo corregido y 2 permisos
    $oldRow = $pdo->query("SELECT correo_destino, estado FROM dbo.invitaciones_usuario WHERE invitacion_id = {$origInvId}")->fetch();
    pr4_assert($oldRow['correo_destino'] === $origEmail && $oldRow['estado'] === 'REVOCADA', 'Registro origen debe quedar REVOCADA con correo original inmutable.');

    $stNew = $pdo->prepare('SELECT invitacion_id, correo_destino, estado FROM dbo.invitaciones_usuario WHERE correo_destino = ? ORDER BY invitacion_id DESC');
    $stNew->execute([$corrEmail]);
    $newRow = $stNew->fetch();
    $stNew->closeCursor();
    pr4_assert(is_array($newRow) && $newRow['estado'] === 'PENDIENTE', 'Debe crearse nueva invitación PENDIENTE para correo corregido.');
    $corrInvId = (int)$newRow['invitacion_id'];
    $cleanup['invitacion_ids'][] = $corrInvId;

    $permCount = (int)$pdo->query("SELECT COUNT(*) FROM dbo.invitaciones_permisos WHERE invitacion_id = {$corrInvId}")->fetchColumn();
    pr4_assert($permCount === 2, "Nueva invitación debe heredar 2 permisos (encontrados: {$permCount}).");

    // Enlace antiguo en /auth/onboarding.php?token=<origToken> DEBE ser rechazado
    [$stOldOnb, $oldOnbHtml] = pr4_http($base . '/auth/onboarding.php?token=' . urlencode($origToken), '', false, [], false);
    pr4_assert(!str_contains($oldOnbHtml, 'id="form-onboarding"'), 'El enlace de la invitación anterior ya no debe mostrar formulario de onboarding.');

    // 2.4 Prevención de escalamiento al corregir correo con gestor limitado (solo CLIENTES_VER, sin FLOTAS_VER)
    $pdo->prepare('UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = ?')->execute([$corrInvId]);
    $corrEmail2 = "{$prefix}.corregido.noesc@example.test";
    [$st, $noEscBody] = pr4_http($base . '/usuarios/reenviar_invitacion.php', $gesCookie, true, [
        'csrf_token' => $gesCsrf,
        'invitacion_id' => $corrInvId,
        'nuevo_correo' => $corrEmail2,
        'origen' => 'modal',
    ]);
    pr4_assert($st === 200, "Gestor limitado debe poder corregir correo sin escalar privilegios (obtenido {$st}): {$noEscBody}");
    $stNew->execute([$corrEmail2]);
    $noEscRow = $stNew->fetch();
    $stNew->closeCursor();
    $noEscInvId = (int)$noEscRow['invitacion_id'];
    $cleanup['invitacion_ids'][] = $noEscInvId;
    $inheritedCodes = $pdo->query("SELECT p.codigo FROM dbo.invitaciones_permisos ip JOIN dbo.permisos p ON p.permiso_id = ip.permiso_id WHERE ip.invitacion_id = {$noEscInvId}")->fetchAll(PDO::FETCH_COLUMN);
    pr4_assert($inheritedCodes === ['CLIENTES_VER'], 'Actor limitado solo debe copiar CLIENTES_VER y nunca FLOTAS_VER. Copiados: ' . implode(',', $inheritedCodes));

    // 2.5 Concurrencia real (2 POST paralelos a 2 procesos PHP simultáneos con curl_multi sobre la misma invitación)
    $pdo->prepare('UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = ?')->execute([$noEscInvId]);
    $raceTarget = "{$prefix}.race@example.test";
    $mh = curl_multi_init();
    $handles = [];
    $raceTargets = [
        [$base, $admCookie, $admCsrf],
        [$base2, $gesCookie, $gesCsrf],
    ];
    foreach ($raceTargets as [$targetBase, $rCookie, $rCsrf]) {
        $ch = curl_init($targetBase . '/usuarios/reenviar_invitacion.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'csrf_token' => $rCsrf,
                'invitacion_id' => $noEscInvId,
                'nuevo_correo' => $raceTarget,
                'origen' => 'modal',
            ]),
            CURLOPT_HTTPHEADER => ['Cookie: ' . $rCookie, 'HX-Request: true'],
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        $statusExec = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 0.5);
        }
    } while ($active && $statusExec === CURLM_OK);
    $raceCodes = [];
    foreach ($handles as $ch) {
        $raceCodes[] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    sort($raceCodes);
    $activeRaceRows = $pdo->prepare("SELECT invitacion_id FROM dbo.invitaciones_usuario WHERE correo_destino = ? AND estado = 'PENDIENTE' AND fecha_expiracion > SYSUTCDATETIME()");
    $activeRaceRows->execute([$raceTarget]);
    $raceIds = $activeRaceRows->fetchAll(PDO::FETCH_COLUMN);
    foreach ($raceIds as $rid) {
        $cleanup['invitacion_ids'][] = (int)$rid;
    }
    pr4_assert(count($raceIds) === 1, 'Dos reenvíos concurrentes jamás deben dejar más de 1 invitación PENDIENTE vigente (creadas: ' . count($raceIds) . ', códigos HTTP: ' . implode(',', $raceCodes) . ').');
    pr4_assert($raceCodes[0] === 200 && in_array($raceCodes[1], [409, 422], true), 'En concurrencia, 1 petición debe pasar (200) y la otra bloquearse (409/422): ' . implode(',', $raceCodes));

    // 2.6 Brevo Mock HTTP 201 vs HTTP 500 en modal Reenviar con corrección de correo
    $mockDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tssa-pr4-brevo-' . $suffix;
    mkdir($mockDir, 0700, true);
    $cleanup['temp_dirs'][] = $mockDir;
    $modeFile = $mockDir . DIRECTORY_SEPARATOR . 'mode.txt';
    file_put_contents($modeFile, '500');
    file_put_contents($mockDir . DIRECTORY_SEPARATOR . 'index.php', <<<'PHP'
<?php
$mode = trim((string)@file_get_contents(__DIR__ . '/mode.txt'));
header('Content-Type: application/json');
if ($mode === '201') {
    http_response_code(201);
    echo json_encode(['messageId' => '<pr4-ok@brevo.test>']);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Simulated failure']);
}
PHP);
    $mockPort = 8015;
    $mockServer = pr4_start_server($mockPort, $mockDir);
    $brevoPort = 8016;
    $brevoBase = "http://127.0.0.1:{$brevoPort}";
    $brevoServer = pr4_start_server($brevoPort, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public', [
        'TSSA_MAIL_TRANSPORT' => 'brevo',
        'BREVO_API_BASE_URL' => "http://127.0.0.1:{$mockPort}",
        'BREVO_API_KEY' => 'mock-key-pr4',
        'BREVO_SENDER_EMAIL' => 'notificaciones@transportesseguros.cr',
        'BREVO_SENDER_NAME' => 'Transportes Seguros S.A.',
        'TSSA_APP_URL' => $brevoBase,
    ], ['-d', 'session.save_path=' . $sessDir]);

    $raceInvId = (int)$raceIds[0];
    $pdo->prepare('UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = ?')->execute([$raceInvId]);
    $brevoFailMail = "{$prefix}.brevo.fail@example.test";
    [$st, $bFailHtml, $bFailHdrs] = pr4_http($brevoBase . '/usuarios/reenviar_invitacion.php', $admCookie, true, [
        'csrf_token' => $admCsrf,
        'invitacion_id' => $raceInvId,
        'nuevo_correo' => $brevoFailMail,
        'origen' => 'modal',
    ]);
    pr4_assert($st === 200 && str_contains($bFailHtml, 'Fallido') && str_contains($bFailHtml, 'data-disable-parent-form="true"') && str_contains($bFailHtml, 'Reintentar reenvío'), 'Fallo Brevo en modal Reenviar debe dejar modal abierto con Fallido y botón Reintentar.');
    pr4_assert(pr4_header($bFailHdrs, 'HX-Trigger-After-Settle') === null, 'Fallo Brevo no debe emitir cierre automático de modal.');
    $stNew->execute([$brevoFailMail]);
    $bFailRow = $stNew->fetch();
    $stNew->closeCursor();
    $bFailId = (int)$bFailRow['invitacion_id'];
    $cleanup['invitacion_ids'][] = $bFailId;

    // Ahora Brevo 201 -> cierra modal automáticamente
    file_put_contents($modeFile, '201');
    $pdo->prepare('UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = ?')->execute([$bFailId]);
    $brevoOkMail = "{$prefix}.brevo.ok@example.test";
    [$st, $bOkHtml, $bOkHdrs] = pr4_http($brevoBase . '/usuarios/reenviar_invitacion.php', $admCookie, true, [
        'csrf_token' => $admCsrf,
        'invitacion_id' => $bFailId,
        'nuevo_correo' => $brevoOkMail,
        'origen' => 'modal',
    ]);
    pr4_assert($st === 200, 'Brevo 201 debe responder 200.');
    $bOkTrig = json_decode((string)pr4_header($bOkHdrs, 'HX-Trigger-After-Settle'), true);
    pr4_assert(($bOkTrig['tssaNotice']['closeModal'] ?? false) === true && str_contains((string)($bOkTrig['tssaNotice']['message'] ?? ''), 'proveedor'), 'Brevo 201 debe cerrar modal y mencionar aceptación del proveedor.');
    $stNew->execute([$brevoOkMail]);
    $cleanup['invitacion_ids'][] = (int)$stNew->fetch()['invitacion_id'];
    $stNew->closeCursor();

    echo "[OK] Sección 2: Modal Reenviar, corrección atómica, anti-escalamiento, concurrencia real y Brevo 201/500.\n";

    // =========================================================================
    // SECCIÓN 3: UBICACIONES (MAPA, FRONTERAS, NULL) Y ENLACES GOOGLE MAPS
    // =========================================================================
    // 3.1 Validaciones de coordenadas frontera y errores
    [$st] = pr4_http($base . '/flota/guardar.php', $admCookie, true, [
        'csrf_token' => $admCsrf, 'entidad' => 'ubicacion',
        'nombre' => "PR4 Bad Parcial {$suffix}", 'direccion' => 'Dir parcial', 'latitud' => '9.934739', 'longitud' => '',
    ]);
    pr4_assert($st === 422, 'Solo latitud sin longitud debe rechazarse con 422.');

    [$st] = pr4_http($base . '/flota/guardar.php', $admCookie, true, [
        'csrf_token' => $admCsrf, 'entidad' => 'ubicacion',
        'nombre' => "PR4 Bad Rango {$suffix}", 'direccion' => 'Dir rango', 'latitud' => '95.000000', 'longitud' => '-84.087502',
    ]);
    pr4_assert($st === 422, 'Latitud fuera de rango (-90..90) debe rechazarse con 422.');

    [$st] = pr4_http($base . '/flota/guardar.php', $admCookie, true, [
        'csrf_token' => $admCsrf, 'entidad' => 'ubicacion',
        'nombre' => "PR4 Bad NaN {$suffix}", 'direccion' => 'Dir NaN', 'latitud' => 'abc', 'longitud' => '-84.087502',
    ]);
    pr4_assert($st === 422, 'Coordenadas NaN deben rechazarse con 422.');

    // 3.2 Ubicación con coordenadas válidas 6 decimales + Ubicación sin coordenadas (NULL, nunca 0,0)
    $uWithName = "PR4 Bodega Mapa {$suffix}";
    $uNoName = "PR4 Bodega SinCoords {$suffix}";
    [$st, $bU1] = pr4_http($base . '/flota/guardar.php', $admCookie, true, [
        'csrf_token' => $admCsrf, 'entidad' => 'ubicacion',
        'nombre' => $uWithName, 'direccion' => '200m norte de parque central, Alajuela',
        'latitud' => '10.016250', 'longitud' => '-84.211630',
    ]);
    pr4_assert($st === 200, "Alta ubicación con mapa debe responder 200: {$bU1}");

    [$st, $bU2] = pr4_http($base . '/flota/guardar.php', $admCookie, true, [
        'csrf_token' => $admCsrf, 'entidad' => 'ubicacion',
        'nombre' => $uNoName, 'direccion' => 'Calle sin punto en el mapa',
        'latitud' => '', 'longitud' => '',
    ]);
    pr4_assert($st === 200, "Alta ubicación sin coordenadas debe responder 200: {$bU2}");

    $stFindU = $pdo->prepare('SELECT ubicacion_id, latitud, longitud FROM dbo.ubicaciones WHERE nombre = ?');
    $stFindU->execute([$uWithName]);
    $u1 = $stFindU->fetch();
    $stFindU->closeCursor();
    $u1Id = (int)$u1['ubicacion_id'];
    $cleanup['ubicacion_ids'][] = $u1Id;
    pr4_assert(abs((float)$u1['latitud'] - 10.01625) < 0.000002 && abs((float)$u1['longitud'] - (-84.21163)) < 0.000002, 'Coordenadas 6 decimales deben persistirse con precisión.');

    $stFindU->execute([$uNoName]);
    $u2 = $stFindU->fetch();
    $stFindU->closeCursor();
    $u2Id = (int)$u2['ubicacion_id'];
    $cleanup['ubicacion_ids'][] = $u2Id;
    pr4_assert($u2['latitud'] === null && $u2['longitud'] === null, 'Ubicación sin punto debe persistir NULL en BD y jamás 0,0.');

    // Verificar listado de ubicaciones muestra enlace Google Maps solo para u1 (sin coordenadas numéricas crudas) y "Sin punto registrado" para u2
    [$st, $ubiListHtml] = pr4_http($base . '/flota/lista.php?seccion=ubicaciones&buscar=' . urlencode("PR4 Bodega"), $admCookie);
    pr4_assert($st === 200, 'Listado de ubicaciones debe responder 200.');
    $expectedUrl = 'https://www.google.com/maps/search/?api=1&amp;query=10.016250%2C-84.211630';
    pr4_assert(str_contains($ubiListHtml, $expectedUrl) && str_contains($ubiListHtml, 'rel="noopener noreferrer"') && str_contains($ubiListHtml, 'target="_blank"'), 'Listado de ubicaciones debe renderizar enlace Google Maps seguro para ubicación con coordenadas.');
    pr4_assert(!str_contains($ubiListHtml, '<code>10.016250'), 'Listado ordinario de ubicaciones no debe exponer coordenadas numéricas crudas.');
    pr4_assert(str_contains($ubiListHtml, 'Sin punto registrado'), 'Ubicación sin coordenadas debe mostrar Sin punto registrado.');

    // 3.3 Verificar enlaces Google Maps en detalle de solicitud (origen con punto, destino sin punto) y detalle de viaje (paradas)
    $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?")->execute([$admId]);
    $clienteId = (int)$pdo->query('SELECT TOP 1 cliente_id FROM dbo.clientes WHERE activo = 1 ORDER BY cliente_id')->fetchColumn();
    $tipoMercId = (int)$pdo->query('SELECT TOP 1 tipo_mercancia_id FROM dbo.tipos_mercancia WHERE activo = 1 ORDER BY tipo_mercancia_id')->fetchColumn();
    $solCode = "PR4-SOL-{$suffix}";
    $fechaEnt = gmdate('Y-m-d\TH:i', time() + 86400 * 3);
    [$st, $solResp] = pr4_http($base . '/solicitudes/guardar.php', $admCookie, true, [
        'csrf_token' => $admCsrf,
        'cliente_id' => $clienteId,
        'origen_id' => $u1Id,
        'destino_id' => $u2Id,
        'fecha_entrega' => $fechaEnt,
        'modo_destinatario' => 'tercero',
        'destinatario_nombre' => 'Mario',
        'destinatario_apellido1' => 'Sánchez',
        'mercancias' => [
            ['tipo_mercancia_id' => $tipoMercId, 'descripcion' => 'Cajas PR4', 'peso_kg' => '150', 'volumen_m3' => '1.5'],
        ],
    ]);
    pr4_assert($st === 200, "Creación de solicitud PR4 debe responder 200 (obtenido {$st}): {$solResp}");
    $solId = (int)$pdo->query("SELECT TOP 1 solicitud_id FROM dbo.solicitudes WHERE origen_id = {$u1Id} AND destino_id = {$u2Id} ORDER BY solicitud_id DESC")->fetchColumn();
    pr4_assert($solId > 0, 'Solicitud PR4 debe existir en BD.');
    $cleanup['solicitud_ids'][] = $solId;

    [$st, $solDetHtml] = pr4_http($base . "/solicitudes/detalle.php?id={$solId}", $admCookie, false, [], false);
    pr4_assert($st === 200 && str_contains($solDetHtml, $expectedUrl) && str_contains($solDetHtml, 'Sin punto registrado'), 'Detalle de solicitud debe mostrar Google Maps para origen con punto y Sin punto registrado para destino sin punto.');

    // Crear ruta + viaje + 2 paradas (u1 con coords y u2 sin coords) y validar /viajes/detalle.php
    $stRuta = $pdo->prepare('DECLARE @rid INT; EXEC dbo.sp_crear_ruta @nombre=?, @origen_id=?, @destino_id=?, @distancia_km=45.5, @duracion_estimada_min=60, @ruta_id=@rid OUTPUT; SELECT @rid AS id;');
    $stRuta->execute(["PR4 Ruta {$suffix}", $u1Id, $u2Id]);
    $rutaId = (int)$stRuta->fetchColumn();
    $stRuta->closeCursor();
    $cleanup['ruta_ids'][] = $rutaId;

    $stVeh = $pdo->prepare("DECLARE @vid INT; EXEC dbo.sp_crear_vehiculo @placa=?, @marca=N'Isuzu', @modelo=N'NPR', @anio=2025, @capacidad_carga_kg=2000, @capacidad_volumen_m3=10, @kilometraje_actual=0, @estado='DISPONIBLE', @pma_kg=4000, @clase_legal='LIVIANO', @es_articulado=0, @estado_homologacion='VERIFICADO', @vehiculo_id=@vid OUTPUT; SELECT @vid AS id;");
    $stVeh->execute(["PR4{$suffix}"]);
    $vehId = (int)$stVeh->fetchColumn();
    $stVeh->closeCursor();
    $cleanup['vehiculo_ids'][] = $vehId;

    $stCond = $pdo->prepare("DECLARE @cid INT; EXEC dbo.sp_crear_conductor @identificacion=?, @nombre=N'Luis', @primer_apellido=N'Mora', @segundo_apellido=NULL, @telefono=NULL, @correo=NULL, @numero_licencia=?, @fecha_vencimiento_licencia=?, @estado_operativo='DISPONIBLE', @usuario_id=NULL, @codigo_licencia_inicial='B1', @conductor_id=@cid OUTPUT; SELECT @cid AS id;");
    $stCond->execute(["PR4-C-{$suffix}", "PR4-L-{$suffix}", gmdate('Y-m-d', time() + 86400 * 365)]);
    $condId = (int)$stCond->fetchColumn();
    $stCond->closeCursor();
    $cleanup['conductor_ids'][] = $condId;

    $stV = $pdo->prepare('DECLARE @vid INT; EXEC dbo.sp_crear_viaje @codigo=?, @vehiculo_id=?, @conductor_id=?, @ruta_id=?, @fecha_programada=?, @viaje_id=@vid OUTPUT; SELECT @vid AS id;');
    $stV->execute(["PR4-V-{$suffix}", $vehId, $condId, $rutaId, gmdate('Y-m-d H:i:s', time() + 86400 * 2)]);
    $viajeId = (int)$stV->fetchColumn();
    $stV->closeCursor();
    $cleanup['viaje_ids'][] = $viajeId;

    $pdo->prepare("DECLARE @pid INT; EXEC dbo.sp_agregar_parada @viaje_id=?, @ubicacion_id=?, @orden=1, @tipo_parada='RECOGIDA', @parada_id=@pid OUTPUT;")->execute([$viajeId, $u1Id]);
    $pdo->prepare("DECLARE @pid INT; EXEC dbo.sp_agregar_parada @viaje_id=?, @ubicacion_id=?, @orden=2, @tipo_parada='ENTREGA', @parada_id=@pid OUTPUT;")->execute([$viajeId, $u2Id]);

    [$st, $viajeDetHtml] = pr4_http($base . "/viajes/detalle.php?id={$viajeId}", $admCookie, false, [], false);
    pr4_assert($st === 200 && str_contains($viajeDetHtml, $expectedUrl) && str_contains($viajeDetHtml, 'Sin punto registrado'), 'Detalle de viaje debe mostrar enlace Google Maps para parada con punto y Sin punto registrado para parada sin coordenadas.');

    echo "[OK] Sección 3: Ubicaciones (6 decimales, fronteras, NULL) y Google Maps links en Flota, Solicitud y Viaje.\n";
    echo "=== SUITE PHP PR #4 (INVITACIONES, CONCURRENCIA, UBICACIONES Y MAPS): 100% PASS ===\n";
} finally {
    if ($server) pr4_stop_server($server[0], $server[1], $server[2] ?? '');
    if ($server2) pr4_stop_server($server2[0], $server2[1], $server2[2] ?? '');
    if ($brevoServer) pr4_stop_server($brevoServer[0], $brevoServer[1], $brevoServer[2] ?? '');
    if ($mockServer) pr4_stop_server($mockServer[0], $mockServer[1], $mockServer[2] ?? '');

    require_test_database($pdo);
    foreach ($cleanup['viaje_ids'] as $id) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'viajes', N'paradas_viaje') AND registro_id = ?")->execute([(string)$id]);
        $pdo->prepare('DELETE FROM dbo.paradas_viaje WHERE viaje_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM dbo.viajes WHERE viaje_id = ?')->execute([$id]);
    }
    foreach ($cleanup['solicitud_ids'] as $id) {
        $pdo->prepare('DELETE FROM dbo.mercancias WHERE solicitud_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id = ?')->execute([$id]);
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'solicitudes' AND registro_id = ?")->execute([(string)$id]);
        $pdo->prepare('DELETE FROM dbo.solicitudes WHERE solicitud_id = ?')->execute([$id]);
    }
    foreach ($cleanup['ruta_ids'] as $id) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'rutas' AND registro_id = ?")->execute([(string)$id]);
        $pdo->prepare('DELETE FROM dbo.rutas WHERE ruta_id = ?')->execute([$id]);
    }
    foreach ($cleanup['vehiculo_ids'] as $id) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'vehiculos' AND registro_id = ?")->execute([(string)$id]);
        $pdo->prepare('DELETE FROM dbo.vehiculos WHERE vehiculo_id = ?')->execute([$id]);
    }
    foreach ($cleanup['conductor_ids'] as $id) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'conductores', N'conductores_licencias') AND (registro_id = ? OR registro_id LIKE ?)")->execute([(string)$id, "{$id}:%"]);
        $pdo->prepare('DELETE FROM dbo.conductores_licencias WHERE conductor_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM dbo.conductores WHERE conductor_id = ?')->execute([$id]);
    }
    foreach ($cleanup['ubicacion_ids'] as $id) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'ubicaciones' AND registro_id = ?")->execute([(string)$id]);
        $pdo->prepare('DELETE FROM dbo.ubicaciones WHERE ubicacion_id = ?')->execute([$id]);
    }
    $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'invitaciones_usuario', N'invitaciones_permisos') AND registro_id IN (SELECT CONVERT(NVARCHAR(100), invitacion_id) FROM dbo.invitaciones_usuario WHERE correo_destino LIKE ?)")->execute(["{$prefix}.%"]);
    $pdo->prepare('DELETE FROM dbo.invitaciones_permisos WHERE invitacion_id IN (SELECT invitacion_id FROM dbo.invitaciones_usuario WHERE correo_destino LIKE ?)')->execute(["{$prefix}.%"]);
    $pdo->prepare('DELETE FROM dbo.invitaciones_usuario WHERE correo_destino LIKE ?')->execute(["{$prefix}.%"]);
    foreach ($cleanup['invitacion_ids'] as $id) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'invitaciones_usuario', N'invitaciones_permisos') AND (registro_id = ? OR registro_id LIKE ?)")->execute([(string)$id, "{$id}:%"]);
        $pdo->prepare('DELETE FROM dbo.invitaciones_permisos WHERE invitacion_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM dbo.invitaciones_usuario WHERE invitacion_id = ?')->execute([$id]);
    }
    foreach ($cleanup['usuario_ids'] as $id) {
        $pdo->prepare('DELETE FROM dbo.auditorias WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([$id]);
    }
    foreach ($cleanup['temp_dirs'] as $td) {
        if (is_dir($td)) {
            foreach (glob($td . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($td);
        }
    }
}
