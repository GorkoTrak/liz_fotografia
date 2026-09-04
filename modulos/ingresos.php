<?php
require_once '../config.php';
date_default_timezone_set('America/Bogota');
requerirLogin();


// ── Verificación de administrador ──────────────────────────────────────────
if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acceso restringido</title>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:\'Nunito\',sans-serif;background:#faf8f6;display:flex;align-items:center;justify-content:center;min-height:100vh;}
  .card{background:#fff;border:1.5px solid #ede0ea;border-top:4px solid #e8789a;border-radius:16px;padding:40px 44px;max-width:420px;text-align:center;box-shadow:0 4px 20px rgba(180,120,160,.13);}
  .icon{width:64px;height:64px;background:#fce8f0;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;}
  .icon svg{width:30px;height:30px;color:#e8789a;}
  h2{font-family:\'Dancing Script\',cursive;font-size:26px;color:#1a1f3c;margin-bottom:10px;}
  p{font-size:13px;color:#6b5e7a;line-height:1.7;margin-bottom:24px;}
  a{display:inline-block;padding:10px 26px;background:linear-gradient(135deg,#e8789a,#c4547a);color:#fff;border-radius:20px;text-decoration:none;font-size:13px;font-weight:700;transition:opacity .2s;}
  a:hover{opacity:.88;}
</style>
</head>
<body>
  <div class="card">
    <div class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
    </div>
    <h2>Acceso restringido</h2>
    <p>Solo el administrador puede acceder a esta sección.</p>
    <a href="../index.php">← Volver al inicio</a>
  </div>
</body>
</html>';
    exit;
}

$db = getDB();
$mensaje = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $id          = sanitizeInt($_POST['id'] ?? 0);
        $cliente_id  = sanitizeInt($_POST['cliente_id'] ?? 0) ?: null;
        $concepto    = sanitize($_POST['concepto'] ?? '');
        $monto       = floatval($_POST['monto'] ?? 0);
        $tipo        = sanitize($_POST['tipo'] ?? 'ingreso');
        $metodo_pago = sanitize($_POST['metodo_pago'] ?? 'efectivo');
        $fecha       = sanitize($_POST['fecha'] ?? date('Y-m-d'));
        $notas       = sanitize($_POST['notas'] ?? '');

        if (empty($concepto) || $monto <= 0) {
            $error = 'Concepto y monto son obligatorios.';
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE ingresos SET cliente_id=?, concepto=?, monto=?, tipo=?, metodo_pago=?, fecha=?, notas=? WHERE id=?");
                $stmt->bind_param("isdssssi", $cliente_id, $concepto, $monto, $tipo, $metodo_pago, $fecha, $notas, $id);
                $mensaje = 'Movimiento actualizado.';
            } else {
                $stmt = $db->prepare("INSERT INTO ingresos (cliente_id, concepto, monto, tipo, metodo_pago, fecha, notas) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param("idsssss", $cliente_id, $concepto, $monto, $tipo, $metodo_pago, $fecha, $notas);
                $mensaje = 'Movimiento registrado.';
            }
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($accion === 'eliminar') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM ingresos WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $mensaje = 'Movimiento eliminado.';
        }
    }
}

// FILTROS
$filtroTipo  = sanitize($_GET['tipo'] ?? '');
$filtroMes   = sanitizeInt($_GET['mes'] ?? date('n'));
$filtroAnio  = sanitizeInt($_GET['anio'] ?? date('Y'));
$pagina      = max(1, sanitizeInt($_GET['p'] ?? 1));
$porPagina   = 15;
$offset      = ($pagina - 1) * $porPagina;

$where  = "WHERE MONTH(fecha)=? AND YEAR(fecha)=?";
$params = [$filtroMes, $filtroAnio];
$tipos  = 'ii';

if ($filtroTipo) {
    $where   .= " AND tipo=?";
    $params[] = $filtroTipo;
    $tipos   .= 's';
}

$stmtC = $db->prepare("SELECT COUNT(*) as t FROM ingresos $where");
$stmtC->bind_param($tipos, ...$params);
$stmtC->execute();
$totalReg   = $stmtC->get_result()->fetch_assoc()['t'];
$totalPags  = ceil($totalReg / $porPagina);
$stmtC->close();

$sqlI = "SELECT i.*, cl.nombre, cl.apellido FROM ingresos i LEFT JOIN clientes cl ON i.cliente_id=cl.id $where ORDER BY i.fecha DESC, i.id DESC LIMIT ? OFFSET ?";
$tiposL  = $tipos . 'ii';
$paramsL = array_merge($params, [$porPagina, $offset]);
$stmtI   = $db->prepare($sqlI);
$stmtI->bind_param($tiposL, ...$paramsL);
$stmtI->execute();
$movimientos = $stmtI->get_result();
$stmtI->close();

// Totales del mes
$r = $db->query("SELECT COALESCE(SUM(monto),0) as t FROM ingresos WHERE tipo='ingreso' AND MONTH(fecha)=$filtroMes AND YEAR(fecha)=$filtroAnio");
$totalIngMes = $r->fetch_assoc()['t'];
$r = $db->query("SELECT COALESCE(SUM(monto),0) as t FROM ingresos WHERE tipo='egreso' AND MONTH(fecha)=$filtroMes AND YEAR(fecha)=$filtroAnio");
$totalEgrMes = $r->fetch_assoc()['t'];
$balanceMes  = $totalIngMes - $totalEgrMes;

// Totales año
$r = $db->query("SELECT COALESCE(SUM(monto),0) as t FROM ingresos WHERE tipo='ingreso' AND YEAR(fecha)=$filtroAnio");
$totalIngAnio = $r->fetch_assoc()['t'];
$r = $db->query("SELECT COALESCE(SUM(monto),0) as t FROM ingresos WHERE tipo='egreso' AND YEAR(fecha)=$filtroAnio");
$totalEgrAnio = $r->fetch_assoc()['t'];

// Gráfica por mes del año
$graficaData = [];
$rG = $db->query("SELECT MONTH(fecha) as mes, tipo, COALESCE(SUM(monto),0) as total FROM ingresos WHERE YEAR(fecha)=$filtroAnio GROUP BY MONTH(fecha), tipo ORDER BY mes");
while ($row = $rG->fetch_assoc()) $graficaData[$row['mes']][$row['tipo']] = $row['total'];
$maxGrafica = 1;
foreach ($graficaData as $m) { foreach ($m as $v) $maxGrafica = max($maxGrafica, $v); }

// Gráfica semana: últimos 7 días (día por día)
$graficaSemana = [];
for ($d = 6; $d >= 0; $d--) {
    $fecha = date('Y-m-d', strtotime("-$d days"));
    $graficaSemana[$fecha] = ['ingreso' => 0, 'egreso' => 0];
}
$rS = $db->query("
    SELECT DATE(fecha) as dia, tipo, COALESCE(SUM(monto),0) as total
    FROM ingresos
    WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(fecha), tipo
    ORDER BY dia
");
while ($row = $rS->fetch_assoc()) {
    $graficaSemana[$row['dia']][$row['tipo']] = (float)$row['total'];
}

// Gráfica año: los 12 meses del año filtrado
$graficaAnio = [];
for ($m = 1; $m <= 12; $m++) $graficaAnio[$m] = ['ingreso' => 0, 'egreso' => 0];
$rA = $db->query("
    SELECT MONTH(fecha) as mes, tipo, COALESCE(SUM(monto),0) as total
    FROM ingresos
    WHERE YEAR(fecha)=$filtroAnio
    GROUP BY MONTH(fecha), tipo
    ORDER BY mes
");
while ($row = $rA->fetch_assoc()) {
    $graficaAnio[(int)$row['mes']][$row['tipo']] = (float)$row['total'];
}

$todosClientes = $db->query("SELECT id, nombre, apellido FROM clientes ORDER BY nombre ASC");
$mesesNombres  = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$mesesFull     = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$metodosLabel  = ['efectivo'=>'Efectivo','transferencia'=>'Transferencia','nequi'=>'Nequi','daviplata'=>'Daviplata','tarjeta'=>'Tarjeta'];
$avClasses     = ['av-a','av-b','av-c','av-d','av-e'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ingresos – <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;600;700&family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#faf8f6;--surface:#fff;--surface2:#fdf5f8;--navy:#1a1f3c;--navy-mid:#2d3460;--navy-soft:#e8eaf6;--rose:#e8789a;--rose-light:#f5b8ce;--rose-pale:#fce8f0;--rose-deep:#c4547a;--teal:#5bbcb8;--teal-light:#9ddbd8;--teal-pale:#e0f5f4;--teal-deep:#3a9994;--lavender:#9e8bc9;--lav-light:#c5b8e8;--lav-pale:#f0ecfb;--text:#2a2040;--text-mid:#6b5e7a;--text-dim:#a899b5;--border:#ede0ea;--sidebar-w:230px;--shadow-sm:0 2px 8px rgba(180,120,160,.10);--shadow-md:0 4px 20px rgba(180,120,160,.15);}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;font-size:13px;min-height:0;}
  .sidebar{width:var(--sidebar-w);background:var(--navy);display:flex;flex-direction:column;flex-shrink:0;position:relative;overflow:hidden;min-height:0;}
  .sidebar::before{content:'';position:absolute;width:180px;height:180px;border-radius:50%;background:radial-gradient(circle,rgba(232,120,154,.18) 0%,transparent 70%);top:-40px;right:-50px;pointer-events:none;}
  .sidebar::after{content:'';position:absolute;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle,rgba(91,188,184,.15) 0%,transparent 70%);bottom:60px;left:-30px;pointer-events:none;}
  .logo-area{padding:24px 20px 20px;display:flex;flex-direction:column;align-items:center;border-bottom:1px solid rgba(255,255,255,.08);position:relative;z-index:1;}
  .logo-circle{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:34px;color:var(--navy);font-weight:700;margin-bottom:10px;box-shadow:0 4px 16px rgba(232,120,154,.35);overflow:hidden;}
  .logo-circle img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
  .logo-name{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--rose-light);text-align:center;line-height:1.15;}
  .logo-sub{font-size:9px;color:var(--teal-light);letter-spacing:.22em;text-transform:uppercase;margin-top:3px;font-weight:600;}
  nav{padding:14px 0;flex:1;position:relative;z-index:1;overflow-y:auto;}
  .nav-section{padding:10px 20px 5px;font-size:9px;letter-spacing:.18em;color:rgba(255,255,255,.3);text-transform:uppercase;font-weight:600;}
  .nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,.55);transition:all .2s;border-left:3px solid transparent;font-size:12.5px;font-weight:600;text-decoration:none;}
  .nav-item:hover{color:rgba(255,255,255,.9);background:rgba(255,255,255,.05);}
  .nav-item.active{color:#fff;border-left-color:var(--rose-light);background:rgba(232,120,154,.15);}
  .nav-item svg{width:16px;height:16px;flex-shrink:0;}
  .sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08);position:relative;z-index:1;}
  .user-chip{display:flex;align-items:center;gap:10px;}
  .avatar-sm{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:18px;color:var(--navy);font-weight:700;flex-shrink:0;overflow:hidden;}
  .avatar-sm img{width:100%;height:100%;object-fit:cover;}
  .badge{margin-left:auto;background:var(--rose);color:#fff;border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;}
  .badge.teal{background:var(--teal-deep);}
  .user-name{font-size:12px;color:#fff;font-weight:600;}
  .user-role{font-size:10px;color:var(--teal-light);}
  .logout-btn{display:block;margin-top:10px;font-size:11px;color:rgba(255,255,255,.35);text-decoration:none;transition:color .2s;}
  .logout-btn:hover{color:var(--rose-light);}
  .main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0;}
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
  .btn-sm{padding:4px 12px;font-size:11px;border-radius:14px;}
  .content{flex:1;overflow-y:auto;padding:22px 26px;display:flex;flex-direction:column;gap:16px;scrollbar-width:thin;scrollbar-color:var(--border) transparent;min-height:0;}
  .content::-webkit-scrollbar{width:4px;}
  .content::-webkit-scrollbar-thumb{background:var(--rose-light);border-radius:2px;}

  /* STATS */
  .stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
  .stat-card{background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:16px 18px;box-shadow:var(--shadow-sm);position:relative;overflow:hidden;}
  .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:14px 14px 0 0;}
  .sc1::before{background:linear-gradient(90deg,var(--teal),var(--teal-light))}
  .sc2::before{background:linear-gradient(90deg,var(--rose),var(--rose-light))}
  .sc3::before{background:linear-gradient(90deg,var(--lavender),var(--lav-light))}
  .sc4::before{background:linear-gradient(90deg,#f0a500,#f5c842)}
  .stat-label{font-size:10px;letter-spacing:.1em;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-bottom:6px;}
  .stat-value{font-family:'Dancing Script',cursive;font-size:26px;font-weight:700;color:var(--navy);line-height:1;}
  .stat-sub{font-size:11px;color:var(--text-dim);font-weight:600;margin-top:4px;}

  /* LAYOUT */
  .two-col{display:grid;grid-template-columns:1fr 300px;gap:16px;}
  .card{background:var(--surface);border:1.5px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);}
  .card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--surface2);flex-wrap:wrap;}
  .card-title{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--navy);flex:1;}

  /* GRÁFICA */
  .grafica-wrap{padding:16px 20px;}
  .grafica-bars{display:flex;align-items:flex-end;gap:6px;height:100px;margin-bottom:10px;}
  .bar-group{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;}
  .bars-duo{display:flex;gap:2px;align-items:flex-end;width:100%;}
  .bar-ing{flex:1;background:linear-gradient(180deg,var(--teal-light),var(--teal));border-radius:4px 4px 0 0;min-height:3px;transition:opacity .2s;}
  .bar-egr{flex:1;background:linear-gradient(180deg,var(--rose-light),var(--rose));border-radius:4px 4px 0 0;min-height:3px;transition:opacity .2s;}
  .bar-lbl{font-size:9px;color:var(--text-dim);font-weight:700;}
  .bar-mes-act .bar-ing{box-shadow:0 -3px 10px rgba(91,188,184,.4);}
  .bar-mes-act .bar-egr{box-shadow:0 -3px 10px rgba(232,120,154,.4);}
  .grafica-legend{display:flex;gap:16px;margin-top:10px;padding-top:10px;border-top:1.5px solid var(--border);}
  .vista-btns{display:flex;gap:4px;}
  .vista-btn{padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);cursor:pointer;transition:all .2s;font-family:'Nunito',sans-serif;}
  .vista-btn:hover{border-color:var(--teal-light);color:var(--teal-deep);}
  .vista-btn.active{background:var(--teal);border-color:var(--teal);color:#fff;}
  .legend-item{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--text-mid);font-weight:600;}
  .legend-dot{width:10px;height:10px;border-radius:3px;}
  .dot-ing{background:var(--teal);}
  .dot-egr{background:var(--rose);}

  /* TABLE */
  table{width:100%;border-collapse:collapse;}
  th{text-align:left;padding:11px 20px;font-size:10px;letter-spacing:.14em;color:var(--text-dim);text-transform:uppercase;border-bottom:1.5px solid var(--border);font-weight:700;background:#fdf9fb;}
  td{padding:11px 20px;border-bottom:1px solid var(--border);font-size:12.5px;color:var(--text-mid);vertical-align:middle;}
  tr:last-child td{border-bottom:none;}
  tr:hover td{background:var(--rose-pale);}
  .tipo-ing{color:var(--teal-deep);font-weight:700;}
  .tipo-egr{color:var(--rose-deep);font-weight:700;}
  .monto-ing{font-family:'Dancing Script',cursive;font-size:18px;color:var(--teal-deep);font-weight:700;}
  .monto-egr{font-family:'Dancing Script',cursive;font-size:18px;color:var(--rose-deep);font-weight:700;}
  .metodo-badge{font-size:10px;font-weight:700;color:var(--teal-deep);background:var(--teal-pale);padding:2px 8px;border-radius:20px;}

  /* FILTROS */
  .filtros{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .filtro-btn{padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);text-decoration:none;transition:all .2s;}
  .filtro-btn:hover,.filtro-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}
  .mes-select{background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:4px 10px;font-family:'Nunito',sans-serif;font-size:12px;font-weight:600;color:var(--navy);outline:none;cursor:pointer;}

  /* RESUMEN LATERAL */
  .resumen-item{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
  .resumen-item:last-child{border-bottom:none;}
  .resumen-label{font-size:11px;color:var(--text-mid);font-weight:600;}
  .resumen-val{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;}
  .rv-ing{color:var(--teal-deep);}
  .rv-egr{color:var(--rose-deep);}
  .rv-bal{color:var(--navy);}

  /* PAGINATION */
  .pagination{display:flex;gap:6px;justify-content:center;}
  .page-btn{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);text-decoration:none;transition:all .2s;}
  .page-btn:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .page-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}

  /* MODAL */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(26,31,60,.45);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
  .modal-overlay.open{display:flex;}
  .modal{background:var(--surface);border-radius:20px;width:100%;max-width:460px;box-shadow:var(--shadow-md);animation:fadeUp .3s ease both;max-height:92vh;overflow-y:auto;}
  .modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;background:var(--surface2);border-radius:20px 20px 0 0;}
  .modal-title{font-family:'Dancing Script',cursive;font-size:22px;font-weight:700;color:var(--navy);flex:1;}
  .modal-close{width:28px;height:28px;border-radius:50%;background:var(--rose-pale);border:none;color:var(--rose-deep);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;font-weight:700;}
  .modal-body{padding:20px 24px;}
  .modal-footer{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--surface2);border-radius:0 0 20px 20px;}
  .form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:13px;}
  .form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  label{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);font-weight:700;}
  .form-input{background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;color:var(--navy);font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:600;outline:none;transition:border-color .2s;width:100%;}
  .form-input:focus{border-color:var(--rose-light);box-shadow:0 0 0 3px rgba(232,120,154,.10);}
  textarea.form-input{resize:vertical;min-height:60px;}

  /* TIPO SELECTOR */
  .tipo-btns{display:flex;gap:8px;}
  .tipo-radio{display:none;}
  .tipo-radio+label{padding:8px 20px;border-radius:20px;border:1.5px solid var(--border);cursor:pointer;font-size:12px;font-weight:700;color:var(--text-mid);transition:all .2s;}
  .tipo-radio:checked+label.ing-lbl{background:var(--teal-pale);border-color:var(--teal-light);color:var(--teal-deep);}
  .tipo-radio:checked+label.egr-lbl{background:var(--rose-pale);border-color:var(--rose-light);color:var(--rose-deep);}

  .alert{padding:10px 16px;border-radius:10px;font-size:12px;font-weight:600;}
  .alert-success{background:var(--teal-pale);color:var(--teal-deep);border:1.5px solid var(--teal-light);}
  .alert-error{background:var(--rose-pale);color:var(--rose-deep);border:1.5px solid var(--rose-light);}
  .empty-row{text-align:center;padding:36px;color:var(--text-dim);font-size:13px;}

  @keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>

