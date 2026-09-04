<?php
$paginaActual = basename($_SERVER['PHP_SELF']);
$enModulos    = strpos($_SERVER['PHP_SELF'], '/modulos/') !== false;
$base         = $enModulos ? '../' : '';

$db = getDB();
$r = $db->query("SELECT COUNT(*) as t FROM citas WHERE fecha=CURDATE() AND estado!='cancelada'");
$_citasHoy  = $r->fetch_assoc()['t'];
$r = $db->query("SELECT COUNT(*) as t FROM facturas WHERE estado='pendiente'");
$_factPend  = $r->fetch_assoc()['t'];
$r = $db->query("SELECT COUNT(*) as t FROM sesiones WHERE estado_entrega!='entregado'");
$_sesPend   = $r->fetch_assoc()['t'];

$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'admin';

$idUsuario = (int)$_SESSION['usuario_id'];

$r = $db->query("
    SELECT foto_perfil
    FROM usuarios
    WHERE id = $idUsuario
    LIMIT 1
");

$fotoPerfil = null;

if ($r && $row = $r->fetch_assoc()) {
    $fotoPerfil = $row['foto_perfil'];
}

?>
<aside class="sidebar">
<div class="logo-area">
    <div class="logo-circle">
        <img src="<?= SITE_URL ?>/uploads/logo.jpg" alt="Logo">
    </div>
    <div class="logo-name">Lizdy Pineda</div>
    <div class="logo-sub">Fotoestudio</div>
</div>
  <nav>
    <div class="nav-section">Principal</div>
    <a class="nav-item <?= $paginaActual==='index.php'?'active':'' ?>" href="<?= $base ?>index.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg>
      Dashboard
    </a>
    <a class="nav-item <?= $paginaActual==='sesiones.php'?'active':'' ?>" href="<?= $base ?>modulos/sesiones.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
      Sesiones <?php if($_sesPend>0): ?><span class="badge teal"><?= $_sesPend ?></span><?php endif; ?>
    </a>
    <a class="nav-item <?= $paginaActual==='clientes.php'?'active':'' ?>" href="<?= $base ?>modulos/clientes.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      Clientes
    </a>
    <div class="nav-section">Negocio</div>
    <a class="nav-item <?= $paginaActual==='productos.php'?'active':'' ?>" href="<?= $base ?>modulos/productos.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
      Productos / Combos
    </a>
    <a class="nav-item <?= $paginaActual==='facturas.php'?'active':'' ?>" href="<?= $base ?>modulos/facturas.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Facturas <?php if($_factPend>0): ?><span class="badge"><?= $_factPend ?></span><?php endif; ?>
    </a>
    <a class="nav-item <?= $paginaActual==='ingresos.php'?'active':'' ?>" href="<?= $base ?>modulos/ingresos.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      Panel de Ingresos
    </a>
    <?php if($esAdmin): ?>
    <div class="nav-section">Administración</div>
    <a class="nav-item <?= $paginaActual==='usuarios.php'?'active':'' ?>" href="<?= $base ?>modulos/usuarios.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/><path d="M16 11l1.5 1.5L21 9"/></svg>
      Usuarios
    </a>
    <?php endif; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="avatar-sm">
    <?php if ($fotoPerfil): ?>
        <img src="<?= UPLOAD_URL . htmlspecialchars($fotoPerfil) ?>" alt="Perfil">
    <?php else: ?>
        <?= mb_strtoupper(mb_substr($_SESSION['usuario_nombre'],0,1)) ?>
    <?php endif; ?>
</div>
      <div>
        <div class="user-name"><?= htmlspecialchars($_SESSION['usuario_nombre']) ?></div>
        <div class="user-role"><?= $esAdmin ? 'Administrador' : 'Usuario' ?></div>
      </div>
    </div>
    <a href="<?= $base ?>logout.php" class="logout-btn">↩ Cerrar sesión</a>
  </div>
</aside>
