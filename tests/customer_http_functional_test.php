<?php
declare(strict_types=1);

/**
 * Prueba funcional HTTP del módulo de Clientes (alta, edición física/jurídica,
 * desactivación, permisos, CSRF, escape XSS, estado incompatible e integridad).
 * Opera exclusivamente sobre registros aislados efímeros en TransportesSegurosSA_Test
 * y limpia los artefactos efímeros al finalizar sin tocar datos preexistentes.
 */
putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';

function ch_fail(string $message): never
{
    fwrite(STDERR, "FALLO: {$message}\n");
    exit(1);
}

function ch_assert(bool $condition, string $message): void
{
    if (!$condition) {
        ch_fail($message);
    }
}

function ch_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        ch_fail('No se pudo reservar puerto local.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

/** @return array{0:int,1:string,2:array<int,string>} */
function ch_request(string $url, string $cookie, bool $htmx = true, ?array $postFields = null): array
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
    ch_fail('Se requiere TSSA_DB_DATABASE=TransportesSegurosSA_Test.');
}
$pdo = db();
require_test_database($pdo);

$suffix = bin2hex(random_bytes(4));
$idFisico = '__HTTP_CF_' . $suffix;
$idJuridico = '__HTTP_CJ_' . $suffix;
$unauthUsername = '__http_cl_unauth_' . $suffix;
$createdClientIds = [];
$createdSolicitudId = null;
$unauthUserId = null;

$sessionName = 'TSSA_TRANSPORTES_SESSION';
$adminSessionId = bin2hex(random_bytes(24));
$adminCsrf = bin2hex(random_bytes(32));
$unauthSessionId = bin2hex(random_bytes(24));
$unauthCsrf = bin2hex(random_bytes(32));

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Crear usuario efímero sólo con CLIENTES_VER (sin crear/editar/desactivar)
$insertUser = $pdo->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) OUTPUT INSERTED.usuario_id VALUES (?, ?, ?, 1)');
$insertUser->execute([$unauthUsername, $unauthUsername . '@example.test', password_hash('ClaveTemporalPrueba123!', PASSWORD_BCRYPT)]);
$unauthUserId = (int)$insertUser->fetchColumn();
$insertUser->closeCursor();

$grantView = $pdo->prepare("INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo = N'CLIENTES_VER'");
$grantView->execute([$unauthUserId]);
$grantView->closeCursor();

// Preparar sesiones de prueba en almacenamiento de sesiones local (en CLI antes de servidor HTTP)
ini_set('session.use_strict_mode', '0');
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

// Preparar sesión con permisos insuficientes
session_name($sessionName);
session_id($unauthSessionId);
session_start();
$_SESSION = [
    'usuario_id' => $unauthUserId,
    'authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => $unauthCsrf,
];
session_write_close();
ini_set('session.use_strict_mode', '1');

