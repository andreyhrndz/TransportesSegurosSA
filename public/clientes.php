<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_permission('CLIENTES_CREAR');
require_once __DIR__ . '/../app/layout.php';
layout_start('clientes', 'Clientes', 'Administra el registro de personas físicas y empresas.');
?>
<section class="panel-white clients-toolbar"><div><h2 class="title is-5 mb-1">Directorio de clientes</h2><p class="has-text-grey">Consulta y registra personas físicas y empresas.</p></div><div class="buttons"><button class="button is-primary" hx-get="/clientes/form.php?tipo=fisico" hx-target="#modal-contenido"><?= svg_icon('plus') ?><span>Nuevo cliente</span></button><button class="button button-secondary" hx-get="/clientes/form.php?tipo=juridico" hx-target="#modal-contenido"><?= svg_icon('plus') ?><span>Nueva empresa</span></button></div></section>
<section class="panel-white"><div class="search-wrap"><label class="label" for="buscar">Buscar clientes</label><div class="control has-icons-left"><input class="input" id="buscar" type="search" name="buscar" placeholder="Nombre, identificación, correo o empresa" autocomplete="off" hx-get="/clientes/lista.php" hx-trigger="keyup changed delay:350ms, search" hx-target="#clientes-resultado" hx-include="this" aria-controls="clientes-resultado"><span class="icon is-small is-left"><?= svg_icon('search') ?></span></div></div><div id="clientes-resultado" hx-get="/clientes/lista.php" hx-trigger="load, clienteCreado from:body" hx-include="#buscar" hx-swap="innerHTML"><div class="is-skeleton" aria-label="Cargando clientes"></div></div></section>
<?php layout_end(); ?>
