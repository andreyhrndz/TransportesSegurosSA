<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/audit_presenter.php';
require_permission('AUDITORIA_VER');
require_once __DIR__ . '/../app/layout.php';

$modulos = audit_module_options();
$acciones = audit_action_options();

layout_start(
    'auditoria',
    'Historial de actividad',
    'Registro cronológico de eventos relevantes ejecutados por usuarios y procesos del sistema.'
);
?>
<section class="toolbar-panel" aria-label="Filtros del historial de actividad">
    <form
        id="auditoria-filtros"
        class="filter-form"
        hx-get="/auditoria/lista.php"
        hx-target="#auditoria-lista"
        hx-trigger="submit, keyup changed delay:350ms from:#auditoria-usuario, change from:select, change from:input[data-datepicker]"
        hx-include="this"
        hx-indicator="#auditoria-cargando"
    >
        <div class="input-with-icon">
            <?= svg_icon('search', '', 'input-icon') ?>
            <input class="input" id="auditoria-usuario" name="usuario" type="search" maxlength="100" placeholder="Usuario (o «sistema»)" aria-label="Filtrar por usuario" autocomplete="off">
        </div>
        <div class="select">
            <select id="auditoria-modulo" name="entidad" data-combobox data-allow-clear="false" data-placeholder="Todos los módulos" aria-label="Filtrar por módulo">
                <option value="">Todos los módulos</option>
                <?php foreach ($modulos as $codigo => $etiqueta): ?>
                    <option value="<?= e($codigo) ?>"><?= e($etiqueta) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="select">
            <select id="auditoria-accion" name="accion" data-combobox data-allow-clear="false" data-placeholder="Todas las acciones" aria-label="Filtrar por tipo de acción">
                <option value="">Todas las acciones</option>
                <?php foreach ($acciones as $codigo => $etiqueta): ?>
                    <option value="<?= e($codigo) ?>"><?= e($etiqueta) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input class="input" id="auditoria-desde" name="desde" type="text" data-datepicker="date" placeholder="Desde (dd/mm/aaaa)" aria-label="Fecha desde">
        <input class="input" id="auditoria-hasta" name="hasta" type="text" data-datepicker="date" placeholder="Hasta (dd/mm/aaaa)" aria-label="Fecha hasta">
        <div class="buttons">
            <button class="button is-primary" type="submit">
                <?= svg_icon('filter') ?>
                <span>Filtrar</span>
            </button>
            <a class="button button-secondary" href="/auditoria.php">Limpiar</a>
        </div>
    </form>
</section>

<div id="auditoria-cargando" class="htmx-indicator mb-2">Consultando historial de actividad…</div>
<section class="panel-box table-panel">
    <div
        id="auditoria-lista"
        hx-get="/auditoria/lista.php"
        hx-trigger="load"
        hx-indicator="#auditoria-cargando"
        aria-live="polite"
    >
        <div class="p-4">Cargando eventos registrados…</div>
    </div>
</section>
<?php layout_end(); ?>
