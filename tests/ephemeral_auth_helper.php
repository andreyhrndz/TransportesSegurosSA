<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$connection = db();
require_test_database($connection);

$action = $argv[1] ?? '';

if ($action === 'create') {
    $suffix = bin2hex(random_bytes(6));
    $username = '__playwright_' . $suffix;
    $password = 'PlaywrightSecure2026*' . $suffix;
    $email = "{$username}@example.test";
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $connection->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) OUTPUT INSERTED.usuario_id VALUES (?, ?, ?, 1)');
    $stmt->execute([$username, $email, $hash]);
    $userId = (int)$stmt->fetchColumn();
    $stmt->closeCursor();

    // Asignar todos los permisos activos para la prueba visual
    $grantStmt = $connection->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE activo = 1');
    $grantStmt->execute([$userId]);
    $grantStmt->closeCursor();

    echo json_encode([
        'user_id' => $userId,
        'username' => $username,
        'password' => $password,
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($action === 'cleanup') {
    $userId = (int)($argv[2] ?? 0);
    if ($userId > 0) {
        $delPerms = $connection->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?');
        $delPerms->execute([$userId]);
        $delPerms->closeCursor();

        $delUser = $connection->prepare("DELETE FROM dbo.usuarios WHERE usuario_id = ? AND nombre_usuario LIKE '__playwright_%'");
        $delUser->execute([$userId]);
        $delUser->closeCursor();
        echo "OK: cleaned up ephemeral user {$userId}\n";
    }
    exit(0);
}

fwrite(STDERR, "Uso: php ephemeral_auth_helper.php [create|cleanup <id>]\n");
exit(1);
