<?php
require_once '../config.php';
date_default_timezone_set('America/Bogota');
requerirLogin();

$db = getDB();
$mensaje = '';
$error   = '';

if (isset($_GET['accion_galeria'])) {
    $sid = sanitizeInt($_GET['sesion_id'] ?? 0);
    $rows = [];

    if ($sid) {
        $r = $db->query("SELECT id, archivo, descripcion
                         FROM sesion_fotos
                         WHERE sesion_id=$sid
                         ORDER BY fecha_subida DESC");

        while ($row = $r->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

// ── Asegurar carpeta de uploads ──
$uploadDir = UPLOAD_DIR . 'sesiones/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ============================================
// ACCIONES POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ── Guardar sesión ──
    if ($accion === 'guardar') {
        $id             = sanitizeInt($_POST['id'] ?? 0);
        $cliente_id     = sanitizeInt($_POST['cliente_id'] ?? 0);
        $cita_id        = sanitizeInt($_POST['cita_id'] ?? 0) ?: null;
        $fecha_sesion   = sanitize($_POST['fecha_sesion'] ?? '');
        $total          = floatval($_POST['total'] ?? 0);
        $abono          = floatval($_POST['abono'] ?? 0);
        $estado_pago    = sanitize($_POST['estado_pago'] ?? 'pendiente');
        $estado_entrega = sanitize($_POST['estado_entrega'] ?? 'pendiente');
        $notas          = sanitize($_POST['notas'] ?? '');
        $metodo_pago    = sanitize($_POST['metodo_pago'] ?? 'efectivo');

        $servicios_ids = $_POST['producto_id'] ?? [];
        if (!is_array($servicios_ids)) $servicios_ids = [$servicios_ids];
        $servicios_ids = array_filter(array_map('intval', $servicios_ids));
        $producto_id_principal = !empty($servicios_ids) ? (int)$servicios_ids[0] : null;

        if (!$cliente_id || !$fecha_sesion) {
            $error = 'Cliente y fecha son obligatorios.';
        } else {
            if ($id > 0) {
                // Obtener estado anterior para detectar cambio a pagado
                $prev = $db->query("SELECT estado_pago, total, abono FROM sesiones WHERE id=$id")->fetch_assoc();

                $stmt = $db->prepare("UPDATE sesiones SET cliente_id=?,cita_id=?,producto_id=?,fecha_sesion=?,total=?,abono=?,estado_pago=?,estado_entrega=?,notas=?,metodo_pago=? WHERE id=?");
                $stmt->bind_param("iiisddssssi", $cliente_id,$cita_id,$producto_id_principal,$fecha_sesion,$total,$abono,$estado_pago,$estado_entrega,$notas,$metodo_pago,$id);
                $stmt->execute(); $stmt->close();

                // ── Actualizar factura vinculada automáticamente ──
                $estadoFac = ($total > 0 && $abono >= $total) ? 'pagada' : ($abono > 0 ? 'abonada' : 'pendiente');
                $sfac = $db->prepare("UPDATE facturas SET total=?, abono=?, metodo_pago=?, estado=? WHERE sesion_id=?");
                $sfac->bind_param("ddssi", $total, $abono, $metodo_pago, $estadoFac, $id);
                $sfac->execute(); $sfac->close();

                // Si cambió a pagado → registrar ingreso automático
                if ($prev['estado_pago'] !== 'pagado' && $estado_pago === 'pagado') {
                    $montoIngreso = $total - $prev['abono'];
                    if ($montoIngreso > 0) {
                        $concepto  = "Pago sesión – " . date('d/m/Y', strtotime($fecha_sesion));
                        $fecha_hoy = $fecha_sesion;
                        $si = $db->prepare("INSERT INTO ingresos (cliente_id, concepto, monto, tipo, metodo_pago, fecha) VALUES (?,?,?,'ingreso',?,?)");
                        $si->bind_param("isdss", $cliente_id, $concepto, $montoIngreso, $metodo_pago, $fecha_hoy);
                        $si->execute(); $si->close();
                    }
                }
                // Si el abono aumentó → registrar la diferencia en ingresos
                if ($prev['estado_pago'] !== 'pagado' && $abono > $prev['abono']) {
                    $diff = $abono - $prev['abono'];
                    $concepto  = "Abono sesión – " . date('d/m/Y', strtotime($fecha_sesion));
                    $fecha_hoy = $fecha_sesion;
                    $si = $db->prepare("INSERT INTO ingresos (cliente_id, concepto, monto, tipo, metodo_pago, fecha) VALUES (?,?,?,'ingreso',?,?)");
                    $si->bind_param("isdss", $cliente_id, $concepto, $diff, $metodo_pago, $fecha_hoy);
                    $si->execute(); $si->close();
                }
                $mensaje = 'Sesión actualizada correctamente.';
            } else {
                $stmt = $db->prepare("INSERT INTO sesiones (cliente_id,cita_id,producto_id,fecha_sesion,total,abono,estado_pago,estado_entrega,notas,metodo_pago) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("iiisddssss", $cliente_id,$cita_id,$producto_id_principal,$fecha_sesion,$total,$abono,$estado_pago,$estado_entrega,$notas,$metodo_pago);
                $stmt->execute();
                $nuevaSesionId = $stmt->insert_id;
                $stmt->close();

                // ── Generar factura automáticamente ──
                $anio = date('Y');
                $rNum = $db->query("SELECT COUNT(*) as t FROM facturas WHERE YEAR(fecha_emision)=$anio");
                $numFac = $rNum->fetch_assoc()['t'] + 1;
                $numero_factura = 'FAC-' . $anio . '-' . str_pad($numFac, 4, '0', STR_PAD_LEFT);
                $estadoFac = ($total > 0 && $abono >= $total) ? 'pagada' : ($abono > 0 ? 'abonada' : 'pendiente');
                $sfac = $db->prepare("INSERT INTO facturas (sesion_id, cliente_id, numero_factura, total, abono, metodo_pago, estado) VALUES (?,?,?,?,?,?,?)");
                $sfac->bind_param("iisddss", $nuevaSesionId, $cliente_id, $numero_factura, $total, $abono, $metodo_pago, $estadoFac);
                $sfac->execute(); $sfac->close();

                // Si se crea ya pagado, registrar ingreso
                if ($estado_pago === 'pagado' && $total > 0) {
                    $concepto  = "Pago sesión – " . date('d/m/Y', strtotime($fecha_sesion));
                    $fecha_hoy = $fecha_sesion;
                    $si = $db->prepare("INSERT INTO ingresos (cliente_id, concepto, monto, tipo, metodo_pago, fecha) VALUES (?,?,?,'ingreso',?,?)");
                    $si->bind_param("isdss", $cliente_id, $concepto, $total, $metodo_pago, $fecha_hoy);
                    $si->execute(); $si->close();
                } elseif ($abono > 0) {
                    $concepto  = "Abono sesión – " . date('d/m/Y', strtotime($fecha_sesion));
                    $fecha_hoy = $fecha_sesion;
                    $si = $db->prepare("INSERT INTO ingresos (cliente_id, concepto, monto, tipo, metodo_pago, fecha) VALUES (?,?,?,'ingreso',?,?)");
                    $si->bind_param("isdss", $cliente_id, $concepto, $abono, $metodo_pago, $fecha_hoy);
                    $si->execute(); $si->close();
                }
                $mensaje = 'Sesión registrada correctamente.';
            }
        }
    }

    // ── Actualizar entrega ──
    if ($accion === 'actualizar_entrega') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        $e  = sanitize($_POST['estado_entrega'] ?? '');
        if ($id && $e) {
            $s = $db->prepare("UPDATE sesiones SET estado_entrega=? WHERE id=?");
            $s->bind_param("si", $e, $id); $s->execute(); $s->close();
            $mensaje = 'Estado de entrega actualizado.';
        }
    }

    // ── Subir fotos ──
    if ($accion === 'subir_fotos') {
        $sesion_id = sanitizeInt($_POST['sesion_id'] ?? 0);
        $descripcion = sanitize($_POST['descripcion'] ?? '');
        if ($sesion_id && isset($_FILES['fotos'])) {
            $subidos = 0;
            $files = $_FILES['fotos'];
            $count = count($files['name']);
            for ($f = 0; $f < $count; $f++) {
                if ($files['error'][$f] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($files['name'][$f], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) continue;
                $nombre = 'ses_' . $sesion_id . '_' . time() . '_' . $f . '.' . $ext;
                $dest = $uploadDir . $nombre;
                if (move_uploaded_file($files['tmp_name'][$f], $dest)) {
                    $rel = 'sesiones/' . $nombre;
                    $st = $db->prepare("INSERT INTO sesion_fotos (sesion_id, archivo, descripcion) VALUES (?,?,?)");
                    $st->bind_param("iss", $sesion_id, $rel, $descripcion);
                    $st->execute(); $st->close();
                    $subidos++;
                }
            }
            $mensaje = "$subidos foto(s) subida(s) correctamente.";
        }
    }

    // ── Eliminar foto ──
    if ($accion === 'eliminar_foto') {
        $foto_id = sanitizeInt($_POST['foto_id'] ?? 0);
        if ($foto_id) {
            $r = $db->query("SELECT archivo FROM sesion_fotos WHERE id=$foto_id");
            if ($row = $r->fetch_assoc()) {
                $f = UPLOAD_DIR . $row['archivo'];
                if (file_exists($f)) unlink($f);
            }
            $db->query("DELETE FROM sesion_fotos WHERE id=$foto_id");
            $mensaje = 'Foto eliminada.';
        }
    }

    // ── Eliminar sesión ──
    if ($accion === 'eliminar') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            // Borrar fotos del disco
            $r = $db->query("SELECT archivo FROM sesion_fotos WHERE sesion_id=$id");
            while ($row = $r->fetch_assoc()) {
                $f = UPLOAD_DIR . $row['archivo'];
                if (file_exists($f)) unlink($f);
            }
            // Borrar ingresos asociados a esta sesión para que el panel de ingresos refleje el cambio
            $r2 = $db->query("SELECT cliente_id, fecha_sesion FROM sesiones WHERE id=$id");
            if ($rowS = $r2->fetch_assoc()) {
                $fechaFmt      = date('d/m/Y', strtotime($rowS['fecha_sesion']));
                $pagoConcepto  = "Pago sesión – $fechaFmt";
                $abonoConcepto = "Abono sesión – $fechaFmt";
                $cliId         = (int)$rowS['cliente_id'];
                $sI = $db->prepare("DELETE FROM ingresos WHERE cliente_id=? AND (concepto=? OR concepto=?)");
                $sI->bind_param("iss", $cliId, $pagoConcepto, $abonoConcepto);
                $sI->execute();
                $sI->close();
            }
            $s = $db->prepare("DELETE FROM sesiones WHERE id=?");
            $s->bind_param("i", $id); $s->execute(); $s->close();
            $mensaje = 'Sesión eliminada.';
        }
    }
}

// ── Calendario ──
$hoy   = new DateTime();
$anioC = sanitizeInt($_GET['anio'] ?? $hoy->format('Y'));
$mesC  = sanitizeInt($_GET['mes']  ?? $hoy->format('n'));
if ($mesC < 1)  { $mesC = 12; $anioC--; }
if ($mesC > 12) { $mesC = 1;  $anioC++; }
$primerDia = new DateTime("$anioC-$mesC-01");
$diasMes   = (int)$primerDia->format('t');
$diaSemana = (int)$primerDia->format('N');

$estadoFiltro = sanitize($_GET['estado'] ?? '');
$pagina       = max(1, sanitizeInt($_GET['p'] ?? 1));
$porPagina    = 12;
$offset       = ($pagina - 1) * $porPagina;

$fechaIni = "$anioC-$mesC-01";
$fechaFin = "$anioC-$mesC-$diasMes";
$stmtCal  = $db->prepare("SELECT s.*,cl.nombre,cl.apellido,p.nombre AS servicio FROM sesiones s JOIN clientes cl ON s.cliente_id=cl.id LEFT JOIN productos p ON s.producto_id=p.id WHERE s.fecha_sesion BETWEEN ? AND ? ORDER BY s.fecha_sesion,s.id");
$stmtCal->bind_param("ss", $fechaIni, $fechaFin);
$stmtCal->execute();
$sesionesCalendario = [];
$res = $stmtCal->get_result();
while ($row = $res->fetch_assoc()) {
    $d = (int)(new DateTime($row['fecha_sesion']))->format('j');
    $sesionesCalendario[$d][] = $row;
}
$stmtCal->close();

$diaSeleccionado = sanitizeInt($_GET['dia'] ?? $hoy->format('j'));
$sesionesDelDia  = $sesionesCalendario[$diaSeleccionado] ?? [];

// Stats
$r = $db->query("SELECT COUNT(*) as t FROM sesiones WHERE estado_pago!='pagado'");           $pagosPend    = $r->fetch_assoc()['t'];
$r = $db->query("SELECT COUNT(*) as t FROM sesiones WHERE estado_entrega!='entregado'");       $entregasPend = $r->fetch_assoc()['t'];
$r = $db->query("SELECT COALESCE(SUM(saldo),0) as t FROM sesiones WHERE estado_pago!='pagado'"); $saldoPend = $r->fetch_assoc()['t'];

// Listado
$where  = "WHERE 1=1"; $params = []; $tipos = '';
if ($estadoFiltro) { $where .= " AND s.estado_pago=?"; $params[] = $estadoFiltro; $tipos .= 's'; }
$stmtC = $db->prepare("SELECT COUNT(*) as total FROM sesiones s $where");
if ($params) $stmtC->bind_param($tipos, ...$params);
$stmtC->execute();
$totalRegistros = $stmtC->get_result()->fetch_assoc()['total'];
$totalPaginas   = ceil($totalRegistros / $porPagina);
$stmtC->close();

$stmtL = $db->prepare("SELECT s.*,cl.nombre,cl.apellido,cl.telefono,p.nombre AS servicio FROM sesiones s JOIN clientes cl ON s.cliente_id=cl.id LEFT JOIN productos p ON s.producto_id=p.id $where ORDER BY s.fecha_sesion DESC,s.id DESC LIMIT ? OFFSET ?");
$tiposL = $tipos.'ii'; $paramsL = array_merge($params, [$porPagina, $offset]);
$stmtL->bind_param($tiposL, ...$paramsL);
$stmtL->execute();
$listadoSesiones = $stmtL->get_result();
$stmtL->close();

// Sesión seleccionada para galería
$sesionGaleriaId = sanitizeInt($_GET['galeria'] ?? 0);
$fotosGaleria = [];
$sesionGaleriaInfo = null;
if ($sesionGaleriaId) {
    $rg = $db->query("SELECT sf.*, s.fecha_sesion, cl.nombre, cl.apellido FROM sesion_fotos sf JOIN sesiones s ON sf.sesion_id=s.id JOIN clientes cl ON s.cliente_id=cl.id WHERE sf.sesion_id=$sesionGaleriaId ORDER BY sf.fecha_subida DESC");
    while ($fg = $rg->fetch_assoc()) $fotosGaleria[] = $fg;
    $sg = $db->query("SELECT s.*, cl.nombre, cl.apellido FROM sesiones s JOIN clientes cl ON s.cliente_id=cl.id WHERE s.id=$sesionGaleriaId");
    $sesionGaleriaInfo = $sg->fetch_assoc();
}

$todosClientes  = $db->query("SELECT id,nombre,apellido,telefono FROM clientes ORDER BY nombre ASC");
$todosProductos = $db->query("SELECT id,nombre,precio FROM productos WHERE estado='activo' ORDER BY nombre ASC");
$mesesNombres   = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$diasNombres    = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
$avClasses      = ['av-a','av-b','av-c','av-d','av-e'];
$coloresBloque  = ['','alt','alt2'];
$mesPrev = $mesC-1; $anioPrev = $anioC; if($mesPrev<1){$mesPrev=12;$anioPrev--;}
$mesSig  = $mesC+1; $anioSig  = $anioC; if($mesSig>12){$mesSig=1;$anioSig++;}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sesiones – <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;600;700&family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#faf8f6;--surface:#fff;--surface2:#fdf5f8;--navy:#1a1f3c;--navy-mid:#2d3460;--navy-soft:#e8eaf6;--rose:#e8789a;--rose-light:#f5b8ce;--rose-pale:#fce8f0;--rose-deep:#c4547a;--teal:#5bbcb8;--teal-light:#9ddbd8;--teal-pale:#e0f5f4;--teal-deep:#3a9994;--lavender:#9e8bc9;--lav-light:#c5b8e8;--lav-pale:#f0ecfb;--text:#2a2040;--text-mid:#6b5e7a;--text-dim:#a899b5;--border:#ede0ea;--sidebar-w:230px;--shadow-sm:0 2px 8px rgba(180,120,160,.10);--shadow-md:0 4px 20px rgba(180,120,160,.15);}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;font-size:13px;}  .sidebar{width:var(--sidebar-w);background:var(--navy);display:flex;flex-direction:column;flex-shrink:0;height:100%;position:relative;overflow:hidden;}
  .sidebar::before{content:'';position:absolute;width:180px;height:180px;border-radius:50%;background:radial-gradient(circle,rgba(232,120,154,.18) 0%,transparent 70%);top:-40px;right:-50px;pointer-events:none;}
  .sidebar::after{content:'';position:absolute;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle,rgba(91,188,184,.15) 0%,transparent 70%);bottom:60px;left:-30px;pointer-events:none;}
  .logo-area{padding:24px 20px 20px;display:flex;flex-direction:column;align-items:center;border-bottom:1px solid rgba(255,255,255,.08);position:relative;z-index:1;}
  .logo-circle{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:34px;color:var(--navy);font-weight:700;margin-bottom:10px;box-shadow:0 4px 16px rgba(232,120,154,.35);overflow:hidden;}
  .logo-circle img{width:100%;height:100%;object-fit:cover;}
  .logo-name{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--rose-light);text-align:center;line-height:1.15;}
  .logo-sub{font-size:9px;color:var(--teal-light);letter-spacing:.22em;text-transform:uppercase;margin-top:3px;font-weight:600;}
  nav{padding:14px 0;flex:1;position:relative;z-index:1;overflow-y:auto;}
  .nav-section{padding:10px 20px 5px;font-size:9px;letter-spacing:.18em;color:rgba(255,255,255,.3);text-transform:uppercase;font-weight:600;}
  .nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,.55);cursor:pointer;transition:all .2s;border-left:3px solid transparent;font-size:12.5px;font-weight:600;text-decoration:none;}
  .nav-item:hover{color:rgba(255,255,255,.9);background:rgba(255,255,255,.05);}
  .nav-item.active{color:#fff;border-left-color:var(--rose-light);background:rgba(232,120,154,.15);}
  .nav-item svg{width:16px;height:16px;flex-shrink:0;}
  .badge{margin-left:auto;background:var(--rose);color:#fff;border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;}
  .badge.teal{background:var(--teal-deep);}
  .sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08);position:relative;z-index:1;}
  .user-chip{display:flex;align-items:center;gap:10px;}
  .avatar-sm{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:18px;color:var(--navy);font-weight:700;flex-shrink:0;overflow:hidden;}
  .avatar-sm img{width:100%;height:100%;object-fit:cover;}
  .user-name{font-size:12px;color:#fff;font-weight:600;}
  .user-role{font-size:10px;color:var(--teal-light);}
  .logout-btn{display:block;margin-top:10px;font-size:11px;color:rgba(255,255,255,.35);text-decoration:none;transition:color .2s;}
  .logout-btn:hover{color:var(--rose-light);}
.main{flex:1;display:flex;flex-direction:column;height:100%;min-width:0;overflow:hidden;}
  .topbar{height:58px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 26px;gap:14px;flex-shrink:0;box-shadow:var(--shadow-sm);}
  .page-title{font-family:'Dancing Script',cursive;font-size:26px;font-weight:700;color:var(--navy);}
  .topbar-sep{flex:1;}
  .btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:20px;font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:700;cursor:pointer;border:none;transition:all .2s;text-decoration:none;}
  .btn-primary{background:linear-gradient(135deg,var(--rose),var(--rose-deep));color:#fff;box-shadow:0 4px 14px rgba(196,84,122,.3);}
  .btn-primary:hover{transform:translateY(-1px);}
  .btn-ghost{background:transparent;color:var(--text-mid);border:1.5px solid var(--border);}
  .btn-ghost:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .btn-danger{background:transparent;color:var(--rose-deep);border:1.5px solid var(--rose-light);}
  .btn-danger:hover{background:var(--rose-pale);}
  .btn-teal{background:linear-gradient(135deg,var(--teal),var(--teal-deep));color:#fff;}
  .btn-teal:hover{transform:translateY(-1px);}
  .btn-sm{padding:4px 12px;font-size:11px;border-radius:14px;}
  .btn svg{width:14px;height:14px;}
  .content{flex:1;overflow-y:auto;overflow-x:auto;padding:22px 26px;display:flex;flex-direction:column;gap:18px;scrollbar-width:thin;scrollbar-color:var(--border) transparent;}
  .content::-webkit-scrollbar{width:4px;}
  .content::-webkit-scrollbar-thumb{background:var(--rose-light);border-radius:2px;}
  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
  .stat-card{background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:14px 18px;box-shadow:var(--shadow-sm);position:relative;overflow:hidden;}
  .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:14px 14px 0 0;}
  .sc1::before{background:linear-gradient(90deg,var(--rose),var(--rose-light))}
  .sc2::before{background:linear-gradient(90deg,#f0a500,#f5c842)}
  .sc3::before{background:linear-gradient(90deg,var(--teal),var(--teal-light))}
  .stat-label{font-size:10px;letter-spacing:.1em;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-bottom:4px;}
  .stat-value{font-family:'Dancing Script',cursive;font-size:28px;font-weight:700;color:var(--navy);line-height:1;}
  .stat-sub{font-size:11px;color:var(--text-dim);margin-top:3px;}
  .sesiones-grid{display:grid;grid-template-columns:1fr 320px;gap:16px;min-width:0;}  .sesiones-grid>*{min-width:0;}
  .card{background:var(--surface);border:1.5px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);}
  .card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--surface2);}
  .card-title{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--navy);flex:1;}
  .card-tag{font-size:10px;font-weight:700;color:var(--teal-deep);background:var(--teal-pale);border-radius:20px;padding:3px 10px;}
  .cal-header-inner{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--surface2);}
  .cal-mes{font-family:'Dancing Script',cursive;font-size:22px;font-weight:700;color:var(--navy);flex:1;}
  .cal-nav{display:flex;gap:6px;}
  .cal-nav-btn{width:28px;height:28px;border-radius:50%;background:var(--rose-pale);border:1.5px solid var(--rose-light);color:var(--rose-deep);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;text-decoration:none;transition:background .2s;}
  .cal-nav-btn:hover{background:var(--rose-light);}
  .cal-grid{padding:10px 14px 14px;display:grid;grid-template-columns:repeat(7,1fr);gap:3px;}
  .cal-day-name{text-align:center;font-size:9px;font-weight:700;letter-spacing:.08em;color:var(--text-dim);padding:5px 0;text-transform:uppercase;}
  .cal-day{text-align:center;font-size:12px;font-weight:600;color:var(--text-mid);border-radius:8px;cursor:pointer;transition:all .15s;min-height:38px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding-top:5px;}
  .cal-day:hover{background:var(--rose-pale);color:var(--rose-deep);}
  .cal-day.dim{color:var(--text-dim);cursor:default;}
  .cal-day.dim:hover{background:transparent;color:var(--text-dim);}
  .cal-day.hoy{background:linear-gradient(135deg,var(--rose),var(--rose-deep));color:#fff;font-weight:700;}
  .cal-day.seleccionado:not(.hoy){background:var(--rose-pale);color:var(--rose-deep);font-weight:700;border:1.5px solid var(--rose-light);}
  .cal-dots{display:flex;gap:2px;justify-content:center;flex-wrap:wrap;margin-top:3px;}
  .cal-dot{width:5px;height:5px;border-radius:50%;}
  .dot-pendiente{background:var(--rose);}
  .dot-pagado{background:var(--teal-deep);}
  .dot-abonado{background:#c9943a;}
  .time-slots{display:flex;flex-direction:column;gap:0;padding:6px 20px 16px;}
  .ses-block{border-radius:10px;padding:9px 13px;cursor:pointer;transition:all .18s;margin-bottom:8px;background:var(--rose-pale);border:1.5px solid var(--rose-light);border-left:4px solid var(--rose);}
  .ses-block:hover{transform:translateX(2px);}
  .ses-block.alt{background:var(--teal-pale);border:1.5px solid var(--teal-light);border-left:4px solid var(--teal);}
  .ses-block.alt2{background:var(--lav-pale);border:1.5px solid var(--lav-light);border-left:4px solid var(--lavender);}
  .ses-nombre{font-size:13px;color:var(--navy);font-weight:700;margin-bottom:2px;}
  .ses-service{font-size:11px;color:var(--text-mid);}
  .ses-monto{font-family:'Dancing Script',cursive;font-size:15px;color:var(--navy);margin-top:3px;}
  .ses-saldo{font-size:10px;color:var(--rose-deep);font-weight:700;}
  .ses-actions{display:flex;gap:5px;margin-top:6px;align-items:center;flex-wrap:wrap;}
  .empty-dia{padding:36px 20px;text-align:center;color:var(--text-dim);font-size:12px;font-style:italic;}
  .form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:13px;}
  .form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  .form-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;}
  label{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);font-weight:700;}
  .form-input{background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;color:var(--navy);font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:600;outline:none;transition:border-color .2s;width:100%;}
  .form-input:focus{border-color:var(--rose-light);box-shadow:0 0 0 3px rgba(232,120,154,.10);}
  textarea.form-input{resize:vertical;min-height:60px;}
  .form-body{padding:18px;}
  .divider{border:none;border-top:1.5px dashed var(--border);margin:6px 0 14px;}
  .servicios-lista{display:flex;flex-direction:column;gap:8px;margin-bottom:10px;}
  .servicio-item{display:flex;align-items:center;gap:6px;background:var(--rose-pale);border:1.5px solid var(--rose-light);border-radius:10px;padding:7px 10px;}
  .servicio-item select{flex:1;background:transparent;border:none;color:var(--navy);font-family:'Nunito',sans-serif;font-size:12px;font-weight:600;outline:none;}
  .servicio-item.teal-item{background:var(--teal-pale);border-color:var(--teal-light);}
  .btn-remove-serv{width:22px;height:22px;border-radius:50%;background:var(--rose-light);border:none;color:var(--rose-deep);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;}
  .add-servicio-row{display:flex;align-items:center;gap:8px;padding:7px 11px;background:var(--surface2);border:1.5px dashed var(--border);border-radius:10px;cursor:pointer;font-size:11.5px;color:var(--text-mid);font-weight:700;transition:all .2s;}
  .add-servicio-row:hover{border-color:var(--teal-light);color:var(--teal-deep);background:var(--teal-pale);}
  table{width:100%;border-collapse:collapse;}
  th{text-align:left;padding:11px 20px;font-size:10px;letter-spacing:.14em;color:var(--text-dim);text-transform:uppercase;border-bottom:1.5px solid var(--border);font-weight:700;background:#fdf9fb;}
  td{padding:12px 20px;border-bottom:1px solid var(--border);font-size:12.5px;color:var(--text-mid);vertical-align:middle;}
  tr:last-child td{border-bottom:none;}
  tr:hover td{background:var(--rose-pale);}
  .client-cell{display:flex;align-items:center;gap:10px;}
  .client-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:16px;font-weight:700;flex-shrink:0;}
  .av-a{background:var(--rose-pale);color:var(--rose-deep)}.av-b{background:var(--teal-pale);color:var(--teal-deep)}.av-c{background:var(--lav-pale);color:var(--lavender)}.av-d{background:var(--navy-soft);color:var(--navy-mid)}.av-e{background:#fef3e2;color:#b07a30}
  .client-name{font-size:13px;color:var(--navy);font-weight:700;}
  .client-phone{font-size:10px;color:var(--text-dim);}
  .status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;}
  .status-pill::before{content:'';width:5px;height:5px;border-radius:50%;flex-shrink:0;}
  .pill-pagado{background:#e8f8f4;color:#2a9d73}.pill-pagado::before{background:#2a9d73}
  .pill-abonado{background:#fff4e0;color:#b07a30}.pill-abonado::before{background:#c9943a}
  .pill-pendiente{background:var(--rose-pale);color:var(--rose-deep)}.pill-pendiente::before{background:var(--rose-deep)}
  .pill-entregado{background:var(--teal-pale);color:var(--teal-deep)}.pill-entregado::before{background:var(--teal-deep)}
  .pill-en_edicion{background:var(--lav-pale);color:var(--lavender)}.pill-en_edicion::before{background:var(--lavender)}
  .filtros{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .filtro-btn{padding:5px 14px;border-radius:20px;font-size:11px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);cursor:pointer;text-decoration:none;transition:all .2s;}
  .filtro-btn:hover,.filtro-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}
  .pagination{display:flex;gap:6px;justify-content:center;}
  .page-btn{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);text-decoration:none;transition:all .2s;}
  .page-btn:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .page-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}
  /* GALERÍA */
  .galeria-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;padding:16px;}
  .galeria-item{position:relative;border-radius:10px;overflow:hidden;aspect-ratio:1;background:var(--surface2);border:1.5px solid var(--border);}
  .galeria-item img{width:100%;height:100%;object-fit:cover;transition:transform .2s;}
  .galeria-item:hover img{transform:scale(1.05);}
  .galeria-item .foto-overlay{position:absolute;inset:0;background:rgba(26,31,60,.5);opacity:0;transition:opacity .2s;display:flex;align-items:center;justify-content:center;gap:8px;}
  .galeria-item:hover .foto-overlay{opacity:1;}
  .foto-btn{width:28px;height:28px;border-radius:50%;background:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;}
  .upload-zone{border:2px dashed var(--rose-light);border-radius:12px;padding:24px;text-align:center;cursor:pointer;transition:all .2s;margin:16px;}
  .upload-zone:hover{border-color:var(--rose);background:var(--rose-pale);}
  .upload-zone input{display:none;}
  /* MODAL */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(26,31,60,.45);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
  .modal-overlay.open{display:flex;}
  .modal{background:var(--surface);border-radius:20px;width:100%;max-width:520px;box-shadow:var(--shadow-md);animation:fadeUp .3s ease both;max-height:92vh;overflow-y:auto;}
  .modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;background:var(--surface2);border-radius:20px 20px 0 0;}
  .modal-title{font-family:'Dancing Script',cursive;font-size:22px;font-weight:700;color:var(--navy);flex:1;}
  .modal-close{width:28px;height:28px;border-radius:50%;background:var(--rose-pale);border:none;color:var(--rose-deep);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;font-weight:700;}
  .modal-body{padding:20px 24px;}
  .modal-footer{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--surface2);border-radius:0 0 20px 20px;}
  .lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:200;align-items:center;justify-content:center;}
  .lightbox.open{display:flex;}
  .lightbox img{max-width:90vw;max-height:90vh;border-radius:8px;}
  .lightbox-close{position:absolute;top:20px;right:24px;color:#fff;font-size:28px;cursor:pointer;font-weight:700;}
  .alert{padding:10px 16px;border-radius:10px;font-size:12px;font-weight:600;}
  .alert-success{background:var(--teal-pale);color:var(--teal-deep);border:1.5px solid var(--teal-light);}
  .alert-error{background:var(--rose-pale);color:var(--rose-deep);border:1.5px solid var(--rose-light);}
  .empty-row{text-align:center;padding:40px;color:var(--text-dim);font-size:13px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
  /* ── Buscador de cliente ── */
  .cliente-search-wrapper{position:relative;}
  .cliente-search-input-row{position:relative;display:flex;align-items:center;}
  .cliente-search-input-row .search-icon{position:absolute;left:12px;color:var(--text-dim);pointer-events:none;flex-shrink:0;}
  .cliente-search-input{padding-left:36px!important;width:100%;}
  .cliente-dropdown{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--surface);border:1.5px solid var(--border);border-radius:10px;max-height:200px;overflow-y:auto;z-index:999;box-shadow:0 4px 16px rgba(180,120,160,.18);}
  .cliente-dropdown.open{display:block;}
  .cliente-option{padding:9px 14px;cursor:pointer;font-size:12.5px;font-weight:600;color:var(--text);transition:background .15s;}
  .cliente-option:hover{background:var(--rose-pale);color:var(--rose-deep);}
  .cliente-option.hidden{display:none;}
</style>
</head>
<body>
<?php require_once '../includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <span class="page-title">Sesiones Fotográficas</span>
    <div class="topbar-sep"></div>
    
  </div>

  <div class="content">
    <?php if ($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

    <div class="stats-row">
      <div class="stat-card sc1"><div class="stat-label">Sin pagar</div><div class="stat-value"><?= $pagosPend ?></div><div class="stat-sub">pendientes o abonadas</div></div>
      <div class="stat-card sc2"><div class="stat-label">Saldo por cobrar</div><div class="stat-value" style="font-size:20px;"><?= formatoPeso($saldoPend) ?></div><div class="stat-sub">en deuda</div></div>
      <div class="stat-card sc3"><div class="stat-label">Por entregar</div><div class="stat-value"><?= $entregasPend ?></div><div class="stat-sub">sesiones</div></div>
    </div>

    <div class="sesiones-grid">

      <!-- CALENDARIO -->
      <div class="card">
        <div class="cal-header-inner">
          <div class="cal-mes"><?= $mesesNombres[$mesC] ?> <?= $anioC ?></div>
          <div class="cal-nav">
            <a href="?mes=<?= $mesPrev ?>&anio=<?= $anioPrev ?>&dia=1" class="cal-nav-btn">‹</a>
            <a href="?mes=<?= $hoy->format('n') ?>&anio=<?= $hoy->format('Y') ?>&dia=<?= $hoy->format('j') ?>" class="cal-nav-btn" title="Hoy">·</a>
            <a href="?mes=<?= $mesSig ?>&anio=<?= $anioSig ?>&dia=1" class="cal-nav-btn">›</a>
          </div>
          <span class="card-tag"><?= $mesesNombres[$mesC] ?></span>
        </div>
        <div class="cal-grid">
          <?php foreach ($diasNombres as $dn): ?><div class="cal-day-name"><?= $dn ?></div><?php endforeach; ?>
          <?php
            for ($x=1;$x<$diaSemana;$x++) echo "<div class='cal-day dim'></div>";
            $diaHoy = ($anioC==$hoy->format('Y')&&$mesC==$hoy->format('n'))?(int)$hoy->format('j'):-1;
            for ($d=1;$d<=$diasMes;$d++):
              $clases='cal-day';
              if($d===$diaHoy) $clases.=' hoy';
              elseif($d===$diaSeleccionado) $clases.=' seleccionado';
              $sesDelDia=$sesionesCalendario[$d]??[];
          ?>
          <a href="?mes=<?= $mesC ?>&anio=<?= $anioC ?>&dia=<?= $d ?>" class="<?= $clases ?>" style="text-decoration:none;">
            <?= $d ?>
            <?php if(!empty($sesDelDia)): ?><div class="cal-dots"><?php foreach(array_slice($sesDelDia,0,3) as $se): ?><div class="cal-dot dot-<?= $se['estado_pago'] ?>"></div><?php endforeach; ?></div><?php endif; ?>
          </a>
          <?php endfor; ?>
        </div>
        <div style="padding:0 18px 14px;display:flex;gap:14px;">
          <div style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--text-dim);font-weight:600;"><div class="cal-dot dot-pagado" style="display:inline-block;"></div> Pagada</div>
          <div style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--text-dim);font-weight:600;"><div class="cal-dot dot-abonado" style="display:inline-block;"></div> Abonada</div>
          <div style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--text-dim);font-weight:600;"><div class="cal-dot dot-pendiente" style="display:inline-block;"></div> Pendiente</div>
        </div>
        <div style="border-top:1.5px dashed var(--border);margin:0 20px;"></div>
        <div style="padding:12px 20px 6px;display:flex;align-items:center;gap:8px;">
          <span style="font-family:'Dancing Script',cursive;font-size:18px;font-weight:700;color:var(--navy);"><?= $diaSeleccionado ?> <?= $mesesNombres[$mesC] ?></span>
          <span style="font-size:11px;color:var(--text-dim);font-weight:600;margin-left:auto;"><?= count($sesionesDelDia) ?> sesión<?= count($sesionesDelDia)!==1?'es':'' ?></span>
        </div>
        <div class="time-slots">
          <?php if(empty($sesionesDelDia)): ?>
            <div class="empty-dia">Sin sesiones este día</div>
          <?php else: $i=0; foreach($sesionesDelDia as $se): $colorCls=$coloresBloque[$i%3]; ?>
          <div class="ses-block <?= $colorCls ?>" onclick="editarSesion(<?= htmlspecialchars(json_encode($se)) ?>)">
            <div class="ses-nombre"><?= htmlspecialchars($se['nombre'].' '.$se['apellido']) ?></div>
            <div class="ses-service"><?= htmlspecialchars($se['servicio']??'Sin servicio') ?></div>
            <div class="ses-monto"><?= formatoPeso($se['total']) ?>
              <?php if(($se['saldo']??0)>0 && $se['estado_pago']!=='pagado'): ?> <span class="ses-saldo"> · Saldo: <?= formatoPeso($se['saldo']) ?></span><?php endif; ?>
            </div>
            <div class="ses-actions" onclick="event.stopPropagation()">
              <form method="POST" style="display:inline;">
                <input type="hidden" name="accion" value="actualizar_entrega">
                <input type="hidden" name="id" value="<?= $se['id'] ?>">
                <select name="estado_entrega" class="form-input" style="padding:3px 8px;font-size:11px;width:auto;" onchange="this.form.submit()">
                  <option value="pendiente" <?= $se['estado_entrega']==='pendiente'?'selected':'' ?>>Pendiente</option>
                  <option value="en_edicion" <?= $se['estado_entrega']==='en_edicion'?'selected':'' ?>>En edición</option>
                  <option value="entregado" <?= $se['estado_entrega']==='entregado'?'selected':'' ?>>Entregado</option>
                </select>
              </form>
              <span class="status-pill pill-<?= $se['estado_pago'] ?>"><?= ucfirst($se['estado_pago']) ?></span>
              <button class="btn btn-teal btn-sm" onclick="event.stopPropagation();abrirGaleria(<?= $se['id'] ?>, '<?= htmlspecialchars($se['nombre'].' '.$se['apellido']) ?>')">📷 Fotos</button>
              <button class="btn btn-danger btn-sm" onclick="event.stopPropagation();confirmarEliminar(<?= $se['id'] ?>,'<?= htmlspecialchars($se['nombre'].' '.$se['apellido']) ?>')">Eliminar</button>
            </div>
          </div>
          <?php $i++; endforeach; endif; ?>
        </div>
      </div>

      <!-- FORM NUEVA SESIÓN -->
      <div class="card" style="align-self:start;">
        <div class="card-header"><div class="card-title">Nueva Sesión</div></div>
        <form method="POST" action="">
          <input type="hidden" name="accion" value="guardar">
          <input type="hidden" name="id" value="0">
          <div class="form-body">
            <div class="form-group">
              <label>Cliente *</label>
              <div class="cliente-search-wrapper">
                <div class="cliente-search-input-row">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" class="search-icon"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="qClienteSearch" class="form-input cliente-search-input" placeholder="Buscar por nombre o apellido..." autocomplete="off"
                    oninput="filtrarClientes('qClienteSearch','qClienteDropdown','qClienteIdInput')"
                    onfocus="mostrarDropdown('qClienteDropdown')">
                </div>
                <div class="cliente-dropdown" id="qClienteDropdown">
                  <?php $todosClientes->data_seek(0); while($cl=$todosClientes->fetch_assoc()): ?>
                  <div class="cliente-option"
                    data-id="<?=$cl['id']?>"
                    data-nombre="<?=htmlspecialchars($cl['nombre'].' '.$cl['apellido'])?>"
                    onclick="seleccionarCliente(this,'qClienteSearch','qClienteDropdown','qClienteIdInput')">
                    <?=htmlspecialchars($cl['nombre'].' '.$cl['apellido'])?>
                  </div>
                  <?php endwhile; ?>
                </div>
                <input type="hidden" name="cliente_id" id="qClienteIdInput" required>
              </div>
            </div>
            <div class="form-group">
              <label>Fecha *</label>
              <input class="form-input" type="date" name="fecha_sesion" value="<?= "$anioC-".str_pad($mesC,2,'0',STR_PAD_LEFT)."-".str_pad($diaSeleccionado,2,'0',STR_PAD_LEFT) ?>" required>
            </div>
            <div class="form-group">
              <label>Servicio(s)</label>
              <div id="serviciosLista" class="servicios-lista">
                <div class="servicio-item" id="servItem0">
                  <select class="form-input" name="producto_id[]" id="qProducto0" style="border:none;background:transparent;padding:0;" onchange="autoTotal()">
                    <option value="">— Sin servicio —</option>
                    <?php $todosProductos->data_seek(0); while($pr=$todosProductos->fetch_assoc()): ?>
                    <option value="<?=$pr['id']?>" data-precio="<?=$pr['precio']?>"><?=htmlspecialchars($pr['nombre'])?> — <?=formatoPeso($pr['precio'])?></option>
                    <?php endwhile; ?>
                  </select>
                </div>
              </div>
              <div class="add-servicio-row" onclick="agregarServicio()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                ¿Agregar otro servicio?
              </div>
            </div>
            <hr class="divider">
            <div class="form-row">
              <div class="form-group"><label>Total ($)</label><input class="form-input" type="number" name="total" id="qTotal" min="0" step="1000" placeholder="0"></div>
              <div class="form-group"><label>Abono ($)</label><input class="form-input" type="number" name="abono" id="qAbono" min="0" step="1000" placeholder="0"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>Estado pago</label>
                <select class="form-input" name="estado_pago" id="qEstadoPago">
                  <option value="pendiente">Pendiente</option>
                  <option value="abonado">Abonado</option>
                  <option value="pagado">Pagado</option>
                </select>
              </div>
              <div class="form-group"><label>Método pago</label>
                <select class="form-input" name="metodo_pago">
                  <option value="efectivo">Efectivo</option>
                  <option value="transferencia">Transferencia</option>
                  <option value="nequi">Nequi</option>
                  <option value="daviplata">Daviplata</option>
                  <option value="tarjeta">Tarjeta</option>
                </select>
              </div>
            </div>
            <div class="form-group"><label>Entrega</label>
              <select class="form-input" name="estado_entrega">
                <option value="pendiente">Pendiente</option>
                <option value="en_edicion">En edición</option>
                <option value="entregado">Entregado</option>
              </select>
            </div>
            <div class="form-group"><label>Notas</label><textarea class="form-input" name="notas" placeholder="Observaciones..."></textarea></div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;border-radius:12px;">Registrar Sesión</button>
          </div>
        </form>
      </div>
    </div>

    <!-- LISTADO GENERAL -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Todas las Sesiones</div>
        <div class="filtros">
          <a href="sesiones.php" class="filtro-btn <?= !$estadoFiltro?'active':'' ?>">Todas</a>
          <a href="?estado=pendiente" class="filtro-btn <?= $estadoFiltro==='pendiente'?'active':'' ?>">Pendientes</a>
          <a href="?estado=abonado"   class="filtro-btn <?= $estadoFiltro==='abonado'  ?'active':'' ?>">Abonadas</a>
          <a href="?estado=pagado"    class="filtro-btn <?= $estadoFiltro==='pagado'   ?'active':'' ?>">Pagadas</a>
        </div>
      </div>
      <div style="overflow-x:auto;">
      <table>
        <thead><tr><th>Cliente</th><th>Servicio</th><th>Fecha</th><th>Total / Saldo</th><th>Pago</th><th>Entrega</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php if($listadoSesiones->num_rows===0): ?>
            <tr><td colspan="7" class="empty-row">No hay sesiones con ese filtro.</td></tr>
          <?php else: $i=0; while($s=$listadoSesiones->fetch_assoc()): ?>
          <tr>
            <td>
              <div class="client-cell">
                <div class="client-avatar <?= $avClasses[$i%5] ?>"><?= mb_strtoupper(mb_substr($s['nombre'],0,1)) ?></div>
                <div><div class="client-name"><?= htmlspecialchars($s['nombre'].' '.$s['apellido']) ?></div><div class="client-phone"><?= htmlspecialchars($s['telefono']??'') ?></div></div>
              </div>
            </td>
            <td><?= htmlspecialchars($s['servicio']??'—') ?></td>
            <td style="font-size:11px;font-weight:600;"><?= formatoFecha($s['fecha_sesion']) ?></td>
            <td>
              <div style="font-family:'Dancing Script',cursive;font-size:15px;color:var(--navy);"><?= formatoPeso($s['total']) ?></div>
              <?php if(($s['saldo']??0)>0): ?><div style="font-size:10px;color:var(--rose-deep);font-weight:700;">Saldo: <?= formatoPeso($s['saldo']) ?></div><?php endif; ?>
            </td>
            <td><span class="status-pill pill-<?= $s['estado_pago'] ?>"><?= ucfirst($s['estado_pago']) ?></span></td>
            <td><span class="status-pill pill-<?= $s['estado_entrega'] ?>"><?= ucfirst(str_replace('_',' ',$s['estado_entrega'])) ?></span></td>
            <td>
              <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <button class="btn btn-teal btn-sm" onclick="abrirGaleria(<?= $s['id'] ?>,'<?= htmlspecialchars($s['nombre'].' '.$s['apellido']) ?>')">📷</button>
                <button class="btn btn-ghost btn-sm" onclick="editarSesion(<?= htmlspecialchars(json_encode($s)) ?>)">Editar</button>
                <button class="btn btn-danger btn-sm" onclick="confirmarEliminar(<?= $s['id'] ?>,'<?= htmlspecialchars($s['nombre'].' '.$s['apellido']) ?>')">Eliminar</button>
              </div>
            </td>
          </tr>
          <?php $i++; endwhile; endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    <?php if($totalPaginas>1): ?>
    <div class="pagination">
      <?php for($p=1;$p<=$totalPaginas;$p++): ?>
        <a href="?p=<?=$p?>&estado=<?=urlencode($estadoFiltro)?>&mes=<?=$mesC?>&anio=<?=$anioC?>&dia=<?=$diaSeleccionado?>" class="page-btn <?=$p===$pagina?'active':''?>"><?=$p?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- MODAL EDITAR -->
