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

    // ── CREAR / EDITAR ──
    if ($accion === 'guardar') {
        $id       = sanitizeInt($_POST['id'] ?? 0);
        $nombre   = sanitize($_POST['nombre'] ?? '');
        $apellido = sanitize($_POST['apellido'] ?? '');
        $telefono = sanitize($_POST['telefono'] ?? '');
        $email    = sanitize($_POST['email'] ?? '');
        $direccion= sanitize($_POST['direccion'] ?? '');
        $notas    = sanitize($_POST['notas'] ?? '');

        if (empty($nombre) || empty($apellido)) {
            $error = 'El nombre y apellido son obligatorios.';
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE clientes SET nombre=?, apellido=?, telefono=?, email=?, direccion=?, notas=? WHERE id=?");
                $stmt->bind_param("ssssssi", $nombre, $apellido, $telefono, $email, $direccion, $notas, $id);
                $stmt->execute();
                $mensaje = 'Cliente actualizado correctamente.';
            } else {
                $stmt = $db->prepare("INSERT INTO clientes (nombre, apellido, telefono, email, direccion, notas) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param("ssssss", $nombre, $apellido, $telefono, $email, $direccion, $notas);
                $stmt->execute();
                $mensaje = 'Cliente registrado correctamente.';
            }
            $stmt->close();
        }
    }

    // ── ELIMINAR ──
    if ($accion === 'eliminar') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM clientes WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $mensaje = 'Cliente eliminado.';
        }
    }
}

// ============================================
// BÚSQUEDA Y LISTADO
// ============================================
$busqueda = sanitize($_GET['q'] ?? '');
$pagina   = max(1, sanitizeInt($_GET['p'] ?? 1));
$porPagina = 10;
$offset    = ($pagina - 1) * $porPagina;

$where = '';
$params = [];
$tipos  = '';
if ($busqueda !== '') {
    $where = "WHERE nombre LIKE ? OR apellido LIKE ? OR telefono LIKE ? OR email LIKE ?";
    $like  = "%$busqueda%";
    $params = [$like, $like, $like, $like];
    $tipos  = 'ssss';
}

// Total para paginación
$sqlCount = "SELECT COUNT(*) as total FROM clientes $where";
$stmtC = $db->prepare($sqlCount);
if ($params) $stmtC->bind_param($tipos, ...$params);
$stmtC->execute();
$totalRegistros = $stmtC->get_result()->fetch_assoc()['total'];
$totalPaginas   = ceil($totalRegistros / $porPagina);
$stmtC->close();

// Listado con conteo de sesiones
$sql = "SELECT c.*, 
        (SELECT COUNT(*) FROM citas ci WHERE ci.cliente_id = c.id) AS total_citas,
        (SELECT MAX(fecha) FROM citas ci WHERE ci.cliente_id = c.id) AS ultima_cita
        FROM clientes c $where
        ORDER BY c.fecha_registro DESC
        LIMIT ? OFFSET ?";
$stmtL = $db->prepare($sql);
if ($params) {
    $tipos .= 'ii';
    $params[] = $porPagina;
    $params[] = $offset;
    $stmtL->bind_param($tipos, ...$params);
} else {
    $stmtL->bind_param('ii', $porPagina, $offset);
}
$stmtL->execute();
$clientes = $stmtL->get_result();
$stmtL->close();

// Cliente a editar
$clienteEditar = null;
if (isset($_GET['editar'])) {
    $idEditar = sanitizeInt($_GET['editar']);
    $stmtE = $db->prepare("SELECT * FROM clientes WHERE id=?");
    $stmtE->bind_param("i", $idEditar);
    $stmtE->execute();
    $clienteEditar = $stmtE->get_result()->fetch_assoc();
    $stmtE->close();
}

