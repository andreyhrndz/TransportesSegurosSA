<?php
declare(strict_types=1);

/**
 * Presentación humana y segura de eventos de dbo.auditorias.
 * Traduce entidades y acciones reales sin inventar datos inexistentes y redacta
 * cualquier patrón potencialmente sensible antes de mostrar detalles técnicos.
 */

/** @return array<string, string> */
function audit_module_options(): array
{
    return [
        'solicitudes' => 'Solicitudes de transporte',
        'viajes' => 'Viajes y despachos',
        'paradas_viaje' => 'Paradas de viaje',
        'viaje_solicitudes' => 'Asignación de solicitudes a viaje',
        'entregas' => 'Entregas',
        'incidencias' => 'Incidencias',
        'clientes' => 'Clientes',
        'vehiculos' => 'Vehículos',
        'conductores' => 'Conductores',
        'conductores_licencias' => 'Licencias de conductores',
        'ubicaciones' => 'Ubicaciones',
        'rutas' => 'Rutas',
        'usuarios' => 'Usuarios',
        'usuarios_permisos' => 'Permisos de usuario',
        'invitaciones_usuario' => 'Invitaciones de acceso',
        'invitaciones_permisos' => 'Permisos de invitación',
        'tipos_mercancia' => 'Tipos de mercancía',
        'tipos_incidencia' => 'Tipos de incidencia',
        'tipos_documento' => 'Tipos de documento',
        'respaldos' => 'Respaldos y recuperación',
    ];
}

/** @return array<string, string> */
function audit_action_options(): array
{
    return [
        'INSERT' => 'Creación',
        'UPDATE' => 'Actualización',
        'REORDER' => 'Reordenamiento',
        'DELETE' => 'Eliminación',
        'DEACTIVATE' => 'Desactivación',
        'INVITE' => 'Invitación enviada',
        'RESEND' => 'Reenvío de invitación',
        'REVOKE' => 'Revocación de invitación',
        'ONBOARDING' => 'Activación de cuenta',
        'PASSWORD_RESET' => 'Restablecimiento de contraseña',
        'ACTUALIZAR_PRINCIPAL' => 'Actualización de licencia',
        'UPSERT' => 'Actualización de registro',
        'BACKUP_FULL' => 'Respaldo FULL verificado',
        'BACKUP_DIFF' => 'Respaldo Diferencial verificado',
        'BACKUP_LOG' => 'Respaldo Transaction Log (.trn) verificado',
        'ETL_SYNC' => 'Sincronización ETL Data Warehouse',
    ];
}

function audit_module_label(string $entity): string
{
    $key = strtolower(trim($entity));
    $map = audit_module_options();
    if (isset($map[$key])) {
        return $map[$key];
    }
    return humanize_status($entity);
}

function audit_action_label(string $action): string
{
    $key = strtoupper(trim($action));
    $map = audit_action_options();
    if (isset($map[$key])) {
        return $map[$key];
    }
    return humanize_status($action);
}

function audit_redact_detail(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $clean = trim($raw);
    if ($clean === '') {
        return null;
    }

    // Redactar hashes bcrypt/argon, tokens hexadecimales largos y campos etiquetados como secretos.
    $clean = preg_replace('/\$2[aby]\$\d{2}\$[A-Za-z0-9.\/]{20,}/', '[redactado]', $clean) ?? $clean;
    $clean = preg_replace('/\b[0-9a-f]{32,}\b/i', '[token redactado]', $clean) ?? $clean;
    $clean = preg_replace('/(password_hash|password|contrasena|token|secret)\s*[:=]\s*[^\s,;}\]]+/i', '$1=[redactado]', $clean) ?? $clean;

    if (mb_strlen($clean) > 360) {
        $clean = mb_substr($clean, 0, 357) . '…';
    }
    return $clean;
}

/**
 * Construye el resumen humano fiel del evento y su detalle técnico redactado.
 * @param array<string, mixed> $row
 * @return array{
 *   modulo: string,
 *   accion_etiqueta: string,
 *   accion_tono: string,
 *   descripcion: string,
 *   referencia: string,
 *   usuario_nombre: string,
 *   usuario_nota: string,
 *   anterior: ?string,
 *   nuevo: ?string
 * }
 */