<div class="modal-overlay" id="modalSesion">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="mTitulo">Editar Sesión</div>
      <button class="modal-close" onclick="cerrarModal()">✕</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="mId" value="0">
      <div class="modal-body">

        <!-- Cliente búsqueda -->
        <div class="form-group">
          <label>Cliente *</label>
          <div class="cliente-search-wrapper">
            <div class="cliente-search-input-row">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" class="search-icon">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
              </svg>
              <input 
                type="text" 
                id="mClienteSearch" 
                class="form-input cliente-search-input" 
                placeholder="Buscar por nombre o apellido..."
                autocomplete="off"
                oninput="filtrarClientes('mClienteSearch','mClienteDropdown','mClienteIdInput')"
                onfocus="mostrarDropdown('mClienteDropdown')"
              >
            </div>
            <div class="cliente-dropdown" id="mClienteDropdown">
              <?php $todosClientes->data_seek(0); while($cl=$todosClientes->fetch_assoc()): ?>
              <div class="cliente-option" 
                   data-id="<?=$cl['id']?>" 
                   data-nombre="<?=htmlspecialchars($cl['nombre'].' '.$cl['apellido'])?>"
                   onclick="seleccionarCliente(this,'mClienteSearch','mClienteDropdown','mClienteIdInput')">
                <?=htmlspecialchars($cl['nombre'].' '.$cl['apellido'])?>
              </div>
              <?php endwhile; ?>
            </div>
            <input type="hidden" name="cliente_id" id="mClienteIdInput" required>
          </div>
        </div>

        <!-- Fecha -->
        <div class="form-group">
          <label>Fecha *</label>
          <input class="form-input" type="date" name="fecha_sesion" id="mFecha" required>
        </div>

        <!-- Servicios con opción de agregar más -->
        <div class="form-group">
          <label>Servicio(s)</label>
          <div id="mServiciosLista" class="servicios-lista">
            <div class="servicio-item" id="mServItem0">
              <select class="form-input" name="producto_id[]" id="mProducto0" style="border:none;background:transparent;padding:0;" onchange="autoTotalModal()">
                <option value="">— Sin servicio —</option>
                <?php $todosProductos->data_seek(0); while($pr=$todosProductos->fetch_assoc()): ?>
                <option value="<?=$pr['id']?>" data-precio="<?=$pr['precio']?>"><?=htmlspecialchars($pr['nombre'])?> — <?=formatoPeso($pr['precio'])?></option>
                <?php endwhile; ?>
              </select>
            </div>
          </div>
          <div class="add-servicio-row" onclick="agregarServicioModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            ¿Agregar otro servicio?
          </div>
        </div>

        <hr class="divider">

        <!-- Total y Abono -->
        <div class="form-row">
          <div class="form-group"><label>Total ($)</label><input class="form-input" type="number" name="total" id="mTotal" min="0" step="1000" placeholder="0"></div>
          <div class="form-group"><label>Abono ($)</label><input class="form-input" type="number" name="abono" id="mAbono" min="0" step="1000" placeholder="0"></div>
        </div>

        <!-- Estado pago y Método -->
        <div class="form-row">
          <div class="form-group"><label>Estado pago</label>
            <select class="form-input" name="estado_pago" id="mEstadoPago">
              <option value="pendiente">Pendiente</option>
              <option value="abonado">Abonado</option>
              <option value="pagado">Pagado</option>
            </select>
          </div>
          <div class="form-group"><label>Método pago</label>
            <select class="form-input" name="metodo_pago" id="mMetodoPago">
              <option value="efectivo">Efectivo</option>
              <option value="transferencia">Transferencia</option>
              <option value="nequi">Nequi</option>
              <option value="daviplata">Daviplata</option>
              <option value="tarjeta">Tarjeta</option>
            </select>
          </div>
        </div>

        <!-- Entrega -->
        <div class="form-group"><label>Entrega</label>
          <select class="form-input" name="estado_entrega" id="mEstadoEntrega">
            <option value="pendiente">Pendiente</option>
            <option value="en_edicion">En edición</option>
            <option value="entregado">Entregado</option>
          </select>
        </div>

        <!-- Notas -->
        <div class="form-group"><label>Notas</label>
          <textarea class="form-input" name="notas" id="mNotas" placeholder="Observaciones..."></textarea>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="cerrarModal()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL GALERÍA -->
