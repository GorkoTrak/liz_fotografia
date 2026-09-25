<?php
require_once '../config.php';
requerirLogin();

$db = getDB();
$mensaje = '';
$error   = '';

// ============================================
// ACCIONES POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $id          = sanitizeInt($_POST['id'] ?? 0);
        $nombre      = sanitize($_POST['nombre'] ?? '');
        $descripcion = sanitize($_POST['descripcion'] ?? '');
        $precio      = floatval($_POST['precio'] ?? 0);
        $tipo        = sanitize($_POST['tipo'] ?? 'sesion');
        $categoria  = sanitize($_POST['categoria'] ?? '');
        $estado      = sanitize($_POST['estado'] ?? 'activo');
        if (!in_array($tipo, ['sesion','combo','adicional'], true)) $tipo = 'adicional';

        // Las sesiones y los adicionales deben pertenecer a una categoría.
        if (empty($nombre) || $precio <= 0) {
            $error = 'El nombre y precio son obligatorios.';
        } elseif (in_array($tipo, ['sesion','adicional'], true) && $categoria === '') {
            $error = 'Debes asignar una categoría a la sesión o adicional.';
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE productos SET nombre=?, descripcion=?, precio=?, tipo=?, categoria=?, estado=? WHERE id=?");
                $stmt->bind_param("ssdsssi", $nombre, $descripcion, $precio, $tipo, $categoria, $estado, $id);
                $mensaje = 'Elemento actualizado correctamente.';
            } else {
                $stmt = $db->prepare("INSERT INTO productos (nombre, descripcion, precio, tipo, categoria, estado) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param("ssdsss", $nombre, $descripcion, $precio, $tipo, $categoria, $estado);
                $mensaje = 'Elemento creado correctamente.';
            }
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($accion === 'eliminar') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM productos WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $mensaje = 'Elemento eliminado.';
        }
    }

    if ($accion === 'toggle_estado') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE productos SET estado = IF(estado='activo','inactivo','activo') WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $mensaje = 'Estado actualizado.';
        }
    }
}

// ============================================
// FILTROS Y LISTADO
// ============================================
$filtroTipo   = sanitize($_GET['tipo'] ?? '');
$filtroEstado = sanitize($_GET['estado'] ?? 'activo');
$filtroCategoria = sanitize($_GET['categoria'] ?? '');

$where  = "WHERE 1=1";
$params = [];
$tipos  = '';

if ($filtroTipo) {
    $where   .= " AND tipo = ?";
    $params[] = $filtroTipo;
    $tipos   .= 's';
}
if ($filtroEstado) {
    $where   .= " AND estado = ?";
    $params[] = $filtroEstado;
    $tipos   .= 's';
}
if ($filtroCategoria) {
    $where   .= " AND categoria = ?";
    $params[] = $filtroCategoria;
    $tipos   .= 's';
}

$sqlProductos = "SELECT * FROM productos $where ORDER BY tipo ASC, nombre ASC";
$stmtP = $db->prepare($sqlProductos);
if ($params) $stmtP->bind_param($tipos, ...$params);
$stmtP->execute();
$productos = $stmtP->get_result();
$stmtP->close();

// Stats por tipo
$r = $db->query("SELECT tipo, COUNT(*) as total, AVG(precio) as promedio, MIN(precio) as minimo, MAX(precio) as maximo FROM productos WHERE estado='activo' GROUP BY tipo");
$statsPorTipo = [];
while ($row = $r->fetch_assoc()) $statsPorTipo[$row['tipo']] = $row;

$totalActivos  = $db->query("SELECT COUNT(*) as t FROM productos WHERE estado='activo'")->fetch_assoc()['t'];
$totalProductos = $db->query("SELECT COUNT(*) as t FROM productos")->fetch_assoc()['t'];
$precioPromedio = $db->query("SELECT AVG(precio) as t FROM productos WHERE estado='activo'")->fetch_assoc()['t'];

