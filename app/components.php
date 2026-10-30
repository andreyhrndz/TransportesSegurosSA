<?php
declare(strict_types=1);

/** Componentes de presentación sin estado compartidos por las vistas PHP. */
function svg_icon(string $name, string $label = '', string $extraClass = ''): string
{
    $paths = [
        'dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'reports' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'clients' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'requests' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'trips' => '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
        'deliveries' => '<path d="m16 16 2 2 4-4"/><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/><path d="m7.5 4.27 9 5.15"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" x2="12" y1="22" y2="12"/>',
        'fleet' => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        'warehouse' => '<path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35A2 2 0 0 1 3.26 6.5l8-3.2a2 2 0 0 1 1.48 0l8 3.2A2 2 0 0 1 22 8.35Z"/><path d="M6 18h12"/><path d="M6 14h12"/><rect width="12" height="12" x="6" y="10"/>',
        'menu' => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'panel-left-close' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="m16 15-3-3 3-3"/>',
        'panel-left-open' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="m14 9 3 3-3 3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'edit' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'deactivate' => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
        'activate' => '<path d="M20 6 9 17l-5-5"/>',
        'key' => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'close' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'empty' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'arrow-up-right' => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'map-pin' => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'layers' => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
    ];
    $path = $paths[$name] ?? $paths['empty'];
    $aria = $label === '' ? 'aria-hidden="true"' : 'role="img" aria-label="' . e($label) . '"';
    $classAttr = trim('icon ' . $extraClass);

    return '<svg class="' . e($classAttr) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" ' . $aria . '>' . $path . '</svg>';
}

function ui_notice(string $message, string $variant = 'info'): string
{
    $allowed = ['info', 'success', 'warning', 'danger'];
    $variant = in_array($variant, $allowed, true) ? $variant : 'info';
    $icon = match ($variant) {
        'success' => 'activate',
        'warning', 'danger' => 'alert-triangle',
        default => 'shield',
    };
    return '<div class="notification notification-' . $variant . '" role="alert">' . svg_icon($icon) . '<span>' . e($message) . '</span></div>';
}

function empty_state(string $title, string $description): string
{
    return '<section class="empty-state" aria-live="polite"><div class="empty-state-icon">' . svg_icon('empty') . '</div><h2>' . e($title) . '</h2><p>' . e($description) . '</p></section>';
}

function modal_header(string $titleId, string $title): string
{
    return '<header class="modal-card-head"><p class="modal-card-title" id="' . e($titleId) . '">' . e($title) . '</p><button class="modal-close icon-button" type="button" aria-label="Cerrar" data-close-modal>' . svg_icon('close') . '</button></header>';
}

function modal_footer(string $submitLabel = 'Guardar', string $submitClass = 'is-primary'): string
{
    $allowedClasses = ['is-primary', 'is-danger', 'button-secondary'];
    $buttonClass = in_array($submitClass, $allowedClasses, true) ? $submitClass : 'is-primary';
    return '<footer class="modal-card-foot"><div class="buttons"><button class="button ' . $buttonClass . '" type="submit">' . e($submitLabel) . '</button><button class="button button-secondary" type="button" data-close-modal>Cancelar</button></div></footer>';
}

/**
 * Convierte marcas de tiempo almacenadas en UTC (SYSUTCDATETIME() en SQL Server)
 * a la zona horaria local de Costa Rica (America/Costa_Rica) con formato legible.
 */
function format_utc_datetime(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Sin fecha';
    }

    try {
        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string)$value, new DateTimeZone('UTC'));

        $crDate = $date->setTimezone(new DateTimeZone('America/Costa_Rica'));
        $meridiem = ((int)$crDate->format('G') < 12) ? 'a. m.' : 'p. m.';
        return $crDate->format('d/m/Y, g:i') . ' ' . $meridiem;
    } catch (Exception) {
        return 'Fecha no disponible';
    }
}

/**
 * Formatea valores de tipo DATE puro (YYYY-MM-DD) al formato costarricense dd/mm/aaaa
 * sin aplicar desplazamiento de huso horario.
 */
function format_cr_date(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Sin fecha';
    }

    if ($value instanceof DateTimeInterface) {
        return $value->format('d/m/Y');
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return 'Sin fecha';
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m) === 1) {
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    try {
        $date = new DateTimeImmutable($raw);
        return $date->format('d/m/Y');
    } catch (Exception) {
        return $raw;
    }
}

/**
 * Traduce códigos técnicos y enumeraciones con guiones bajos a etiquetas legibles en español.
 */
