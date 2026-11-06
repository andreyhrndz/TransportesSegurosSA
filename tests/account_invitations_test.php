<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/user_security.php';
require_once __DIR__ . '/../app/mail/mail_service.php';

echo "=== INICIO DE PRUEBAS DE INVITACIONES Y ONBOARDING ===\n";

function inv_assert(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException("FALLO INVITACIONES TEST: $msg");
    }
}

$pdo = db();
require_test_database($pdo);
$adminId = 1;

// Configurar contexto de sesión como admin
$ctx = $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?, @read_only=0;");
$ctx->execute([$adminId]);
$ctx->closeCursor();

// 1. Identificadores efímeros para la suite
$prefix = '__test_inv_' . time() . '_';
$testEmail1 = $prefix . 'usr1@example.test';
$testEmail2 = $prefix . 'usr2@example.test';
$testEmailRate = $prefix . 'rate@example.test';
$testCedula1 = '1' . str_pad((string)random_int(10000000, 99999999), 8, '0');

$createdUserIds = [];
$createdInvIds = [];

try {
    // 2. Crear invitación inicial
    $token1 = bin2hex(random_bytes(32));
    $tokenHash1 = hash('sha256', $token1);

    $stmt = $pdo->prepare('DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT; SELECT @inv_id AS invitacion_id;');
    $stmt->execute([$testEmail1, $tokenHash1]);
    $invId1 = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $createdInvIds[] = $invId1;

    inv_assert($invId1 > 0, "La invitación inicial debe retornar un invitacion_id > 0 (obtenido: $invId1)");

    // Enviar correo con FakeMailCaptureSender
    $sender = new FakeMailCaptureSender();
    $inviteLink1 = get_app_base_url() . '/auth/onboarding.php?token=' . urlencode($token1);
    $html1 = render_invitation_email($inviteLink1, $testEmail1);
    $sent = $sender->send($testEmail1, 'Invitación a Transportes Seguros S.A.', $html1);

    inv_assert($sent, "El envío mediante FakeMailCaptureSender debe retornar true");
    inv_assert($sender->count() === 1, "FakeMailCaptureSender debe registrar exactamente 1 correo");
    $captured = $sender->getLastMessage();
    inv_assert($captured !== null, "Debe existir un mensaje capturado");
    inv_assert($captured['to'] === $testEmail1, "El destinatario debe coincidir con $testEmail1");
    inv_assert(str_contains($captured['htmlBody'], $token1), "El cuerpo del correo debe incluir el token de activación");

    // 3. Validar token
    $stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
    $stmt->execute([$tokenHash1]);
    $valRow = $stmt->fetch();
    $stmt->closeCursor();

    inv_assert($valRow['resultado'] === 'valida', "El token recién creado debe ser válido");
    inv_assert($valRow['correo_destino'] === $testEmail1, "El correo validado debe coincidir");

    // Validar token inventado o modificado
    $badTokenHash = hash('sha256', 'token_falso_invalido_12345678901234567890');
    $stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
    $stmt->execute([$badTokenHash]);
    $badRow = $stmt->fetch();
    $stmt->closeCursor();

    inv_assert($badRow['resultado'] === 'invalido', "Un token inexistente debe retornar resultado = invalido");

    // 4. Completar invitación (Onboarding)
    $nuevoUsuario = 'usr_auto_' . substr(md5($testEmail1), 0, 8);
    $pwdHash = password_hash('ClaveSegura2026!#', PASSWORD_BCRYPT);

    $stmt = $pdo->prepare('DECLARE @uid INT; EXEC dbo.sp_completar_invitacion_usuario @token_hash=?, @identificacion=?, @tipo_identificacion=\'01\', @nombre=?, @primer_apellido=?, @segundo_apellido=?, @nombre_usuario=?, @password_hash=?, @telefono=NULL, @nuevo_usuario_id=@uid OUTPUT; SELECT @uid AS usuario_id;');
    $stmt->execute([
        $tokenHash1,
        $testCedula1,
        'Carlos',
        'Mora',
        'Alvarado',
        $nuevoUsuario,
        $pwdHash
    ]);
    $nuevoUid = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $createdUserIds[] = $nuevoUid;

    inv_assert($nuevoUid > 0, "El usuario debe crearse y retornar usuario_id > 0 (obtenido: $nuevoUid)");

    // 5. Verificar que el usuario tiene CERO permisos por defecto
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.usuarios_permisos WHERE usuario_id = ?;');
    $stmt->execute([$nuevoUid]);
    $permisosCount = (int)$stmt->fetchColumn();
    $stmt->closeCursor();

    inv_assert($permisosCount === 0, "La cuenta creada por invitación debe tener exactamente 0 permisos por defecto");

    // 6. Verificar que la invitación pasó a COMPLETADA
    $stmt = $pdo->prepare('SELECT estado, fecha_completada, completado_usuario_id FROM dbo.invitaciones_usuario WHERE invitacion_id = ?;');
    $stmt->execute([$invId1]);
    $invFinal = $stmt->fetch();
    $stmt->closeCursor();

    inv_assert($invFinal['estado'] === 'COMPLETADA', "El estado de la invitación debe ser COMPLETADA");
    inv_assert($invFinal['fecha_completada'] !== null, "fecha_completada no debe ser nulo");
    inv_assert((int)$invFinal['completado_usuario_id'] === $nuevoUid, "completado_usuario_id debe coincidir con $nuevoUid");

    // 7. Revalidar el token usado: debe retornar completada
    $stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
    $stmt->execute([$tokenHash1]);
    $revalRow = $stmt->fetch();
    $stmt->closeCursor();

    inv_assert($revalRow['resultado'] === 'completada', "El token ya utilizado debe retornar completada");

    // 8. Reenvío, Cooldown y Revocación
    $token2 = bin2hex(random_bytes(32));
    $tokenHash2 = hash('sha256', $token2);

    $stmt = $pdo->prepare('DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT; SELECT @inv_id AS invitacion_id;');
    $stmt->execute([$testEmail2, $tokenHash2]);
    $invId2 = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $createdInvIds[] = $invId2;

    inv_assert($invId2 > 0, "Segunda invitación creada");

    // Reenvío inmediato debe fallar por Cooldown (60s)
    $token2Reenvio = bin2hex(random_bytes(32));
    $tokenHash2Reenvio = hash('sha256', $token2Reenvio);
    $cooldownBlocked = false;
    try {
        $stmt = $pdo->prepare('DECLARE @nueva_id INT; EXEC dbo.sp_reenviar_invitacion_usuario @invitacion_id=?, @nuevo_token_hash=?, @horas_vigencia=24, @nueva_invitacion_id=@nueva_id OUTPUT; SELECT @nueva_id AS nueva_invitacion_id;');
        $stmt->execute([$invId2, $tokenHash2Reenvio]);
        $stmt->closeCursor();
    } catch (PDOException $ex) {
        $msg = (string)($ex->errorInfo[2] ?? $ex->getMessage());
        if (str_contains($msg, '50440') || str_contains($msg, '60 segundos')) {
            $cooldownBlocked = true;
        }
    }
    inv_assert($cooldownBlocked, "Reenvío antes de 60 segundos debe ser rechazado por cooldown (50440)");

    // Simular que transcurrieron 65 segundos modificando ultimo_envio_en
    $pdo->prepare('UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = ?;')->execute([$invId2]);

    $stmt = $pdo->prepare('DECLARE @nueva_id INT; EXEC dbo.sp_reenviar_invitacion_usuario @invitacion_id=?, @nuevo_token_hash=?, @horas_vigencia=24, @nueva_invitacion_id=@nueva_id OUTPUT; SELECT @nueva_id AS nueva_invitacion_id;');
    $stmt->execute([$invId2, $tokenHash2Reenvio]);
    $nuevaInvId2 = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $createdInvIds[] = $nuevaInvId2;

    inv_assert($nuevaInvId2 > 0, "Reenvío tras cooldown debe generar nueva invitación");

    // Token anterior debe estar revocado / inválido
    $stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
    $stmt->execute([$tokenHash2]);
    $rowOld = $stmt->fetch();
    $stmt->closeCursor();
    inv_assert(in_array($rowOld['resultado'], ['revocada', 'invalido'], true), "El token anterior tras reenvío debe ser revocado o inválido");

    // Nuevo token debe ser válido
    $stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
    $stmt->execute([$tokenHash2Reenvio]);
    $rowNew = $stmt->fetch();
    $stmt->closeCursor();
    inv_assert($rowNew['resultado'] === 'valida', "El nuevo token de reenvío debe ser válido");

    // Revocar la nueva invitación
    $stmt = $pdo->prepare('EXEC dbo.sp_revocar_invitacion_usuario @invitacion_id=?;');
    $stmt->execute([$nuevaInvId2]);
    $stmt->closeCursor();

    // Tras revocar, el token retorna revocada
    $stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
    $stmt->execute([$tokenHash2Reenvio]);
    $rowRev = $stmt->fetch();
    $stmt->closeCursor();
    inv_assert($rowRev['resultado'] === 'revocada', "El token de una invitación revocada debe retornar revocada");

    // 9. Probar rate limiting de 5 invitaciones por 24h
    for ($i = 1; $i <= 4; $i++) {
        $tok = bin2hex(random_bytes(32));
        $tokHash = hash('sha256', $tok);
        // Insertar directamente para simular historial de 24h
        $stmt = $pdo->prepare('INSERT INTO dbo.invitaciones_usuario (correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id, fecha_creacion, ultimo_envio_en) OUTPUT INSERTED.invitacion_id VALUES (?, ?, \'EXPIRADA\', DATEADD(HOUR, -1, SYSUTCDATETIME()), ?, SYSUTCDATETIME(), SYSUTCDATETIME());');
        $stmt->execute([$testEmailRate, $tokHash, $adminId]);
        $createdInvIds[] = (int)$stmt->fetchColumn();
        $stmt->closeCursor();
    }
    // La 5ta emisión permitida
    $tok5 = bin2hex(random_bytes(32));
    $tokHash5 = hash('sha256', $tok5);
    $stmt = $pdo->prepare('INSERT INTO dbo.invitaciones_usuario (correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id, fecha_creacion, ultimo_envio_en) OUTPUT INSERTED.invitacion_id VALUES (?, ?, \'EXPIRADA\', DATEADD(HOUR, -1, SYSUTCDATETIME()), ?, SYSUTCDATETIME(), SYSUTCDATETIME());');
    $stmt->execute([$testEmailRate, $tokHash5, $adminId]);
    $createdInvIds[] = (int)$stmt->fetchColumn();
    $stmt->closeCursor();

    // El 6to intento debe ser bloqueado por rate limit (50439)
    $rateLimitBlocked = false;
    try {
        $tok6 = bin2hex(random_bytes(32));
        $tokHash6 = hash('sha256', $tok6);
        $stmt = $pdo->prepare('DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT;');
        $stmt->execute([$testEmailRate, $tokHash6]);
        $stmt->closeCursor();
    } catch (PDOException $ex) {
        $msg = (string)($ex->errorInfo[2] ?? $ex->getMessage());
        if (str_contains($msg, '50439') || str_contains($msg, 'límite de 5 invitaciones')) {
            $rateLimitBlocked = true;
        }
    }
    inv_assert($rateLimitBlocked, "Superar 5 invitaciones en 24 horas para el mismo correo debe ser bloqueado (50439)");

    echo "=== TODAS LAS PRUEBAS DE INVITACIONES Y ONBOARDING PASARON EXITOSAMENTE ===\n";

} finally {
    // Limpieza segura de identidades efímeras de prueba
    if (!empty($createdUserIds)) {
        foreach ($createdUserIds as $uid) {
            $pdo->prepare('DELETE FROM dbo.auditorias WHERE usuario_id = ?;')->execute([$uid]);
            $pdo->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?;')->execute([$uid]);
            $pdo->prepare('DELETE FROM dbo.usuarios_perfil WHERE usuario_id = ?;')->execute([$uid]);
            $pdo->prepare('UPDATE dbo.invitaciones_usuario SET completado_usuario_id = NULL WHERE completado_usuario_id = ?;')->execute([$uid]);
            $pdo->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?;')->execute([$uid]);
        }
    }
    if (!empty($createdInvIds)) {
        $inClause = implode(',', array_filter(array_map('intval', $createdInvIds)));
        if ($inClause !== '') {
            $pdo->exec("DELETE FROM dbo.invitaciones_usuario WHERE invitacion_id IN ($inClause)");
        }
    }
}