<div class="modal-overlay" id="modalGaleria" style="z-index:110;">
  <div class="modal" style="max-width:640px;">
    <div class="modal-header">
      <div class="modal-title" id="galeriaTitulo">Fotos de la Sesión</div>
      <button class="modal-close" onclick="cerrarGaleria()">✕</button>
    </div>
    <div id="galeriaContenido" style="min-height:200px;">
      <div style="text-align:center;padding:40px;color:var(--text-dim);">Cargando...</div>
    </div>
    <!-- Subir fotos -->
    <div style="padding:0 16px 16px;">
      <form method="POST" action="" enctype="multipart/form-data" id="formFotos">
        <input type="hidden" name="accion" value="subir_fotos">
        <input type="hidden" name="sesion_id" id="galeriaId">
        <div class="upload-zone" onclick="document.getElementById('inputFotos').click()">
          <input type="file" name="fotos[]" id="inputFotos" multiple accept="image/*" onchange="previsualizarFotos(this)">
          <div id="uploadText">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--rose)" stroke-width="1.5" style="margin-bottom:8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <div style="font-size:12px;color:var(--text-dim);font-weight:600;">Clic para subir fotos de la sesión</div>
            <div style="font-size:10px;color:var(--text-dim);margin-top:4px;">JPG, PNG, WEBP · Puedes subir varias a la vez</div>
          </div>
          <div id="previewFotos" style="display:none;display:flex;flex-wrap:wrap;gap:6px;justify-content:center;"></div>
        </div>
        <div class="form-group" style="margin-bottom:8px;">
          <label>Descripción (opcional)</label>
          <input class="form-input" type="text" name="descripcion" placeholder="Ej: Sesión quinceañera terminada">
        </div>
        <button type="submit" class="btn btn-teal" style="width:100%;justify-content:center;">📤 Subir Fotos</button>
      </form>
    </div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="this.classList.remove('open')">
  <span class="lightbox-close">✕</span>
  <img src="" id="lightboxImg" alt="">