$avClasses = ['av-a','av-b','av-c','av-d','av-e'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clientes – <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;600;700&family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:#faf8f6;--surface:#fff;--surface2:#fdf5f8;
    --navy:#1a1f3c;--navy-mid:#2d3460;--navy-soft:#e8eaf6;
    --rose:#e8789a;--rose-light:#f5b8ce;--rose-pale:#fce8f0;--rose-deep:#c4547a;
    --teal:#5bbcb8;--teal-light:#9ddbd8;--teal-pale:#e0f5f4;--teal-deep:#3a9994;
    --lavender:#9e8bc9;--lav-light:#c5b8e8;--lav-pale:#f0ecfb;
    --text:#2a2040;--text-mid:#6b5e7a;--text-dim:#a899b5;--border:#ede0ea;
    --sidebar-w:230px;--shadow-sm:0 2px 8px rgba(180,120,160,0.10);--shadow-md:0 4px 20px rgba(180,120,160,0.15);
  }
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
  .badge{margin-left:auto;background:var(--rose);color:#fff;border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;}
  .badge.teal{background:var(--teal-deep);}
  .sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,0.08);position:relative;z-index:1;}
  .user-chip{display:flex;align-items:center;gap:10px;}
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
  .btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(196,84,122,0.4);}
  .btn-ghost{background:transparent;color:var(--text-mid);border:1.5px solid var(--border);}
  .btn-ghost:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .btn-danger{background:transparent;color:var(--rose-deep);border:1.5px solid var(--rose-light);}
  .btn-danger:hover{background:var(--rose-pale);}
  .btn svg{width:14px;height:14px;}
  .btn-sm{padding:4px 12px;font-size:11px;border-radius:14px;}

  /* CONTENT */
  .content{flex:1;overflow-y:auto;padding:22px 26px;display:flex;flex-direction:column;gap:16px;scrollbar-width:thin;scrollbar-color:var(--border) transparent;}
  .content::-webkit-scrollbar{width:4px;}
  .content::-webkit-scrollbar-thumb{background:var(--rose-light);border-radius:2px;}

  /* TOOLBAR */
  .toolbar{display:flex;gap:10px;align-items:center;}
  .search-wrap{position:relative;flex:1;max-width:340px;}
  .search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:14px;color:var(--text-dim);pointer-events:none;}
  .search-input{width:100%;background:var(--surface);border:1.5px solid var(--border);border-radius:20px;padding:8px 14px 8px 34px;font-family:'Nunito',sans-serif;font-size:12.5px;color:var(--navy);outline:none;transition:border-color 0.2s;}
  .search-input:focus{border-color:var(--rose-light);}

  /* CARD */
  .card{background:var(--surface);border:1.5px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);}
  .card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--surface2);}
  .card-title{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--navy);flex:1;}

  /* TABLE */
  table{width:100%;border-collapse:collapse;}
  th{text-align:left;padding:11px 20px;font-size:10px;letter-spacing:0.14em;color:var(--text-dim);text-transform:uppercase;border-bottom:1.5px solid var(--border);font-weight:700;background:#fdf9fb;}
  td{padding:12px 20px;border-bottom:1px solid var(--border);font-size:12.5px;color:var(--text-mid);vertical-align:middle;}
  tr:last-child td{border-bottom:none;}
  tr:hover td{background:var(--rose-pale);}
  .client-cell{display:flex;align-items:center;gap:10px;}
  .client-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:17px;font-weight:700;flex-shrink:0;}
  .av-a{background:var(--rose-pale);color:var(--rose-deep)}.av-b{background:var(--teal-pale);color:var(--teal-deep)}.av-c{background:var(--lav-pale);color:var(--lavender)}.av-d{background:var(--navy-soft);color:var(--navy-mid)}.av-e{background:#fef3e2;color:#b07a30}
  .client-name{font-size:13px;color:var(--navy);font-weight:700;}
  .client-email{font-size:10px;color:var(--text-dim);margin-top:1px;}
  .actions{display:flex;gap:6px;}
  .empty-row{text-align:center;padding:40px;color:var(--text-dim);font-size:13px;}

  /* PAGINACIÓN */
  .pagination{display:flex;gap:6px;justify-content:center;padding-top:4px;}
  .page-btn{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);text-decoration:none;transition:all 0.2s;}
  .page-btn:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .page-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}

  /* MODAL */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(26,31,60,0.45);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
  .modal-overlay.open{display:flex;}
  .modal{background:var(--surface);border-radius:20px;width:100%;max-width:500px;box-shadow:var(--shadow-md);animation:fadeUp 0.3s ease both;max-height:90vh;overflow-y:auto;}
  .modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--surface2);border-radius:20px 20px 0 0;}
  .modal-title{font-family:'Dancing Script',cursive;font-size:22px;font-weight:700;color:var(--navy);flex:1;}
  .modal-close{width:28px;height:28px;border-radius:50%;background:var(--rose-pale);border:none;color:var(--rose-deep);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;font-weight:700;}
  .modal-body{padding:20px 24px;}
  .form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
  .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  label{font-size:10px;letter-spacing:0.1em;text-transform:uppercase;color:var(--text-dim);font-weight:700;}
  .form-input{background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;color:var(--navy);font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:600;outline:none;transition:border-color 0.2s;width:100%;}
  .form-input:focus{border-color:var(--rose-light);box-shadow:0 0 0 3px rgba(232,120,154,0.10);}
  textarea.form-input{resize:vertical;min-height:70px;}
  .modal-footer{padding:16px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--surface2);border-radius:0 0 20px 20px;}

  /* ALERTS */
  .alert{padding:10px 16px;border-radius:10px;font-size:12px;font-weight:600;margin-bottom:4px;}
  .alert-success{background:var(--teal-pale);color:var(--teal-deep);border:1.5px solid var(--teal-light);}
  .alert-error{background:var(--rose-pale);color:var(--rose-deep);border:1.5px solid var(--rose-light);}

  @keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<?php require_once '../includes/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <span class="page-title">Clientes</span>
    <div class="topbar-sep"></div>
    <button class="btn btn-primary" onclick="abrirModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nuevo Cliente
    </button>
  </div>

  <div class="content">

    <?php if ($mensaje): ?>
      <div class="alert alert-success"><?= $mensaje ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>

    <!-- TOOLBAR BÚSQUEDA -->
    <div class="toolbar">
      <form method="GET" action="" style="display:flex;gap:10px;align-items:center;flex:1;">
        <div class="search-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input class="search-input" type="text" name="q" placeholder="Buscar por nombre, teléfono o correo..." value="<?= htmlspecialchars($busqueda) ?>">
        </div>
        <button type="submit" class="btn btn-ghost">Buscar</button>
        <?php if ($busqueda): ?>
          <a href="clientes.php" class="btn btn-ghost">✕ Limpiar</a>
        <?php endif; ?>
      </form>
      <span style="font-size:11px;color:var(--text-dim);font-weight:600;"><?= $totalRegistros ?> cliente<?= $totalRegistros !== 1 ? 's' : '' ?></span>
    </div>

    <!-- TABLA -->
    <div class="card">
      <table>
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Teléfono</th>
            <th>Correo</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($clientes->num_rows === 0): ?>
            <tr><td colspan="6" class="empty-row">
              <?= $busqueda ? 'Sin resultados para "' . htmlspecialchars($busqueda) . '"' : 'Aún no hay clientes registrados.' ?>
            </td></tr>
          <?php else: ?>
            <?php $i = 0; while ($c = $clientes->fetch_assoc()): ?>
            <tr>
              <td>
                <div class="client-cell">
                  <div class="client-avatar <?= $avClasses[$i % 5] ?>"><?= mb_strtoupper(mb_substr($c['nombre'], 0, 1)) ?></div>
                  <div>
                    <div class="client-name"><?= htmlspecialchars($c['nombre'] . ' ' . $c['apellido']) ?></div>
                    <?php if ($c['direccion']): ?><div class="client-email"><?= htmlspecialchars($c['direccion']) ?></div><?php endif; ?>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($c['telefono'] ?: '—') ?></td>
              <td><?= htmlspecialchars($c['email'] ?: '—') ?></td>
              <td style="font-weight:700;color:var(--navy);"><?= $c['total_citas'] ?></td>
              <td><?= $c['ultima_cita'] ? formatoFecha($c['ultima_cita']) : '—' ?></td>
              <td>
                <div class="actions">
                  <button class="btn btn-ghost btn-sm" onclick="editarCliente(<?= htmlspecialchars(json_encode($c)) ?>)">Editar</button>
                  <button class="btn btn-danger btn-sm" onclick="confirmarEliminar(<?= $c['id'] ?>, '<?= htmlspecialchars($c['nombre'] . ' ' . $c['apellido']) ?>')">Eliminar</button>
                </div>
              </td>
            </tr>
            <?php $i++; endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- PAGINACIÓN -->
    <?php if ($totalPaginas > 1): ?>
    <div class="pagination">
      <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
        <a href="?p=<?= $p ?>&q=<?= urlencode($busqueda) ?>" class="page-btn <?= $p === $pagina ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- MODAL CREAR / EDITAR -->
