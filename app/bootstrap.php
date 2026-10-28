<?php
/**
 * Funciones compartidas del proyecto.
 * IMPORTANTE: este archivo se mantiene fuera de public/.
 */
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('TSSA_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Para esta primera prueba local se reutiliza la autenticación integrada
    // de Windows, comprobada previamente en este equipo.
    $server = getenv('TSSA_DB_SERVER') ?: 'localhost';
    $database = 'TransportesSegurosSA';
    $dsn = "sqlsrv:Server={$server};Database={$database};Encrypt=yes;TrustServerCertificate=yes";

    try {
        $pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
        return $pdo;
    } catch (PDOException $ex) {
        error_log('TSSA: no se pudo conectar a SQL Server: ' . $ex->getMessage());
        http_response_code(503);
        exit('No se pudo acceder a la base de datos. Revisa SQL Server y el registro de PHP.');
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function valid_csrf(?string $token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function require_login(): array
{
    if (empty($_SESSION['usuario_id'])) {
        redirect_login();
    }
    $id = (int)$_SESSION['usuario_id'];
    $stmt = db()->prepare('SELECT usuario_id, nombre_usuario FROM dbo.usuarios WHERE usuario_id = ? AND activo = 1');
    $stmt->execute([$id]);
    $usuario = $stmt->fetch();
    $stmt->closeCursor();

    if (!$usuario) {
        unset($_SESSION['usuario_id']);
        redirect_login();
    }

    // En la misma conexión SQL de la petición, asociamos el usuario de la
    // sesión PHP al contexto que consultan los procedimientos almacenados.
    // Cada petición PHP abre una conexión no persistente.
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
    if (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true') {
        header('HX-Redirect: /auth/login.php');
        http_response_code(200);
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
    if (!has_permission($codigo)) {
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
