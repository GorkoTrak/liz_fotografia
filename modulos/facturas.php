<?php
require_once '../config.php';
date_default_timezone_set('America/Bogota');
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
        $id               = sanitizeInt($_POST['id'] ?? 0);
        $sesion_id        = sanitizeInt($_POST['sesion_id'] ?? 0);
        $cliente_id       = sanitizeInt($_POST['cliente_id'] ?? 0);
        $total            = floatval($_POST['total'] ?? 0);
        $abono            = floatval($_POST['abono'] ?? 0);
        $metodo_pago      = sanitize($_POST['metodo_pago'] ?? 'efectivo');
        $estado           = sanitize($_POST['estado'] ?? 'pendiente');
        $fecha_vencimiento= sanitize($_POST['fecha_vencimiento'] ?? '');
        $notas            = sanitize($_POST['notas'] ?? '');

        if (!$cliente_id || $total <= 0) {
            $error = 'Cliente y total son obligatorios.';
        } elseif ($id === 0 && $sesion_id > 0) {
            // Verificar que la sesión no tenga ya una factura generada automáticamente
            $rChk = $db->query("SELECT id FROM facturas WHERE sesion_id=$sesion_id LIMIT 1");
            if ($rChk && $rChk->num_rows > 0) {
                $error = 'Esta sesión ya tiene una factura generada automáticamente. Edítala desde el listado.';
            }
        }
        if (!$error && (!$cliente_id || $total <= 0)) {
            $error = 'Cliente y total son obligatorios.';
        }
        if (!$error) {
            $fv = $fecha_vencimiento ?: null;
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE facturas SET sesion_id=?, cliente_id=?, total=?, abono=?, metodo_pago=?, estado=?, fecha_vencimiento=?, notas=? WHERE id=?");
                $stmt->bind_param("iddsssssi", $sesion_id, $cliente_id, $total, $abono, $metodo_pago, $estado, $fv, $notas, $id);
                $mensaje = 'Factura actualizada.';
            } else {
                // Generar número de factura
                $anio = date('Y');
                $r = $db->query("SELECT COUNT(*) as t FROM facturas WHERE YEAR(fecha_emision)=$anio");
                $num = $r->fetch_assoc()['t'] + 1;
                $numero_factura = 'FAC-' . $anio . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);

                $stmt = $db->prepare("INSERT INTO facturas (sesion_id, cliente_id, numero_factura, total, abono, metodo_pago, estado, fecha_vencimiento, notas) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("iisddssss", $sesion_id, $cliente_id, $numero_factura, $total, $abono, $metodo_pago, $estado, $fv, $notas);
                $mensaje = 'Factura creada correctamente.';
            }
            $stmt->execute();
            $stmt->close();

            // Registrar ingreso si hay abono
            if ($abono > 0 && $id === 0) {
                $r2 = $db->query("SELECT MAX(id) as last FROM facturas");
                $factura_id_nuevo = $r2->fetch_assoc()['last'];
                $concepto = "Abono factura $numero_factura";
                $fecha_hoy = date('Y-m-d');
                $stmtI = $db->prepare("INSERT INTO ingresos (factura_id, cliente_id, concepto, monto, tipo, metodo_pago, fecha) VALUES (?,?,?,?,'ingreso',?,?)");
                $stmtI->bind_param("iisdss", $factura_id_nuevo, $cliente_id, $concepto, $abono, $metodo_pago, $fecha_hoy);
                $stmtI->execute();
                $stmtI->close();
            }
        }
    }

    if ($accion === 'registrar_pago') {
        $id          = sanitizeInt($_POST['id'] ?? 0);
        $monto       = floatval($_POST['monto'] ?? 0);
        $metodo_pago = sanitize($_POST['metodo_pago'] ?? 'efectivo');
        $cliente_id  = sanitizeInt($_POST['cliente_id'] ?? 0);
        $num_factura = sanitize($_POST['numero_factura'] ?? '');

        if ($id && $monto > 0) {
            // Actualizar abono y estado en factura
            $stmt = $db->prepare("UPDATE facturas SET abono = abono + ?, metodo_pago = ?, estado = IF((abono + ?) >= total, 'pagada', IF(abono + ? > 0, 'abonada', estado)) WHERE id = ?");
            $stmt->bind_param("dsddi", $monto, $metodo_pago, $monto, $monto, $id);
            $stmt->execute();
            $stmt->close();

            // Sincronizar sesión vinculada con los nuevos valores de la factura
            $rFac = $db->query("SELECT sesion_id, abono, total, estado FROM facturas WHERE id=$id");
            if ($rFac && $rowFac = $rFac->fetch_assoc()) {
                $sid = (int)$rowFac['sesion_id'];
                if ($sid > 0) {
                    $nuevoAbono  = (float)$rowFac['abono'];
                    $nuevoTotal  = (float)$rowFac['total'];
                    $estadoPago  = ($nuevoAbono >= $nuevoTotal) ? 'pagado' : ($nuevoAbono > 0 ? 'abonado' : 'pendiente');
                    $sSync = $db->prepare("UPDATE sesiones SET abono=?, estado_pago=?, metodo_pago=? WHERE id=?");
                    $sSync->bind_param("dssi", $nuevoAbono, $estadoPago, $metodo_pago, $sid);
                    $sSync->execute(); $sSync->close();
                }
            }

            // Registrar en ingresos
            $concepto  = "Pago factura $num_factura";
            $fecha_hoy = date('Y-m-d');
            $stmtI = $db->prepare("INSERT INTO ingresos (factura_id, cliente_id, concepto, monto, tipo, metodo_pago, fecha) VALUES (?,?,?,?,'ingreso',?,?)");
            $stmtI->bind_param("iisdss", $id, $cliente_id, $concepto, $monto, $metodo_pago, $fecha_hoy);
            $stmtI->execute();
            $stmtI->close();
            $mensaje = 'Pago registrado correctamente.';
        }
    }

    if ($accion === 'eliminar') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            // Eliminar ingresos asociados a esta factura
            $sDel = $db->prepare("DELETE FROM ingresos WHERE factura_id=?");
            $sDel->bind_param("i", $id);
            $sDel->execute(); $sDel->close();
            // Eliminar la factura
            $stmt = $db->prepare("DELETE FROM facturas WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $mensaje = 'Factura eliminada.';
        }
    }
}

