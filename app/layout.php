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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="/assets/css/estilos.css">
    <script src="/assets/js/htmx.min.js" defer></script>
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" aria-label="Navegación principal">
        <div class="brand"><span class="brand-icon">TS</span><div><strong>Transportes Seguros</strong><span>Administración logística</span></div></div>
        <div class="sidebar-label">PRINCIPAL</div>
        <nav class="menu-list">
            <?php if ($puedeReportes): ?>
                <a class="sidebar-link <?= $activo === 'dashboard' ? 'active' : '' ?>" href="/"><span aria-hidden="true">▦</span> Panel general</a>
            <?php endif; ?>
            <?php if ($puedeClientes): ?>
                <a class="sidebar-link <?= $activo === 'clientes' ? 'active' : '' ?>" href="/clientes.php"><span aria-hidden="true">◉</span> Clientes</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-label">PRÓXIMOS MÓDULOS</div>
        <div class="menu-list">
            <span class="sidebar-link muted">Solicitudes</span>
            <span class="sidebar-link muted">Viajes y rutas</span>
            <span class="sidebar-link muted">Entregas</span>
            <span class="sidebar-link muted">Reportes avanzados</span>
        </div>
        <div class="sidebar-bottom"><small>Proyecto universitario · Grupo 6</small><strong>Transportes Seguros S.A.</strong></div>
    </aside>
    <div class="main-area">
        <header class="topbar"><div class="mobile-brand">Transportes Seguros S.A.</div><div class="topbar-right"><span class="user-badge"><?= e($usuario['nombre_usuario']) ?></span><form action="/auth/logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="button is-small is-light" type="submit">Cerrar sesión</button></form></div></header>
        <main class="main-content">
            <div class="page-intro"><div><p class="eyebrow">SISTEMA DE GESTIÓN DE TRANSPORTES</p><h1 class="title is-3"><?= e($titulo) ?></h1><p class="page-subtitle"><?= e($descripcion) ?></p></div></div>
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
