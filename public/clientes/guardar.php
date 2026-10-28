<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_permission('CLIENTES_CREAR');

function form_error(string $mensaje, int $codigo = 422): never
{
    http_response_code($codigo);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div class="notification is-danger">' . e($mensaje) . '</div>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    form_error('Método no permitido.', 405);
}
if (!valid_csrf($_POST['csrf_token'] ?? null)) {
    form_error('El formulario caducó. Recarga la página y vuelve a intentarlo.', 403);
}

$tipo = (string)($_POST['tipo'] ?? '');
$identificacion = trim((string)($_POST['identificacion'] ?? ''));
$correo = trim((string)($_POST['correo'] ?? ''));
if (!in_array($tipo, ['fisico', 'juridico'], true)) {
    form_error('Tipo de cliente no válido.');
}
if (!valid_text($identificacion, 30) || !valid_text($correo, 150) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    form_error('Ingresa una identificación y un correo electrónico válidos.');
}

if ($tipo === 'fisico') {
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $apellido = trim((string)($_POST['primer_apellido'] ?? ''));
    $apellido2 = trim((string)($_POST['segundo_apellido'] ?? ''));
    $telefono = trim((string)($_POST['telefono'] ?? ''));
    if (!valid_text($nombre, 100) || !valid_text($apellido, 100) || !valid_text($apellido2, 100, false) || !valid_text($telefono, 30, false)) {
        form_error('Revisa los nombres, apellidos y teléfono del cliente.');
    }
} else {
    $razon = trim((string)($_POST['razon_social'] ?? ''));
    $comercial = trim((string)($_POST['nombre_comercial'] ?? ''));
    $representante = trim((string)($_POST['representante_legal'] ?? ''));
    if (!valid_text($razon, 150) || !valid_text($comercial, 150, false) || !valid_text($representante, 150, false)) {
        form_error('Revisa la razón social, nombre comercial y representante legal.');
    }
}

try {
    // Comprobación previa para informar mejor; la UNIQUE SQL protege ante concurrencia.
    $exists = db()->prepare('SELECT COUNT(*) FROM dbo.clientes WHERE identificacion = ?');
    $exists->execute([$identificacion]);
    $duplicado = (int)$exists->fetchColumn() > 0;
    $exists->closeCursor();
    if ($duplicado) {
        form_error('Ya existe un cliente con esa identificación.', 409);
    }

    if ($tipo === 'fisico') {
        $sql = "DECLARE @nuevo_id INT;
                EXEC dbo.sp_crear_cliente_fisico
                    @identificacion=?, @nombre=?, @primer_apellido=?, @segundo_apellido=?,
                    @correo=?, @telefono=?, @cliente_id=@nuevo_id OUTPUT;";
        $params = [$identificacion, $nombre, $apellido, $apellido2 !== '' ? $apellido2 : null, $correo, $telefono !== '' ? $telefono : null];
    } else {
        $sql = "DECLARE @nuevo_id INT;
                EXEC dbo.sp_crear_cliente_juridico
                    @identificacion=?, @razon_social=?, @nombre_comercial=?,
                    @representante_legal=?, @correo=?, @cliente_id=@nuevo_id OUTPUT;";
        $params = [$identificacion, $razon, $comercial !== '' ? $comercial : null, $representante !== '' ? $representante : null, $correo];
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $stmt->closeCursor();
    header('HX-Trigger-After-Settle: clienteCreado');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div class="notification is-success">Cliente registrado correctamente.</div>';
} catch (PDOException $ex) {
    error_log('TSSA: error al registrar cliente: ' . $ex->getMessage());
    $number = (int)($ex->errorInfo[1] ?? 0);
    if (in_array($number, [2601, 2627], true)) {
        form_error('La identificación ya está registrada.', 409);
    }
    form_error('No se pudo registrar el cliente. Verifica los datos y vuelve a intentarlo.', 500);
}
