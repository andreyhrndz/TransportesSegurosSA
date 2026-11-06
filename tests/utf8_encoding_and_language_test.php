<?php
declare(strict_types=1);

/**
 * Suite automatizada de codificación UTF-8 de extremo a extremo y lenguaje de interfaz.
 * Comprueba:
 * 1. Preservación exacta de la cadena Unicode de referencia: "á é í ó ú Á É Í Ó Ú ñ Ñ ü ¿ ¡"
 * 2. Recuperación mediante PDO_SQLSRV, tablas de TransportesSegurosSA_Test y definiciones de SPs.
 * 3. Descripciones de permisos en dbo.permisos y en la vista /usuarios/detalle.php.
 * 4. Respuestas HTTP completas, fragmentos HTMX y endpoints JSON (Content-Type + charset UTF-8).
 * 5. Envío desde formulario HTTP POST y recuperación posterior en HTML, modal de edición y JSON
 *    sin doble codificación ni pérdida de caracteres.
 * 6. Lenguaje de usuario natural ("Flota y catálogos", "Consultar historial de actividad",
 *    "Ver historial de actividad", ausencia de "UTC" visible y de cadenas mojibake).
 *
 * Opera exclusivamente sobre TransportesSegurosSA_Test.
 */
putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';

function utf8_fail(string $message): never
{
    fwrite(STDERR, "FALLO UTF-8: {$message}\n");
    exit(1);
}

function utf8_assert(bool $condition, string $message): void
{
    if (!$condition) {
        utf8_fail($message);
    }
}

function utf8_has_mojibake(string $text): bool
{
    if (!preg_match('//u', $text)) {
        return true;
    }
    // Secuencias Unicode típicas de UTF-8 mal decodificado como Latin-1/CP1252 (Ã..., Â..., â€...) o carácter de reemplazo U+FFFD
    return preg_match('/\x{00C3}[\x{0080}-\x{00BF}\x{20AC}-\x{2122}]|\x{00C2}[\x{0080}-\x{00BF}]|\x{00E2}[\x{0080}-\x{00BF}\x{20AC}\x{201A}\x{0192}]|\x{FFFD}/u', $text) === 1;
}

function utf8_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        utf8_fail('No se pudo reservar puerto local.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

/** @return array{0:int,1:string,2:array<int,string>} */
function utf8_http_request(string $url, string $cookie, bool $htmx = false, ?array $postFields = null): array
{
    $headers = "Cookie: {$cookie}\r\nAccept: text/html,application/json\r\n" . ($htmx ? "HX-Request: true\r\n" : '');
    $options = [
        'header' => $headers,
        'ignore_errors' => true,
        'timeout' => 8,
        'follow_location' => 0,
    ];
    if ($postFields !== null) {
        $options['method'] = 'POST';
        $options['header'] .= "Content-Type: application/x-www-form-urlencoded; charset=UTF-8\r\n";
        $options['content'] = http_build_query($postFields);
    }
    $context = stream_context_create(['http' => $options]);
    $body = file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = (int)(preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match) ? $match[1] : 0);
    return [$status, $body === false ? '' : $body, $responseHeaders];
}

/**
 * @param array<int,string> $headers
 */
function utf8_find_header(array $headers, string $prefix): ?string
{
    foreach ($headers as $line) {
        if (stripos($line, $prefix) === 0) {
            return trim($line);
        }
    }
    return null;
}

if (configured_database_name() !== 'TransportesSegurosSA_Test') {
    utf8_fail('Se requiere TSSA_DB_DATABASE=TransportesSegurosSA_Test.');
}

$pdo = db();
require_test_database($pdo);

$unicodeSample = 'á é í ó ú Á É Í Ó Ú ñ Ñ ü ¿ ¡';

// 1. Verificar configuración PHP y viaje redondo directo por PDO_SQLSRV
utf8_assert(strtoupper((string)ini_get('default_charset')) === 'UTF-8', 'default_charset de PHP debe ser UTF-8.');

$stmt = $pdo->prepare('SELECT ? AS param_echo, N\'á é í ó ú Á É Í Ó Ú ñ Ñ ü ¿ ¡\' AS literal_echo');
$stmt->execute([$unicodeSample]);
$echoRow = $stmt->fetch();
$stmt->closeCursor();

utf8_assert(is_array($echoRow), 'PDO_SQLSRV debe devolver fila de eco Unicode.');
utf8_assert($echoRow['param_echo'] === $unicodeSample, 'El parámetro enviado por PDO_SQLSRV debe preservar exactamente "' . $unicodeSample . '".');
utf8_assert($echoRow['literal_echo'] === $unicodeSample, 'El literal N\'...\' en SQL Server debe preservar exactamente "' . $unicodeSample . '".');