// Categorías existentes, separadas por tipo para sugerirlas al crear/editar.
// Cada tipo mantiene sus propias categorías: Sesiones, Combos y Adicionales no se mezclan.
$categoriasPorTipo = ['sesion' => [], 'combo' => [], 'adicional' => []];
$rCategorias = $db->query("SELECT tipo, categoria FROM productos WHERE categoria IS NOT NULL AND TRIM(categoria) <> '' GROUP BY tipo, categoria ORDER BY categoria ASC");
while ($cat = $rCategorias->fetch_assoc()) {
    if (isset($categoriasPorTipo[$cat['tipo']])) {
        $categoriasPorTipo[$cat['tipo']][] = $cat['categoria'];
    }
}

// Categorías del filtro. Se obtienen según el apartado que realmente se está viendo.
// Así, por ejemplo, en ADICIONALES solo aparecen categorías de productos tipo adicional.
$categoriasFiltro = [];
$whereCategorias = [];
$paramsCategorias = [];
$tiposCategorias = "";

if ($filtroTipo) {
    $whereCategorias[] = "tipo = ?";
    $paramsCategorias[] = $filtroTipo;
    $tiposCategorias .= "s";
}
if ($filtroEstado) {
    $whereCategorias[] = "estado = ?";
    $paramsCategorias[] = $filtroEstado;
    $tiposCategorias .= "s";
}

$sqlCategoriasFiltro = "SELECT DISTINCT categoria FROM productos WHERE categoria IS NOT NULL AND TRIM(categoria) <> ''";
if ($whereCategorias) {
    $sqlCategoriasFiltro .= " AND " . implode(" AND ", $whereCategorias);
}
$sqlCategoriasFiltro .= " ORDER BY categoria ASC";

$stmtCategorias = $db->prepare($sqlCategoriasFiltro);
if ($paramsCategorias) {
    $stmtCategorias->bind_param($tiposCategorias, ...$paramsCategorias);
}
$stmtCategorias->execute();
$rCategoriasFiltro = $stmtCategorias->get_result();
while ($cat = $rCategoriasFiltro->fetch_assoc()) {
    $categoriasFiltro[] = $cat['categoria'];
}
$stmtCategorias->close();