</div>

<!-- FORM ELIMINAR -->
<form method="POST" id="formEliminar" style="display:none;">
  <input type="hidden" name="accion" value="eliminar">
  <input type="hidden" name="id" id="eliminarId">
</form>
<form method="POST" id="formElimFoto" style="display:none;">
  <input type="hidden" name="accion" value="eliminar_foto">
  <input type="hidden" name="foto_id" id="elimFotoId">
</form>

<script>
const productosData = <?php
  $todosProductos->data_seek(0); $prods=[];
  while($pr=$todosProductos->fetch_assoc()) $prods[]=$pr;
  echo json_encode($prods);
?>;
const UPLOAD_URL = '<?= UPLOAD_URL ?>';

// ── Buscador de clientes ──
function filtrarClientes(inputId, dropdownId, hiddenId){
  const q=document.getElementById(inputId).value.toLowerCase();
  const dropdown=document.getElementById(dropdownId);
  const options=dropdown.querySelectorAll('.cliente-option');
  let hay=false;
  options.forEach(op=>{
    const nombre=op.dataset.nombre.toLowerCase();
    if(nombre.includes(q)){op.classList.remove('hidden');hay=true;}
    else op.classList.add('hidden');
  });
  dropdown.classList.toggle('open', hay||q==='');
  if(q==='') document.getElementById(hiddenId).value='';
}
function mostrarDropdown(dropdownId){
  document.getElementById(dropdownId).classList.add('open');
}
function seleccionarCliente(el, inputId, dropdownId, hiddenId){
  document.getElementById(inputId).value=el.dataset.nombre;
  document.getElementById(hiddenId).value=el.dataset.id;
  document.getElementById(dropdownId).classList.remove('open');
}
document.addEventListener('click',function(e){
  document.querySelectorAll('.cliente-search-wrapper').forEach(wrapper=>{
    if(!wrapper.contains(e.target))
      wrapper.querySelector('.cliente-dropdown')?.classList.remove('open');
  });
});

