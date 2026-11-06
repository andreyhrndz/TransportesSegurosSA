<?php
declare(strict_types=1);

/**
 * Transportes Seguros S.A.
 * Suite E2E de Pruebas Automatizadas: Permisos en Invitaciones, Radios de Destinatario y Estandarización Nombre(s)
 * Ambiente exclusivo: TransportesSegurosSA_Test
 */

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/transport_request_tvp.php';

function test_fail(string $message): never
{
    fwrite(STDERR, "FALLO SUITE: {$message}\n");
    exit(1);
}

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        test_fail($message);
    }
}

function test_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        test_fail('No se pudo reservar puerto local.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

function test_extract_cookie(array $headers): string
{
    $cookie = '';
    foreach ($headers as $h) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $h, $m)) {
            $cookie = $m[1];
        }
    }
    return $cookie;
}

$createdSessionFiles = [];

function make_session_cookie(int $usuarioId, string &$csrfToken): string
{
    global $createdSessionFiles;
    $savePath = session_save_path() ?: sys_get_temp_dir();
    $sid = bin2hex(random_bytes(16));
    $csrfToken = bin2hex(random_bytes(32));
    $content = sprintf(
        'usuario_id|i:%d;authenticated_at|i:%d;last_activity_at|i:%d;csrf_token|s:64:"%s";',
        $usuarioId,
        time(),
        time(),
        $csrfToken
    );
    $filePath = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    file_put_contents($filePath, $content);
    $createdSessionFiles[] = $filePath;
    return session_cookie_name() . '=' . $sid;
}

/** @return array{0: int, 1: string, 2: array<int, string>} */
function test_request(string $url, string $cookie = '', bool $htmx = true, ?array $postFields = null): array
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
    $status = 0;
    if (!empty($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match)) {
        $status = (int)$match[1];
    }
    return [$status, (string)$body, $responseHeaders];
}

$pdo = db();
require_test_database($pdo);

echo "=== INICIO DE PRUEBAS DE PERMISOS EN INVITACIONES, RADIOS Y NOMBRE(S) ===\n";

$port = test_port();
$base = "http://127.0.0.1:{$port}";
$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'tssa-inv-http-');

$env = array_merge(getenv(), [
    'TSSA_DB_DATABASE' => 'TransportesSegurosSA_Test',
    'TSSA_DB_SERVER' => 'localhost',
    'TSSA_MAIL_TRANSPORT' => 'fake',
    'TSSA_APP_URL' => $base
]);

$process = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . DIRECTORY_SEPARATOR . 'public'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    $root,
    $env
);
test_assert(is_resource($process), 'No se pudo iniciar el servidor PHP local.');

$started = false;
for ($i = 0; $i < 30; $i++) {
    usleep(100000);
    $check = @fsockopen('127.0.0.1', $port);
    if ($check) {
        fclose($check);
        $started = true;
        break;
    }
}
test_assert($started, 'El servidor PHP local no respondió en el tiempo límite.');
echo "Servidor HTTP local activo en {$base}\n";

$createdUserIds = [];
$createdInvIds = [];
$createdClientIds = [];

