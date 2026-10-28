<?php
declare(strict_types=1);

function layout_start(string $activo, string $titulo, string $descripcion): void
{
    $usuario = require_login();
    $puedeClientes = has_permission('CLIENTES_CREAR');
    $puedeReportes = has_permission('REPORTES_VER');
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($titulo) ?> — Transportes Seguros S.A.</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <!-- Recurso externo limitado por CSP; producción debe fijar SRI o alojar el CSS aprobado. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="/assets/css/estilos.css">
    <script src="/assets/js/htmx.min.js" defer></script>
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Navegación principal">
        <div class="brand"><span class="brand-icon" aria-hidden="true">TS</span><div><strong>Transportes Seguros</strong><span>Administración logística</span></div></div>
        <nav class="menu-list">
            <?php if ($puedeReportes): ?>
                <a class="sidebar-link <?= $activo === 'dashboard' ? 'active' : '' ?>" href="/"><?= svg_icon('dashboard') ?><span>Panel general</span></a>
            <?php endif; ?>
            <?php if ($puedeClientes): ?>
                <a class="sidebar-link <?= $activo === 'clientes' ? 'active' : '' ?>" href="/clientes.php"><?= svg_icon('clients') ?><span>Clientes</span></a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-bottom"><strong>Transportes Seguros S.A.</strong></div>
    </aside>
    <div class="main-area">
        <header class="topbar">
            <button class="icon-button sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="false" data-sidebar-toggle><?= svg_icon('menu', 'Abrir navegación') ?></button>
            <div class="mobile-brand">Transportes Seguros S.A.</div>
            <div class="topbar-right"><span class="user-badge"><?= e($usuario['nombre_usuario']) ?></span><form action="/auth/logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="button button-secondary is-small" type="submit"><?= svg_icon('logout') ?><span>Cerrar sesión</span></button></form></div>
        </header>
        <main class="main-content">
            <div class="page-intro"><div><h1 class="title is-3"><?= e($titulo) ?></h1><p class="page-subtitle"><?= e($descripcion) ?></p></div></div>
<?php
}

function layout_end(): void
{
?>
        </main>
    </div>
</div>
<div id="modal-contenido" aria-live="polite"></div>
<div id="aviso-temporal" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
<?php
}