function audit_present_row(array $row): array
{
    $entidad = strtolower(trim((string)($row['entidad'] ?? '')));
    $accion = strtoupper(trim((string)($row['accion'] ?? '')));
    $registroId = trim((string)($row['registro_id'] ?? ''));
    $modulo = audit_module_label($entidad);
    $accionEtiqueta = audit_action_label($accion);

    $phrases = [
        'paradas_viaje:REORDER' => 'Reordenó las paradas del itinerario de un viaje',
        'paradas_viaje:UPDATE' => 'Editó la ubicación o el tipo de una parada del viaje',
        'paradas_viaje:INSERT' => 'Agregó una parada al itinerario de un viaje',
        'solicitudes:INSERT' => 'Registró una solicitud de transporte',
        'solicitudes:UPDATE' => 'Actualizó el estado o los datos de una solicitud',
        'solicitudes:DELETE' => 'Eliminó una solicitud de transporte',
        'viajes:INSERT' => 'Programó un nuevo viaje',
        'viajes:UPDATE' => 'Actualizó el estado o los detalles de un viaje',
        'viajes:DELETE' => 'Eliminó un registro de viaje',
        'viaje_solicitudes:INSERT' => 'Asignó una solicitud a un viaje planificado',
        'viaje_solicitudes:UPDATE' => 'Actualizó la asignación de una solicitud en viaje',
        'viaje_solicitudes:DELETE' => 'Desvinculó una solicitud de un viaje',
        'entregas:INSERT' => 'Registró el resultado de una entrega',
        'entregas:UPDATE' => 'Actualizó el registro de una entrega',
        'clientes:INSERT' => 'Registró un nuevo cliente',
        'clientes:UPDATE' => 'Actualizó la información de un cliente',
        'clientes:DEACTIVATE' => 'Desactivó un cliente',
        'vehiculos:INSERT' => 'Registró un vehículo en la flota',
        'vehiculos:UPDATE' => 'Actualizó los datos de un vehículo',
        'conductores:INSERT' => 'Registró un conductor en la flota',
        'conductores:UPDATE' => 'Actualizó los datos de un conductor',
        'conductores_licencias:INSERT' => 'Registró una licencia de conductor',
        'conductores_licencias:UPDATE' => 'Actualizó una licencia de conductor',
        'conductores_licencias:UPSERT' => 'Actualizó la licencia habilitada de un conductor',
        'conductores_licencias:ACTUALIZAR_PRINCIPAL' => 'Actualizó la licencia principal de un conductor',
        'ubicaciones:INSERT' => 'Registró una ubicación',
        'ubicaciones:UPDATE' => 'Actualizó una ubicación',
        'rutas:INSERT' => 'Registró una ruta operativa',
        'rutas:UPDATE' => 'Actualizó una ruta operativa',
        'usuarios:INSERT' => 'Creó una cuenta de usuario',
        'usuarios:UPDATE' => 'Actualizó la configuración de un usuario',
        'usuarios:DEACTIVATE' => 'Desactivó una cuenta de usuario',
        'usuarios:PASSWORD_RESET' => 'Restableció la contraseña de un usuario',
        'usuarios_permisos:INSERT' => 'Asignó un permiso a un usuario',
        'usuarios_permisos:UPDATE' => 'Modificó permisos de un usuario',
        'usuarios_permisos:DELETE' => 'Retiró un permiso a un usuario',
        'invitaciones_usuario:INVITE' => 'Envió una invitación de acceso a un usuario',
        'invitaciones_usuario:RESEND' => 'Reenvió una invitación de acceso',
        'invitaciones_usuario:REVOKE' => 'Revocó una invitación de acceso',
        'invitaciones_usuario:ONBOARDING' => 'Activó una cuenta de usuario mediante invitación',
        'invitaciones_permisos:INSERT' => 'Definió permisos iniciales para una invitación',
        'invitaciones_permisos:DELETE' => 'Ajustó permisos de una invitación',
        'tipos_mercancia:INSERT' => 'Agregó un tipo de mercancía al catálogo',
        'tipos_mercancia:UPDATE' => 'Actualizó un tipo de mercancía del catálogo',
        'tipos_incidencia:INSERT' => 'Agregó un tipo de incidencia al catálogo',
        'tipos_incidencia:UPDATE' => 'Actualizó un tipo de incidencia del catálogo',
        'tipos_documento:INSERT' => 'Agregó un tipo de documento al catálogo',
        'tipos_documento:UPDATE' => 'Actualizó un tipo de documento del catálogo',
    ];

    $pair = $entidad . ':' . $accion;
    if (isset($phrases[$pair])) {
        $descripcion = $phrases[$pair];
    } else {
        $descripcion = $accionEtiqueta . ' en ' . utf8_strtolower($modulo);
    }

    $referencia = $registroId !== '' ? 'Ref. #' . $registroId : 'Sin referencia';

    $usuarioRaw = trim((string)($row['usuario'] ?? ''));
    $usuarioId = $row['usuario_id'] ?? null;
    if ($usuarioRaw !== '') {
        $usuarioNombre = $usuarioRaw;
        $activo = $row['usuario_activo'] ?? null;
        $usuarioNota = ($activo !== null && (int)$activo === 0) ? 'Cuenta inactiva' : '';
    } elseif ($usuarioId !== null && $usuarioId !== '') {
        $usuarioNombre = 'Usuario #' . (int)$usuarioId;
        $usuarioNota = 'Cuenta no disponible';
    } else {
        $usuarioNombre = 'Sistema';
        $usuarioNota = 'Proceso automático';
    }

    $tono = match ($accion) {
        'INSERT', 'INVITE', 'ONBOARDING' => 'success',
        'DELETE', 'DEACTIVATE', 'REVOKE' => 'danger',
        'REORDER', 'RESEND', 'PASSWORD_RESET' => 'warning',
        default => 'info',
    };

    return [
        'modulo' => $modulo,
        'accion_etiqueta' => $accionEtiqueta,
        'accion_tono' => $tono,
        'descripcion' => $descripcion,
        'referencia' => $referencia,
        'usuario_nombre' => $usuarioNombre,
        'usuario_nota' => $usuarioNota,
        'anterior' => audit_redact_detail(isset($row['valores_anteriores']) ? (string)$row['valores_anteriores'] : null),
        'nuevo' => audit_redact_detail(isset($row['valores_nuevos']) ? (string)$row['valores_nuevos'] : null),
    ];
}