// 2. Verificar ausencia total de mojibake en permisos, tablas de datos y procedimientos almacenados
$permRows = $pdo->query('SELECT codigo, nombre, descripcion FROM dbo.permisos ORDER BY codigo')->fetchAll();
utf8_assert(count($permRows) >= 15, 'dbo.permisos debe contener el catálogo de permisos.');

$permMap = [];
foreach ($permRows as $p) {
    $codigo = (string)$p['codigo'];
    $nombre = (string)$p['nombre'];
    $desc = (string)($p['descripcion'] ?? '');
    utf8_assert(!utf8_has_mojibake($nombre) && !utf8_has_mojibake($desc), "El permiso {$codigo} contiene caracteres dañados: {$nombre} / {$desc}");
    $permMap[$codigo] = ['nombre' => $nombre, 'descripcion' => $desc];
}

utf8_assert(
    ($permMap['AUDITORIA_VER']['nombre'] ?? '') === 'Consultar historial de actividad'
    && ($permMap['AUDITORIA_VER']['descripcion'] ?? '') === 'Ver historial de actividad',
    'AUDITORIA_VER debe mostrar "Consultar historial de actividad: Ver historial de actividad".'
);
utf8_assert(
    ($permMap['CATALOGOS_VER']['nombre'] ?? '') === 'Consultar catálogos'
    && str_contains($permMap['CATALOGOS_VER']['descripcion'] ?? '', 'mercancía'),
    'CATALOGOS_VER debe incluir tildes correctas en "catálogos" y "mercancía".'
);
utf8_assert(
    ($permMap['FLOTAS_VER']['descripcion'] ?? '') === 'Consultar vehículos y conductores',
    'FLOTAS_VER debe incluir tilde correcta en "vehículos".'
);
utf8_assert(
    ($permMap['SOLICITUDES_VER']['descripcion'] ?? '') === 'Consultar solicitudes y mercancías asociadas',
    'SOLICITUDES_VER debe incluir tilde correcta en "mercancías".'
);

$spRows = $pdo->query("
    SELECT o.name, m.definition
    FROM sys.sql_modules m
    INNER JOIN sys.objects o ON o.object_id = m.object_id
    WHERE o.type = 'P'
")->fetchAll();
foreach ($spRows as $sp) {
    $spName = (string)$sp['name'];
    $spDef = (string)$sp['definition'];
    utf8_assert(!utf8_has_mojibake($spDef), "La definición del procedimiento almacenado {$spName} contiene mojibake.");
}

// 3. Iniciar servidor local y comprobar páginas completas, fragmentos HTMX, JSON y envío de formularios
$suffix = bin2hex(random_bytes(4));
$sessionName = 'TSSA_TRANSPORTES_SESSION';
$sessionId = bin2hex(random_bytes(24));
$csrfToken = bin2hex(random_bytes(32));

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
ini_set('session.use_strict_mode', '0');
session_name($sessionName);
session_id($sessionId);
session_start();
$_SESSION = [
    'usuario_id' => 1,
    'authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => $csrfToken,
];
session_write_close();
ini_set('session.use_strict_mode', '1');

$port = utf8_port();
$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'tssa-utf8-test-');
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
    utf8_fail('No se pudo iniciar el servidor HTTP local.');
}

