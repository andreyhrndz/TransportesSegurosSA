<?php
/** Funciones compartidas fuera de public/. */
declare(strict_types=1);

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function is_htmx_request(): bool
{
    return ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';
}

function configured_database_name(): string
{
    // ASVS 1.2.4: el nombre de base no se interpola desde entrada libre; sólo
    // se admiten los destinos locales conocidos para evitar cambiar el DSN.
    $database = trim((string)(getenv('TSSA_DB_DATABASE') ?: 'TransportesSegurosSA'));
    if (!in_array($database, ['TransportesSegurosSA', 'TransportesSegurosSA_Test'], true)) {
        throw new LogicException('Configuración de base de datos no permitida.');
    }

    return $database;
}

function require_test_database(PDO $connection): void
{
    // ASVS 16.5.3: las pruebas de escritura deben fallar cerradas si no están
    // conectadas a la copia aislada esperada.
    $effectiveDatabase = (string)$connection->query('SELECT DB_NAME()')->fetchColumn();
    if ($effectiveDatabase !== 'TransportesSegurosSA_Test') {
        throw new LogicException('Las pruebas de escritura requieren TransportesSegurosSA_Test.');
    }
}

function session_cookie_name(): string
{
    // ASVS 3.3.1-3.3.3: aislar la cookie de esta aplicación; el prefijo
    // __Host- sólo se usa cuando la petición realmente está protegida por HTTPS.
    return is_https() ? '__Host-TSSA_TRANSPORTES_SESSION' : 'TSSA_TRANSPORTES_SESSION';
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    // ASVS 3.2.1, 3.4.4-3.4.6: limitar interpretación y contenido ejecutable.
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), geolocation=(), microphone=()');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'self'; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

send_security_headers();

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = is_https();
    // ASVS 3.3.1-3.3.4: cookie host-only, Secure, HttpOnly y SameSite en HTTPS.
    // HTTP se conserva sólo para el entorno local de desarrollo.
    session_name(session_cookie_name());
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_cache_limiter('nocache');
    if (!session_start()) {
        // ASVS 16.3.4: no continuar una petición sin almacenamiento de sesión.
        error_log('TSSA security: unable to start PHP session');
        http_response_code(503);
        exit('El servicio no está disponible temporalmente. Inténtalo más tarde.');
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $server = getenv('TSSA_DB_SERVER') ?: 'localhost';
    try {
        $database = configured_database_name();
        $dsn = "sqlsrv:Server={$server};Database={$database};Encrypt=yes;TrustServerCertificate=yes";
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException|LogicException $ex) {
        // ASVS 16.3.4: registrar el detalle solo del lado del servidor.
        error_log('TSSA: no se pudo conectar a SQL Server: ' . $ex->getMessage());
        http_response_code(503);
        exit('El servicio no está disponible temporalmente. Inténtalo más tarde.');
    }
}

function e(mixed $value): string
{
    // ASVS 1.2.1: codificación HTML contextual en vistas PHP.
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require_once __DIR__ . '/components.php';

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function valid_csrf(?string $token): bool
{
    // ASVS 3.5.1: token anti-forgery de sesión comparado en tiempo constante.
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function session_is_current(): bool
{
    $now = time();
    $lastActivity = (int)($_SESSION['last_activity_at'] ?? 0);
    $authenticatedAt = (int)($_SESSION['authenticated_at'] ?? 0);
    $idleLimit = 30 * 60;
    $absoluteLimit = 8 * 60 * 60;
    if ($lastActivity === 0 || $authenticatedAt === 0 || $now - $lastActivity > $idleLimit || $now - $authenticatedAt > $absoluteLimit) {
        return false;
    }
    $_SESSION['last_activity_at'] = $now;
    return true;
}

function require_login(): array
{
    // ASVS 7.2.1, 7.3.1-7.3.2: validar la sesión y sus límites temporales.
    if (empty($_SESSION['usuario_id']) || !session_is_current()) {
        destroy_session();
        redirect_login();
    }
    $id = (int)$_SESSION['usuario_id'];
    $stmt = db()->prepare('SELECT usuario_id, nombre_usuario FROM dbo.usuarios WHERE usuario_id = ? AND activo = 1');
    $stmt->execute([$id]);
    $usuario = $stmt->fetch();
    $stmt->closeCursor();
    if (!$usuario) {
        destroy_session();
        redirect_login();
    }

    // El contexto SQL procede exclusivamente de una sesión PHP ya validada.
    static $contextSet = false;
    if (!$contextSet) {
        $ctx = db()->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?, @read_only=1");
        $ctx->execute([$id]);
        $ctx->closeCursor();
        $contextSet = true;
    }
    return $usuario;
}

function redirect_login(): never
{
    if (is_htmx_request()) {
        header('HX-Redirect: /auth/login.php');
        http_response_code(401);
        exit;
    }
    header('Location: /auth/login.php', true, 302);
    exit;
}

function has_permission(string $codigo): bool
{
    $usuario = require_login();
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM dbo.usuarios_permisos up
         JOIN dbo.permisos p ON p.permiso_id = up.permiso_id
         WHERE up.usuario_id = ? AND p.codigo = ? AND p.activo = 1'
    );
    $stmt->execute([(int)$usuario['usuario_id'], $codigo]);
    $ok = ((int)$stmt->fetchColumn()) > 0;
    $stmt->closeCursor();
    return $ok;
}

function require_permission(string $codigo): void
{
    // ASVS 8.2.1, 8.3.1: permiso individual validado del lado del servidor.
    if (!has_permission($codigo)) {
        error_log('TSSA security: authorization denied for permission ' . $codigo);
        if (is_htmx_request()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            exit(ui_notice('No tienes permiso para realizar esta acción.', 'danger'));
        }
        http_response_code(403);
        exit('No tienes permiso para acceder a esta función.');
    }
}

function valid_text(string $texto, int $max, bool $required = true): bool
{
    if ($required && $texto === '') {
        return false;
    }
    $length = preg_match_all('/./us', $texto);
    return $length !== false && $length <= $max;
}
