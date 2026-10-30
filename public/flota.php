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
    ],
    'conductores' => [
        'label' => 'Conductores',
        'subtitle' => 'Gestión de personal de conducción, vigencia de licencias y disponibilidad.',
        'read' => 'FLOTAS_VER',
        'write' => 'FLOTAS_GESTIONAR',
        'entity' => 'conductor',
        'new_label' => 'Nuevo conductor',
    ],
    'rutas' => [
        'label' => 'Rutas',
        'subtitle' => 'Tramos autorizados entre ubicaciones activas, distancia y duración estimada.',
        'read' => 'RUTAS_VER',
        'write' => 'RUTAS_GESTIONAR',
        'entity' => 'ruta',
        'new_label' => 'Nueva ruta',
    ],
    'ubicaciones' => [
        'label' => 'Ubicaciones',
        'subtitle' => 'Puntos logísticos de origen, destino y paradas con coordenadas opcionales.',
        'read' => 'UBICACIONES_VER',
        'write' => 'UBICACIONES_GESTIONAR',
        'entity' => 'ubicacion',
        'new_label' => 'Nueva ubicación',
    ],
    'catalogos' => [
        'label' => 'Catálogos',
        'subtitle' => 'Tipos de mercancía, tipos de incidencia y tipos de documento operativos.',
        'read' => 'CATALOGOS_VER',
        'write' => 'CATALOGOS_GESTIONAR',
        'entity' => '',
        'new_label' => '',
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
layout_start('flota', 'Flota y maestros', 'Administra vehículos, conductores, rutas, ubicaciones y catálogos respetando la disponibilidad operativa.');
?>
<section class="panel-white">
    <nav class="module-tabs" aria-label="Secciones de flota y maestros">
        <?php foreach ($sections as $key => $cfg): ?>
            <?php if (has_permission($cfg['read'])): ?>
                <a class="<?= $section === $key ? 'active' : '' ?>" href="/flota.php?seccion=<?= e($key) ?>"><?= e($cfg['label']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="panel-heading-custom">
        <div>
            <h2 class="title is-5"><?= e($activeConfig['label']) ?></h2>
            <p><?= e($activeConfig['subtitle']) ?></p>
        </div>
        <?php if ($canWrite && $activeConfig['entity'] !== ''): ?>
            <div class="buttons">
                <button class="button is-primary" type="button" hx-get="/flota/form.php?entidad=<?= urlencode($activeConfig['entity']) ?>" hx-target="#modal-contenido">
                    <?= svg_icon('plus') ?><span><?= e($activeConfig['new_label']) ?></span>
                </button>
            </div>
        <?php endif; ?>
    </div>

    <form class="filter-grid" id="flota-filtros" hx-get="/flota/lista.php" hx-target="#flota-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <input type="hidden" name="seccion" value="<?= e($section) ?>">
        <div class="field mb-0">
            <label class="label" for="flota-buscar">Buscar</label>
            <div class="control has-icons-left">
                <input class="input" id="flota-buscar" type="search" name="buscar" maxlength="120" placeholder="Filtrar <?= strtolower(e($activeConfig['label'])) ?>" autocomplete="off" aria-controls="flota-resultado">
                <span class="icon is-small is-left"><?= svg_icon('search') ?></span>
            </div>
        </div>

        <?php if (in_array($section, ['vehiculos', 'conductores'], true)): ?>
            <div class="field mb-0">
                <label class="label" for="flota-estado">Estado operativo</label>
                <div class="control select is-fullwidth">
                    <select id="flota-estado" name="estado">
                        <option value="">Todos</option>
                        <option value="DISPONIBLE">DISPONIBLE</option>
                        <option value="ASIGNADO">ASIGNADO</option>
                        <?php if ($section === 'vehiculos'): ?>
                            <option value="MANTENIMIENTO">MANTENIMIENTO</option>
                            <option value="FUERA_SERVICIO">FUERA_SERVICIO</option>
                        <?php else: ?>
                            <option value="INACTIVO">INACTIVO</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        <div class="field mb-0">
            <label class="label" for="flota-activo">Condición de registro</label>
            <div class="control select is-fullwidth">
                <select id="flota-activo" name="activo">
                    <option value="">Todos</option>
                    <option value="1">Activos</option>
                    <option value="0">Inactivos</option>
                </select>
            </div>
        </div>
    </form>

    <div id="flota-resultado" hx-get="/flota/lista.php?seccion=<?= e($section) ?>" hx-trigger="load, maestroActualizado from:body" hx-include="#flota-filtros" hx-swap="innerHTML">
        <div class="is-skeleton" aria-label="Cargando <?= strtolower(e($activeConfig['label'])) ?>"></div>
    </div>
</section>
<?php layout_end(); ?>