$tiposLabel = ['sesion' => 'Sesión', 'combo' => 'Combo', 'adicional' => 'Adicional'];
$tiposColor = ['sesion' => 'rose', 'combo' => 'teal', 'adicional' => 'orange'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Catálogo – <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;600;700&family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#faf8f6;--surface:#fff;--surface2:#fdf5f8;--navy:#1a1f3c;--navy-mid:#2d3460;--navy-soft:#e8eaf6;--rose:#e8789a;--rose-light:#f5b8ce;--rose-pale:#fce8f0;--rose-deep:#c4547a;--teal:#5bbcb8;--teal-light:#9ddbd8;--teal-pale:#e0f5f4;--teal-deep:#3a9994;--lavender:#9e8bc9;--lav-light:#c5b8e8;--lav-pale:#f0ecfb;--text:#2a2040;--text-mid:#6b5e7a;--text-dim:#a899b5;--border:#ede0ea;--sidebar-w:230px;--shadow-sm:0 2px 8px rgba(180,120,160,0.10);--shadow-md:0 4px 20px rgba(180,120,160,0.15);}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;font-size:13px;}

  /* SIDEBAR */
  .sidebar{width:var(--sidebar-w);background:var(--navy);display:flex;flex-direction:column;flex-shrink:0;position:relative;overflow:hidden;}
  .sidebar::before{content:'';position:absolute;width:180px;height:180px;border-radius:50%;background:radial-gradient(circle,rgba(232,120,154,0.18) 0%,transparent 70%);top:-40px;right:-50px;pointer-events:none;}
  .sidebar::after{content:'';position:absolute;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle,rgba(91,188,184,0.15) 0%,transparent 70%);bottom:60px;left:-30px;pointer-events:none;}
  .logo-area{padding:24px 20px 20px;display:flex;flex-direction:column;align-items:center;border-bottom:1px solid rgba(255,255,255,.08);position:relative;z-index:1;}
  .logo-circle{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:34px;color:var(--navy);font-weight:700;margin-bottom:10px;box-shadow:0 4px 16px rgba(232,120,154,.35);overflow:hidden;}
  .logo-circle img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
  .logo-name{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--rose-light);text-align:center;line-height:1.15;}
  .logo-sub{font-size:9px;color:var(--teal-light);letter-spacing:0.22em;text-transform:uppercase;margin-top:3px;font-weight:600;}
  nav{padding:14px 0;flex:1;position:relative;z-index:1;overflow-y:auto;}
  .nav-section{padding:10px 20px 5px;font-size:9px;letter-spacing:0.18em;color:rgba(255,255,255,0.3);text-transform:uppercase;font-weight:600;}
  .nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,0.55);cursor:pointer;transition:all 0.2s;border-left:3px solid transparent;font-size:12.5px;font-weight:600;text-decoration:none;}
  .nav-item:hover{color:rgba(255,255,255,0.9);background:rgba(255,255,255,0.05);}
  .nav-item.active{color:#fff;border-left-color:var(--rose-light);background:rgba(232,120,154,0.15);}
  .nav-item svg{width:16px;height:16px;flex-shrink:0;}
  .sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,0.08);position:relative;z-index:1;}
  .user-chip{display:flex;align-items:center;gap:10px;}
  .badge{margin-left:auto;background:var(--rose);color:#fff;border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;}
  .badge.teal{background:var(--teal-deep);}
  .avatar-sm{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:18px;color:var(--navy);font-weight:700;flex-shrink:0;overflow:hidden;}
  .avatar-sm img{width:100%;height:100%;object-fit:cover;}
  .user-name{font-size:12px;color:#fff;font-weight:600;}
  .user-role{font-size:10px;color:var(--teal-light);}
  .logout-btn{display:block;margin-top:10px;font-size:11px;color:rgba(255,255,255,0.35);text-decoration:none;transition:color 0.2s;}
  .logout-btn:hover{color:var(--rose-light);}

  /* MAIN */
  .main{flex:1;display:flex;flex-direction:column;overflow:hidden;}
  .topbar{height:58px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 26px;gap:14px;flex-shrink:0;box-shadow:var(--shadow-sm);}
  .page-title{font-family:'Dancing Script',cursive;font-size:26px;font-weight:700;color:var(--navy);}
  .topbar-sep{flex:1;}

  .btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:20px;font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:700;cursor:pointer;border:none;transition:all 0.2s;text-decoration:none;}
  .btn-primary{background:linear-gradient(135deg,var(--rose),var(--rose-deep));color:#fff;box-shadow:0 4px 14px rgba(196,84,122,0.3);}
  .btn-primary:hover{transform:translateY(-1px);}
  .btn-ghost{background:transparent;color:var(--text-mid);border:1.5px solid var(--border);}
  .btn-ghost:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .btn-danger{background:transparent;color:var(--rose-deep);border:1.5px solid var(--rose-light);}
  .btn-danger:hover{background:var(--rose-pale);}
  .btn-sm{padding:4px 12px;font-size:11px;border-radius:14px;}
  .btn svg{width:14px;height:14px;}

  /* CONTENT */
  .content{flex:1;overflow-y:auto;padding:22px 26px;display:flex;flex-direction:column;gap:16px;scrollbar-width:thin;scrollbar-color:var(--border) transparent;}
  .content::-webkit-scrollbar{width:4px;}
  .content::-webkit-scrollbar-thumb{background:var(--rose-light);border-radius:2px;}

  /* STATS */
  .stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
  .stat-card{background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:16px 18px;box-shadow:var(--shadow-sm);position:relative;overflow:hidden;}
  .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:14px 14px 0 0;}
  .sc1::before{background:linear-gradient(90deg,var(--rose),var(--rose-light))}
  .sc2::before{background:linear-gradient(90deg,var(--teal),var(--teal-light))}
  .sc3::before{background:linear-gradient(90deg,var(--lavender),var(--lav-light))}
  .sc4::before{background:linear-gradient(90deg,#f0a500,#f5c842)}
  .stat-label{font-size:10px;letter-spacing:0.1em;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-bottom:6px;}
  .stat-value{font-family:'Dancing Script',cursive;font-size:30px;font-weight:700;color:var(--navy);line-height:1;}
  .stat-sub{font-size:11px;color:var(--text-dim);font-weight:600;margin-top:4px;}

  /* GRID CARDS */
  .productos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;}
  .producto-card{background:var(--surface);border:1.5px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);transition:transform 0.2s,box-shadow 0.2s;animation:fadeUp 0.3s ease both;}
  .producto-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);}
  .producto-card.inactivo{opacity:0.55;}
  .pc-top{padding:20px 20px 14px;position:relative;}
  .pc-tipo-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;margin-bottom:12px;}
  .tipo-rose{background:var(--rose-pale);color:var(--rose-deep);}
  .tipo-teal{background:var(--teal-pale);color:var(--teal-deep);}
  .tipo-lavender{background:var(--lav-pale);color:var(--lavender);}
  .tipo-orange{background:#fff4e0;color:#b07a30;}
  .pc-nombre{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:6px;line-height:1.3;}
  .pc-descripcion{font-size:11.5px;color:var(--text-mid);line-height:1.5;min-height:34px;}
  .pc-bottom{padding:12px 20px 16px;border-top:1.5px dashed var(--border);display:flex;align-items:center;gap:10px;}
  .pc-precio{font-family:'Dancing Script',cursive;font-size:24px;font-weight:700;color:var(--rose-deep);flex:1;}
  .estado-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
  .dot-activo{background:#2a9d73;}
  .dot-inactivo{background:var(--text-dim);}
  .pc-actions{display:flex;gap:6px;padding:0 20px 16px;}

  /* FILTROS */
  .toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
  .filtros{display:flex;gap:6px;flex-wrap:wrap;}
  .filtro-btn{padding:5px 14px;border-radius:20px;font-size:11px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);cursor:pointer;text-decoration:none;transition:all 0.2s;}
  .filtro-btn:hover,.filtro-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}
  .filtro-sep{width:1.5px;background:var(--border);height:22px;align-self:center;}

  /* MODAL */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(26,31,60,0.45);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
  .modal-overlay.open{display:flex;}
  .modal{background:var(--surface);border-radius:20px;width:100%;max-width:460px;box-shadow:var(--shadow-md);animation:fadeUp 0.3s ease both;max-height:92vh;overflow-y:auto;}
  .modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;background:var(--surface2);border-radius:20px 20px 0 0;}
  .modal-title{font-family:'Dancing Script',cursive;font-size:22px;font-weight:700;color:var(--navy);flex:1;}
  .modal-close{width:28px;height:28px;border-radius:50%;background:var(--rose-pale);border:none;color:var(--rose-deep);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;font-weight:700;}
  .modal-body{padding:20px 24px;}
  .modal-footer{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--surface2);border-radius:0 0 20px 20px;}
  .form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:13px;}
  .form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  label{font-size:10px;letter-spacing:0.1em;text-transform:uppercase;color:var(--text-dim);font-weight:700;}
  .form-input{background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;color:var(--navy);font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:600;outline:none;transition:border-color 0.2s;width:100%;}
  .form-input:focus{border-color:var(--rose-light);box-shadow:0 0 0 3px rgba(232,120,154,0.10);}
  .categoria-select{cursor:pointer;appearance:auto;}
  .categoria-nueva{display:none;margin-top:7px;}
  .categoria-nueva.visible{display:block;}
  .categoria-hint{font-size:10px;color:var(--text-dim);line-height:1.4;}
  .categoria-filtro{display:flex;align-items:center;gap:7px;margin-top:10px;}
  .categoria-filtro label{font-size:10px;color:var(--text-dim);font-weight:700;text-transform:uppercase;letter-spacing:.08em;}
  .categoria-filtro select{min-width:190px;padding:7px 12px;border:1.5px solid var(--border);border-radius:10px;background:var(--surface);color:var(--text-mid);font:600 11px 'Nunito',sans-serif;outline:none;cursor:pointer;}
  .categoria-filtro select:focus{border-color:var(--rose-light);box-shadow:0 0 0 3px rgba(232,120,154,0.10);}
  textarea.form-input{resize:vertical;min-height:70px;}

  /* TIPO SELECTOR */
  .tipo-selector{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;}
  .tipo-opt{display:none;}
  .tipo-opt + label{display:flex;flex-direction:column;align-items:center;padding:10px 6px;border-radius:10px;border:1.5px solid var(--border);cursor:pointer;font-size:10px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.06em;text-align:center;transition:all 0.18s;gap:4px;}
  .tipo-opt + label span{font-size:18px;}
  .tipo-opt:checked + label{border-color:var(--rose-light);background:var(--rose-pale);color:var(--rose-deep);}

  .alert{padding:10px 16px;border-radius:10px;font-size:12px;font-weight:600;}
  .alert-success{background:var(--teal-pale);color:var(--teal-deep);border:1.5px solid var(--teal-light);}
  .alert-error{background:var(--rose-pale);color:var(--rose-deep);border:1.5px solid var(--rose-light);}
  .empty-state{text-align:center;padding:50px 20px;color:var(--text-dim);}
  .empty-state svg{width:48px;height:48px;opacity:0.3;margin-bottom:12px;}

  @keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<?php require_once '../includes/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <span class="page-title">Sesiones, Combos y Adicionales</span>
    <div class="topbar-sep"></div>
    <button class="btn btn-primary" onclick="abrirModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nuevo elemento
    </button>
  </div>

  <div class="content">

    <?php if ($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
      <div class="stat-card sc1">
        <div class="stat-label">Total activos</div>
        <div class="stat-value"><?= $totalActivos ?></div>
        <div class="stat-sub">de <?= $totalProductos ?> registrados</div>
      </div>
      <div class="stat-card sc2">
        <div class="stat-label">Sesiones</div>
        <div class="stat-value"><?= $statsPorTipo['sesion']['total'] ?? 0 ?></div>
        <div class="stat-sub">servicios activos</div>
      </div>
      <div class="stat-card sc3">
        <div class="stat-label">Combos</div>
        <div class="stat-value"><?= $statsPorTipo['combo']['total'] ?? 0 ?></div>
        <div class="stat-sub">paquetes activos</div>
      </div>
      
    </div>

    <!-- FILTROS -->
    <div class="toolbar">
      <div class="filtros">
        <a href="productos.php" class="filtro-btn <?= !$filtroTipo && $filtroEstado==='activo' ? 'active' : '' ?>">Todos</a>
        <a href="?tipo=sesion" class="filtro-btn <?= $filtroTipo==='sesion' ? 'active' : '' ?>">📷 Sesiones</a>
        <a href="?tipo=combo" class="filtro-btn <?= $filtroTipo==='combo' ? 'active' : '' ?>">🎁 Combos</a>
        <a href="?tipo=adicional" class="filtro-btn <?= $filtroTipo==='adicional' ? 'active' : '' ?>">➕ Adicionales</a>
        <div class="filtro-sep"></div>
        <a href="?estado=inactivo" class="filtro-btn <?= $filtroEstado==='inactivo' ? 'active' : '' ?>">Inactivos</a>
      </div>
      <form method="GET" class="categoria-filtro">
        <?php if ($filtroTipo): ?><input type="hidden" name="tipo" value="<?= htmlspecialchars($filtroTipo) ?>"><?php endif; ?>
        <input type="hidden" name="estado" value="<?= htmlspecialchars($filtroEstado) ?>">
        <label for="filtroCategoria">Categoría</label>
        <select id="filtroCategoria" name="categoria" onchange="this.form.submit()">
          <option value="">Todas las categorías</option>
          <?php foreach ($categoriasFiltro as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>" <?= $filtroCategoria === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span style="font-size:11px;color:var(--text-dim);font-weight:600;margin-left:auto;"><?= $productos->num_rows ?> resultado<?= $productos->num_rows !== 1 ? 's' : '' ?></span>
    </div>

    <!-- GRID DE PRODUCTOS -->
    <?php if ($productos->num_rows === 0): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        <div style="font-size:14px;font-weight:700;color:var(--text-mid);margin-bottom:4px;">Sin elementos</div>
        <div style="font-size:12px;">Crea tu primer elemento para comenzar.</div>
      </div>
    <?php else: ?>
    <div class="productos-grid">
      <?php while ($p = $productos->fetch_assoc()):
        $colorCls = 'tipo-' . ($tiposColor[$p['tipo']] ?? 'rose');
        $tipoLabel = $tiposLabel[$p['tipo']] ?? ucfirst($p['tipo']);
      ?>
      <div class="producto-card <?= $p['estado'] === 'inactivo' ? 'inactivo' : '' ?>">
        <div class="pc-top">
          <div class="pc-tipo-badge <?= $colorCls ?>"><?= $tipoLabel ?></div>
          <div class="pc-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
          <div class="pc-descripcion"><?= htmlspecialchars($p['descripcion'] ?: 'Sin descripción.') ?></div>
          <?php if(!empty($p['categoria'])): ?><div style="font-size:10px;color:var(--text-dim);margin-top:6px;font-weight:700;">Categoría: <?= htmlspecialchars($p['categoria']) ?></div><?php endif; ?>
        </div>
        <div class="pc-bottom">
          <div class="pc-precio"><?= formatoPeso($p['precio']) ?></div>
          <div class="estado-dot <?= $p['estado'] === 'activo' ? 'dot-activo' : 'dot-inactivo' ?>" title="<?= ucfirst($p['estado']) ?>"></div>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="accion" value="toggle_estado">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit" title="<?= $p['estado']==='activo' ? 'Desactivar' : 'Activar' ?>">
              <?= $p['estado']==='activo' ? 'Pausar' : 'Activar' ?>
            </button>
          </form>
        </div>
        <div class="pc-actions">
          <button class="btn btn-ghost btn-sm" onclick="editarProducto(<?= htmlspecialchars(json_encode($p)) ?>)" style="flex:1;justify-content:center;">Editar</button>
          <button class="btn btn-danger btn-sm" onclick="confirmarEliminar(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nombre']) ?>')">Eliminar</button>
        </div>
      </div>
      <?php endwhile; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="modalProducto">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitulo">Nuevo elemento</div>
      <button class="modal-close" onclick="cerrarModal()">✕</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="productoId" value="0">
      <div class="modal-body">
        <div class="form-group">
          <label>Tipo *</label>
          <div class="tipo-selector">
            <input type="radio" name="tipo" id="t1" value="sesion" class="tipo-opt" checked>
            <label for="t1"><span>📷</span>Sesión</label>
            <input type="radio" name="tipo" id="t2" value="combo" class="tipo-opt">
            <label for="t2"><span>🎁</span>Combo</label>
            <input type="radio" name="tipo" id="t3" value="adicional" class="tipo-opt">
            <label for="t3"><span>➕</span>Adicional</label>
          </div>
        </div>
        <div class="form-group">
          <label>Nombre *</label>
          <input class="form-input" type="text" name="nombre" id="mNombre" placeholder="Ej: Quinceañera Premium" required>
        </div>
        <div class="form-group">
          <label>Descripción</label>
          <textarea class="form-input" name="descripcion" id="mDescripcion" placeholder="Qué incluye este servicio..."></textarea>
        </div>
        <div class="form-group">
          <label id="categoriaLabel">Categoría *</label>
          <select class="form-input categoria-select" id="mCategoriaSelect" onchange="cambiarCategoria()">
            <option value="">Selecciona una categoría...</option>
          </select>
          <input class="form-input categoria-nueva" type="text" id="mCategoriaNueva" placeholder="Escribe la nueva categoría..." autocomplete="off">
          <input type="hidden" name="categoria" id="mCategoria">
          <div class="categoria-hint" id="categoriaHint">Selecciona una categoría existente o crea una nueva.</div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Precio ($) *</label>
            <input class="form-input" type="number" name="precio" id="mPrecio" min="0" step="1000" placeholder="0" required>
          </div>
          <div class="form-group">
            <label>Estado</label>
            <select class="form-input" name="estado" id="mEstado">
              <option value="activo">Activo</option>
              <option value="inactivo">Inactivo</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="cerrarModal()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- FORM ELIMINAR -->
<form method="POST" id="formEliminar" style="display:none;">
  <input type="hidden" name="accion" value="eliminar">
  <input type="hidden" name="id" id="eliminarId">
</form>

<script>
function actualizarCategoriaSegunTipo(categoriaActual = '') {
  const tipo = document.querySelector('input[name="tipo"]:checked')?.value || 'sesion';
  const select = document.getElementById('mCategoriaSelect');
  const nueva = document.getElementById('mCategoriaNueva');
  const hidden = document.getElementById('mCategoria');
  const label = document.getElementById('categoriaLabel');
  const hint = document.getElementById('categoriaHint');

  const categorias = <?= json_encode($categoriasPorTipo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const lista = categorias[tipo] || [];

  select.innerHTML = '<option value="">Selecciona una categoría...</option>';
  lista.forEach(function(cat) {
    const option = document.createElement('option');
    option.value = cat;
    option.textContent = cat;
    select.appendChild(option);
  });

  const nuevaOption = document.createElement('option');
  nuevaOption.value = '__nueva__';
  nuevaOption.textContent = '＋ Crear nueva categoría';
  select.appendChild(nuevaOption);

  label.textContent = (tipo === 'sesion' || tipo === 'adicional') ? 'Categoría *' : 'Categoría';
  select.required = (tipo === 'sesion' || tipo === 'adicional');

  nueva.value = '';
  nueva.classList.remove('visible');
  hidden.value = categoriaActual || '';

  if (categoriaActual && lista.includes(categoriaActual)) {
    select.value = categoriaActual;
  } else if (categoriaActual) {
    select.value = '__nueva__';
    nueva.value = categoriaActual;
    nueva.classList.add('visible');
  } else {
    select.value = '';
  }

  hint.textContent = (tipo === 'sesion' || tipo === 'adicional')
    ? 'Selecciona una categoría existente o crea una nueva.'
    : 'La categoría es opcional.';
}

function cambiarCategoria() {
  const select = document.getElementById('mCategoriaSelect');
  const nueva = document.getElementById('mCategoriaNueva');
  const hidden = document.getElementById('mCategoria');

  if (select.value === '__nueva__') {
    nueva.classList.add('visible');
    nueva.focus();
    hidden.value = nueva.value.trim();
  } else {
    nueva.classList.remove('visible');
    hidden.value = select.value;
  }
}

document.getElementById('mCategoriaNueva').addEventListener('input', function() {
  if (document.getElementById('mCategoriaSelect').value === '__nueva__') {
    document.getElementById('mCategoria').value = this.value.trim();
  }
});

function abrirModal() {
  document.getElementById('modalTitulo').textContent = 'Nuevo elemento';
  document.getElementById('productoId').value = '0';
  document.getElementById('mNombre').value = '';
  document.getElementById('mDescripcion').value = '';
  document.getElementById('mCategoria').value = '';
  document.getElementById('mCategoriaNueva').value = '';
  document.getElementById('mCategoriaNueva').classList.remove('visible');
  document.getElementById('mPrecio').value = '';
  document.getElementById('mEstado').value = 'activo';
  document.querySelector('input[name="tipo"][value="sesion"]').checked = true;
  actualizarCategoriaSegunTipo('');
  document.getElementById('modalProducto').classList.add('open');
}

function editarProducto(p) {
  document.getElementById('modalTitulo').textContent = 'Editar elemento';
  document.getElementById('productoId').value    = p.id;
  document.getElementById('mNombre').value       = p.nombre;
  document.getElementById('mDescripcion').value  = p.descripcion || '';
  document.getElementById('mCategoria').value    = p.categoria || '';
  document.getElementById('mPrecio').value       = p.precio;
  document.getElementById('mEstado').value       = p.estado;
  const radio = document.querySelector('input[name="tipo"][value="' + p.tipo + '"]');
  if (radio) radio.checked = true;
  actualizarCategoriaSegunTipo(p.categoria || '');
  document.getElementById('modalProducto').classList.add('open');
}

document.querySelectorAll('input[name="tipo"]').forEach(function(radio) {
  radio.addEventListener('change', function() { actualizarCategoriaSegunTipo(''); });
});

function cerrarModal() {
  document.getElementById('modalProducto').classList.remove('open');
}

function confirmarEliminar(id, nombre) {
  if (confirm('¿Eliminar "' + nombre + '"?\nSi tiene sesiones asociadas no se podrá eliminar.')) {
    document.getElementById('eliminarId').value = id;
    document.getElementById('formEliminar').submit();
  }
}

document.getElementById('modalProducto').addEventListener('click', function(e) {
  if (e.target === this) cerrarModal();
});

<?php if ($error): ?>
document.getElementById('modalProducto').classList.add('open');
<?php endif; ?>
</script>

</body>
</html>