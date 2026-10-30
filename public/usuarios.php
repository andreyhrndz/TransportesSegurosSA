<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('USUARIOS_VER');
require_once __DIR__ . '/../app/layout.php';
layout_start('usuarios', 'Usuarios y permisos', 'Consulta cuentas y asignaciones individuales sin revelar contraseñas ni hashes.');
?>
<section class="panel-white"><div class="panel-heading-custom"><div><h2 class="title is-5">Directorio de usuarios</h2><p>La creación, el cambio de estado y las modificaciones de permisos requieren contratos SQL aprobados.</p></div></div><form class="filter-grid" id="usuarios-filtros" hx-get="/usuarios/lista.php" hx-target="#usuarios-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this"><div class="field mb-0"><label class="label" for="usuarios-buscar">Buscar</label><div class="control has-icons-left"><input class="input" id="usuarios-buscar" type="search" name="buscar" maxlength="120" placeholder="Usuario o correo" autocomplete="off" aria-controls="usuarios-resultado"><span class="icon is-small is-left"><?= svg_icon('search') ?></span></div></div><div class="field mb-0"><label class="label" for="usuarios-activo">Estado</label><div class="control select is-fullwidth"><select id="usuarios-activo" name="activo"><option value="">Todos</option><option value="1">Activos</option><option value="0">Inactivos</option></select></div></div></form><div id="usuarios-resultado" hx-get="/usuarios/lista.php" hx-trigger="load" hx-include="#usuarios-filtros"><div class="is-skeleton" aria-label="Cargando usuarios"></div></div></section>
<?php layout_end(); ?>
