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
        $id           = sanitizeInt($_POST['id'] ?? 0);
        $cliente_id   = sanitizeInt($_POST['cliente_id'] ?? 0);
        $producto_id  = sanitizeInt($_POST['producto_id'] ?? 0) ?: null;
        $fecha        = sanitize($_POST['fecha'] ?? '');
        $hora         = sanitize($_POST['hora'] ?? '');
        $duracion     = sanitizeInt($_POST['duracion_min'] ?? 60);
        $estado       = sanitize($_POST['estado'] ?? 'pendiente');
        $observaciones= sanitize($_POST['observaciones'] ?? '');

        if (!$cliente_id || !$fecha || !$hora) {
            $error = 'Cliente, fecha y hora son obligatorios.';
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE citas SET cliente_id=?, producto_id=?, fecha=?, hora=?, duracion_min=?, estado=?, observaciones=? WHERE id=?");
                $stmt->bind_param("iississi", $cliente_id, $producto_id, $fecha, $hora, $duracion, $estado, $observaciones, $id);
                $mensaje = 'Cita actualizada correctamente.';
            } else {
                $stmt = $db->prepare("INSERT INTO citas (cliente_id, producto_id, fecha, hora, duracion_min, estado, observaciones) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param("iississ", $cliente_id, $producto_id, $fecha, $hora, $duracion, $estado, $observaciones);
                $mensaje = 'Cita agendada correctamente.';
            }
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($accion === 'cambiar_estado') {
        $id     = sanitizeInt($_POST['id'] ?? 0);
        $estado = sanitize($_POST['estado'] ?? '');
        if ($id && $estado) {
            $stmt = $db->prepare("UPDATE citas SET estado=? WHERE id=?");
            $stmt->bind_param("si", $estado, $id);
            $stmt->execute();
            $stmt->close();
            $mensaje = 'Estado actualizado.';
        }
    }

    if ($accion === 'eliminar') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM citas WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $mensaje = 'Cita eliminada.';
        }
    }
}

// ============================================
// FILTROS
// ============================================
$fechaFiltro  = sanitize($_GET['fecha'] ?? date('Y-m-d'));
$estadoFiltro = sanitize($_GET['estado'] ?? '');
$pagina       = max(1, sanitizeInt($_GET['p'] ?? 1));
$porPagina    = 12;
$offset       = ($pagina - 1) * $porPagina;