function humanize_status(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return 'Sin estado';
    }

    $upper = strtoupper($raw);
    $dictionary = [
        'REGISTRADA' => 'Registrada',
        'PENDIENTE_ASIGNACION' => 'Pendiente de asignación',
        'ASIGNADA' => 'Asignada',
        'ASIGNADO' => 'Asignado',
        'EN_TRANSITO' => 'En tránsito',
        'EN_CURSO' => 'En curso',
        'ENTREGADA' => 'Entregada',
        'ENTREGA_FALLIDA' => 'Entrega fallida',
        'FALLIDA' => 'Fallida',
        'CANCELADA' => 'Cancelada',
        'FINALIZADO' => 'Finalizado',
        'PLANIFICADO' => 'Planificado',
        'DISPONIBLE' => 'Disponible',
        'MANTENIMIENTO' => 'En mantenimiento',
        'FUERA_SERVICIO' => 'Fuera de servicio',
        'ACTIVO' => 'Activo',
        'INACTIVO' => 'Inactivo',
        'ABIERTA' => 'Abierta',
        'EN_ATENCION' => 'En atención',
        'RESUELTA' => 'Resuelta',
        'CERRADA' => 'Cerrada',
        'PENDIENTE' => 'Pendiente',
        'ENVIADA' => 'Enviada',
        'ERROR' => 'Con error',
        'BAJA' => 'Baja',
        'MEDIA' => 'Media',
        'ALTA' => 'Alta',
        'CRITICA' => 'Crítica',
        'NORMAL' => 'Normal',
        'URGENTE' => 'Urgente',
        'PROGRAMADA' => 'Programada',
        'SIN_ASIGNAR' => 'Sin asignar',
        'INSERCION' => 'Creación',
        'ACTUALIZACION' => 'Actualización',
        'ELIMINACION' => 'Eliminación',
        'CAMBIO_ESTADO' => 'Cambio de estado',
        'ASIGNACION' => 'Asignación',
        'DESASIGNACION' => 'Desasignación',
        'RETIRO' => 'Retiro',
        'SALIDA' => 'Salida',
        'LLEGADA' => 'Llegada',
        'PARADA' => 'Parada en ruta',
        'INCIDENCIA' => 'Incidencia',
        'ENTREGA' => 'Entrega',
        'RECOGIDA' => 'Recogida',
        'CARGA_PELIGROSA' => 'Carga peligrosa',
        'CARGA_GENERAL' => 'Carga general',
        'REFRIGERADA' => 'Refrigerada',
        'FRAGIL' => 'Frágil',
        'VALORES' => 'Valores custodiados',
        'FACTURA' => 'Factura comercial',
        'GUIA_REMISION' => 'Guía de remisión',
        'MANIFIESTO' => 'Manifiesto de carga',
        'COMPROBANTE_ENTREGA' => 'Comprobante de entrega',
        'DECLARACION_ADUANERA' => 'Declaración aduanera',
        'POLIZA_SEGURO' => 'Póliza de seguro',
        'RETRASO_RUTA' => 'Retraso en ruta',
        'AVERIA_MECANICA' => 'Avería mecánica',
        'DANIO_MERCANCIA' => 'Daño en mercancía',
        'CLIENTE_AUSENTE' => 'Cliente ausente',
        'DIRECCION_INCORRECTA' => 'Dirección incorrecta',
        'CIERRE_VIAL' => 'Cierre vial',
        'CORREO' => 'Correo electrónico',
        'SMS' => 'Mensaje SMS',
        'SISTEMA' => 'Aviso del sistema',
        'WHATSAPP' => 'WhatsApp',
    ];

    if (isset($dictionary[$upper])) {
        return $dictionary[$upper];
    }

    $cleaned = str_replace('_', ' ', strtolower($raw));
    return ucfirst($cleaned);
}

function status_tag(mixed $value): string
{
    $status = strtoupper(trim((string)$value));
    $tone = match ($status) {
        'ENTREGADA', 'FINALIZADO', 'RESUELTA', 'ENVIADA', 'DISPONIBLE', 'ACTIVO' => 'success',
        'CANCELADA', 'FALLIDA', 'ENTREGA_FALLIDA', 'ERROR', 'FUERA_SERVICIO', 'INACTIVO', 'CRITICA' => 'danger',
        'EN_TRANSITO', 'EN_CURSO', 'ASIGNADA', 'ASIGNADO', 'EN_ATENCION', 'MANTENIMIENTO', 'URGENTE', 'ALTA', 'PENDIENTE_ASIGNACION', 'ABIERTA' => 'warning',
        default => 'info',
    };

    return '<span class="status-tag status-tag-' . $tone . '">' . e(humanize_status($value)) . '</span>';
}

function user_initials(string $username): string
{
    $clean = trim($username);
    if ($clean === '') {
        return 'TS';
    }
    $parts = preg_split('/[\s._-]+/', $clean) ?: [];
    $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
    if (count($parts) >= 2) {
        $chars0 = preg_split('//u', $parts[0], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chars1 = preg_split('//u', $parts[1], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return strtoupper(($chars0[0] ?? '') . ($chars1[0] ?? ''));
    }
    $chars = preg_split('//u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return strtoupper(($chars[0] ?? '') . ($chars[1] ?? ''));
}

function normalize_spanish_text(mixed $value): string
{
    $text = (string)$value;
    return strtr($text, [
        'Ã¡' => 'á',
        'Ã©' => 'é',
        'Ã­' => 'í',
        'Ã³' => 'ó',
        'Ãº' => 'ú',
        'Ã±' => 'ñ',
        'Ãa' => 'ía',
    ]);
}

