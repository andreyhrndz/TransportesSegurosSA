<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_permission('CLIENTES_CREAR');
header('Cache-Control: no-store');
$buscar = trim((string)($_GET['buscar'] ?? ''));
$buscar = substr($buscar, 0, 120);
$pagina = filter_var($_GET['pagina'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range'=>1]]) ?: 1;
$porPagina = 10;

$from = ' FROM dbo.clientes c LEFT JOIN dbo.clientes_fisicos f ON f.cliente_id=c.cliente_id LEFT JOIN dbo.clientes_juridicos j ON j.cliente_id=c.cliente_id ';
$where = '';
$params = [];
if ($buscar !== '') {
    $where = ' WHERE c.identificacion LIKE ? OR c.correo LIKE ? OR f.nombre LIKE ? OR f.primer_apellido LIKE ? OR j.razon_social LIKE ? OR j.nombre_comercial LIKE ? ';
    $params = array_fill(0, 6, '%' . $buscar . '%');
}
$count = db()->prepare('SELECT COUNT(*)' . $from . $where);
$count->execute($params);
$total = (int)$count->fetchColumn();
$count->closeCursor();
$paginas = max(1, (int)ceil($total/$porPagina));
$pagina = min($pagina, $paginas);
$offset = ($pagina-1)*$porPagina;

$sql = "SELECT c.cliente_id, c.identificacion, c.correo, c.telefono, c.activo,
CASE WHEN f.cliente_id IS NOT NULL THEN 'Física' ELSE 'Jurídica' END AS tipo,
CASE WHEN f.cliente_id IS NOT NULL THEN CONCAT(f.nombre, ' ', f.primer_apellido, ' ', COALESCE(f.segundo_apellido, ''))
 ELSE COALESCE(j.nombre_comercial, j.razon_social, 'Sin razón social') END AS nombre
" . $from . $where . ' ORDER BY c.cliente_id DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
$stmt = db()->prepare($sql);
foreach ($params as $i=>$v) {
    $stmt->bindValue($i+1, $v, PDO::PARAM_STR);
}
$stmt->bindValue(count($params)+1, $offset, PDO::PARAM_INT);
$stmt->bindValue(count($params)+2, $porPagina, PDO::PARAM_INT);
$stmt->execute();
$clientes = $stmt->fetchAll();
$stmt->closeCursor();
?>
<div class="table-container"><table class="table is-fullwidth is-hoverable"><thead><tr><th>ID</th><th>Cliente</th><th>Identificación</th><th>Tipo</th><th>Correo</th><th>Estado</th></tr></thead><tbody>
<?php if (!$clientes): ?><tr><td colspan="6" class="has-text-centered has-text-grey py-6">No encontramos clientes con esa búsqueda.</td></tr><?php endif; ?>
<?php foreach ($clientes as $c): ?><tr><td><?= (int)$c['cliente_id'] ?></td><td><strong><?= e(trim((string)$c['nombre'])) ?></strong></td><td><?= e($c['identificacion']) ?></td><td><?= e($c['tipo']) ?></td><td><?= e($c['correo']) ?></td><td><span class="tag <?= (int)$c['activo']===1 ? 'is-success is-light' : 'is-light' ?>"><?= (int)$c['activo']===1 ? 'Activo' : 'Inactivo' ?></span></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="pagination-wrap"><span>Mostrando <?= $total ? $offset+1 : 0 ?>–<?= min($offset+$porPagina,$total) ?> de <?= $total ?> clientes</span><div class="buttons is-right" hx-target="#clientes-resultado" hx-include="#buscar" hx-swap="innerHTML"><button class="button is-small" <?= $pagina<=1 ? 'disabled' : '' ?> hx-get="/clientes/lista.php?pagina=<?= max(1,$pagina-1) ?>">Anterior</button><span class="pagination-info">Página <?= $pagina ?> de <?= $paginas ?></span><button class="button is-small" <?= $pagina >= $paginas ? 'disabled' : '' ?> hx-get="/clientes/lista.php?pagina=<?= min($paginas,$pagina+1) ?>">Siguiente</button></div></div>