<div class="modal-overlay" id="modalCliente">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitulo">Nuevo Cliente</div>
      <button class="modal-close" onclick="cerrarModal()">✕</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="clienteId" value="0">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label>Nombre *</label>
            <input class="form-input" type="text" name="nombre" id="fNombre" placeholder="Ej: María" required>
          </div>
          <div class="form-group">
            <label>Apellido *</label>
            <input class="form-input" type="text" name="apellido" id="fApellido" placeholder="Ej: García" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Teléfono</label>
            <input class="form-input" type="text" name="telefono" id="fTelefono" placeholder="Ej: 314 000 0001">
          </div>
          <div class="form-group">
            <label>Correo</label>
            <input class="form-input" type="email" name="email" id="fEmail" placeholder="correo@ejemplo.com">
          </div>
        </div>
        <div class="form-group">
          <label>Dirección</label>
          <input class="form-input" type="text" name="direccion" id="fDireccion" placeholder="Dirección del cliente">
        </div>
        <div class="form-group">
          <label>Notas</label>
          <textarea class="form-input" name="notas" id="fNotas" placeholder="Observaciones adicionales..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="cerrarModal()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar Cliente</button>
      </div>
    </form>
  </div>
</div>

<!-- FORM ELIMINAR (oculto) -->
<form method="POST" action="" id="formEliminar" style="display:none;">
  <input type="hidden" name="accion" value="eliminar">
  <input type="hidden" name="id" id="eliminarId">
