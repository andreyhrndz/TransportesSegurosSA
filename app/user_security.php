<?php
declare(strict_types=1);

/**
 * Validaciones de seguridad para cuentas de usuario y credenciales administrativas.
 * Nunca registra ni devuelve contraseñas en texto plano ni hashes expuestos.
 */

function validate_username(mixed $value): string
{
    // ASVS 2.2.1: lista blanca de caracteres para identificador de cuenta (3..80).
    $username = trim((string)$value);
    if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
        throw new InvalidArgumentException('El nombre de usuario debe tener entre 3 y 80 caracteres (letras, números, punto, guion o guion bajo).');
    }
    return $username;
}

function validate_permission_code(mixed $value): string
{
    // ASVS 2.2.1: formato estricto de código de permiso individual.
    $code = strtoupper(trim((string)$value));
    if ($code === '' || !preg_match('/^[A-Z0-9_]{3,100}$/', $code)) {
        throw new InvalidArgumentException('El código de permiso indicado no es válido.');
    }
    return $code;
}

function is_critical_admin_permission(string $code): bool
{
    return in_array($code, ['USUARIOS_VER', 'USUARIOS_GESTIONAR_PERMISOS', 'USUARIOS_GESTIONAR_CUENTAS'], true);
}

function validate_new_password_hash(string $password, string $confirmation, string $username = '', string $email = ''): string
{
    // ASVS 6.2.8: no aplicar trim() ni transformar la contraseña ingresada.
    $byteLength = strlen($password);
    $charLength = preg_match_all('/./us', $password);

    // ASVS 6.2.1, 6.2.5: mínimo 12 caracteres sin exigir tipos específicos; máximo 72 bytes para evitar truncamiento de bcrypt.
    if ($charLength === false || $charLength < 12) {
        throw new InvalidArgumentException('La contraseña debe tener al menos 12 caracteres.');
    }
    if ($byteLength > 72) {
        throw new InvalidArgumentException('La contraseña no puede superar los 72 bytes para preservar su integridad.');
    }
    if (!hash_equals($password, $confirmation)) {
        throw new InvalidArgumentException('La confirmación de la contraseña no coincide.');
    }

    // ASVS 6.2.4, 6.2.11: rechazar secretos obvios o derivados del contexto/cuenta.
    $normalized = strtolower(trim($password));
    $blockedExact = [
        '123456789012', '1234567890123', '12345678901234',
        'password1234', 'password12345', 'qwertyuiop12', 'qwerty123456',
        'administrador', 'administrador1', 'changeme1234', 'welcome12345',
        'transportes1', 'transportes2026', 'transportesseguros',
    ];
    if (in_array($normalized, $blockedExact, true)) {
        throw new InvalidArgumentException('Elige una contraseña menos predecible.');
    }

    foreach ([$username, $email, strtok($email, '@') ?: ''] as $contextWord) {
        $word = strtolower(trim((string)$contextWord));
        if (strlen($word) >= 3 && str_contains($normalized, $word)) {
            throw new InvalidArgumentException('La contraseña no debe contener el usuario ni el correo electrónico.');
        }
    }
    if (str_contains($normalized, 'transportes')) {
        throw new InvalidArgumentException('La contraseña no debe contener el nombre de la empresa.');
    }

    // Compatible con password_verify() del login actual.
    return password_hash($password, PASSWORD_BCRYPT);
}