// ── Modal editar ──
function cerrarModal(){ document.getElementById('modalSesion').classList.remove('open'); }

function resetModalServicios(){
  // Eliminar filas extra del modal, dejar solo mServItem0
  document.querySelectorAll('#mServiciosLista .servicio-item').forEach((el,i)=>{
    if(i>0) el.remove();
  });
  document.getElementById('mProducto0').value='';
}

function editarSesion(s){
  document.getElementById('mTitulo').textContent='Editar Sesión';
  document.getElementById('mId').value=s.id;
  // Buscador cliente: poner el nombre en el input y el id en el hidden
  document.getElementById('mClienteSearch').value=(s.nombre||'')+' '+(s.apellido||'');
  document.getElementById('mClienteIdInput').value=s.cliente_id;
  document.getElementById('mFecha').value=s.fecha_sesion;
  document.getElementById('mTotal').value=s.total;
  document.getElementById('mAbono').value=s.abono;
  document.getElementById('mEstadoPago').value=s.estado_pago;
  document.getElementById('mEstadoEntrega').value=s.estado_entrega;
  document.getElementById('mMetodoPago').value=s.metodo_pago||'efectivo';
  document.getElementById('mNotas').value=s.notas||'';
  resetModalServicios();
  if(s.producto_id) document.getElementById('mProducto0').value=s.producto_id;
  document.getElementById('modalSesion').classList.add('open');
}
function confirmarEliminar(id,nombre){
  if(confirm('¿Eliminar sesión de '+nombre+'?')){
    document.getElementById('eliminarId').value=id;
    document.getElementById('formEliminar').submit();
  }
}
document.getElementById('modalSesion').addEventListener('click',function(e){if(e.target===this)cerrarModal();});
  // ── Auto-rellenar abono=total cuando cambia a "pagado" ──