// ============================================
// FILTROS Y LISTADO
// ============================================
$filtroEstado = sanitize($_GET['estado'] ?? '');
$pagina       = max(1, sanitizeInt($_GET['p'] ?? 1));
$porPagina    = 10;
$offset       = ($pagina - 1) * $porPagina;

$where  = "WHERE 1=1";
$params = [];
$tipos  = '';

if ($filtroEstado) {
    $where   .= " AND f.estado = ?";
    $params[] = $filtroEstado;
    $tipos   .= 's';
}

$sqlCount = "SELECT COUNT(*) as total FROM facturas f $where";
$stmtC = $db->prepare($sqlCount);
if ($params) $stmtC->bind_param($tipos, ...$params);
$stmtC->execute();
$totalRegistros = $stmtC->get_result()->fetch_assoc()['total'];
$totalPaginas   = ceil($totalRegistros / $porPagina);
$stmtC->close();

$sqlF = "
    SELECT f.*, cl.nombre, cl.apellido, cl.telefono, cl.email,
           s.fecha_sesion, p.nombre AS servicio
    FROM facturas f
    JOIN clientes cl ON f.cliente_id = cl.id
    LEFT JOIN sesiones s ON f.sesion_id = s.id
    LEFT JOIN productos p ON s.producto_id = p.id
    $where
    ORDER BY f.fecha_emision DESC
    LIMIT ? OFFSET ?
";
$tiposL  = $tipos . 'ii';
$paramsL = array_merge($params, [$porPagina, $offset]);
$stmtL   = $db->prepare($sqlF);
$stmtL->bind_param($tiposL, ...$paramsL);
$stmtL->execute();
$facturas = $stmtL->get_result();
$stmtL->close();

// Stats
$r = $db->query("SELECT COUNT(*) as t, COALESCE(SUM(total),0) as s FROM facturas WHERE estado IN ('pendiente','abonada')");
$rp = $r->fetch_assoc(); $cPend = $rp['t']; $sPend = $rp['s'];
$r = $db->query("SELECT COUNT(*) as t, COALESCE(SUM(total),0) as s FROM facturas WHERE estado='pagada'");
$rp2 = $r->fetch_assoc(); $cPag = $rp2['t']; $sPag = $rp2['s'];
$r = $db->query("SELECT COALESCE(SUM(saldo),0) as s FROM facturas WHERE estado!='pagada'");
$saldoPend = $r->fetch_assoc()['s'];
$r = $db->query("SELECT COUNT(*) as t FROM facturas");
$totalFacturas = $r->fetch_assoc()['t'];

