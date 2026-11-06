<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/read_queries.php';

$sections = [
    'vehiculos' => [
        'label' => 'Vehículos',
        'subtitle' => 'Administración de unidades, capacidad de carga, odómetro y estado operativo.',
        'read' => 'FLOTAS_VER',
        'write' => 'FLOTAS_GESTIONAR',
        'entity' => 'vehiculo',
        'new_label' => 'Nuevo vehículo',
        'icon' => 'fleet',
    ],
    'conductores' => [
        'label' => 'Conductores',
        'subtitle' => 'Gestión de personal de conducción, vigencia de licencias y disponibilidad.',
        'read' => 'FLOTAS_VER',
        'write' => 'FLOTAS_GESTIONAR',
        'entity' => 'conductor',
        'new_label' => 'Nuevo conductor',
        'icon' => 'user',
    ],
    'rutas' => [
        'label' => 'Rutas',
        'subtitle' => 'Tramos autorizados entre ubicaciones activas, distancia y duración estimada.',
        'read' => 'RUTAS_VER',
        'write' => 'RUTAS_GESTIONAR',
        'entity' => 'ruta',
        'new_label' => 'Nueva ruta',
        'icon' => 'trips',
    ],
    'ubicaciones' => [
        'label' => 'Ubicaciones',
        'subtitle' => 'Puntos logísticos de origen, destino y paradas con coordenadas opcionales.',
        'read' => 'UBICACIONES_VER',
        'write' => 'UBICACIONES_GESTIONAR',
        'entity' => 'ubicacion',
        'new_label' => 'Nueva ubicación',
        'icon' => 'map-pin',
    ],
    'catalogos' => [
        'label' => 'Catálogos',
        'subtitle' => 'Tipos de mercancía, tipos de incidencia y tipos de documento operativos.',
        'read' => 'CATALOGOS_VER',
        'write' => 'CATALOGOS_GESTIONAR',
        'entity' => '',
        'new_label' => '',
        'icon' => 'layers',
    ],
];

require_any_permission(['FLOTAS_VER', 'RUTAS_VER', 'UBICACIONES_VER', 'CATALOGOS_VER']);

$requestedSection = $_GET['seccion'] ?? null;
if ($requestedSection === null || $requestedSection === '') {
    $section = 'vehiculos';
    foreach ($sections as $key => $cfg) {
        if (has_permission($cfg['read'])) {
            $section = $key;
            break;
        }
    }
} else {
    $section = (string)$requestedSection;
    if (!isset($sections[$section])) {
        http_response_code(404);
        exit('Sección no encontrada.');
    }
}

$activeConfig = $sections[$section];
require_permission($activeConfig['read']);
$canWrite = has_permission($activeConfig['write']);

require_once __DIR__ . '/../app/layout.php';
layout_start('flota', 'Flota y catálogos', 'Administra vehículos, conductores, rutas, ubicaciones y catálogos respetando la disponibilidad operativa.');
?>
<nav class="tabs-bar" aria-label="Secciones de flota y catálogos">
    <?php foreach ($sections as $key => $cfg): ?>
        <?php if (has_permission($cfg['read'])): ?>
            <a class="tab-chip <?= $section === $key ? 'active' : '' ?>" href="/flota.php?seccion=<?= e($key) ?>">
                <?= svg_icon($cfg['icon']) ?>
                <span><?= e($cfg['label']) ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<section class="toolbar-panel" aria-label="Filtros y acciones de <?= e(utf8_strtolower($activeConfig['label'])) ?>">
    <form class="filter-form" id="flota-filtros" hx-get="/flota/lista.php" hx-target="#flota-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <input type="hidden" name="seccion" value="<?= e($section) ?>">
        <div class="input-with-icon">
            <?= svg_icon('search', '', 'input-icon') ?>
            <input class="input" id="flota-buscar" type="search" name="buscar" maxlength="120" placeholder="Filtrar <?= e(utf8_strtolower($activeConfig['label'])) ?>…" autocomplete="off" aria-label="Buscar en <?= e($activeConfig['label']) ?>" aria-controls="flota-resultado">
        </div>

        <?php if (in_array($section, ['vehiculos', 'conductores'], true)): ?>
            <div class="select">
                <select id="flota-estado" name="estado" data-combobox data-allow-clear="false" data-placeholder="Todos los estados operativos" aria-label="Estado operativo">
                    <option value="">Todos los estados operativos</option>
                    <option value="DISPONIBLE">Disponible</option>
                    <option value="ASIGNADO">Asignado</option>
                    <?php if ($section === 'vehiculos'): ?>
                        <option value="MANTENIMIENTO">En mantenimiento</option>
                        <option value="FUERA_SERVICIO">Fuera de servicio</option>
                    <?php else: ?>
                        <option value="INACTIVO">Inactivo</option>
                    <?php endif; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="select">
            <select id="flota-activo" name="activo" data-combobox data-allow-clear="false" data-placeholder="Todos los registros" aria-label="Condición de registro">
                <option value="">Todos los registros</option>
                <option value="1">Activos</option>
                <option value="0">Inactivos</option>
            </select>
        </div>
    </form>

    <?php if ($canWrite && $activeConfig['entity'] !== ''): ?>
        <div class="buttons">
            <button class="button is-primary" type="button" hx-get="/flota/form.php?entidad=<?= urlencode($activeConfig['entity']) ?>" hx-target="#modal-contenido">
                <?= svg_icon('plus') ?>
                <span><?= e($activeConfig['new_label']) ?></span>
            </button>
        </div>
    <?php endif; ?>
</section>

<section class="<?= $section === 'catalogos' ? '' : 'panel-box table-panel' ?>">
    <?php if ($section !== 'catalogos'): ?>
        <div class="panel-box-header">
            <div>
                <h2 class="panel-box-title"><?= e($activeConfig['label']) ?></h2>
                <p class="panel-box-subtitle"><?= e($activeConfig['subtitle']) ?></p>
            </div>
        </div>
    <?php endif; ?>
    <div id="flota-resultado" hx-get="/flota/lista.php?seccion=<?= e($section) ?>" hx-trigger="load, maestroActualizado from:body" hx-include="#flota-filtros" hx-swap="innerHTML"></div>
</section>
<?php layout_end(); ?>
