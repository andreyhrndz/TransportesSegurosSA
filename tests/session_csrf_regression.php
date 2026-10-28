<?php
declare(strict_types=1);

/**
 * Regresión HTTP sin credenciales ni escrituras SQL.
 *
 * Ejecutar: & 'C:\AdmBD2026\Herramientas\PHP\8.4\php.exe' tests\session_csrf_regression.php
 */
function fail(string $message): never
{
    fwrite(STDERR, "FALLO: {$message}\n");
    exit(1);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

function reserve_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        fail('No se pudo reservar un puerto local.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

/** @return array{0:string,1:list<string>} */
function request(string $url, string $method = 'GET', string $body = '', string $cookie = ''): array
{
    $headers = "Accept: text/html\r\n";
    if ($cookie !== '') {
        $headers .= "Cookie: {$cookie}\r\n";
    }
    if ($method === 'POST') {
        $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $content = file_get_contents($url, false, $context);
    /** @var list<string> $responseHeaders */
    $responseHeaders = $http_response_header ?? [];
    return [$content === false ? '' : $content, $responseHeaders];
}

/** @return array{0:string,1:string,2:string} */
function login_form_session(string $url): array
{
    [$html, $headers] = request($url);
    assert_true(str_starts_with($headers[0] ?? '', 'HTTP/') && str_contains($headers[0], '200'), 'El GET del login debe responder 200.');
    assert_true((bool)preg_match('/name="csrf_token" value="([^"]+)"/', $html, $token), 'El GET debe incluir token CSRF.');
    $setCookie = implode("\n", array_filter($headers, static fn (string $header): bool => str_starts_with(strtolower($header), 'set-cookie:')));
    assert_true((bool)preg_match('/Set-Cookie:\s*([^=;]+)=([^;]+)/i', $setCookie, $cookie), 'El GET debe emitir cookie de sesión.');
    assert_true($cookie[1] === 'TSSA_TRANSPORTES_SESSION', 'HTTP local debe usar cookie de sesión específica.');
    assert_true(!str_contains(strtolower($setCookie), 'secure'), 'HTTP local no debe emitir una cookie Secure.');
    assert_true(str_contains(strtolower($setCookie), 'httponly') && str_contains(strtolower($setCookie), 'samesite=lax'), 'La cookie debe conservar HttpOnly y SameSite=Lax.');
    return [$token[1], $cookie[1], $cookie[2]];
}

$root = dirname(__DIR__);
$php = PHP_BINARY;
$port = reserve_port();
$log = tempnam(sys_get_temp_dir(), 'tssa-session-test-');
$process = proc_open(
    [$php, '-S', "127.0.0.1:{$port}", '-t', $root . DIRECTORY_SEPARATOR . 'public'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    $root
);
if (!is_resource($process)) {
    @unlink($log);
    fail('No se pudo iniciar el servidor PHP local.');
}

try {
    $url = "http://127.0.0.1:{$port}/auth/login.php";
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port);
        if ($connection !== false) {
            fclose($connection);
            break;
        }
        usleep(100000);
    }

    [$tokenA, $cookieNameA, $cookieValueA] = login_form_session($url);
    $cookieA = $cookieNameA . '=' . $cookieValueA;
    [$validBody] = request($url, 'POST', http_build_query([
        'csrf_token' => $tokenA,
        'usuario' => 'synthetic-user',
        'password' => 'synthetic-password',
    ]), $cookieA);
    assert_true(!str_contains($validBody, 'La sesión del formulario caducó.'), 'El token de la misma sesión debe superar CSRF.');

    [$missingTokenBody] = request($url, 'POST', http_build_query([
        'usuario' => 'synthetic-user',
        'password' => 'synthetic-password',
    ]), $cookieA);
    assert_true(str_contains($missingTokenBody, 'La sesión del formulario caducó.'), 'Un POST sin token debe rechazarse.');

    [$invalidTokenBody] = request($url, 'POST', http_build_query([
        'csrf_token' => 'invalid-token',
        'usuario' => 'synthetic-user',
        'password' => 'synthetic-password',
    ]), $cookieA);
    assert_true(str_contains($invalidTokenBody, 'La sesión del formulario caducó.'), 'Un POST con token incorrecto debe rechazarse.');

    [$tokenB, $cookieNameB, $cookieValueB] = login_form_session($url);
    [$differentSessionBody] = request($url, 'POST', http_build_query([
        'csrf_token' => $tokenA,
        'usuario' => 'synthetic-user',
        'password' => 'synthetic-password',
    ]), $cookieNameB . '=' . $cookieValueB);
    assert_true(str_contains($differentSessionBody, 'La sesión del formulario caducó.'), 'Un token de otra sesión debe rechazarse.');

    fwrite(STDOUT, "OK: ciclo GET/cookie/POST CSRF verificado sin credenciales ni escrituras SQL.\n");
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($process);
    proc_close($process);
    @unlink($log);
}
