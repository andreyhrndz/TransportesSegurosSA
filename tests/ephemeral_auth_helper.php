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

if ($action === 'create_invitation') {
    $tokenRaw = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $tokenRaw);
    $suffix = bin2hex(random_bytes(4));
    $correo = "test.onboarding.{$suffix}@example.test";

    $connection->exec("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=1");
    $stmt = $connection->prepare('DECLARE @id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@id OUTPUT; SELECT @id AS invitacion_id;');
    $stmt->execute([$correo, $tokenHash]);
    $invId = (int)$stmt->fetchColumn();
    $stmt->closeCursor();

    echo json_encode([
        'invitacion_id' => $invId,
        'correo' => $correo,
        'token' => $tokenRaw
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($action === 'cleanup_invitation') {
    $invId = (int)($argv[2] ?? 0);
    if ($invId > 0) {
        $connection->exec("DELETE FROM dbo.invitaciones_usuario WHERE invitacion_id = {$invId}");
        echo "OK: cleaned up invitation {$invId}\n";
    }
    exit(0);
}

if ($action === 'cleanup_onboarding_user') {
    $target = $argv[2] ?? '';
    if ($target !== '') {
        $stmtInv = $connection->prepare("UPDATE dbo.invitaciones_usuario SET completado_usuario_id = NULL WHERE completado_usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = ? OR correo_acceso = ?)");
        $stmtInv->execute([$target, $target]);
        $stmtInv->closeCursor();

        $stmtPerms = $connection->prepare("DELETE FROM dbo.usuarios_permisos WHERE usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = ? OR correo_acceso = ?)");
        $stmtPerms->execute([$target, $target]);
        $stmtPerms->closeCursor();

        $stmtAud = $connection->prepare("DELETE FROM dbo.auditorias WHERE usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = ? OR correo_acceso = ?)");
        $stmtAud->execute([$target, $target]);
        $stmtAud->closeCursor();

        $stmtProf = $connection->prepare("DELETE FROM dbo.usuarios_perfil WHERE identificacion = ? OR usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = ? OR correo_acceso = ?)");
        $stmtProf->execute([$target, $target, $target]);
        $stmtProf->closeCursor();

        $stmtUsr = $connection->prepare("DELETE FROM dbo.usuarios WHERE nombre_usuario = ? OR correo_acceso = ?");
        $stmtUsr->execute([$target, $target]);
        $stmtUsr->closeCursor();
        echo "OK: cleaned up onboarding user {$target}\n";
    }
    exit(0);
}

fwrite(STDERR, "Uso: php ephemeral_auth_helper.php [create|cleanup <id>|create_invitation|cleanup_invitation <id>]\n");
exit(1);
