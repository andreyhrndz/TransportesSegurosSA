<?php
declare(strict_types=1);

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/transport_request_tvp.php';

function sol_fail(string $message): never
{
    fwrite(STDERR, "FALLO SOLICITUD TEST: {$message}\n");
    exit(1);
}

function sol_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        sol_fail($msg);
    }
}

function sol_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        sol_fail('No se pudo reservar puerto local.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

/** @return array{0:int,1:string,2:array<int,string>} */
function sol_request(string $url, string $cookie, bool $htmx = true, ?array $postFields = null): array
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

$pdo = db();
require_test_database($pdo);

// 1. Obtener datos de referencia para las pruebas
$cliFisicoStmt = $pdo->query("SELECT TOP 1 c.cliente_id, cf.nombre, cf.primer_apellido, cf.segundo_apellido, c.identificacion, c.telefono 
                              FROM dbo.clientes c 
                              JOIN dbo.clientes_fisicos cf ON cf.cliente_id = c.cliente_id 
                              WHERE c.activo = 1");
$cliFisico = $cliFisicoStmt->fetch(PDO::FETCH_ASSOC);
sol_assert($cliFisico !== false, 'Debe existir un cliente físico activo en Test');

$cliJuridicoStmt = $pdo->query("SELECT TOP 1 c.cliente_id, cj.razon_social, c.identificacion 
                                FROM dbo.clientes c 
                                JOIN dbo.clientes_juridicos cj ON cj.cliente_id = c.cliente_id 
                                WHERE c.activo = 1");
$cliJuridico = $cliJuridicoStmt->fetch(PDO::FETCH_ASSOC);
sol_assert($cliJuridico !== false, 'Debe existir un cliente jurídico activo en Test');

$ubiStmt = $pdo->query("SELECT TOP 2 ubicacion_id FROM dbo.ubicaciones WHERE activo = 1 ORDER BY ubicacion_id");
$ubis = $ubiStmt->fetchAll(PDO::FETCH_COLUMN);
sol_assert(count($ubis) >= 2, 'Deben existir al menos 2 ubicaciones activas en Test');

$tipoStmt = $pdo->query("SELECT TOP 1 tipo_mercancia_id FROM dbo.tipos_mercancia WHERE activo = 1");
$tipoMercanciaId = (int)$tipoStmt->fetchColumn();
sol_assert($tipoMercanciaId > 0, 'Debe existir un tipo de mercancía activo');

// 2. Preparar sesión local autoritativa de administrador (usuario_id = 1)
$sessionName = session_cookie_name();
$adminSessionId = bin2hex(random_bytes(16));
$adminCsrf = bin2hex(random_bytes(32));

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
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
ini_set('session.use_strict_mode', '1');

$adminCookie = "{$sessionName}={$adminSessionId}";

echo "=== INICIO DE PRUEBAS DE CREAR SOLICITUD Y DESTINATARIO CONDICIONAL ===\n";

// 3. Levantar servidor local ephemeral
$port = sol_port();
$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'tssa-sol-http-');
$env = array_merge(getenv(), ['TSSA_DB_DATABASE' => 'TransportesSegurosSA_Test']);
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . DIRECTORY_SEPARATOR . 'public'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    $root,
    $env
);
sol_assert(is_resource($proc), 'Debe iniciar servidor HTTP local');

for ($i = 0; $i < 25; $i++) {
    $sock = @fsockopen('127.0.0.1', $port);
    if ($sock !== false) {
        fclose($sock);
        break;
    }
    usleep(100000);
}

$base = "http://127.0.0.1:{$port}";
$createdSolicitudIds = [];

try {
    // 4. Probar GET /solicitudes/form.php
    [$formCode, $formHtml] = sol_request($base . '/solicitudes/form.php', $adminCookie, true);
    sol_assert($formCode === 200, "Formulario debe responder 200 (obtenido: $formCode)");
    sol_assert(!str_contains($formHtml, 'Código de solicitud'), 'No debe existir la etiqueta Código de solicitud');
    sol_assert(!str_contains($formHtml, 'Código asignado al guardar (automático)'), 'No debe existir el input de código asignado');
    sol_assert(str_contains($formHtml, 'solicitud-cliente'), 'Debe incluir selector de cliente');
    sol_assert(str_contains($formHtml, 'modo_destinatario'), 'Debe incluir radio buttons de modo destinatario');
    sol_assert(str_contains($formHtml, 'seccion-destinatario'), 'Debe incluir sección de destinatario condicional');

    // 5. POST /solicitudes/guardar.php - Caso cliente físico con "mismo_cliente"
    $futureDate = gmdate('Y-m-d\\TH:i', strtotime('+4 days'));
    $postMismo = [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $cliFisico['cliente_id'],
        'origen_id' => $ubis[0],
        'destino_id' => $ubis[1],
        'fecha_entrega' => $futureDate,
        'modo_destinatario' => 'mismo_cliente',
        'mercancias' => [
            [
                'tipo_mercancia_id' => $tipoMercanciaId,
                'descripcion' => 'Paquetería certificada cliente propio',
                'peso_kg' => '12.50',
                'volumen_m3' => '0.250',
            ],
        ],
    ];

    [$codeMismo, $respMismo, $headersMismo] = sol_request($base . '/solicitudes/guardar.php', $adminCookie, true, $postMismo);
    sol_assert($codeMismo === 200, "Guardar con mismo_cliente debe responder 200 (obtenido: $codeMismo, respuesta: $respMismo)");
    sol_assert(str_contains($respMismo, 'Solicitud registrada correctamente'), 'Debe retornar notificación de éxito');

    // Verificar en BD que se guardaron los datos reales del cliente físico
    $solId1 = 0;
    foreach ($headersMismo as $hdr) {
        if (preg_match('/HX-Redirect:\s*\/solicitudes\/detalle\.php\?id=(\d+)/i', $hdr, $mRedir)) {
            $solId1 = (int)$mRedir[1];
        }
    }
    if ($solId1 === 0 && preg_match('/detalle\.php\?id=(\d+)/', $respMismo, $mSolId)) {
        $solId1 = (int)$mSolId[1];
    }
    sol_assert($solId1 > 0, "Debe redirigir al ID de la nueva solicitud (obtenido: $solId1)");
    $createdSolicitudIds[] = $solId1;

    $stmtCheck1 = $pdo->prepare("SELECT codigo_solicitud, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion 
                                 FROM dbo.solicitudes WHERE solicitud_id = ?");
    $stmtCheck1->execute([$solId1]);
    $solRow1 = $stmtCheck1->fetch(PDO::FETCH_ASSOC);
    sol_assert($solRow1 !== false, 'La solicitud debe existir en BD');
    sol_assert(str_starts_with($solRow1['codigo_solicitud'], 'SOL-'), 'El código debe autogenerarse con prefijo SOL-');
    sol_assert($solRow1['destinatario_nombre'] === $cliFisico['nombre'], 'Nombre debe ser el del cliente físico');
    sol_assert($solRow1['destinatario_apellido1'] === $cliFisico['primer_apellido'], 'Primer apellido debe ser el del cliente físico');
    sol_assert($solRow1['destinatario_identificacion'] === $cliFisico['identificacion'], 'Identificación debe ser la del cliente físico');

    // 6. POST /solicitudes/guardar.php - Caso "tercero" con datos legítimos
    $postTercero = [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $cliFisico['cliente_id'],
        'origen_id' => $ubis[0],
        'destino_id' => $ubis[1],
        'fecha_entrega' => $futureDate,
        'modo_destinatario' => 'tercero',
        'destinatario_identificacion' => '1-0999-0888',
        'destinatario_nombre' => 'Mariana',
        'destinatario_apellido1' => 'Castro',
        'destinatario_apellido2' => 'Salazar',
        'destinatario_telefono' => '8765-4321',
        'mercancias' => [
            [
                'tipo_mercancia_id' => $tipoMercanciaId,
                'descripcion' => 'Material electrónico para Mariana Castro',
                'peso_kg' => '8.00',
                'volumen_m3' => '0.150',
            ],
        ],
    ];

    [$codeTercero, $respTercero, $headersTercero] = sol_request($base . '/solicitudes/guardar.php', $adminCookie, true, $postTercero);
    sol_assert($codeTercero === 200, "Guardar con tercero debe responder 200 (obtenido: $codeTercero)");
    $solId2 = 0;
    foreach ($headersTercero as $hdr) {
        if (preg_match('/HX-Redirect:\s*\/solicitudes\/detalle\.php\?id=(\d+)/i', $hdr, $mRedir)) {
            $solId2 = (int)$mRedir[1];
        }
    }
    if ($solId2 === 0 && preg_match('/detalle\.php\?id=(\d+)/', $respTercero, $mSolId2)) {
        $solId2 = (int)$mSolId2[1];
    }
    sol_assert($solId2 > 0, "Debe crearse la solicitud con tercero (obtenido: $solId2)");
    $createdSolicitudIds[] = $solId2;

    $stmtCheck2 = $pdo->prepare("SELECT codigo_solicitud, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion 
                                 FROM dbo.solicitudes WHERE solicitud_id = ?");
    $stmtCheck2->execute([$solId2]);
    $solRow2 = $stmtCheck2->fetch(PDO::FETCH_ASSOC);
    sol_assert($solRow2['destinatario_nombre'] === 'Mariana', 'Nombre debe ser Mariana');
    sol_assert($solRow2['destinatario_apellido1'] === 'Castro', 'Apellido1 debe ser Castro');
    sol_assert($solRow2['destinatario_apellido2'] === 'Salazar', 'Apellido2 debe ser Salazar');

    // 7. Rechazo: Cliente jurídico con "mismo_cliente"
    $postJuridicoInvalido = [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $cliJuridico['cliente_id'],
        'origen_id' => $ubis[0],
        'destino_id' => $ubis[1],
        'fecha_entrega' => $futureDate,
        'modo_destinatario' => 'mismo_cliente',
        'mercancias' => [
            [
                'tipo_mercancia_id' => $tipoMercanciaId,
                'descripcion' => 'Carga empresarial',
                'peso_kg' => '50.00',
            ],
        ],
    ];

    [$codeJur, $respJur] = sol_request($base . '/solicitudes/guardar.php', $adminCookie, true, $postJuridicoInvalido);
    sol_assert($codeJur === 422, "Cliente jurídico con mismo_cliente debe ser rechazado con 422 (obtenido: $codeJur)");
    sol_assert(str_contains($respJur, 'jurídico') || str_contains($respJur, 'persona física autorizada'), 'Debe explicar la regla jurídica');

    // 8. Rechazo: Modo tercero sin datos requeridos (nombre/apellido vacíos)
    $postTerceroVacio = [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $cliFisico['cliente_id'],
        'origen_id' => $ubis[0],
        'destino_id' => $ubis[1],
        'fecha_entrega' => $futureDate,
        'modo_destinatario' => 'tercero',
        'destinatario_nombre' => '',
        'destinatario_apellido1' => '',
        'mercancias' => [
            [
                'tipo_mercancia_id' => $tipoMercanciaId,
                'descripcion' => 'Carga sin destinatario',
                'peso_kg' => '5.00',
            ],
        ],
    ];

    [$codeVacio, $respVacio] = sol_request($base . '/solicitudes/guardar.php', $adminCookie, true, $postTerceroVacio);
    sol_assert($codeVacio === 422, "Tercero sin nombre debe ser rechazado con 422 (obtenido: $codeVacio)");

    // 9. Rechazo: Modo destinatario desconocido
    $postModoInvalido = [
        'csrf_token' => $adminCsrf,
        'cliente_id' => $cliFisico['cliente_id'],
        'origen_id' => $ubis[0],
        'destino_id' => $ubis[1],
        'fecha_entrega' => $futureDate,
        'modo_destinatario' => 'modo_inventado_hack',
        'mercancias' => [
            [
                'tipo_mercancia_id' => $tipoMercanciaId,
                'descripcion' => 'Carga hack',
                'peso_kg' => '5.00',
            ],
        ],
    ];

    [$codeModo, $respModo] = sol_request($base . '/solicitudes/guardar.php', $adminCookie, true, $postModoInvalido);
    sol_assert($codeModo === 422, "Modo desconocido debe ser rechazado con 422 (obtenido: $codeModo)");

    echo "OK: Todas las pruebas funcionales de solicitudes y destinatario pasaron con éxito.\n";

} finally {
    // Limpieza de solicitudes efímeras en la base de datos de prueba
    foreach ($createdSolicitudIds as $sid) {
        $pdo->prepare("DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM dbo.mercancias WHERE solicitud_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM dbo.solicitudes WHERE solicitud_id = ?")->execute([$sid]);
    }

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