// Para selects
$todosClientes = $db->query("SELECT id, nombre, apellido FROM clientes ORDER BY nombre ASC");
$todasSesiones = $db->query("
    SELECT s.id, s.cliente_id, s.fecha_sesion, s.total, cl.nombre, cl.apellido, p.nombre AS servicio
    FROM sesiones s
    JOIN clientes cl ON s.cliente_id = cl.id
    LEFT JOIN productos p ON s.producto_id = p.id
    ORDER BY s.fecha_sesion DESC LIMIT 50
");

$metodosLabel = ['efectivo'=>'Efectivo','transferencia'=>'Transferencia','nequi'=>'Nequi','daviplata'=>'Daviplata','tarjeta'=>'Tarjeta'];
$avClasses = ['av-a','av-b','av-c','av-d','av-e'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Facturas – <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;600;700&family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#faf8f6;--surface:#fff;--surface2:#fdf5f8;--navy:#1a1f3c;--navy-mid:#2d3460;--navy-soft:#e8eaf6;--rose:#e8789a;--rose-light:#f5b8ce;--rose-pale:#fce8f0;--rose-deep:#c4547a;--teal:#5bbcb8;--teal-light:#9ddbd8;--teal-pale:#e0f5f4;--teal-deep:#3a9994;--lavender:#9e8bc9;--lav-light:#c5b8e8;--lav-pale:#f0ecfb;--text:#2a2040;--text-mid:#6b5e7a;--text-dim:#a899b5;--border:#ede0ea;--sidebar-w:230px;--shadow-sm:0 2px 8px rgba(180,120,160,0.10);--shadow-md:0 4px 20px rgba(180,120,160,0.15);}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;font-size:13px;}

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
  .nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,0.55);transition:all 0.2s;border-left:3px solid transparent;font-size:12.5px;font-weight:600;text-decoration:none;}
  .nav-item:hover{color:rgba(255,255,255,0.9);background:rgba(255,255,255,0.05);}
  .nav-item.active{color:#fff;border-left-color:var(--rose-light);background:rgba(232,120,154,0.15);}
  .nav-item svg{width:16px;height:16px;flex-shrink:0;}
  .badge{margin-left:auto;background:var(--rose);color:#fff;border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;}
  .sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,0.08);position:relative;z-index:1;}
  .user-chip{display:flex;align-items:center;gap:10px;}
  .avatar-sm{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:18px;color:var(--navy);font-weight:700;flex-shrink:0;overflow:hidden;}
  .avatar-sm img{width:100%;height:100%;object-fit:cover;}
  .badge.teal{background:var(--teal-deep);}
  .user-name{font-size:12px;color:#fff;font-weight:600;}
  .user-role{font-size:10px;color:var(--teal-light);}
  .logout-btn{display:block;margin-top:10px;font-size:11px;color:rgba(255,255,255,0.35);text-decoration:none;transition:color 0.2s;}
  .logout-btn:hover{color:var(--rose-light);}

  .main{flex:1;display:flex;flex-direction:column;overflow:hidden;}
  .topbar{height:58px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 26px;gap:14px;flex-shrink:0;box-shadow:var(--shadow-sm);}
  .page-title{font-family:'Dancing Script',cursive;font-size:26px;font-weight:700;color:var(--navy);}
  .topbar-sep{flex:1;}

  .btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:20px;font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:700;cursor:pointer;border:none;transition:all 0.2s;text-decoration:none;}
  .btn-primary{background:linear-gradient(135deg,var(--rose),var(--rose-deep));color:#fff;box-shadow:0 4px 14px rgba(196,84,122,0.3);}
  .btn-primary:hover{transform:translateY(-1px);}
  .btn-teal{background:linear-gradient(135deg,var(--teal),var(--teal-deep));color:#fff;}
  .btn-ghost{background:transparent;color:var(--text-mid);border:1.5px solid var(--border);}
  .btn-ghost:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .btn-danger{background:transparent;color:var(--rose-deep);border:1.5px solid var(--rose-light);}
  .btn-danger:hover{background:var(--rose-pale);}
  .btn-pdf{background:transparent;color:var(--lavender);border:1.5px solid var(--lav-light);}
  .btn-pdf:hover{background:var(--lav-pale);color:var(--lavender);}
  .btn-sm{padding:4px 12px;font-size:11px;border-radius:14px;}
  .btn svg{width:14px;height:14px;}

  .content{flex:1;overflow-y:auto;padding:22px 26px;display:flex;flex-direction:column;gap:16px;scrollbar-width:thin;scrollbar-color:var(--border) transparent;}
  .content::-webkit-scrollbar{width:4px;}
  .content::-webkit-scrollbar-thumb{background:var(--rose-light);border-radius:2px;}

  /* STATS */
  .stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
  .stat-card{background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:16px 18px;box-shadow:var(--shadow-sm);position:relative;overflow:hidden;}
  .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:14px 14px 0 0;}
  .sc1::before{background:linear-gradient(90deg,var(--rose),var(--rose-light))}
  .sc2::before{background:linear-gradient(90deg,#f0a500,#f5c842)}
  .sc3::before{background:linear-gradient(90deg,var(--teal),var(--teal-light))}
  .sc4::before{background:linear-gradient(90deg,var(--lavender),var(--lav-light))}
  .stat-label{font-size:10px;letter-spacing:0.1em;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-bottom:6px;}
  .stat-value{font-family:'Dancing Script',cursive;font-size:28px;font-weight:700;color:var(--navy);line-height:1;}
  .stat-sub{font-size:11px;color:var(--text-dim);font-weight:600;margin-top:4px;}

  /* CARD / TABLE */
  .card{background:var(--surface);border:1.5px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);}
  .card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--surface2);flex-wrap:wrap;}
  .card-title{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--navy);flex:1;}

  table{width:100%;border-collapse:collapse;}
  th{text-align:left;padding:11px 20px;font-size:10px;letter-spacing:0.14em;color:var(--text-dim);text-transform:uppercase;border-bottom:1.5px solid var(--border);font-weight:700;background:#fdf9fb;}
  td{padding:12px 20px;border-bottom:1px solid var(--border);font-size:12.5px;color:var(--text-mid);vertical-align:middle;}
  tr:last-child td{border-bottom:none;}
  tr:hover td{background:var(--rose-pale);}

  .client-cell{display:flex;align-items:center;gap:10px;}
  .client-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:16px;font-weight:700;flex-shrink:0;}
  .av-a{background:var(--rose-pale);color:var(--rose-deep)}.av-b{background:var(--teal-pale);color:var(--teal-deep)}.av-c{background:var(--lav-pale);color:var(--lavender)}.av-d{background:var(--navy-soft);color:var(--navy-mid)}.av-e{background:#fef3e2;color:#b07a30}
  .client-name{font-size:13px;color:var(--navy);font-weight:700;}
  .client-sub{font-size:10px;color:var(--text-dim);}

  .num-factura{font-size:11px;font-weight:700;color:var(--navy-mid);background:var(--navy-soft);padding:3px 8px;border-radius:6px;}

  .monto-total{font-family:'Dancing Script',cursive;font-size:18px;color:var(--navy);font-weight:700;}
  .monto-saldo{font-size:11px;font-weight:700;}
  .saldo-ok{color:var(--teal-deep);}
  .saldo-deuda{color:var(--rose-deep);}

  .pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;}
  .pill::before{content:'';width:5px;height:5px;border-radius:50%;}
  .pill-pagada{background:#e8f8f4;color:#2a9d73}.pill-pagada::before{background:#2a9d73}
  .pill-pendiente{background:var(--rose-pale);color:var(--rose-deep)}.pill-pendiente::before{background:var(--rose-deep)}
  .pill-abonada{background:#fff4e0;color:#b07a30}.pill-abonada::before{background:#c9943a}
  .pill-cancelada{background:var(--navy-soft);color:var(--text-dim)}.pill-cancelada::before{background:var(--text-dim)}

  .metodo-badge{font-size:10px;font-weight:700;color:var(--teal-deep);background:var(--teal-pale);padding:2px 8px;border-radius:20px;}

  .filtros{display:flex;gap:6px;flex-wrap:wrap;}
  .filtro-btn{padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);text-decoration:none;transition:all 0.2s;}
  .filtro-btn:hover,.filtro-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}

  .pagination{display:flex;gap:6px;justify-content:center;}
  .page-btn{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);text-decoration:none;transition:all 0.2s;}
  .page-btn:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .page-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}

  /* MODAL */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(26,31,60,0.45);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
  .modal-overlay.open{display:flex;}
  .modal{background:var(--surface);border-radius:20px;width:100%;max-width:500px;box-shadow:var(--shadow-md);animation:fadeUp 0.3s ease both;max-height:92vh;overflow-y:auto;}
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
  textarea.form-input{resize:vertical;min-height:60px;}
  .divider{border:none;border-top:1.5px dashed var(--border);margin:6px 0 14px;}
  .cliente-search-wrapper{position:relative;}