</form>

<script>
function abrirModal() {
  document.getElementById('modalTitulo').textContent = 'Nuevo Cliente';
  document.getElementById('clienteId').value = '0';
  document.getElementById('fNombre').value = '';
  document.getElementById('fApellido').value = '';
  document.getElementById('fTelefono').value = '';
  document.getElementById('fEmail').value = '';
  document.getElementById('fDireccion').value = '';
  document.getElementById('fNotas').value = '';
  document.getElementById('modalCliente').classList.add('open');
}

function editarCliente(c) {
  document.getElementById('modalTitulo').textContent = 'Editar Cliente';
  document.getElementById('clienteId').value = c.id;
  document.getElementById('fNombre').value = c.nombre;
  document.getElementById('fApellido').value = c.apellido;
  document.getElementById('fTelefono').value = c.telefono || '';
  document.getElementById('fEmail').value = c.email || '';
  document.getElementById('fDireccion').value = c.direccion || '';
  document.getElementById('fNotas').value = c.notas || '';
  document.getElementById('modalCliente').classList.add('open');
}

function cerrarModal() {
  document.getElementById('modalCliente').classList.remove('open');
}

function confirmarEliminar(id, nombre) {
  if (confirm('¿Eliminar a ' + nombre + '?\nEsta acción no se puede deshacer.')) {
    document.getElementById('eliminarId').value = id;
    document.getElementById('formEliminar').submit();
  }
}

// Cerrar modal al hacer clic fuera
document.getElementById('modalCliente').addEventListener('click', function(e) {
  if (e.target === this) cerrarModal();
});

// Abrir modal si venía con error (campo incompleto)
<?php if ($error): ?>
document.getElementById('modalCliente').classList.add('open');
<?php endif; ?>

// Abrir modal si venía a editar por URL
<?php if ($clienteEditar): ?>
editarCliente(<?= json_encode($clienteEditar) ?>);
<?php endif; ?>
</script>

</body>
</html>