try {
    // -------------------------------------------------------------
    // AUTENTICACIÓN ADMIN (Sesión canónica usuario_id = 1)
    // -------------------------------------------------------------
    $authCsrf = '';
    $cookie = make_session_cookie(1, $authCsrf);

    // Verificar que el dashboard responde 200 con la sesión establecida
    [$dashStatus, $dashBody] = test_request($base . '/dashboard.php', $cookie, false);
    test_assert($dashStatus === 200, "Dashboard debe responder 200 con sesión activa (obtenido {$dashStatus}).");

    // -------------------------------------------------------------
    // FASE 1: OBJETIVO A — Radio Buttons en Nueva Solicitud
    // -------------------------------------------------------------
    echo ">>> Fase 1: Radios interactivos y estado inicial en Solicitudes...\n";

    [$solFormStatus, $solFormBody] = test_request($base . '/solicitudes/form.php', $cookie, true);
    test_assert($solFormStatus === 200, "Formulario de solicitud debe responder 200 (obtenido {$solFormStatus}).");

    // Verificar que los radios NO están deshabilitados
    test_assert(!preg_match('/id="modo-mismo-cliente"[^>]*disabled/i', $solFormBody), 'El radio mismo cliente NO debe contener el atributo disabled.');
    test_assert(!preg_match('/id="modo-tercero"[^>]*disabled/i', $solFormBody), 'El radio tercero NO debe contener el atributo disabled.');

    // Verificar que mismo cliente inicia seleccionado
    test_assert(preg_match('/id="modo-mismo-cliente"[^>]*checked/i', $solFormBody) === 1, 'El radio mismo cliente debe estar checked por defecto.');

    // Verificar etiqueta Nombre(s) * en destinatario
    test_assert(str_contains($solFormBody, 'for="destinatario-nombre">Nombre(s) <span class="required">*</span>'), 'El campo destinatario-nombre debe tener etiqueta visible exacta "Nombre(s) *".');

    echo "   [OK] Radios interactivos desde apertura y etiqueta Nombre(s) * confirmados.\n";

    // 1.2 Restricción backend de cliente jurídico
    $suffix = bin2hex(random_bytes(4));
    $cedJur = '3101' . random_int(100000, 999999);
    $pdo->exec("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=1");
    $stmtCliJur = $pdo->prepare("DECLARE @cid INT; EXEC dbo.sp_crear_cliente_juridico @identificacion=?, @razon_social=?, @nombre_comercial=?, @representante_legal=?, @correo=?, @cliente_id=@cid OUTPUT; SELECT @cid;");
    $stmtCliJur->execute([$cedJur, "Empresa {$suffix} S.A.", "Empresa {$suffix}", "Representante {$suffix}", "empresa_{$suffix}@test.cr"]);
    $jurId = (int)$stmtCliJur->fetchColumn();
    $stmtCliJur->closeCursor();
    $createdClientIds[] = $jurId;

    // POST con mismo_cliente para cliente jurídico -> debe devolver error 422
    [$badSolStatus, $badSolBody] = test_request($base . '/solicitudes/guardar.php', $cookie, true, [
        'csrf_token' => $authCsrf,
        'cliente_id' => $jurId,
        'origen_id' => 1,
        'destino_id' => 2,
        'fecha_entrega' => (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d\TH:i'),
        'modo_destinatario' => 'mismo_cliente',
        'mercancias' => [
            ['tipo_mercancia_id' => 1, 'descripcion' => 'Carga de prueba', 'peso_kg' => '10.0', 'volumen_m3' => '1.0']
        ]
    ]);
    test_assert($badSolStatus === 422, "Solicitud para persona jurídica con mismo_cliente debe ser rechazada con 422 (obtenido {$badSolStatus}).");
    test_assert(str_contains($badSolBody, 'jurídico') || str_contains($badSolBody, 'persona física'), 'El mensaje debe requerir persona física autorizada.');
    echo "   [OK] Backend rechaza cliente jurídico con recepción propia (HTTP 422).\n";

    // -------------------------------------------------------------
    // FASE 2: OBJETIVO B — Permisos en Invitaciones
    // -------------------------------------------------------------
    echo ">>> Fase 2: Checklist y asignación de permisos al invitar...\n";

    [$usrFormStatus, $usrFormBody] = test_request($base . '/usuarios/form.php', $cookie, true);
    test_assert($usrFormStatus === 200, "Modal invitar usuario debe responder 200.");
    test_assert(str_contains($usrFormBody, 'id="filtro-permisos-invitacion"'), 'Debe incluir input de filtro de permisos.');
    test_assert(str_contains($usrFormBody, 'id="contador-permisos-invitacion"'), 'Debe incluir badge contador de permisos.');
    test_assert(str_contains($usrFormBody, 'id="aviso-cero-permisos"'), 'Debe incluir aviso de confirmación de 0 permisos.');
    test_assert(str_contains($usrFormBody, 'name="permisos[]"'), 'Debe incluir checkboxes de permisos.');

    // 2.1 Intento de invitar con 0 permisos sin marcar confirmación -> debe responder 422
    $emailInvZero = "colab.zero.{$suffix}@example.test";
    [$zeroStatus, $zeroBody] = test_request($base . '/usuarios/invitar.php', $cookie, true, [
        'csrf_token' => $authCsrf,
        'correo' => $emailInvZero,
    ]);
    test_assert($zeroStatus === 422, "Invitación con 0 permisos sin confirmar debe ser advertida con 422 (obtenido {$zeroStatus}).");
    test_assert(str_contains($zeroBody, 'casilla de confirmación'), 'Debe solicitar confirmación explícita.');

    // 2.2 Invitación con 3 permisos autorizados
    $emailInv3 = "colab.perm3.{$suffix}@example.test";
    $permisosElegidos = ['CLIENTES_VER', 'SOLICITUDES_VER', 'FLOTAS_VER'];

    [$invStatus, $invBody] = test_request($base . '/usuarios/invitar.php', $cookie, true, [
        'csrf_token' => $authCsrf,
        'correo' => $emailInv3,
        'permisos' => $permisosElegidos,
    ]);
    test_assert($invStatus === 200, "Invitación con 3 permisos debe responder 200 (obtenido {$invStatus}): {$invBody}");
    test_assert(str_contains($invBody, '3 permiso(s) planificado(s)'), 'La respuesta debe confirmar 3 permisos planificados.');

    // Obtener ID de la invitación creada en BD
    $fetchInv = $pdo->prepare('SELECT invitacion_id, token_hash FROM dbo.invitaciones_usuario WHERE correo_destino = ? AND estado = \'PENDIENTE\'');
    $fetchInv->execute([$emailInv3]);
    $invRow = $fetchInv->fetch();
    $fetchInv->closeCursor();
    test_assert(is_array($invRow), 'La invitación debe existir en BD.');
    $invId3 = (int)$invRow['invitacion_id'];
    $createdInvIds[] = $invId3;

    // Verificar en BD dbo.invitaciones_permisos
    $chkInvPerms = $pdo->prepare('SELECT COUNT(*) FROM dbo.invitaciones_permisos WHERE invitacion_id = ?');
    $chkInvPerms->execute([$invId3]);
    test_assert((int)$chkInvPerms->fetchColumn() === 3, 'Deben existir exactamente 3 permisos en dbo.invitaciones_permisos.');
    $chkInvPerms->closeCursor();

    // 2.3 Listado de invitaciones incluye conteo y códigos
    [$invListStatus, $invListBody] = test_request($base . '/usuarios/invitaciones_lista.php', $cookie, true);
    test_assert($invListStatus === 200, "Listado de invitaciones debe responder 200.");
    test_assert(str_contains($invListBody, 'Permisos planificados'), 'La tabla de invitaciones debe tener cabecera Permisos planificados.');
    test_assert(str_contains($invListBody, '3 permisos'), 'Debe mostrar badge de 3 permisos.');
    echo "   [OK] Invitación creada y reflejada en lista con 3 permisos planificados.\n";

    // 2.4 Reenvío de invitación conserva permisos planificados
    $pdo->exec("UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = {$invId3}");

    [$resendStatus, $resendBody] = test_request($base . '/usuarios/reenviar_invitacion.php', $cookie, true, [
        'csrf_token' => $authCsrf,
        'invitacion_id' => $invId3,
    ]);
    test_assert($resendStatus === 200, "Reenvío debe responder 200 (obtenido {$resendStatus}): {$resendBody}");

    $fetchNewInv = $pdo->prepare('SELECT invitacion_id, token_hash FROM dbo.invitaciones_usuario WHERE correo_destino = ? AND estado = \'PENDIENTE\' ORDER BY invitacion_id DESC');
    $fetchNewInv->execute([$emailInv3]);
    $newInvRow = $fetchNewInv->fetch();
    $fetchNewInv->closeCursor();
    test_assert(is_array($newInvRow) && (int)$newInvRow['invitacion_id'] !== $invId3, 'El reenvío debe generar un nuevo invitacion_id.');
    $newInvId = (int)$newInvRow['invitacion_id'];
    $createdInvIds[] = $newInvId;

    $chkInheritedPerms = $pdo->prepare('SELECT COUNT(*) FROM dbo.invitaciones_permisos WHERE invitacion_id = ?');
    $chkInheritedPerms->execute([$newInvId]);
    test_assert((int)$chkInheritedPerms->fetchColumn() === 3, 'La nueva invitación debe heredar exactamente los 3 permisos.');
    $chkInheritedPerms->closeCursor();
    echo "   [OK] Reenvío de invitación duplica de forma íntegra los permisos autorizados.\n";

    // 2.5 Activación por Onboarding transaccional y verificación de permisos efectivos
    // Simular el token de activación
    $tokenRaw = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $tokenRaw);
    $pdo->exec("UPDATE dbo.invitaciones_usuario SET token_hash = '{$tokenHash}' WHERE invitacion_id = {$newInvId}");

    $onbUsername = "user.active.{$suffix}";
    $onbPassword = "ClaveSeguraOnboarding2026*";
    $onbCedula = '20' . random_int(1000000, 9999999);

    // Abrir formulario onboarding para obtener sesión y CSRF token válidos
    [$onbFormStatus, $onbFormBody, $onbFormHeaders] = test_request($base . '/auth/onboarding.php?token=' . urlencode($tokenRaw), '', false);
    test_assert($onbFormStatus === 200, "Formulario de onboarding debe responder 200 (obtenido {$onbFormStatus}).");
    $onbCookie = test_extract_cookie($onbFormHeaders);
    test_assert($onbCookie !== '', 'Debe recibirse cookie de sesión en la pantalla de onboarding.');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $onbFormBody, $mOnbCsrf);
    $onbCsrf = $mOnbCsrf[1] ?? '';
    test_assert($onbCsrf !== '', 'Debe extraerse token CSRF de la pantalla de onboarding.');

    [$onbStatus, $onbBody, $onbHeaders] = test_request($base . '/auth/onboarding_guardar.php', $onbCookie, false, [
        'csrf_token' => $onbCsrf,
        'token' => $tokenRaw,
        'identificacion' => $onbCedula,
        'nombre' => 'Andrey Josué',
        'primer_apellido' => 'Tijerino',
        'segundo_apellido' => 'Hernández',
        'nombre_usuario' => $onbUsername,
        'password' => $onbPassword,
        'password_confirmacion' => $onbPassword,
    ]);
    test_assert($onbStatus === 200 || $onbStatus === 302, "Onboarding debe completar con éxito (obtenido {$onbStatus}).");

    // Buscar usuario creado
    $fetchUser = $pdo->prepare('SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = ?');
    $fetchUser->execute([$onbUsername]);
    $newUserId = (int)$fetchUser->fetchColumn();
    $fetchUser->closeCursor();
    test_assert($newUserId > 0, 'El usuario activado debe existir en BD.');
    $createdUserIds[] = $newUserId;

    // Comprobar que en dbo.usuarios_permisos tiene EXACTAMENTE 3 permisos
    $fetchPerms = $pdo->prepare('SELECT p.codigo FROM dbo.usuarios_permisos up JOIN dbo.permisos p ON p.permiso_id = up.permiso_id WHERE up.usuario_id = ? ORDER BY p.codigo');
    $fetchPerms->execute([$newUserId]);
    $userPermCodes = $fetchPerms->fetchAll(PDO::FETCH_COLUMN);
    $fetchPerms->closeCursor();

    test_assert(count($userPermCodes) === 3, "El usuario debe tener EXACTAMENTE 3 permisos (recibidos: " . count($userPermCodes) . ")");
    test_assert($userPermCodes === ['CLIENTES_VER', 'FLOTAS_VER', 'SOLICITUDES_VER'], "Los permisos deben ser exactamente los aprobados.");

    // Verificar que fn_tiene_permiso confirma los 3 y deniega otros
    $checkFn = $pdo->prepare('SELECT dbo.fn_tiene_permiso(?, ?)');
    $checkFn->execute([$newUserId, 'CLIENTES_VER']);
    test_assert((int)$checkFn->fetchColumn() === 1, 'Debe tener CLIENTES_VER.');
    $checkFn->execute([$newUserId, 'SOLICITUDES_VER']);
    test_assert((int)$checkFn->fetchColumn() === 1, 'Debe tener SOLICITUDES_VER.');
    $checkFn->execute([$newUserId, 'FLOTAS_VER']);
    test_assert((int)$checkFn->fetchColumn() === 1, 'Debe tener FLOTAS_VER.');
    $checkFn->execute([$newUserId, 'USUARIOS_VER']);
    test_assert((int)$checkFn->fetchColumn() === 0, 'NO debe tener USUARIOS_VER.');
    $checkFn->execute([$newUserId, 'USUARIOS_GESTIONAR_PERMISOS']);
    test_assert((int)$checkFn->fetchColumn() === 0, 'NO debe tener USUARIOS_GESTIONAR_PERMISOS.');
    $checkFn->closeCursor();

    echo "   [OK] Onboarding aplicó exactamente los 3 permisos preseleccionados en dbo.usuarios_permisos.\n";

    // 2.6 Prevención de elevación de privilegios: sesión con el nuevo usuario e intentar POST con permisos
    $uCsrf = '';
    $uCookie = make_session_cookie($newUserId, $uCsrf);

    // Intentar POST a /usuarios/invitar.php desde cuenta sin permisos
    [$hackStatus, $hackBody] = test_request($base . '/usuarios/invitar.php', $uCookie, true, [
        'csrf_token' => $uCsrf,
        'correo' => 'hacker@example.test',
        'permisos' => ['USUARIOS_GESTIONAR_PERMISOS']
    ]);
    test_assert($hackStatus === 403, "Actor no autorizado debe recibir HTTP 403 al intentar invitar (obtenido {$hackStatus}).");
    echo "   [OK] Prevención de elevación de privilegios confirmada con cuenta de 3 permisos (HTTP 403).\n";

    // -------------------------------------------------------------
    // FASE 3: OBJETIVO C — Estandarización Global «Nombre(s)»
    // -------------------------------------------------------------
    echo ">>> Fase 3: Estandarización global de etiquetas «Nombre(s)»...\n";

    $auditFiles = [
        'public/clientes/form.php' => 'for="nombre">Nombre(s) <span class="required">*</span>',
        'public/clientes/editar_form.php' => 'for="edit-nombre">Nombre(s) <span class="required">*</span>',
        'public/solicitudes/form.php' => 'for="destinatario-nombre">Nombre(s) <span class="required">*</span>',
        'public/flota/form.php' => 'for="cond-nombre">Nombre(s) <span class="required">*</span>',
        'public/auth/onboarding.php' => 'for="onb-nombre">Nombre(s) <span class="required">*</span>',
    ];

    foreach ($auditFiles as $file => $expectedSnippet) {
        $content = file_get_contents($root . '/' . $file);
        test_assert($content !== false, "No se pudo leer el archivo {$file}.");
        test_assert(str_contains($content, $expectedSnippet), "El archivo {$file} debe contener '{$expectedSnippet}'.");
    }

    echo "   [OK] Las 5 vistas de captura de nombres de pila presentan exactamente «Nombre(s) *».\n";

} finally {
    echo ">>> Limpieza rigurosa de datos efímeros en TransportesSegurosSA_Test...\n";
    if (configured_database_name() === 'TransportesSegurosSA_Test') {
        if (!empty($createdUserIds)) {
            $uList = implode(',', array_map('intval', $createdUserIds));
            $pdo->exec("UPDATE dbo.invitaciones_usuario SET completado_usuario_id = NULL WHERE completado_usuario_id IN ({$uList})");
            $pdo->exec("DELETE FROM dbo.usuarios_permisos WHERE usuario_id IN ({$uList})");
            $pdo->exec("DELETE FROM dbo.auditorias WHERE usuario_id IN ({$uList})");
            $pdo->exec("DELETE FROM dbo.usuarios_perfil WHERE usuario_id IN ({$uList})");
            $pdo->exec("DELETE FROM dbo.usuarios WHERE usuario_id IN ({$uList})");
        }
        if (!empty($createdInvIds)) {
            $iList = implode(',', array_map('intval', $createdInvIds));
            $pdo->exec("DELETE FROM dbo.invitaciones_permisos WHERE invitacion_id IN ({$iList})");
            $pdo->exec("DELETE FROM dbo.invitaciones_usuario WHERE invitacion_id IN ({$iList})");
        }
        if (!empty($createdClientIds)) {
            $cList = implode(',', array_map('intval', $createdClientIds));
            $pdo->exec("DELETE FROM dbo.clientes_juridicos WHERE cliente_id IN ({$cList})");
            $pdo->exec("DELETE FROM dbo.clientes WHERE cliente_id IN ({$cList})");
        }
    }

    if (is_resource($process)) {
        $st = proc_get_status($process);
        if (!empty($st['pid']) && strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            @exec("taskkill /F /T /PID " . (int)$st['pid'] . " 2>&1");
        } else {
            proc_terminate($process);
        }
        @proc_close($process);
    }
    if (isset($log) && is_string($log) && file_exists($log)) {
        @unlink($log);
    }
    foreach ($createdSessionFiles as $sf) {
        if (file_exists($sf)) {
            @unlink($sf);
        }
    }
    echo "   [OK] Servidor detenido y datos de prueba eliminados sin residuos.\n";
}

echo "\n============================================================\n";
echo "RESULTADO: Todas las pruebas de permisos, radios y Nombre(s) pasaron exitosamente.\n";
echo "============================================================\n";
