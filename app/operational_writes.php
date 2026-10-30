<?php
declare(strict_types=1);

/** Utilidades de escrituras operativas; todos los SQL llamados por controladores son estáticos. */

function operation_error(string $message, int $status = 422): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    exit(ui_notice($message, 'danger'));
}

function require_operation_post(string $permission): void
{
    require_permission($permission);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        operation_error('Método no permitido.', 405);
    }
    // ASVS 3.5.1, 3.5.3: cada mutación POST exige el token asociado a la sesión autenticada.
    if (!valid_csrf($_POST['csrf_token'] ?? null)) {
        error_log('TSSA security: invalid CSRF token on operational write');
        operation_error('El formulario caducó. Recarga la página y vuelve a intentarlo.', 403);
    }
}

function operation_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Selecciona un registro válido.');
    }
    return (int)$id;
}

function operation_text(mixed $value, int $maxLength, bool $required = true): ?string
{
    $text = trim((string)$value);
    if ($text === '' && !$required) {
        return null;
    }
    if (!valid_text($text, $maxLength, $required)) {
        throw new InvalidArgumentException('Revisa los datos ingresados.');
    }
    return $text;
}

function operation_email(mixed $value, int $maxLength = 150, bool $required = true): ?string
{
    // ASVS 2.2.1, 2.2.2: validación de formato y longitud de correo en capa servidor.
    $email = trim((string)$value);
    if ($email === '' && !$required) {
        return null;
    }
    if (!valid_text($email, $maxLength, $required) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Ingresa un correo electrónico válido.');
    }
    return $email;
}

function operation_future_datetime(mixed $value): string
{
    $text = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $text, new DateTimeZone('UTC'));
    if ($date === false || $date->format('Y-m-d\\TH:i') !== $text || $date <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
        throw new InvalidArgumentException('Indica una fecha futura válida.');
    }
    return $date->format('Y-m-d H:i:s');
}

function operation_reference_url(mixed $value): string
{
    $url = trim((string)$value);
    if (!valid_text($url, 500) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('Indica una URL de referencia válida.');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https', 'http'], true) || isset($parts['user'], $parts['pass'])) {
        throw new InvalidArgumentException('La URL debe usar HTTP o HTTPS sin credenciales.');
    }
    return $url;
}

function operation_sql_error(PDOException $exception, array $messages = []): never
{
    $number = (int)($exception->errorInfo[1] ?? 0);
    // ASVS 16.2.5/16.3.3: se registra sólo el código, nunca datos de formulario, contraseñas ni SQL.
    error_log('TSSA security: operational write rejected with SQL code ' . $number);
    $message = $messages[$number] ?? match ($number) {
        2601, 2627 => 'Ya existe un registro con esos datos.',
        50101 => 'El registro solicitado no existe.',
        50103, 50201, 50202, 50216, 50217, 50407, 50410, 50412, 50501 => 'No tienes permiso para realizar esta acción.',
        default => 'No se pudo completar la operación. Verifica el estado actual e inténtalo nuevamente.',
    };

    $forbiddenCodes = [50103, 50201, 50202, 50216, 50217, 50407, 50410, 50412, 50501];
    $notFoundCodes = [50101, 50415, 50503];
    $conflictCodes = [
        2601, 2627, 547,
        50220, 50221,
        50311, 50324, 50405, 50409,
        50416, 50417, 50418, 50419, 50420,
        50504, 50505, 50506,
    ];

    $status = 422;
    if (in_array($number, $forbiddenCodes, true)) {
        $status = 403;
    } elseif (in_array($number, $notFoundCodes, true)) {
        $status = 404;
    } elseif (in_array($number, $conflictCodes, true)) {
        $status = 409;
    }

    operation_error($message, $status);
}

function operation_success(string $message, string $event, string $redirect): never
{
    header('HX-Trigger-After-Settle: ' . $event);
    if (is_htmx_request()) {
        header('HX-Redirect: ' . $redirect);
    } else {
        header('Location: ' . $redirect, true, 303);
    }
    header('Content-Type: text/html; charset=UTF-8');
    exit(ui_notice($message, 'success'));
}

function operation_modal_success(string $message, string $event, string $fallbackRedirect): never
{
    if (is_htmx_request()) {
        $triggerPayload = json_encode([
            $event => true,
            'tssaNotice' => [
                'message' => $message,
                'error' => false,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        header('HX-Trigger-After-Settle: ' . $triggerPayload);
        header('Content-Type: text/html; charset=UTF-8');
        exit(ui_notice($message, 'success'));
    }

    header('Location: ' . $fallbackRedirect, true, 303);
    exit;
}

/** @return array<int, array<string, mixed>> */
function operation_list(string $sql, array $params = []): array
{
    return read_procedure_list($sql, $params)['rows'];
}