document.getElementById('mEstadoPago').addEventListener('change', function(){
  if(this.value === 'pagado'){
    const total = parseFloat(document.getElementById('mTotal').value) || 0;
    if(total > 0){
      document.getElementById('mAbono').value = total;
    }
  }
});

document.getElementById('qEstadoPago').addEventListener('change', function(){
  if(this.value === 'pagado'){
    const total = parseFloat(document.getElementById('qTotal').value) || 0;
    if(total > 0){
      document.getElementById('qAbono').value = total;
    }
  }
});

// ── Servicios adicionales – formulario nueva sesión ──
let servicioCount=1;
function buildOptions(){
  let html='<option value="">— Sin servicio —</option>';
  productosData.forEach(p=>{
    html+=`<option value="${p.id}" data-precio="${p.precio}">${p.nombre} — $${Number(p.precio).toLocaleString('es-CO')}</option>`;
  });
  return html;
}
function agregarServicio(){
  const lista=document.getElementById('serviciosLista');
  const idx=servicioCount++;
  const color=idx%2===0?'':'teal-item';
  const div=document.createElement('div');
  div.className='servicio-item '+color;
  div.id='servItem'+idx;
  div.innerHTML=`<select class="form-input" name="producto_id[]" style="border:none;background:transparent;padding:0;" onchange="autoTotal()">${buildOptions()}</select><button type="button" class="btn-remove-serv" onclick="document.getElementById('servItem${idx}').remove();autoTotal()">✕</button>`;
  lista.appendChild(div);
}
function autoTotal(){
  let suma=0;
  document.querySelectorAll('#serviciosLista select').forEach(sel=>{
    const p=parseFloat(sel.options[sel.selectedIndex]?.getAttribute('data-precio')||0);
    suma+=p;
  });
  if(suma>0) document.getElementById('qTotal').value=suma;
}