<?php require_once '../includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <span class="page-title">Panel de Ingresos</span>
    <div class="topbar-sep"></div>
    <button class="btn btn-primary" onclick="abrirModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nuevo Movimiento
    </button>
  </div>

  <div class="content">

    <?php if ($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
      <div class="stat-card sc1"><div class="stat-label">Ingresos del mes</div><div class="stat-value"><?=formatoPeso($totalIngMes)?></div><div class="stat-sub"><?=$mesesFull[$filtroMes]?> <?=$filtroAnio?></div></div>
      <div class="stat-card sc2"><div class="stat-label">Egresos del mes</div><div class="stat-value"><?=formatoPeso($totalEgrMes)?></div><div class="stat-sub"><?=$mesesFull[$filtroMes]?> <?=$filtroAnio?></div></div>
      <div class="stat-card sc3"><div class="stat-label">Balance del mes</div><div class="stat-value" style="color:<?=$balanceMes>=0?'var(--teal-deep)':'var(--rose-deep)'?>"><?=formatoPeso($balanceMes)?></div><div class="stat-sub"><?=$balanceMes>=0?'ganancia':'pérdida'?></div></div>
      <div class="stat-card sc4"><div class="stat-label">Ingresos <?=$filtroAnio?></div><div class="stat-value"><?=formatoPeso($totalIngAnio)?></div><div class="stat-sub">total del año</div></div>
    </div>

    <!-- GRÁFICA + RESUMEN -->
    <div class="two-col">
      <div class="card">
        <div class="card-header">
          <div class="card-title" id="graficaTitulo">Flujo Mensual <?=$filtroAnio?></div>
          <div class="vista-btns">
            <button class="vista-btn active" onclick="cambiarVista('mes')">Mes</button>
            <button class="vista-btn" onclick="cambiarVista('semana')">Semana</button>
            <button class="vista-btn" onclick="cambiarVista('anio')">Año</button>
          </div>
        </div>
        <div class="grafica-wrap">
          <div class="grafica-bars" id="graficaBars">
            <?php for($m=1;$m<=12;$m++):
              $ing = $graficaData[$m]['ingreso'] ?? 0;
              $egr = $graficaData[$m]['egreso']  ?? 0;
              $hIng = $ing > 0 ? max(4, round(($ing/$maxGrafica)*90)) : 2;
              $hEgr = $egr > 0 ? max(4, round(($egr/$maxGrafica)*90)) : 2;
              $esActual = ($m == $filtroMes);
            ?>
            <div class="bar-group <?=$esActual?'bar-mes-act':''?>">
              <div class="bars-duo" style="height:90px;align-items:flex-end;">
                <div class="bar-ing" style="height:<?=$hIng?>px" title="<?=$mesesFull[$m]?>: <?=formatoPeso($ing)?>"></div>
                <div class="bar-egr" style="height:<?=$hEgr?>px" title="Egreso <?=$mesesFull[$m]?>: <?=formatoPeso($egr)?>"></div>
              </div>
              <div class="bar-lbl"><?=$mesesNombres[$m]?></div>
            </div>
            <?php endfor; ?>
          </div>
          <div class="grafica-legend">
            <div class="legend-item"><div class="legend-dot dot-ing"></div>Ingresos</div>
            <div class="legend-item"><div class="legend-dot dot-egr"></div>Egresos</div>
          </div>
        </div>
      </div>

      <div class="card" style="align-self:start;">
        <div class="card-header"><div class="card-title">Resumen <?=$mesesFull[$filtroMes]?></div></div>
        <div class="resumen-item"><span class="resumen-label">Total ingresos</span><span class="resumen-val rv-ing"><?=formatoPeso($totalIngMes)?></span></div>
        <div class="resumen-item"><span class="resumen-label">Total egresos</span><span class="resumen-val rv-egr"><?=formatoPeso($totalEgrMes)?></span></div>
        <div class="resumen-item" style="border-top:2px solid var(--border);"><span class="resumen-label">Balance neto</span><span class="resumen-val rv-bal"><?=formatoPeso($balanceMes)?></span></div>
        <div class="resumen-item"><span class="resumen-label">Movimientos</span><span style="font-weight:700;color:var(--navy);"><?=$totalReg?></span></div>
        <div style="padding:14px 20px;border-top:1px solid var(--border);">
          <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;">
            <select name="mes" class="mes-select" onchange="this.form.submit()">
              <?php for($m=1;$m<=12;$m++): ?>
              <option value="<?=$m?>" <?=$m==$filtroMes?'selected':''?>><?=$mesesFull[$m]?></option>
              <?php endfor; ?>
            </select>
            <select name="anio" class="mes-select" onchange="this.form.submit()">
              <?php for($a=date('Y');$a>=date('Y')-3;$a--): ?>
              <option value="<?=$a?>" <?=$a==$filtroAnio?'selected':''?>><?=$a?></option>
              <?php endfor; ?>
            </select>
          </form>
        </div>
      </div>
    </div>

    <!-- TABLA MOVIMIENTOS -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Movimientos</div>
        <div class="filtros">
          <a href="?mes=<?=$filtroMes?>&anio=<?=$filtroAnio?>" class="filtro-btn <?=!$filtroTipo?'active':''?>">Todos</a>
          <a href="?mes=<?=$filtroMes?>&anio=<?=$filtroAnio?>&tipo=ingreso" class="filtro-btn <?=$filtroTipo==='ingreso'?'active':''?>">Ingresos</a>
          <a href="?mes=<?=$filtroMes?>&anio=<?=$filtroAnio?>&tipo=egreso" class="filtro-btn <?=$filtroTipo==='egreso'?'active':''?>">Egresos</a>
        </div>
      </div>
      <table>
        <thead><tr><th>Fecha</th><th>Concepto</th><th>Cliente</th><th>Método</th><th>Tipo</th><th>Monto</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php if($movimientos->num_rows===0): ?>
            <tr><td colspan="7" class="empty-row">Sin movimientos este mes.</td></tr>
          <?php else: $i=0; while($mov=$movimientos->fetch_assoc()): ?>
          <tr>
            <td style="font-size:11px;font-weight:600;"><?=formatoFecha($mov['fecha'])?></td>
            <td style="color:var(--navy);font-weight:600;"><?=htmlspecialchars($mov['concepto'])?></td>
            <td><?= $mov['nombre'] ? htmlspecialchars($mov['nombre'].' '.$mov['apellido']) : '<span style="color:var(--text-dim);">—</span>' ?></td>
            <td><span class="metodo-badge"><?=$metodosLabel[$mov['metodo_pago']]??ucfirst($mov['metodo_pago'])?></span></td>
            <td><span class="<?=$mov['tipo']==='ingreso'?'tipo-ing':'tipo-egr'?>"><?=$mov['tipo']==='ingreso'?'↑ Ingreso':'↓ Egreso'?></span></td>
            <td><span class="<?=$mov['tipo']==='ingreso'?'monto-ing':'monto-egr'?>"><?=formatoPeso($mov['monto'])?></span></td>
            <td><div style="display:flex;gap:5px;">
              <button class="btn btn-ghost btn-sm" onclick="editarMovimiento(<?=htmlspecialchars(json_encode($mov))?>)">Editar</button>
              <button class="btn btn-danger btn-sm" onclick="eliminar(<?=$mov['id']?>, '<?=htmlspecialchars($mov['concepto'])?>')">Eliminar</button>
            </div></td>
          </tr>
          <?php $i++; endwhile; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if($totalPags>1): ?>
    <div class="pagination">
      <?php for($p=1;$p<=$totalPags;$p++): ?>
        <a href="?mes=<?=$filtroMes?>&anio=<?=$filtroAnio?>&tipo=<?=urlencode($filtroTipo)?>&p=<?=$p?>" class="page-btn <?=$p===$pagina?'active':''?>"><?=$p?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="modalMov">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="movTitulo">Nuevo Movimiento</div>
      <button class="modal-close" onclick="cerrarModal()">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="movId" value="0">
      <div class="modal-body">
        <div class="form-group">
          <label>Tipo *</label>
          <div class="tipo-btns">
            <input type="radio" name="tipo" id="tIng" value="ingreso" class="tipo-radio" checked>
            <label for="tIng" class="ing-lbl">↑ Ingreso</label>
            <input type="radio" name="tipo" id="tEgr" value="egreso" class="tipo-radio">
            <label for="tEgr" class="egr-lbl">↓ Egreso</label>
          </div>
        </div>
        <div class="form-group"><label>Concepto *</label><input class="form-input" type="text" name="concepto" id="mConcepto" placeholder="Ej: Pago sesión quinceañera" required></div>
        <div class="form-row">
          <div class="form-group"><label>Monto ($) *</label><input class="form-input" type="number" name="monto" id="mMonto" min="0" step="1000" placeholder="0" required></div>
          <div class="form-group"><label>Fecha *</label><input class="form-input" type="date" name="fecha" id="mFecha" value="<?=date('Y-m-d')?>" required></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Método de pago</label>
            <select class="form-input" name="metodo_pago" id="mMetodo">
              <option value="efectivo">Efectivo</option>
              <option value="transferencia">Transferencia</option>
              <option value="nequi">Nequi</option>
              <option value="daviplata">Daviplata</option>
              <option value="tarjeta">Tarjeta</option>
            </select>
          </div>
          <div class="form-group"><label>Cliente (opcional)</label>
            <select class="form-input" name="cliente_id" id="mClienteId">
              <option value="">Sin cliente</option>
              <?php $todosClientes->data_seek(0); while($cl=$todosClientes->fetch_assoc()): ?>
              <option value="<?=$cl['id']?>"><?=htmlspecialchars($cl['nombre'].' '.$cl['apellido'])?></option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>
        <div class="form-group"><label>Notas</label><textarea class="form-input" name="notas" id="mNotas" placeholder="Observaciones..."></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="cerrarModal()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<form method="POST" id="fElim" style="display:none;"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" id="elimId"></form>

<script>
function cerrarModal(){ document.getElementById('modalMov').classList.remove('open'); }
function abrirModal(){
  document.getElementById('movTitulo').textContent='Nuevo Movimiento';
  document.getElementById('movId').value='0';
  document.getElementById('mConcepto').value='';
  document.getElementById('mMonto').value='';
  document.getElementById('mFecha').value='<?=date('Y-m-d')?>';
  document.getElementById('mMetodo').value='efectivo';
  document.getElementById('mClienteId').value='';
  document.getElementById('mNotas').value='';
  document.querySelector('input[name="tipo"][value="ingreso"]').checked=true;
  document.getElementById('modalMov').classList.add('open');
}
function editarMovimiento(m){
  document.getElementById('movTitulo').textContent='Editar Movimiento';
  document.getElementById('movId').value=m.id;
  document.getElementById('mConcepto').value=m.concepto;
  document.getElementById('mMonto').value=m.monto;
  document.getElementById('mFecha').value=m.fecha;
  document.getElementById('mMetodo').value=m.metodo_pago;
  document.getElementById('mClienteId').value=m.cliente_id||'';
  document.getElementById('mNotas').value=m.notas||'';
  document.querySelector('input[name="tipo"][value="'+m.tipo+'"]').checked=true;
  document.getElementById('modalMov').classList.add('open');
}
function eliminar(id,c){ if(confirm('¿Eliminar "'+c+'"?')){ document.getElementById('elimId').value=id; document.getElementById('fElim').submit(); } }
document.getElementById('modalMov').addEventListener('click',function(e){ if(e.target===this) cerrarModal(); });
<?php if($error): ?>document.getElementById('modalMov').classList.add('open');<?php endif; ?>

// ── Gráfica dinámica Semana / Mes / Año ──────────────────────────────
const datosMes = <?php
echo json_encode([
    [
        'lbl' => $mesesNombres[$filtroMes],
        'ing' => (float)($graficaData[$filtroMes]['ingreso'] ?? 0),
        'egr' => (float)($graficaData[$filtroMes]['egreso'] ?? 0),
        'act' => true
    ]
]);
?>;

const datosSemana = <?php
  $out = [];
  $diasSemana = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
  foreach($graficaSemana as $fecha => $vals){
    $dt = new DateTime($fecha);
    $out[] = [
      'lbl' => $diasSemana[(int)$dt->format('w')] . ' ' . $dt->format('d/m'),
      'ing' => (float)($vals['ingreso'] ?? 0),
      'egr' => (float)($vals['egreso']  ?? 0),
      'act' => ($fecha === date('Y-m-d'))
    ];
  }
  echo json_encode($out);
?>;

const datosAnio = <?php
  $out = [];
  foreach($graficaAnio as $mes => $vals){
    $out[] = [
      'lbl' => $mesesNombres[$mes],
      'ing' => (float)($vals['ingreso'] ?? 0),
      'egr' => (float)($vals['egreso']  ?? 0),
      'act' => ($mes == $filtroMes)
    ];
  }
  echo json_encode($out);
?>;

const titulos = {mes:'Flujo del Mes – <?=$mesesFull[$filtroMes]?> <?=$filtroAnio?>', semana:'Últimos 7 Días', anio:'<?=$filtroAnio?> – Todos los Meses'};

function formatCOP(n){
  return new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',minimumFractionDigits:0}).format(n);
}

function renderGrafica(datos){
  const cont = document.getElementById('graficaBars');
  const alturaMaxPx = 90; // altura en px para la barra más alta
  const max = Math.max(1, ...datos.map(d=>Math.max(d.ing,d.egr)));
  cont.innerHTML = datos.map(d=>{
    const hI = d.ing > 0 ? Math.max(4, Math.round((d.ing/max)*alturaMaxPx)) : 2;
    const hE = d.egr > 0 ? Math.max(4, Math.round((d.egr/max)*alturaMaxPx)) : 2;
    return `<div class="bar-group ${d.act?'bar-mes-act':''}">
      <div class="bars-duo" style="height:${alturaMaxPx}px;align-items:flex-end;">
        <div class="bar-ing" style="height:${hI}px" title="${d.lbl} – Ing: ${formatCOP(d.ing)}"></div>
        <div class="bar-egr" style="height:${hE}px" title="${d.lbl} – Egr: ${formatCOP(d.egr)}"></div>
      </div>
      <div class="bar-lbl">${d.lbl}</div>
    </div>`;
  }).join('');
}

function cambiarVista(vista){
  document.querySelectorAll('.vista-btn').forEach(b=>b.classList.remove('active'));
  event.target.classList.add('active');
  document.getElementById('graficaTitulo').textContent = titulos[vista];
  const map = {mes:datosMes, semana:datosSemana, anio:datosAnio};
  renderGrafica(map[vista]);
}
</script>
</body>
</html>