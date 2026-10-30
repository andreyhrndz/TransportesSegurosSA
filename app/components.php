<?php
declare(strict_types=1);

/** Componentes de presentación sin estado compartidos por las vistas PHP. */
function svg_icon(string $name, string $label = ''): string
{
    $paths = [
        'brand' => '<path d="M7 4h10l3 4-8 12L4 8z"/><path d="M7 4l5 4 5-4M12 8v12"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'dashboard' => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
        'clients' => '<circle cx="9" cy="8" r="3"/><path d="M3.5 20c.7-3.1 2.6-5 5.5-5s4.8 1.9 5.5 5M16 8h4M18 6v4M16 16h4"/>',
        'requests' => '<path d="M6 3h9l3 3v15H6zM15 3v4h4M9 11h6M9 15h6M9 19h4"/>',
        'trips' => '<path d="M4 17h16M6 17V8h12v9M8 8V5h8v3M8 21h.01M16 21h.01M4 12h16"/>',
        'deliveries' => '<path d="M4 7h10v10H4zM14 10h3l3 3v4h-6zM7 20h.01M17 20h.01M14 14h6"/>',
        'fleet' => '<path d="M4 17h16M6 17V9h12v8M8 9V5h8v4M8 21h.01M16 21h.01"/>',
        'logout' => '<path d="M10 5H5v14h5M14 8l4 4-4 4M9 12h9"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="5.5"/><path d="m15 15 4 4"/>',
        'empty' => '<path d="M5 5h14v14H5zM8 9h8M8 13h5"/>',
    ];
    $path = $paths[$name] ?? $paths['empty'];
    $aria = $label === '' ? 'aria-hidden="true"' : 'role="img" aria-label="' . e($label) . '"';

    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" ' . $aria . '>' . $path . '</svg>';
}

function ui_notice(string $message, string $variant = 'info'): string
{
    $allowed = ['info', 'success', 'warning', 'danger'];
    $variant = in_array($variant, $allowed, true) ? $variant : 'info';
    return '<div class="notification notification-' . $variant . '" role="alert">' . e($message) . '</div>';
}

function empty_state(string $title, string $description): string
{
    return '<section class="empty-state" aria-live="polite">' . svg_icon('empty') . '<h2>' . e($title) . '</h2><p>' . e($description) . '</p></section>';
}

function format_utc_datetime(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Sin fecha';
    }

    try {
        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string)$value, new DateTimeZone('UTC'));

        return $date->setTimezone(new DateTimeZone('UTC'))->format('d/m/Y H:i') . ' UTC';
    } catch (Exception) {
        return 'Fecha no disponible';
    }
}

function status_tag(mixed $value): string
{
    $status = strtoupper(trim((string)$value));
    $tone = match ($status) {
        'ENTREGADA', 'FINALIZADO', 'RESUELTA', 'ENVIADA', 'DISPONIBLE', 'ACTIVO' => 'success',
        'CANCELADA', 'FALLIDA', 'ENTREGA_FALLIDA', 'ERROR', 'FUERA_SERVICIO', 'INACTIVO' => 'danger',
        'EN_TRANSITO', 'EN_CURSO', 'ASIGNADA', 'ASIGNADO', 'EN_ATENCION', 'URGENTE', 'ALTA' => 'warning',
        default => 'info',
    };

    return '<span class="status-tag status-tag-' . $tone . '">' . e(str_replace('_', ' ', $status)) . '</span>';
}