.cliente-search-input-row{position:relative;display:flex;align-items:center;}
.cliente-search-input-row .search-icon{position:absolute;left:12px;color:var(--text-dim,#a0a0b0);pointer-events:none;}
.cliente-search-input{padding-left:36px!important;width:100%;}
.cliente-dropdown{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--surface,#fff);border:1.5px solid var(--border,#e0e0e0);border-radius:10px;max-height:200px;overflow-y:auto;z-index:999;box-shadow:0 4px 16px rgba(0,0,0,.12);}
.cliente-dropdown.open{display:block;}
.cliente-option{padding:9px 14px;cursor:pointer;font-size:12.5px;font-weight:600;transition:background .15s;}
.cliente-option:hover{background:var(--rose-pale,#fce8f0);color:var(--rose-deep,#c4547a);}
.cliente-option.hidden{display:none;}

  /* PANEL PAGO */
  .pago-panel{background:linear-gradient(135deg,var(--rose-pale),var(--teal-pale));border:1.5px solid var(--rose-light);border-radius:14px;padding:16px 18px;margin-bottom:14px;}
  .pago-panel-title{font-size:11px;font-weight:700;color:var(--rose-deep);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:10px;}
  .saldo-display{font-family:'Dancing Script',cursive;font-size:32px;color:var(--rose-deep);font-weight:700;margin-bottom:4px;}
  .saldo-label{font-size:11px;color:var(--text-mid);font-weight:600;}

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
    <span class="page-title">Facturas</span>
    <div class="topbar-sep"></div>
    <button class="btn btn-primary" onclick="abrirModalNueva()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nueva Factura
    </button>
  </div>

  <div class="content">

    <?php if ($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
      <div class="stat-card sc1">
        <div class="stat-label">Pendientes</div>
        <div class="stat-value"><?= $cPend ?></div>
        <div class="stat-sub"><?= formatoPeso($sPend) ?> por cobrar</div>
      </div>
      <div class="stat-card sc2">
        <div class="stat-label">Saldo total</div>
        <div class="stat-value" style="font-size:20px;"><?= formatoPeso($saldoPend) ?></div>
        <div class="stat-sub">en deuda</div>
      </div>
      <div class="stat-card sc3">
        <div class="stat-label">Pagadas</div>
        <div class="stat-value"><?= $cPag ?></div>
        <div class="stat-sub"><?= formatoPeso($sPag) ?> cobrado</div>
      </div>
      <div class="stat-card sc4">
        <div class="stat-label">Total facturas</div>
        <div class="stat-value"><?= $totalFacturas ?></div>
        <div class="stat-sub">emitidas</div>
      </div>
    </div>

    <!-- TABLA -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Registro de Facturas</div>
        <div class="filtros">
          <a href="facturas.php" class="filtro-btn <?= !$filtroEstado ? 'active' : '' ?>">Todas</a>
          <a href="?estado=pendiente" class="filtro-btn <?= $filtroEstado==='pendiente' ? 'active' : '' ?>">Pendientes</a>
          <a href="?estado=abonada" class="filtro-btn <?= $filtroEstado==='abonada' ? 'active' : '' ?>">Abonadas</a>
          <a href="?estado=pagada" class="filtro-btn <?= $filtroEstado==='pagada' ? 'active' : '' ?>">Pagadas</a>
          <a href="?estado=cancelada" class="filtro-btn <?= $filtroEstado==='cancelada' ? 'active' : '' ?>">Canceladas</a>
        </div>
      </div>
      <table>
        <thead>
          <tr><th># Factura</th><th>Cliente</th><th>Servicio</th><th>Total / Saldo</th><th>Método</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr>
        </thead>
        <tbody>
          <?php if ($facturas->num_rows === 0): ?>
            <tr><td colspan="8" class="empty-row">No hay facturas con ese filtro.</td></tr>
          <?php else: $i = 0; while ($f = $facturas->fetch_assoc()): ?>
          <tr>
            <td><span class="num-factura"><?= htmlspecialchars($f['numero_factura']) ?></span></td>
            <td>
              <div class="client-cell">
                <div class="client-avatar <?= $avClasses[$i%5] ?>"><?= mb_strtoupper(mb_substr($f['nombre'],0,1)) ?></div>
                <div>
                  <div class="client-name"><?= htmlspecialchars($f['nombre'].' '.$f['apellido']) ?></div>
                  <div class="client-sub"><?= htmlspecialchars($f['telefono'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td><?= htmlspecialchars($f['servicio'] ?? '—') ?></td>
            <td>
              <div class="monto-total"><?= formatoPeso($f['total']) ?></div>
              <?php if ($f['saldo'] > 0): ?>
                <div class="monto-saldo saldo-deuda">Saldo: <?= formatoPeso($f['saldo']) ?></div>
              <?php else: ?>
                <div class="monto-saldo saldo-ok">✓ Cobrado</div>
              <?php endif; ?>
            </td>
            <td><span class="metodo-badge"><?= $metodosLabel[$f['metodo_pago']] ?? ucfirst($f['metodo_pago']) ?></span></td>
            <td><span class="pill pill-<?= $f['estado'] ?>"><?= ucfirst($f['estado']) ?></span></td>
            <td style="font-size:11px;"><?= formatoFecha($f['fecha_emision']) ?></td>
            <td>
              <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <?php if ($f['estado'] !== 'pagada' && $f['saldo'] > 0): ?>
                  <button class="btn btn-teal btn-sm" onclick="abrirPago(<?= htmlspecialchars(json_encode($f)) ?>)">💳 Pago</button>
                <?php endif; ?>
                <a class="btn btn-pdf btn-sm" href="factura_pdf.php?id=<?= $f['id'] ?>" target="_blank" title="Descargar PDF">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                  PDF
                </a>
                <button class="btn btn-ghost btn-sm" onclick="editarFactura(<?= htmlspecialchars(json_encode($f)) ?>)">Editar</button>
                <button class="btn btn-danger btn-sm" onclick="confirmarEliminar(<?= $f['id'] ?>, '<?= htmlspecialchars($f['numero_factura']) ?>')">Eliminar</button>
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
        <a href="?p=<?= $p ?>&estado=<?= urlencode($filtroEstado) ?>" class="page-btn <?= $p===$pagina?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- MODAL NUEVA / EDITAR FACTURA -->
<div class="modal-overlay" id="modalFactura">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitulo">Nueva Factura</div>
      <button class="modal-close" onclick="cerrarModal('modalFactura')">✕</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="facturaId" value="0">
      <div class="modal-body">
        <div class="form-group">
  <label>Cliente *</label>
  <div class="cliente-search-wrapper">
    <div class="cliente-search-input-row">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" class="search-icon"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" id="mClienteSearch" class="form-input cliente-search-input"
        placeholder="Buscar por nombre o apellido..."
        autocomplete="off"
        oninput="filtrarClientes('mClienteSearch','mClienteDropdown','mClienteId')"
        onfocus="mostrarDropdown('mClienteDropdown')">
    </div>
    <div class="cliente-dropdown" id="mClienteDropdown">
      <?php $todosClientes->data_seek(0); while ($cl = $todosClientes->fetch_assoc()): ?>
      <div class="cliente-option"
        data-id="<?= $cl['id'] ?>"
        data-nombre="<?= htmlspecialchars($cl['nombre'].' '.$cl['apellido']) ?>"
        onclick="seleccionarCliente(this,'mClienteSearch','mClienteDropdown','mClienteId')">
        <?= htmlspecialchars($cl['nombre'].' '.$cl['apellido']) ?>
      </div>
      <?php endwhile; ?>
    </div>
    <input type="hidden" name="cliente_id" id="mClienteId" required>
  </div>
</div>
        <div class="form-group">
  <label>Sesión vinculada</label>
  <select class="form-input" name="sesion_id" id="mSesionId">
    <option value="">Sin sesión específica</option>
    <?php $todasSesiones->data_seek(0); while ($se = $todasSesiones->fetch_assoc()): ?>
    <option value="<?= $se['id'] ?>" data-total="<?= $se['total'] ?>" data-cliente="<?= $se['cliente_id'] ?>">
      <?= formatoFecha($se['fecha_sesion']) ?> — <?= htmlspecialchars($se['nombre'].' '.$se['apellido']) ?> <?= $se['servicio'] ? '('.$se['servicio'].')' : '' ?>
    </option>
    <?php endwhile; ?>
  </select>
</div>
        <hr class="divider">
        <div class="form-row">
          <div class="form-group">
            <label>Total ($) *</label>
            <input class="form-input" type="number" name="total" id="mTotal" min="0" step="1000" placeholder="0" required>
          </div>
          <div class="form-group">
            <label>Abono inicial ($)</label>
            <input class="form-input" type="number" name="abono" id="mAbono" min="0" step="1000" placeholder="0">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Método de pago</label>
            <select class="form-input" name="metodo_pago" id="mMetodo">
              <option value="efectivo">Efectivo</option>
              <option value="transferencia">Transferencia</option>
              <option value="nequi">Nequi</option>
              <option value="daviplata">Daviplata</option>
              <option value="tarjeta">Tarjeta</option>
            </select>
          </div>
          <div class="form-group">
            <label>Estado</label>
            <select class="form-input" name="estado" id="mEstado">
              <option value="pendiente">Pendiente</option>
              <option value="abonada">Abonada</option>
              <option value="pagada">Pagada</option>
              <option value="cancelada">Cancelada</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Fecha vencimiento</label>
          <input class="form-input" type="date" name="fecha_vencimiento" id="mVencimiento">
        </div>
        <div class="form-group">
          <label>Notas</label>
          <textarea class="form-input" name="notas" id="mNotas" placeholder="Observaciones..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="cerrarModal('modalFactura')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar Factura</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL REGISTRAR PAGO -->
<div class="modal-overlay" id="modalPago">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header">
      <div class="modal-title">Registrar Pago</div>
      <button class="modal-close" onclick="cerrarModal('modalPago')">✕</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="accion" value="registrar_pago">
      <input type="hidden" name="id" id="pagoFacturaId">
      <input type="hidden" name="cliente_id" id="pagoClienteId">
      <input type="hidden" name="numero_factura" id="pagoNumFactura">
      <div class="modal-body">
        <div class="pago-panel">
          <div class="pago-panel-title">Saldo pendiente</div>
          <div class="saldo-display" id="pagoSaldoDisplay">$0</div>
          <div class="saldo-label" id="pagoSaldoLabel"></div>
        </div>
        <div class="form-group">
          <label>Monto a pagar ($) *</label>
          <input class="form-input" type="number" name="monto" id="pagoMonto" min="1" step="1" placeholder="0" required>
        </div>
        <div class="form-group">
          <label>Método de pago</label>
          <select class="form-input" name="metodo_pago" id="pagoMetodo">
            <option value="efectivo">Efectivo</option>
            <option value="transferencia">Transferencia</option>
            <option value="nequi">Nequi</option>
            <option value="daviplata">Daviplata</option>
            <option value="tarjeta">Tarjeta</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="cerrarModal('modalPago')">Cancelar</button>
        <button type="submit" class="btn btn-teal">✓ Confirmar Pago</button>
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

function filtrarClientes(inputId, dropdownId, hiddenId){
  const q=document.getElementById(inputId).value.toLowerCase();
  const dropdown=document.getElementById(dropdownId);
  dropdown.querySelectorAll('.cliente-option').forEach(op=>{
    op.classList.toggle('hidden', !op.dataset.nombre.toLowerCase().includes(q));
  });
  dropdown.classList.toggle('open', q===''||[...dropdown.querySelectorAll('.cliente-option:not(.hidden)')].length>0);
  if(q==='') document.getElementById(hiddenId).value='';
}
function mostrarDropdown(dropdownId){
  document.getElementById(dropdownId).classList.add('open');
}
function seleccionarCliente(el, inputId, dropdownId, hiddenId){
  document.getElementById(inputId).value = el.dataset.nombre;
  document.getElementById(hiddenId).value = el.dataset.id;
  document.getElementById(dropdownId).classList.remove('open');
  filtrarSesionesPorCliente(el.dataset.id);
}
document.addEventListener('click',function(e){
  document.querySelectorAll('.cliente-search-wrapper').forEach(w=>{
    if(!w.contains(e.target)) w.querySelector('.cliente-dropdown')?.classList.remove('open');
  });
});

function filtrarSesionesPorCliente(clienteId){
  const sel = document.getElementById('mSesionId');
  const valorActual = sel.value;
  Array.from(sel.options).forEach(op => {
    if(!op.value) return; // mantener "Sin sesión específica"
    const visible = !clienteId || op.dataset.cliente == clienteId;
    op.hidden = !visible;
    op.disabled = !visible;
  });
  // Si la sesión seleccionada ya no corresponde al cliente, resetear
  if(valorActual && sel.options[sel.selectedIndex]?.dataset.cliente != clienteId){
    sel.value = '';
  }
}

function cerrarModal(id) { document.getElementById(id).classList.remove('open'); }
function abrirModalNueva() {
  document.getElementById('modalTitulo').textContent = 'Nueva Factura';
  document.getElementById('facturaId').value = '0';
document.getElementById('mClienteSearch').value = '';
document.getElementById('mClienteId').value = '';  document.getElementById('mSesionId').value = '';
  document.getElementById('mTotal').value = '';
  document.getElementById('mAbono').value = '';
  document.getElementById('mMetodo').value = 'efectivo';
  document.getElementById('mEstado').value = 'pendiente';
  document.getElementById('mVencimiento').value = '';
  document.getElementById('mNotas').value = '';
  document.getElementById('modalFactura').classList.add('open');
  filtrarSesionesPorCliente('');

}
function editarFactura(f) {
  document.getElementById('modalTitulo').textContent = 'Editar Factura';
  document.getElementById('facturaId').value    = f.id;
  document.getElementById('mClienteSearch').value = (f.nombre||'') + ' ' + (f.apellido||'');
document.getElementById('mClienteId').value = f.cliente_id;
  document.getElementById('mSesionId').value    = f.sesion_id || '';
  document.getElementById('mTotal').value       = f.total;
  document.getElementById('mAbono').value       = f.abono;
  document.getElementById('mMetodo').value      = f.metodo_pago;
  document.getElementById('mEstado').value      = f.estado;
  document.getElementById('mVencimiento').value = f.fecha_vencimiento || '';
  document.getElementById('mNotas').value       = f.notas || '';
  document.getElementById('modalFactura').classList.add('open');
  filtrarSesionesPorCliente(f.cliente_id);

}
function abrirPago(f) {
  document.getElementById('pagoFacturaId').value  = f.id;
  document.getElementById('pagoClienteId').value  = f.cliente_id;
  document.getElementById('pagoNumFactura').value = f.numero_factura;
  document.getElementById('pagoMonto').value      = f.saldo;
  const fmt = new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',minimumFractionDigits:0});
  document.getElementById('pagoSaldoDisplay').textContent = fmt.format(f.saldo);
  document.getElementById('pagoSaldoLabel').textContent   = 'Total: ' + fmt.format(f.total) + ' · Abonado: ' + fmt.format(f.abono);
  document.getElementById('modalPago').classList.add('open');
}
function confirmarEliminar(id, num) {
  if (confirm('¿Eliminar factura ' + num + '?')) {
    document.getElementById('eliminarId').value = id;
    document.getElementById('formEliminar').submit();
  }
}

// Autocompletar total desde sesión
document.getElementById('mSesionId').addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  const total = opt.getAttribute('data-total');
  if (total) document.getElementById('mTotal').value = total;
});

['modalFactura','modalPago'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) cerrarModal(id);
  });
});

<?php if ($error): ?>
document.getElementById('modalFactura').classList.add('open');
<?php endif; ?>
</script>

</body>
</html>