// ── Servicios adicionales – modal ──
let mServicioCount=1;
function agregarServicioModal(){
  const lista=document.getElementById('mServiciosLista');
  const idx=mServicioCount++;
  const color=idx%2===0?'':'teal-item';
  const div=document.createElement('div');
  div.className='servicio-item '+color;
  div.id='mServItem'+idx;
  div.innerHTML=`<select class="form-input" name="producto_id[]" style="border:none;background:transparent;padding:0;" onchange="autoTotalModal()">${buildOptions()}</select><button type="button" class="btn-remove-serv" onclick="document.getElementById('mServItem${idx}').remove();autoTotalModal()">✕</button>`;
  lista.appendChild(div);
}
function autoTotalModal(){
  let suma=0;
  document.querySelectorAll('#mServiciosLista select').forEach(sel=>{
    const p=parseFloat(sel.options[sel.selectedIndex]?.getAttribute('data-precio')||0);
    suma+=p;
  });
  if(suma>0) document.getElementById('mTotal').value=suma;
}

// ── Galería ──
function abrirGaleria(sesionId, nombre){
  document.getElementById('galeriaId').value=sesionId;
  document.getElementById('galeriaTitulo').textContent='Fotos – '+nombre;
  document.getElementById('modalGaleria').classList.add('open');
  cargarFotos(sesionId);
}
function cerrarGaleria(){ document.getElementById('modalGaleria').classList.remove('open'); }
document.getElementById('modalGaleria').addEventListener('click',function(e){if(e.target===this)cerrarGaleria();});

