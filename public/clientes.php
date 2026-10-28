<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_permission('CLIENTES_CREAR');
require_once __DIR__ . '/../app/layout.php';
layout_start('clientes', 'Clientes', 'Administra el registro de personas físicas y empresas.');
?>
<div class="panel-white clients-toolbar"><div><h2 class="title is-5 mb-1">Directorio de clientes</h2><p class="has-text-grey">Consulta y registra clientes utilizando los procedimientos almacenados.</p></div><div class="buttons"><button class="button is-primary" hx-get="/clientes/form.php?tipo=fisico" hx-target="#modal-contenido">+ Cliente físico</button><button class="button is-outline-brand" hx-get="/clientes/form.php?tipo=juridico" hx-target="#modal-contenido">+ Empresa</button></div></div>
<div class="panel-white"><div class="search-wrap"><label class="label" for="buscar">Buscar clientes</label><input class="input" id="buscar" type="search" name="buscar" placeholder="Nombre, identificación, correo o empresa..." autocomplete="off" hx-get="/clientes/lista.php" hx-trigger="keyup changed delay:350ms, search" hx-target="#clientes-resultado" hx-include="this" aria-controls="clientes-resultado"></div><div id="clientes-resultado" hx-get="/clientes/lista.php" hx-trigger="load, clienteCreado from:body" hx-include="#buscar" hx-swap="innerHTML"><p class="has-text-grey">Cargando clientes...</p></div></div>
<?php layout_end(); ?>