$createdUbicacionId = null;

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
    $cookie = "{$sessionName}={$sessionId}";

    // 3.1 Páginas completas HTML
    $fullPages = [
        '/auth/login.php' => 'Iniciar sesión',
        '/' => 'Panel general',
        '/clientes.php' => 'Clientes',
        '/solicitudes.php' => 'Solicitudes',
        '/viajes.php' => 'Viajes',
        '/entregas.php' => 'Entregas',
        '/usuarios.php' => 'Usuarios y permisos',
        '/flota.php' => 'Flota y catálogos',
        '/reportes.php' => 'Reportes',
    ];

    foreach ($fullPages as $path => $expectedText) {
        $reqCookie = $path === '/auth/login.php' ? '' : $cookie;
        [$status, $body, $headers] = utf8_http_request($base . $path, $reqCookie, false);
        utf8_assert($status === 200, "GET {$path} debe responder 200 (recibido {$status}).");
        $ct = utf8_find_header($headers, 'Content-Type:');
        utf8_assert($ct !== null && stripos($ct, 'charset=utf-8') !== false, "GET {$path} debe enviar Content-Type con charset=utf-8 (recibido: " . ($ct ?? 'ninguno') . ').');
        utf8_assert(!utf8_has_mojibake($body), "GET {$path} contiene caracteres mojibake o UTF-8 inválido.");
        utf8_assert(str_contains($body, $expectedText), "GET {$path} debe incluir '{$expectedText}'.");
        utf8_assert(!str_contains($body, 'Flota y maestros'), "GET {$path} no debe mostrar la denominación antigua 'Flota y maestros'.");
        utf8_assert(preg_match('/\bUTC\b/', strip_tags($body)) !== 1, "GET {$path} no debe mostrar menciones visibles a UTC.");
    }

    // 3.2 Fragmentos HTMX y modales
    $htmxFragments = [
        '/clientes/lista.php',
        '/clientes/detalle.php?id=1',
        '/clientes/editar_form.php?id=1',
        '/solicitudes/lista.php',
        '/solicitudes/detalle.php?id=1',
        '/solicitudes/form.php',
        '/viajes/lista.php',
        '/viajes/detalle.php?id=1',
        '/viajes/form.php',
        '/entregas/lista.php?seccion=entregas',
        '/entregas/lista.php?seccion=incidencias',
        '/entregas/lista.php?seccion=notificaciones',
        '/entregas/lista.php?seccion=documentos&solicitud_id=1',
        '/entregas/incidencia_form.php?accion=registrar',
        '/entregas/documento_form.php',
        '/usuarios/lista.php',
        '/usuarios/detalle.php?id=1',
        '/usuarios/form.php',
        '/flota/lista.php?seccion=vehiculos',
        '/flota/lista.php?seccion=conductores',
        '/flota/lista.php?seccion=rutas',
        '/flota/lista.php?seccion=ubicaciones',
        '/flota/lista.php?seccion=catalogos',
        '/flota/form.php?entidad=vehiculo',
        '/reportes/lista.php?seccion=auditoria',
        '/reportes/lista.php?seccion=solicitudes',
        '/reportes/lista.php?seccion=viajes',
        '/reportes/lista.php?seccion=entregas',
        '/reportes/lista.php?seccion=flota',
    ];

    foreach ($htmxFragments as $fragPath) {
        [$status, $body, $headers] = utf8_http_request($base . $fragPath, $cookie, true);
        utf8_assert($status === 200, "Fragmento HTMX {$fragPath} debe responder 200 (recibido {$status}).");
        $ct = utf8_find_header($headers, 'Content-Type:');
        utf8_assert($ct !== null && stripos($ct, 'charset=utf-8') !== false, "Fragmento HTMX {$fragPath} debe incluir charset=utf-8.");
        utf8_assert(!utf8_has_mojibake($body), "Fragmento HTMX {$fragPath} contiene mojibake o UTF-8 inválido.");
        utf8_assert(preg_match('/\bUTC\b/', strip_tags($body)) !== 1, "Fragmento HTMX {$fragPath} no debe mostrar menciones visibles a UTC.");
    }

    // Verificar textos específicos corregidos en /usuarios/detalle.php?id=1
    [, $userDetailBody] = utf8_http_request($base . '/usuarios/detalle.php?id=1', $cookie, true);
    utf8_assert(
        str_contains($userDetailBody, 'Consultar historial de actividad')
        && str_contains($userDetailBody, 'Ver historial de actividad'),
        'La ficha de permisos de usuario debe mostrar "Consultar historial de actividad" y "Ver historial de actividad".'
    );
    utf8_assert(
        !str_contains($userDetailBody, 'Consultar metadatos de auditor')
        && !str_contains($userDetailBody, 'auditorÃ'),
        'La ficha de permisos de usuario no debe contener "Consultar metadatos de auditoría" ni mojibake.'
    );

    // 3.3 Respuestas JSON para combobox remotos
    $jsonEndpoints = [
        '/clientes/lista.php?formato=json',
        '/flota/lista.php?seccion=vehiculos&formato=json',
        '/flota/lista.php?seccion=conductores&formato=json',
        '/flota/lista.php?seccion=rutas&formato=json',
        '/flota/lista.php?seccion=ubicaciones&formato=json',
        '/flota/lista.php?seccion=catalogos&tipo=mercancia&formato=json',
        '/solicitudes/lista.php?formato=json',
        '/usuarios/lista.php?formato=json',
    ];

    foreach ($jsonEndpoints as $jsonPath) {
        [$status, $body, $headers] = utf8_http_request($base . $jsonPath, $cookie, true);
        utf8_assert($status === 200, "Endpoint JSON {$jsonPath} debe responder 200.");
        $ct = utf8_find_header($headers, 'Content-Type:');
        utf8_assert(
            $ct !== null && stripos($ct, 'application/json') !== false && stripos($ct, 'charset=utf-8') !== false,
            "Endpoint JSON {$jsonPath} debe responder Content-Type: application/json; charset=utf-8."
        );
        utf8_assert(!utf8_has_mojibake($body), "Endpoint JSON {$jsonPath} contiene mojibake.");
        $decoded = json_decode($body, true);
        utf8_assert(is_array($decoded), "Endpoint JSON {$jsonPath} debe devolver un arreglo JSON válido.");
    }

    // 3.4 Envío de formulario con la cadena completa "á é í ó ú Á É Í Ó Ú ñ Ñ ü ¿ ¡" y recuperación posterior
    $nombreUnicode = "Sede Logística {$suffix} {$unicodeSample}";
    $direccionUnicode = "Bodega #4, Cañón del Virilla: {$unicodeSample}";

    [$postStatus, $postBody] = utf8_http_request($base . '/flota/guardar.php', $cookie, true, [
        'csrf_token' => $csrfToken,
        'entidad' => 'ubicacion',
        'nombre' => $nombreUnicode,
        'direccion' => $direccionUnicode,
        'latitud' => '9.934739',
        'longitud' => '-84.087502',
    ]);
    utf8_assert(
        $postStatus === 200 && str_contains($postBody, 'Ubicación registrado correctamente.'),
        "El alta HTTP POST con caracteres Unicode debe responder 200 con tilde en 'Ubicación'."
    );

    $stFind = $pdo->prepare('SELECT ubicacion_id, nombre, direccion FROM dbo.ubicaciones WHERE nombre = ?');
    $stFind->execute([$nombreUnicode]);
    $dbRow = $stFind->fetch();
    $stFind->closeCursor();

    utf8_assert(is_array($dbRow), 'El registro con caracteres Unicode debe encontrarse en SQL Server.');
    $createdUbicacionId = (int)$dbRow['ubicacion_id'];
    utf8_assert((string)$dbRow['nombre'] === $nombreUnicode, 'El nombre almacenado en SQL Server debe coincidir exactamente sin pérdida ni doble codificación.');
    utf8_assert((string)$dbRow['direccion'] === $direccionUnicode, 'La dirección almacenada en SQL Server debe coincidir exactamente sin pérdida ni doble codificación.');

    // Recuperar en fragmento HTML de lista
    [$listStatus, $listHtml] = utf8_http_request(
        $base . '/flota/lista.php?seccion=ubicaciones&buscar=' . rawurlencode($suffix),
        $cookie,
        true
    );
    utf8_assert($listStatus === 200, 'La búsqueda HTMX de la ubicación Unicode debe responder 200.');
    utf8_assert(
        str_contains($listHtml, $nombreUnicode) && str_contains($listHtml, $direccionUnicode),
        'El fragmento HTML debe mostrar exactamente los caracteres "á é í ó ú Á É Í Ó Ú ñ Ñ ü ¿ ¡" sin doble escape.'
    );
    utf8_assert(!str_contains($listHtml, '&amp;'), 'No debe existir doble escape HTML (&amp;) al presentar el texto Unicode.');

    // Recuperar en modal de edición
    [$editStatus, $editHtml] = utf8_http_request(
        $base . '/flota/form.php?entidad=ubicacion&id=' . $createdUbicacionId,
        $cookie,
        true
    );
    utf8_assert($editStatus === 200 && str_contains($editHtml, $nombreUnicode), 'El formulario modal de edición debe precargar intactos los caracteres Unicode.');

    // Recuperar en endpoint JSON del combobox
    [$jsonStatus, $jsonBody] = utf8_http_request(
        $base . '/flota/lista.php?seccion=ubicaciones&formato=json&buscar=' . rawurlencode($suffix),
        $cookie,
        true
    );
    $jsonItems = json_decode($jsonBody, true);
    utf8_assert($jsonStatus === 200 && is_array($jsonItems) && count($jsonItems) === 1, 'El endpoint JSON debe devolver el registro Unicode buscado.');
    utf8_assert(
        ($jsonItems[0]['text'] ?? '') === $nombreUnicode
        && ($jsonItems[0]['subtext'] ?? '') === $direccionUnicode,
        'El JSON del combobox debe conservar exactamente el texto y subtexto Unicode sin corrupción.'
    );

    // 3.5 Verificar archivo JavaScript principal
    $jsContent = (string)file_get_contents($root . '/public/assets/js/app.js');
    utf8_assert(!utf8_has_mojibake($jsContent), 'public/assets/js/app.js no debe contener mojibake.');
    utf8_assert(str_contains($jsContent, 'Buscando opciones disponibles…'), 'public/assets/js/app.js debe usar el mensaje humanizado de carga.');

    fwrite(STDOUT, "OK: suite de codificación UTF-8 de extremo a extremo y lenguaje de interfaz superada en TransportesSegurosSA_Test.\n");
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($process);
    proc_close($process);
    @unlink($log);

    session_name($sessionName);
    session_id($sessionId);
    session_start();
    $_SESSION = [];
    session_destroy();

    if ($createdUbicacionId !== null) {
        require_test_database($pdo);
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'ubicaciones' AND registro_id = ?")->execute([(string)$createdUbicacionId]);
        $pdo->prepare('DELETE FROM dbo.ubicaciones WHERE ubicacion_id = ?')->execute([$createdUbicacionId]);
    }
}