function cargarFotos(sesionId){
  fetch('?accion_galeria=1&sesion_id='+sesionId)
    .then(r=>r.json())
    .then(fotos=>{
      const cont=document.getElementById('galeriaContenido');
      if(!fotos.length){ cont.innerHTML='<div style="text-align:center;padding:30px;color:var(--text-dim);font-size:12px;">Sin fotos subidas aún.</div>'; return; }
      let html='<div class="galeria-grid">';
      fotos.forEach(f=>{
        html+=`<div class="galeria-item">
          <img src="${UPLOAD_URL+f.archivo}" alt="${f.descripcion||'foto'}">
          <div class="foto-overlay">
            <button class="foto-btn" onclick="verFoto('${UPLOAD_URL+f.archivo}')" title="Ver">🔍</button>
            <button class="foto-btn" onclick="eliminarFoto(${f.id})" title="Eliminar">🗑️</button>
          </div>
        </div>`;
      });
      html+='</div>';
      cont.innerHTML=html;
    })
    .catch(()=>{ document.getElementById('galeriaContenido').innerHTML='<div style="padding:20px;text-align:center;color:var(--rose-deep);">Error al cargar fotos.</div>'; });
}

function verFoto(src){ document.getElementById('lightboxImg').src=src; document.getElementById('lightbox').classList.add('open'); }
function eliminarFoto(id){
  if(confirm('¿Eliminar esta foto?')){
    document.getElementById('elimFotoId').value=id;
    document.getElementById('formElimFoto').submit();
  }
}

function previsualizarFotos(input){
  const prev=document.getElementById('previewFotos');
  const txt=document.getElementById('uploadText');
  prev.innerHTML=''; prev.style.display='flex'; txt.style.display='none';
  Array.from(input.files).forEach(file=>{
    const reader=new FileReader();
    reader.onload=e=>{
      const img=document.createElement('img');
      img.src=e.target.result;
      img.style.cssText='width:60px;height:60px;object-fit:cover;border-radius:8px;border:2px solid var(--rose-light);';
      prev.appendChild(img);
    };
    reader.readAsDataURL(file);
  });
}
</script>

<?php
// ── Endpoint AJAX para fotos ──
if (isset($_GET['accion_galeria'])) {
    $sid = sanitizeInt($_GET['sesion_id'] ?? 0);
    $rows = [];
    if ($sid) {
        $r = $db->query("SELECT id, archivo, descripcion FROM sesion_fotos WHERE sesion_id=$sid ORDER BY fecha_subida DESC");
        while ($row = $r->fetch_assoc()) $rows[] = $row;
    }
    // Limpiar buffer y salir como JSON
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}
?>
</body>
</html>