$port = ch_port();
$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'tssa-cust-http-');
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
    ch_fail('No se pudo iniciar servidor HTTP local.');
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
    $unauthCookie = "{$sessionName}={$unauthSessionId}";

    // 1. Alta de cliente físico y jurídico por HTTP
    [$status, $body] = ch_request($base . '/clientes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'tipo' => 'fisico',
        'identificacion' => $idFisico,
        'nombre' => 'Valeria',
        'primer_apellido' => 'Campos',
        'segundo_apellido' => 'Mora',
        'telefono' => '8888-1010',
        'correo' => "valeria.{$suffix}@example.test",
    ]);
    ch_assert($status === 200 && str_contains($body, 'registrado correctamente'), 'El alta HTTP de cliente físico debe responder 200.');

    [$status, $body] = ch_request($base . '/clientes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'tipo' => 'juridico',
        'identificacion' => $idJuridico,
        'razon_social' => "Distribuidora <Segura> {$suffix} S.A.",
        'nombre_comercial' => "DistSegura {$suffix}",
        'representante_legal' => 'Roberto Salas',
        'correo' => "empresa.{$suffix}@example.test",
    ]);
    ch_assert($status === 200 && str_contains($body, 'registrado correctamente'), 'El alta HTTP de cliente jurídico debe responder 200.');

    // Obtener IDs creados
    $findStmt = $pdo->prepare('SELECT cliente_id, identificacion FROM dbo.clientes WHERE identificacion IN (?, ?)');
    $findStmt->execute([$idFisico, $idJuridico]);
    $map = [];
    foreach ($findStmt->fetchAll() as $row) {
        $cid = (int)$row['cliente_id'];
        $createdClientIds[] = $cid;
        $map[(string)$row['identificacion']] = $cid;
    }
    $findStmt->closeCursor();
    $fisicoId = $map[$idFisico] ?? 0;
    $juridicoId = $map[$idJuridico] ?? 0;
    ch_assert($fisicoId > 0 && $juridicoId > 0, 'Ambos clientes de prueba deben existir en Test.');

    // 2. Duplicado de identificación -> 409
    [$status] = ch_request($base . '/clientes/guardar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'tipo' => 'fisico',
        'identificacion' => $idFisico,
        'nombre' => 'Otro',
        'primer_apellido' => 'Duplicado',
        'correo' => "dup.{$suffix}@example.test",
    ]);
    ch_assert($status === 409, 'Alta duplicada debe responder 409.');

    // 3. CSRF inválido en actualizar y desactivar -> 403
    [$status, $body] = ch_request($base . '/clientes/actualizar.php', $adminCookie, true, [
        'csrf_token' => 'invalido',
        'cliente_id' => $fisicoId,
        'subtipo' => 'FISICA',
        'nombre' => 'Valeria',
        'primer_apellido' => 'Campos',
        'correo' => "valeria.{$suffix}@example.test",
    ]);
    ch_assert($status === 403 && str_contains($body, 'formulario caducó'), 'Edición con CSRF inválido debe responder 403.');

    [$status, $body] = ch_request($base . '/clientes/desactivar.php', $adminCookie, true, [
        'csrf_token' => 'invalido',
        'cliente_id' => $fisicoId,
    ]);
    ch_assert($status === 403 && str_contains($body, 'formulario caducó'), 'Desactivación con CSRF inválido debe responder 403.');

    // 4. Permisos insuficientes (usuario con solo CLIENTES_VER) -> 403 y sin botones de mutación
    [$status, $body] = ch_request($base . '/clientes/detalle.php?id=' . $juridicoId, $unauthCookie, false);
    ch_assert($status === 200, 'Usuario con CLIENTES_VER puede ver detalle.');
    ch_assert(!str_contains($body, '/clientes/editar_form.php') && !str_contains($body, '/clientes/desactivar_form.php'), 'No debe renderizar botones sin permiso.');
    ch_assert(str_contains($body, '&lt;Segura&gt;') && !str_contains($body, '<Segura>'), 'Debe escapar caracteres HTML en detalle.');

    [$status] = ch_request($base . '/clientes/editar_form.php?id=' . $fisicoId, $unauthCookie, true);
    ch_assert($status === 403, 'GET editar_form sin permiso debe responder 403.');

    [$status] = ch_request($base . '/clientes/actualizar.php', $unauthCookie, true, [
        'csrf_token' => $unauthCsrf,
        'cliente_id' => $fisicoId,
        'subtipo' => 'FISICA',
        'nombre' => 'Intento',
        'primer_apellido' => 'SinPermiso',
        'correo' => "hack.{$suffix}@example.test",
    ]);
    ch_assert($status === 403, 'POST actualizar sin permiso debe responder 403.');

    [$status] = ch_request($base . '/clientes/desactivar.php', $unauthCookie, true, [
        'csrf_token' => $unauthCsrf,
        'cliente_id' => $fisicoId,
    ]);
    ch_assert($status === 403, 'POST desactivar sin permiso debe responder 403.');

    // 5. Edición válida física y jurídica + recarga parcial HTMX
    [$status, $body] = ch_request($base . '/clientes/editar_form.php?id=' . $fisicoId, $adminCookie, true);
    ch_assert($status === 200 && str_contains($body, $idFisico), 'Formulario de edición física debe cargar.');

    [$status, $body, $headers] = ch_request($base . '/clientes/actualizar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $fisicoId,
        'subtipo' => 'FISICA',
        'nombre' => 'Valeria Sofía',
        'primer_apellido' => 'Campos',
        'segundo_apellido' => 'Mora',
        'correo' => "valeria.editada.{$suffix}@example.test",
        'telefono' => '8888-2020',
        'direccion' => 'Heredia, Belén',
    ]);
    ch_assert($status === 200 && str_contains($body, 'actualizado correctamente'), 'Edición física válida debe responder 200.');
    $triggerHeader = implode("\n", array_filter($headers, static fn (string $h): bool => str_starts_with(strtolower($h), 'hx-trigger-after-settle:')));
    ch_assert(str_contains($triggerHeader, 'clienteActualizado'), 'Debe emitir trigger HTMX clienteActualizado.');

    [$status, $body] = ch_request($base . '/clientes/actualizar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $juridicoId,
        'subtipo' => 'JURIDICA',
        'razon_social' => "Distribuidora Renovada {$suffix} S.A.",
        'nombre_comercial' => "DistRenovada {$suffix}",
        'representante_legal' => 'Mónica Brenes',
        'correo' => "empresa.editada.{$suffix}@example.test",
        'telefono' => '2222-4040',
        'direccion' => 'San José, Pavas',
    ]);
    ch_assert($status === 200 && str_contains($body, 'actualizado correctamente'), 'Edición jurídica válida debe responder 200.');

    [$status, $partialBody] = ch_request($base . '/clientes/detalle.php?id=' . $fisicoId . '&parcial=1', $adminCookie, true);
    ch_assert($status === 200 && str_contains($partialBody, 'Valeria Sofía') && str_contains($partialBody, 'Heredia, Belén'), 'Fragmento HTMX de detalle debe reflejar cambios.');

    // 6. Datos inválidos, subtipo incompatible y registro inexistente
    [$status] = ch_request($base . '/clientes/actualizar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $fisicoId,
        'subtipo' => 'FISICA',
        'nombre' => 'Valeria',
        'primer_apellido' => 'Campos',
        'correo' => 'correo-sin-formato',
    ]);
    ch_assert($status === 422, 'Correo inválido debe responder 422.');

    [$status] = ch_request($base . '/clientes/actualizar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $fisicoId,
        'subtipo' => 'JURIDICA',
        'razon_social' => 'Subtipo Cruzado S.A.',
        'correo' => "cruzado.{$suffix}@example.test",
    ]);
    ch_assert($status === 409, 'Subtipo incompatible debe responder 409.');

    [$status] = ch_request($base . '/clientes/desactivar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => 99999999,
    ]);
    ch_assert($status === 404, 'Desactivar cliente inexistente debe responder 404.');

    // 7. Bloqueo de desactivación con solicitud no terminal -> 409
    $ctxStmt = $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=1");
    $ctxStmt->execute();
    $ctxStmt->closeCursor();

    $solStmt = $pdo->prepare(
        "DECLARE @sid INT; DECLARE @m dbo.tipo_mercancias;
         INSERT INTO @m (tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (1, N'Carga prueba', 5.00, 0.500);
         EXEC dbo.sp_crear_solicitud @codigo=?, @cliente_id=?, @origen_id=1, @destino_id=2,
              @destinatario_nombre=N'Juan', @destinatario_apellido1=N'Pérez', @destinatario_apellido2=N'Soto',
              @destinatario_identificacion=NULL, @destinatario_telefono=NULL,
              @fecha_entrega_requerida=?, @mercancias=@m, @solicitud_id=@sid OUTPUT;
         SELECT @sid AS solicitud_id;"
    );
    $solStmt->execute(['__HTTP_SOL_' . $suffix, $fisicoId, gmdate('Y-m-d H:i:s', time() + 172800)]);
    do {
        if ($solStmt->columnCount() > 0) {
            $r = $solStmt->fetch();
            if (is_array($r) && isset($r['solicitud_id'])) {
                $createdSolicitudId = (int)$r['solicitud_id'];
            }
        }
    } while ($solStmt->nextRowset());
    $solStmt->closeCursor();

    [$status, $body] = ch_request($base . '/clientes/desactivar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $fisicoId,
    ]);
    ch_assert($status === 409 && str_contains($body, 'solicitudes operativas activas'), 'Cliente con solicitud activa debe rechazarse con 409.');

    // Cancelar solicitud y desactivar cliente exitosamente
    $cancelStmt = $pdo->prepare('EXEC dbo.sp_cancelar_solicitud @solicitud_id=?, @motivo=?;');
    $cancelStmt->execute([$createdSolicitudId, 'Fin de prueba HTTP de bloqueo.']);
    $cancelStmt->closeCursor();

    [$status, $body] = ch_request($base . '/clientes/desactivar_form.php?id=' . $fisicoId, $adminCookie, true);
    ch_assert($status === 200 && str_contains($body, 'Desactivar cliente'), 'Modal de confirmación de desactivación debe responder 200.');

    [$status, $body] = ch_request($base . '/clientes/desactivar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $fisicoId,
    ]);
    ch_assert($status === 200 && str_contains($body, 'desactivado correctamente'), 'Desactivación válida debe responder 200.');

    // Re-desactivar cliente ya inactivo -> 409
    [$status] = ch_request($base . '/clientes/desactivar.php', $adminCookie, true, [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $fisicoId,
    ]);
    ch_assert($status === 409, 'Re-desactivar cliente inactivo debe responder 409.');

    fwrite(STDOUT, "OK: pruebas funcionales HTTP de Clientes completadas en TransportesSegurosSA_Test.\n");
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($process);
    proc_close($process);
    @unlink($log);

    foreach ([$adminSessionId, $unauthSessionId] as $sid) {
        session_name($sessionName);
        session_id($sid);
        session_start();
        $_SESSION = [];
        session_destroy();
    }

    // Limpiar únicamente los artefactos efímeros creados por esta prueba en Test
    require_test_database($pdo);
    if ($createdSolicitudId !== null) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'solicitudes' AND registro_id = ?")->execute([(string)$createdSolicitudId]);
        $pdo->prepare('DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id = ?')->execute([$createdSolicitudId]);
        $pdo->prepare('DELETE FROM dbo.mercancias WHERE solicitud_id = ?')->execute([$createdSolicitudId]);
        $pdo->prepare('DELETE FROM dbo.solicitudes WHERE solicitud_id = ?')->execute([$createdSolicitudId]);
    }
    foreach ($createdClientIds as $cid) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'clientes' AND registro_id = ?")->execute([(string)$cid]);
        $pdo->prepare('DELETE FROM dbo.clientes_fisicos WHERE cliente_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM dbo.clientes_juridicos WHERE cliente_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM dbo.clientes WHERE cliente_id = ?')->execute([$cid]);
    }
    if ($unauthUserId !== null) {
        $pdo->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([$unauthUserId]);
        $pdo->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([$unauthUserId]);
    }
}