// Citas del día seleccionado para la agenda
$citasAgenda = $db->prepare("
    SELECT ci.*, cl.nombre, cl.apellido, cl.telefono, p.nombre AS servicio
    FROM citas ci
    JOIN clientes cl ON ci.cliente_id = cl.id
    LEFT JOIN productos p ON ci.producto_id = p.id
    WHERE ci.fecha = ?
    ORDER BY ci.hora ASC
");
$citasAgenda->bind_param("s", $fechaFiltro);
$citasAgenda->execute();
$agendaDia = $citasAgenda->get_result();
$citasAgenda->close();

// Listado general con filtros
$where  = "WHERE 1=1";
$params = [];
$tipos  = '';

if ($estadoFiltro) {
    $where   .= " AND ci.estado = ?";
    $params[] = $estadoFiltro;
    $tipos   .= 's';
}

$sqlCount = "SELECT COUNT(*) as total FROM citas ci $where";
$stmtC = $db->prepare($sqlCount);
if ($params) $stmtC->bind_param($tipos, ...$params);
$stmtC->execute();
$totalRegistros = $stmtC->get_result()->fetch_assoc()['total'];
$totalPaginas   = ceil($totalRegistros / $porPagina);
$stmtC->close();

$sqlCitas = "
    SELECT ci.*, cl.nombre, cl.apellido, cl.telefono, p.nombre AS servicio, p.precio
    FROM citas ci
    JOIN clientes cl ON ci.cliente_id = cl.id
    LEFT JOIN productos p ON ci.producto_id = p.id
    $where
    ORDER BY ci.fecha DESC, ci.hora DESC
    LIMIT ? OFFSET ?
";
$tiposL = $tipos . 'ii';
$paramsL = array_merge($params, [$porPagina, $offset]);
$stmtL = $db->prepare($sqlCitas);
$stmtL->bind_param($tiposL, ...$paramsL);
$stmtL->execute();
$listadoCitas = $stmtL->get_result();
$stmtL->close();

// Clientes y productos para el formulario
$todosClientes = $db->query("SELECT id, nombre, apellido, telefono FROM clientes ORDER BY nombre ASC");
$todosProductos = $db->query("SELECT id, nombre, precio FROM productos WHERE estado='activo' ORDER BY nombre ASC");

// Horas para la agenda
$horasAgenda = ['08:00','09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00'];
$avClasses   = ['av-a','av-b','av-c','av-d','av-e'];
$coloresBloque = ['','alt','alt2'];

$mesesNombres = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fechaDt      = new DateTime($fechaFiltro);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Citas – <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;600;700&family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#faf8f6;--surface:#fff;--surface2:#fdf5f8;--navy:#1a1f3c;--navy-mid:#2d3460;--navy-soft:#e8eaf6;--rose:#e8789a;--rose-light:#f5b8ce;--rose-pale:#fce8f0;--rose-deep:#c4547a;--teal:#5bbcb8;--teal-light:#9ddbd8;--teal-pale:#e0f5f4;--teal-deep:#3a9994;--lavender:#9e8bc9;--lav-light:#c5b8e8;--lav-pale:#f0ecfb;--text:#2a2040;--text-mid:#6b5e7a;--text-dim:#a899b5;--border:#ede0ea;--sidebar-w:230px;--shadow-sm:0 2px 8px rgba(180,120,160,0.10);--shadow-md:0 4px 20px rgba(180,120,160,0.15);}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;font-size:13px;}

  /* SIDEBAR */
  .sidebar{width:var(--sidebar-w);background:var(--navy);display:flex;flex-direction:column;flex-shrink:0;position:relative;overflow:hidden;}
  .sidebar::before{content:'';position:absolute;width:180px;height:180px;border-radius:50%;background:radial-gradient(circle,rgba(232,120,154,0.18) 0%,transparent 70%);top:-40px;right:-50px;pointer-events:none;}
  .sidebar::after{content:'';position:absolute;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle,rgba(91,188,184,0.15) 0%,transparent 70%);bottom:60px;left:-30px;pointer-events:none;}
  .logo-area{padding:24px 20px 20px;display:flex;flex-direction:column;align-items:center;border-bottom:1px solid rgba(255,255,255,0.08);position:relative;z-index:1;}
  .logo-circle{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:34px;color:var(--navy);font-weight:700;margin-bottom:10px;box-shadow:0 4px 16px rgba(232,120,154,0.35);}
  .logo-name{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--rose-light);text-align:center;line-height:1.15;}
  .logo-sub{font-size:9px;color:var(--teal-light);letter-spacing:0.22em;text-transform:uppercase;margin-top:3px;font-weight:600;}
  nav{padding:14px 0;flex:1;position:relative;z-index:1;overflow-y:auto;}
  .nav-section{padding:10px 20px 5px;font-size:9px;letter-spacing:0.18em;color:rgba(255,255,255,0.3);text-transform:uppercase;font-weight:600;}
  .nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,0.55);cursor:pointer;transition:all 0.2s;border-left:3px solid transparent;font-size:12.5px;font-weight:600;text-decoration:none;}
  .nav-item:hover{color:rgba(255,255,255,0.9);background:rgba(255,255,255,0.05);}
  .nav-item.active{color:#fff;border-left-color:var(--rose-light);background:rgba(232,120,154,0.15);}
  .nav-item svg{width:16px;height:16px;flex-shrink:0;}
  .badge{margin-left:auto;background:var(--rose);color:#fff;border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;}
  .sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,0.08);position:relative;z-index:1;}
  .user-chip{display:flex;align-items:center;gap:10px;}
  .avatar-sm{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:18px;color:var(--navy);font-weight:700;flex-shrink:0;}
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

  /* LAYOUT */
  .citas-grid{display:grid;grid-template-columns:1fr 300px;gap:16px;}

  /* CARD */
  .card{background:var(--surface);border:1.5px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);}
  .card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--surface2);}
  .card-title{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--navy);flex:1;}
  .card-tag{font-size:10px;font-weight:700;color:var(--teal-deep);background:var(--teal-pale);border-radius:20px;padding:3px 10px;}

  /* AGENDA */
  .time-slots{display:flex;flex-direction:column;gap:0;padding:10px 20px;}
  .time-slot{display:flex;gap:14px;align-items:flex-start;min-height:52px;padding:4px 0;}
  .slot-time{width:46px;font-size:10.5px;color:var(--text-dim);flex-shrink:0;padding-top:7px;font-weight:700;}
  .slot-line{width:1.5px;background:var(--border);flex-shrink:0;align-self:stretch;margin-top:4px;}
  .slot-content{flex:1;padding-bottom:6px;}
  .appt-block{border-radius:10px;padding:9px 13px;cursor:pointer;transition:all 0.18s;border-left-width:4px;border-left-style:solid;}
  .appt-block{background:var(--rose-pale);border-color:var(--rose-light);border-left-color:var(--rose);border:1.5px solid var(--rose-light);border-left:4px solid var(--rose);}
  .appt-block:hover{transform:translateX(2px);}
  .appt-block.alt{background:var(--teal-pale);border:1.5px solid var(--teal-light);border-left:4px solid var(--teal);}
  .appt-block.alt2{background:var(--lav-pale);border:1.5px solid var(--lav-light);border-left:4px solid var(--lavender);}
  .appt-name{font-size:13px;color:var(--navy);font-weight:700;margin-bottom:2px;}
  .appt-service{font-size:11px;color:var(--text-mid);}
  .appt-actions{display:flex;gap:5px;margin-top:6px;}
  .slot-empty{font-size:11px;color:var(--text-dim);padding-top:7px;font-style:italic;}

  /* NAV FECHA */
  .fecha-nav{display:flex;align-items:center;gap:8px;}
  .fecha-nav input[type=date]{background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:5px 10px;font-family:'Nunito',sans-serif;font-size:12px;font-weight:600;color:var(--navy);outline:none;}
  .fecha-nav input[type=date]:focus{border-color:var(--rose-light);}
  .nav-arrow{width:28px;height:28px;border-radius:50%;background:var(--rose-pale);border:1.5px solid var(--rose-light);color:var(--rose-deep);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;text-decoration:none;transition:background 0.2s;}
  .nav-arrow:hover{background:var(--rose-light);}

  /* FORM */
  .form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:13px;}
  .form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  label{font-size:10px;letter-spacing:0.1em;text-transform:uppercase;color:var(--text-dim);font-weight:700;}
  .form-input{background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;color:var(--navy);font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:600;outline:none;transition:border-color 0.2s;width:100%;}
  .form-input:focus{border-color:var(--rose-light);box-shadow:0 0 0 3px rgba(232,120,154,0.10);}
  textarea.form-input{resize:vertical;min-height:60px;}
  .form-body{padding:18px;}
  .form-footer{padding:14px 18px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;background:var(--surface2);}

  /* TABLE */
  table{width:100%;border-collapse:collapse;}
  th{text-align:left;padding:11px 20px;font-size:10px;letter-spacing:0.14em;color:var(--text-dim);text-transform:uppercase;border-bottom:1.5px solid var(--border);font-weight:700;background:#fdf9fb;}
  td{padding:12px 20px;border-bottom:1px solid var(--border);font-size:12.5px;color:var(--text-mid);vertical-align:middle;}
  tr:last-child td{border-bottom:none;}
  tr:hover td{background:var(--rose-pale);}
  .client-cell{display:flex;align-items:center;gap:10px;}
  .client-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:16px;font-weight:700;flex-shrink:0;}
  .av-a{background:var(--rose-pale);color:var(--rose-deep)}.av-b{background:var(--teal-pale);color:var(--teal-deep)}.av-c{background:var(--lav-pale);color:var(--lavender)}.av-d{background:var(--navy-soft);color:var(--navy-mid)}.av-e{background:#fef3e2;color:#b07a30}
  .client-name{font-size:13px;color:var(--navy);font-weight:700;}
  .client-phone{font-size:10px;color:var(--text-dim);}

  /* STATUS PILLS */
  .status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;}
  .status-pill::before{content:'';width:5px;height:5px;border-radius:50%;flex-shrink:0;}
  .pill-confirmada{background:var(--teal-pale);color:var(--teal-deep)}.pill-confirmada::before{background:var(--teal-deep)}
  .pill-pendiente{background:#fff4e0;color:#b07a30}.pill-pendiente::before{background:#c9943a}
  .pill-cancelada{background:var(--rose-pale);color:var(--rose-deep)}.pill-cancelada::before{background:var(--rose-deep)}
  .pill-realizada{background:#e8f8f4;color:#2a9d73}.pill-realizada::before{background:#2a9d73}

  /* FILTROS */
  .filtros{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .filtro-btn{padding:5px 14px;border-radius:20px;font-size:11px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);cursor:pointer;text-decoration:none;transition:all 0.2s;}
  .filtro-btn:hover,.filtro-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}

  /* PAGINACIÓN */
  .pagination{display:flex;gap:6px;justify-content:center;}
  .page-btn{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);text-decoration:none;transition:all 0.2s;}
  .page-btn:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .page-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}

  /* MODAL */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(26,31,60,0.45);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
  .modal-overlay.open{display:flex;}
  .modal{background:var(--surface);border-radius:20px;width:100%;max-width:480px;box-shadow:var(--shadow-md);animation:fadeUp 0.3s ease both;max-height:92vh;overflow-y:auto;}
  .modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;background:var(--surface2);border-radius:20px 20px 0 0;}
  .modal-title{font-family:'Dancing Script',cursive;font-size:22px;font-weight:700;color:var(--navy);flex:1;}
  .modal-close{width:28px;height:28px;border-radius:50%;background:var(--rose-pale);border:none;color:var(--rose-deep);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;font-weight:700;}
  .modal-body{padding:20px 24px;}
  .modal-footer{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--surface2);border-radius:0 0 20px 20px;}

  .alert{padding:10px 16px;border-radius:10px;font-size:12px;font-weight:600;}
  .alert-success{background:var(--teal-pale);color:var(--teal-deep);border:1.5px solid var(--teal-light);}
  .alert-error{background:var(--rose-pale);color:var(--rose-deep);border:1.5px solid var(--rose-light);}
  .empty-row{text-align:center;padding:40px;color:var(--text-dim);font-size:13px;}

  @keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<?php require_once '../includes/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <span class="page-title">Citas</span>
    <div class="topbar-sep"></div>
    <button class="btn btn-primary" onclick="abrirModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nueva Cita
    </button>
  </div>

  <div class="content">

    <?php if ($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

    <div class="citas-grid">

      <!-- AGENDA DEL DÍA -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Agenda del Día</div>
          <div class="fecha-nav">
            <?php
              $ayer    = (clone $fechaDt)->modify('-1 day')->format('Y-m-d');
              $manana  = (clone $fechaDt)->modify('+1 day')->format('Y-m-d');
            ?>
            <a href="?fecha=<?= $ayer ?>" class="nav-arrow">‹</a>
            <form method="GET" action="">
              <input type="date" name="fecha" value="<?= $fechaFiltro ?>" onchange="this.form.submit()">
            </form>
            <a href="?fecha=<?= $manana ?>" class="nav-arrow">›</a>
          </div>
          <span class="card-tag"><?= $fechaDt->format('d') . ' ' . $mesesNombres[$fechaDt->format('n')-1] ?></span>
        </div>
        <div class="time-slots">
          <?php
          // Agrupar citas por hora
          $citasPorHora = [];
          while ($ca = $agendaDia->fetch_assoc()) {
              $horaKey = substr($ca['hora'], 0, 5);
              $citasPorHora[$horaKey][] = $ca;
          }
          $colorIdx = 0;
          foreach ($horasAgenda as $hora):
              $citasEnHora = $citasPorHora[$hora] ?? [];
          ?>
          <div class="time-slot">
            <div class="slot-time"><?= $hora ?></div>
            <div class="slot-line"></div>
            <div class="slot-content">
              <?php if (empty($citasEnHora)): ?>
                <div class="slot-empty"></div>
              <?php else: foreach ($citasEnHora as $ca):
                $colorCls = $coloresBloque[$colorIdx % 3]; $colorIdx++;
              ?>
                <div class="appt-block <?= $colorCls ?>" onclick="editarCita(<?= htmlspecialchars(json_encode($ca)) ?>)">
                  <div class="appt-name"><?= htmlspecialchars($ca['nombre'] . ' ' . $ca['apellido']) ?></div>
                  <div class="appt-service"><?= htmlspecialchars($ca['servicio'] ?? 'Sin servicio') ?> · <?= formatoHora($ca['hora']) ?></div>
                  <div class="appt-actions">
                    <?php if ($ca['estado'] !== 'realizada'): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="accion" value="cambiar_estado">
                      <input type="hidden" name="id" value="<?= $ca['id'] ?>">
                      <input type="hidden" name="fecha" value="<?= $fechaFiltro ?>">
                      <input type="hidden" name="estado" value="realizada">
                      <button class="btn btn-ghost btn-sm" type="submit">✓ Realizada</button>
                    </form>
                    <?php endif; ?>
                    <span class="status-pill pill-<?= $ca['estado'] ?>"><?= ucfirst($ca['estado']) ?></span>
                  </div>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- FORM NUEVA CITA (lateral) -->
      <div class="card" style="align-self:start;">
        <div class="card-header"><div class="card-title">Nueva Cita</div></div>
        <form method="POST" action="">
          <input type="hidden" name="accion" value="guardar">
          <input type="hidden" name="id" value="0">
          <div class="form-body">
            <div class="form-group">
              <label>Cliente *</label>
              <select class="form-input" name="cliente_id" required>
                <option value="">Seleccionar cliente...</option>
                <?php
                $todosClientes->data_seek(0);
                while ($cl = $todosClientes->fetch_assoc()):
                ?>
                <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['nombre'] . ' ' . $cl['apellido']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Servicio / Combo</label>
              <select class="form-input" name="producto_id">
                <option value="">Sin servicio específico</option>
                <?php
                $todosProductos->data_seek(0);
                while ($pr = $todosProductos->fetch_assoc()):
                ?>
                <option value="<?= $pr['id'] ?>"><?= htmlspecialchars($pr['nombre']) ?> — <?= formatoPeso($pr['precio']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Fecha *</label>
                <input class="form-input" type="date" name="fecha" value="<?= $fechaFiltro ?>" required>
              </div>
              <div class="form-group">
                <label>Hora *</label>
                <input class="form-input" type="time" name="hora" value="09:00" required>
              </div>
            </div>
            <div class="form-group">
              <label>Duración (min)</label>
              <select class="form-input" name="duracion_min">
                <option value="60">1 hora</option>
                <option value="90">1.5 horas</option>
                <option value="120">2 horas</option>
                <option value="180">3 horas</option>
                <option value="240">4 horas</option>
              </select>
            </div>
            <div class="form-group">
              <label>Observaciones</label>
              <textarea class="form-input" name="observaciones" placeholder="Notas adicionales..."></textarea>
            </div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;border-radius:12px;">Agendar Cita</button>
          </div>
        </form>
      </div>

    </div><!-- /citas-grid -->

    <!-- LISTADO GENERAL -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Todas las Citas</div>
        <div class="filtros">
          <a href="citas.php" class="filtro-btn <?= !$estadoFiltro ? 'active' : '' ?>">Todas</a>
          <a href="?estado=pendiente" class="filtro-btn <?= $estadoFiltro==='pendiente' ? 'active' : '' ?>">Pendientes</a>
          <a href="?estado=confirmada" class="filtro-btn <?= $estadoFiltro==='confirmada' ? 'active' : '' ?>">Confirmadas</a>
          <a href="?estado=realizada" class="filtro-btn <?= $estadoFiltro==='realizada' ? 'active' : '' ?>">Realizadas</a>
          <a href="?estado=cancelada" class="filtro-btn <?= $estadoFiltro==='cancelada' ? 'active' : '' ?>">Canceladas</a>
        </div>
      </div>
      <table>
        <thead>
          <tr><th>Cliente</th><th>Servicio</th><th>Fecha</th><th>Hora</th><th>Estado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
          <?php if ($listadoCitas->num_rows === 0): ?>
            <tr><td colspan="6" class="empty-row">No hay citas con ese filtro.</td></tr>
          <?php else: $i = 0; while ($c = $listadoCitas->fetch_assoc()): ?>
          <tr>
            <td>
              <div class="client-cell">
                <div class="client-avatar <?= $avClasses[$i % 5] ?>"><?= mb_strtoupper(mb_substr($c['nombre'], 0, 1)) ?></div>
                <div>
                  <div class="client-name"><?= htmlspecialchars($c['nombre'] . ' ' . $c['apellido']) ?></div>
                  <div class="client-phone"><?= htmlspecialchars($c['telefono'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td><?= htmlspecialchars($c['servicio'] ?? '—') ?></td>
            <td><?= formatoFecha($c['fecha']) ?></td>
            <td><?= formatoHora($c['hora']) ?></td>
            <td><span class="status-pill pill-<?= $c['estado'] ?>"><?= ucfirst($c['estado']) ?></span></td>
            <td>
              <div style="display:flex;gap:6px;">
                <button class="btn btn-ghost btn-sm" onclick="editarCita(<?= htmlspecialchars(json_encode($c)) ?>)">Editar</button>
                <button class="btn btn-danger btn-sm" onclick="confirmarEliminar(<?= $c['id'] ?>, '<?= htmlspecialchars($c['nombre'] . ' ' . $c['apellido']) ?>')">Eliminar</button>
              </div>
            </td>
          </tr>
          <?php $i++; endwhile; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
    <div class="pagination">
      <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
        <a href="?p=<?= $p ?>&estado=<?= urlencode($estadoFiltro) ?>" class="page-btn <?= $p === $pagina ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<!-- MODAL EDITAR CITA -->
<div class="modal-overlay" id="modalCita">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Editar Cita</div>
      <button class="modal-close" onclick="cerrarModal()">✕</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="citaId">
      <div class="modal-body">
        <div class="form-group">
          <label>Cliente *</label>
          <select class="form-input" name="cliente_id" id="mClienteId" required>
            <?php $todosClientes->data_seek(0); while ($cl = $todosClientes->fetch_assoc()): ?>
            <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['nombre'] . ' ' . $cl['apellido']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Servicio / Combo</label>
          <select class="form-input" name="producto_id" id="mProductoId">
            <option value="">Sin servicio específico</option>
            <?php $todosProductos->data_seek(0); while ($pr = $todosProductos->fetch_assoc()): ?>
            <option value="<?= $pr['id'] ?>"><?= htmlspecialchars($pr['nombre']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Fecha *</label>
            <input class="form-input" type="date" name="fecha" id="mFecha" required>
          </div>
          <div class="form-group">
            <label>Hora *</label>
            <input class="form-input" type="time" name="hora" id="mHora" required>
          </div>
        </div>
        <div class="form-group">
          <label>Estado</label>
          <select class="form-input" name="estado" id="mEstado">
            <option value="pendiente">Pendiente</option>
            <option value="confirmada">Confirmada</option>
            <option value="realizada">Realizada</option>
            <option value="cancelada">Cancelada</option>
          </select>
        </div>
        <div class="form-group">
          <label>Observaciones</label>
          <textarea class="form-input" name="observaciones" id="mObservaciones"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="cerrarModal()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
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
function abrirModal() {
  document.getElementById('modalCita').classList.add('open');
}
function cerrarModal() {
  document.getElementById('modalCita').classList.remove('open');
}
function editarCita(c) {
  document.getElementById('citaId').value       = c.id;
  document.getElementById('mClienteId').value   = c.cliente_id;
  document.getElementById('mProductoId').value  = c.producto_id || '';
  document.getElementById('mFecha').value        = c.fecha;
  document.getElementById('mHora').value         = c.hora ? c.hora.substring(0,5) : '';
  document.getElementById('mEstado').value       = c.estado;
  document.getElementById('mObservaciones').value= c.observaciones || '';
  document.getElementById('modalCita').classList.add('open');
}
function confirmarEliminar(id, nombre) {
  if (confirm('¿Eliminar la cita de ' + nombre + '?')) {
    document.getElementById('eliminarId').value = id;
    document.getElementById('formEliminar').submit();
  }
}
document.getElementById('modalCita').addEventListener('click', function(e) {
  if (e.target === this) cerrarModal();
});
</script>

</body>
</html>