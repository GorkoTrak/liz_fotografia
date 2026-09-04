<?php
date_default_timezone_set('America/Bogota');
require_once 'config.php';
requerirLogin();
$esAdmin = (($_SESSION['usuario_rol'] ?? '') === 'admin');
$db = getDB();

// ── Stats principales ──
$r = $db->query("SELECT COUNT(*) as total FROM clientes");
$totalClientes = $r->fetch_assoc()['total'];

$r = $db->query("SELECT COUNT(*) as total FROM citas WHERE MONTH(fecha)=MONTH(CURDATE()) AND YEAR(fecha)=YEAR(CURDATE())");
$citasMes = $r->fetch_assoc()['total'];

$r = $db->query("SELECT COUNT(*) as total FROM sesiones WHERE estado_entrega != 'entregado'");
$sesionesPendEntrega = $r->fetch_assoc()['total'];

$r = $db->query("SELECT COUNT(*) as total FROM sesiones WHERE estado_pago != 'pagado'");
$sesionesPendPago = $r->fetch_assoc()['total'];

$r = $db->query("SELECT COALESCE(SUM(monto),0) as total FROM ingresos WHERE tipo='ingreso' AND MONTH(fecha)=MONTH(CURDATE()) AND YEAR(fecha)=YEAR(CURDATE())");
$ingresosMes = $r->fetch_assoc()['total'];

$r = $db->query("SELECT COALESCE(SUM(saldo),0) as total FROM sesiones WHERE estado_pago != 'pagado'");
$saldoPendiente = $r->fetch_assoc()['total'];

$r = $db->query("SELECT COUNT(*) as total FROM sesiones WHERE fecha_sesion > CURDATE()");
$sesionesProximas = $r->fetch_assoc()['total'];

// ── Alertas: sesiones sin pagar + entregas atrasadas + citas mañana ──
$alertasSesiones = $db->query("
    SELECT 'cobro' AS tipo,
           CONCAT(cl.nombre,' ',cl.apellido) AS cliente,
           s.total AS monto,
           s.saldo AS saldo,
           s.fecha_sesion AS fecha_ref,
           s.id AS ref_id
    FROM sesiones s
    JOIN clientes cl ON s.cliente_id = cl.id
    WHERE s.estado_pago != 'pagado'
    ORDER BY s.fecha_sesion ASC
    LIMIT 4
");

$alertasEntrega = $db->query("
    SELECT 'entrega' AS tipo,
           CONCAT(cl.nombre,' ',cl.apellido) AS cliente,
           0 AS monto, 0 AS saldo,
           s.fecha_sesion AS fecha_ref,
           s.id AS ref_id
    FROM sesiones s
    JOIN clientes cl ON s.cliente_id = cl.id
    WHERE s.estado_entrega != 'entregado'
    ORDER BY s.fecha_sesion ASC
    LIMIT 4
");

$alertasCitas = $db->query("
    SELECT 'cita' AS tipo,
           CONCAT(cl.nombre,' ',cl.apellido) AS cliente,
           0 AS monto, 0 AS saldo,
           c.fecha AS fecha_ref,
           c.id AS ref_id
    FROM citas c
    JOIN clientes cl ON c.cliente_id = cl.id
    WHERE c.fecha = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND c.estado != 'cancelada'
    LIMIT 3
");

// ── Últimas sesiones ──
$ultimasSesiones = $db->query("
    SELECT s.*, cl.nombre, cl.apellido, cl.telefono, p.nombre AS servicio
    FROM sesiones s
    JOIN clientes cl ON s.cliente_id = cl.id
    LEFT JOIN productos p ON s.producto_id = p.id
    ORDER BY s.fecha_sesion DESC, s.id DESC
    LIMIT 6
");

// ── Gráfica ingresos ──
$ingresosPorMes = $db->query("
    SELECT MONTH(fecha) as mes, YEAR(fecha) as anio, COALESCE(SUM(monto),0) as total
    FROM ingresos
    WHERE tipo='ingreso' AND fecha >= DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
    GROUP BY YEAR(fecha), MONTH(fecha)
    ORDER BY anio ASC, mes ASC
");
$datosMeses = [];
while ($row = $ingresosPorMes->fetch_assoc()) $datosMeses[] = $row;

$r = $db->query("SELECT COALESCE(SUM(monto),0) as total FROM ingresos WHERE tipo='ingreso' AND YEAR(fecha)=YEAR(CURDATE())");
$totalAnio = $r->fetch_assoc()['total'];

$mesesNombres = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$avClasses    = ['av-a','av-b','av-c','av-d','av-e'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard – <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;600;700&family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#faf8f6;--surface:#fff;--surface2:#fdf5f8;--navy:#1a1f3c;--navy-mid:#2d3460;--navy-soft:#e8eaf6;--rose:#e8789a;--rose-light:#f5b8ce;--rose-pale:#fce8f0;--rose-deep:#c4547a;--teal:#5bbcb8;--teal-light:#9ddbd8;--teal-pale:#e0f5f4;--teal-deep:#3a9994;--lavender:#9e8bc9;--lav-light:#c5b8e8;--lav-pale:#f0ecfb;--text:#2a2040;--text-mid:#6b5e7a;--text-dim:#a899b5;--border:#ede0ea;--sidebar-w:230px;--shadow-sm:0 2px 8px rgba(180,120,160,.10);--shadow-md:0 4px 20px rgba(180,120,160,.15);}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;font-size:13px;}
  .sidebar{width:var(--sidebar-w);background:var(--navy);display:flex;flex-direction:column;flex-shrink:0;height:100%;position:relative;overflow:hidden;}
  .sidebar::before{content:'';position:absolute;width:180px;height:180px;border-radius:50%;background:radial-gradient(circle,rgba(232,120,154,.18) 0%,transparent 70%);top:-40px;right:-50px;pointer-events:none;}
  .sidebar::after{content:'';position:absolute;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle,rgba(91,188,184,.15) 0%,transparent 70%);bottom:60px;left:-30px;pointer-events:none;}
  .logo-area{padding:24px 20px 20px;display:flex;flex-direction:column;align-items:center;border-bottom:1px solid rgba(255,255,255,.08);position:relative;z-index:1;}
  .logo-circle{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:34px;color:var(--navy);font-weight:700;margin-bottom:10px;box-shadow:0 4px 16px rgba(232,120,154,.35);overflow:hidden;}
  .logo-circle img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
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
  .topbar-date{font-size:11px;color:var(--text-dim);font-weight:600;}
  .btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:20px;font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:700;cursor:pointer;border:none;transition:all .2s;text-decoration:none;}
  .btn-primary{background:linear-gradient(135deg,var(--rose),var(--rose-deep));color:#fff;box-shadow:0 4px 14px rgba(196,84,122,.3);}
  .btn-primary:hover{transform:translateY(-1px);}
  .btn-ghost{background:transparent;color:var(--text-mid);border:1.5px solid var(--border);}
  .btn-ghost:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .btn svg{width:14px;height:14px;}
  .content{flex:1;overflow-y:auto;overflow-x:auto;padding:22px 26px;display:flex;flex-direction:column;gap:18px;scrollbar-width:thin;scrollbar-color:var(--border) transparent;}
  .content::-webkit-scrollbar{width:4px;}
  .content::-webkit-scrollbar-thumb{background:var(--rose-light);border-radius:2px;}

  /* STATS */
  .stats-row{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:14px;min-width:0;}
  .stat-card{background:var(--surface);border:1.5px solid var(--border);border-radius:16px;padding:18px 20px;position:relative;overflow:hidden;box-shadow:var(--shadow-sm);animation:fadeUp .4s ease both;}
  .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:16px 16px 0 0;}
  .sc1::before{background:linear-gradient(90deg,var(--rose),var(--rose-light))}
  .sc2::before{background:linear-gradient(90deg,var(--teal),var(--teal-light))}
  .sc3::before{background:linear-gradient(90deg,#f0a500,#f5c842)}
  .sc4::before{background:linear-gradient(90deg,var(--lavender),var(--lav-light))}
  .stat-blob{position:absolute;width:80px;height:80px;border-radius:50%;right:-20px;bottom:-20px;opacity:.12;}
  .sc1 .stat-blob{background:var(--rose)}.sc2 .stat-blob{background:var(--teal)}.sc3 .stat-blob{background:#f0a500}.sc4 .stat-blob{background:var(--lavender)}.sc5 .stat-blob{background:#4caf50}
  .stat-label{font-size:10px;letter-spacing:.12em;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-bottom:8px;}
  .stat-value{font-family:'Dancing Script',cursive;font-size:34px;font-weight:700;color:var(--navy);line-height:1;margin-bottom:6px;}
  .stat-sub{font-size:11px;font-weight:600;color:var(--text-dim);}
  .stat-icon{position:absolute;top:16px;right:16px;width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;}
  .sc1 .stat-icon{background:var(--rose-pale)}.sc2 .stat-icon{background:var(--teal-pale)}.sc3 .stat-icon{background:#fff4e0}.sc4 .stat-icon{background:var(--lav-pale)}.sc5 .stat-icon{background:#e8f5e9}
  .sc5 .stat-blob{background:#4caf50}
  .stat-icon svg{width:18px;height:18px;}
  .sc1 .stat-icon svg{color:var(--rose)}.sc2 .stat-icon svg{color:var(--teal)}.sc3 .stat-icon svg{color:#b07a30}.sc4 .stat-icon svg{color:var(--lavender)}
  .eye-btn{position:absolute;bottom:14px;right:14px;background:none;border:none;cursor:pointer;color:var(--text-dim);padding:4px;border-radius:6px;transition:color .2s;line-height:0;}
.eye-btn:hover{color:var(--lavender);}
.stat-value.hidden-val::before{content:'••••••';font-family:'Nunito',sans-serif;font-size:20px;letter-spacing:3px;}
.stat-value.hidden-val .real-val{display:none;}

  /* LAYOUT */
  .two-col{display:grid;grid-template-columns:1fr 300px;gap:14px;min-width:0;}
  .right-col{display:flex;flex-direction:column;gap:14px;}

  /* CARDS */
  .card{background:var(--surface);border:1.5px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);animation:fadeUp .4s .1s ease both;min-width:0;}
  .card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--surface2);}
  .card-title{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--navy);flex:1;}
  .card-tag{font-size:10px;font-weight:700;color:var(--teal-deep);background:var(--teal-pale);border-radius:20px;padding:3px 10px;}

  /* TABLE */
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

  /* ALERTAS */
  .alertas-tabs{display:flex;gap:6px;padding:14px 20px 0;border-bottom:1px solid var(--border);background:var(--surface2);}
  .tab-btn{padding:6px 14px;border-radius:20px 20px 0 0;font-size:11px;font-weight:700;border:1.5px solid var(--border);border-bottom:none;background:var(--surface);color:var(--text-mid);cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:5px;}
  .tab-btn.active{background:var(--rose);border-color:var(--rose);color:#fff;}
  .tab-btn.teal.active{background:var(--teal-deep);border-color:var(--teal-deep);}
  .tab-content{display:none;}
  .tab-content.active{display:block;}
  .notif-item{padding:13px 20px;border-bottom:1px solid var(--border);display:flex;gap:12px;align-items:flex-start;transition:background .15s;}
  .notif-item:hover{background:var(--surface2);}
  .notif-item:last-child{border-bottom:none;}
  .notif-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .notif-icon svg{width:15px;height:15px;}
  .ni-rose{background:var(--rose-pale);color:var(--rose-deep)}
  .ni-orange{background:#fff4e0;color:#b07a30}
  .ni-teal{background:var(--teal-pale);color:var(--teal-deep)}
  .notif-title{font-size:12.5px;color:var(--navy);font-weight:700;margin-bottom:2px;}
  .notif-desc{font-size:11px;color:var(--text-dim);line-height:1.4;}
  .notif-monto{font-family:'Dancing Script',cursive;font-size:15px;color:var(--rose-deep);margin-top:2px;}
  .empty-alert{padding:24px;text-align:center;color:var(--text-dim);font-size:12px;}

  /* MINI CALENDARIO */
  .cal-header{padding:14px 20px 10px;border-bottom:1px solid var(--border);display:flex;align-items:center;background:var(--surface2);}
  .cal-month{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--navy);flex:1;}
  .cal-grid{padding:10px 14px 14px;display:grid;grid-template-columns:repeat(7,1fr);gap:2px;}
  .cal-day-name{text-align:center;font-size:9px;font-weight:700;letter-spacing:.08em;color:var(--text-dim);padding:5px 0;text-transform:uppercase;}
  .cal-day{text-align:center;padding:5px 0;font-size:12px;font-weight:600;color:var(--text-mid);border-radius:50%;transition:background .15s;}
  .cal-day.today{background:linear-gradient(135deg,var(--rose),var(--rose-deep));color:#fff;font-weight:700;}
  .cal-day.dim{color:var(--text-dim);}

  /* CHART */
  .chart-area{padding:16px 20px;}
  .chart-wrapper{position:relative;margin-bottom:10px;}
  .chart-grid{position:absolute;inset:0 0 22px 0;display:flex;flex-direction:column;justify-content:space-between;pointer-events:none;}
  .chart-grid-line{width:100%;border-top:1px dashed var(--border);}
  .chart-bars{display:flex;align-items:flex-end;gap:12px;height:140px;padding-bottom:22px;position:relative;}
  .bar-group{flex:1;max-width:60px;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end;}
  .bar{width:100%;max-width:44px;background:linear-gradient(180deg,var(--rose-light),var(--rose));border-radius:6px 6px 0 0;opacity:.55;min-height:4px;transition:all .3s ease;}
  .bar:hover{opacity:.8;transform:scaleY(1.02);transform-origin:bottom;}
  .bar.highlight{opacity:1;background:linear-gradient(180deg,var(--rose-light),var(--rose-deep));box-shadow:0 -4px 14px rgba(196,84,122,.35);}
  .bar-label{font-size:9px;color:var(--text-dim);font-weight:700;position:absolute;bottom:2px;}
  .chart-summary{display:flex;gap:20px;padding-top:12px;border-top:1.5px solid var(--border);}
  .chart-sum-label{font-size:9px;color:var(--text-dim);font-weight:700;text-transform:uppercase;letter-spacing:.1em;}
  .chart-sum-value{font-family:'Dancing Script',cursive;font-size:20px;color:var(--rose-deep);margin-top:2px;}

  .empty-row{text-align:center;padding:30px;color:var(--text-dim);font-size:12px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <span class="page-title">Dashboard</span>
    <div class="topbar-sep"></div>
    <span class="topbar-date"><?= date('l, d \d\e F \d\e Y') ?></span>
  </div>

  <div class="content">

    <!-- STATS -->
    <div class="stats-row">
      <div class="stat-card sc1">
        <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
        <div class="stat-blob"></div>
        <div class="stat-label">Clientes</div>
        <div class="stat-value"><?= $totalClientes ?></div>
        <div class="stat-sub">registrados</div>
      </div>
      <div class="stat-card sc2">
        <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></div>
        <div class="stat-blob"></div>
        <div class="stat-label">Por entregar</div>
        <div class="stat-value"><?= $sesionesPendEntrega ?></div>
        <div class="stat-sub">sesiones pendientes</div>
      </div>
      <div class="stat-card sc3">
        <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
        <div class="stat-blob"></div>
        <div class="stat-label">Sin cobrar</div>
        <div class="stat-value"><?= $sesionesPendPago ?></div>
        <div class="stat-sub"><?= formatoPeso($saldoPendiente) ?> pendiente</div>
      </div>
<div class="stat-card sc4">
  <div class="stat-icon">...</div>
  <div class="stat-blob"></div>
  <div class="stat-label">Ingresos mes</div>
  <div class="stat-value hidden-val" id="ingresoVal" style="font-size:22px;">
    <span class="real-val"><?= formatoPeso($ingresosMes) ?></span>
  </div>
  <div class="stat-sub">este mes</div>
  <?php if ($esAdmin): ?>
  <button class="eye-btn" id="eyeBtn" onclick="toggleIngresos()" title="Mostrar/ocultar">
    <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15">
      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
      <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
      <line x1="1" y1="1" x2="23" y2="23"/>
    </svg>
  </button>
  <?php endif; ?>
</div>
      <div class="stat-card sc5">
        <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><circle cx="12" cy="16" r="2" fill="currentColor" stroke="none"/></svg></div>
        <div class="stat-blob"></div>
        <div class="stat-label">Próximas</div>
        <div class="stat-value"><?= $sesionesProximas ?></div>
        <div class="stat-sub">sesiones por realizar</div>
      </div>
    </div>

    <!-- TABLA SESIONES + PANEL DERECHO -->
    <div class="two-col">

      <!-- ÚLTIMAS SESIONES -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Últimas Sesiones</div>
          <a href="modulos/sesiones.php" class="btn btn-ghost" style="padding:5px 12px;font-size:11px;">Ver todas →</a>
        </div>
        <table>
          <thead><tr><th>Cliente</th><th>Servicio</th><th>Fecha</th><th>Pago</th><th>Entrega</th></tr></thead>
          <tbody>
            <?php if ($ultimasSesiones->num_rows === 0): ?>
              <tr><td colspan="5" class="empty-row">No hay sesiones registradas aún.</td></tr>
            <?php else: $i = 0; while ($ses = $ultimasSesiones->fetch_assoc()): ?>
            <tr>
              <td>
                <div class="client-cell">
                  <div class="client-avatar <?= $avClasses[$i%5] ?>"><?= mb_strtoupper(mb_substr($ses['nombre'],0,1)) ?></div>
                  <div>
                    <div class="client-name"><?= htmlspecialchars($ses['nombre'].' '.$ses['apellido']) ?></div>
                    <div class="client-phone"><?= htmlspecialchars($ses['telefono'] ?? '') ?></div>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($ses['servicio'] ?? '—') ?></td>
              <td style="font-size:11px;font-weight:600;"><?= formatoFecha($ses['fecha_sesion']) ?></td>
              <td><span class="status-pill pill-<?= $ses['estado_pago'] ?>"><?= ucfirst($ses['estado_pago']) ?></span></td>
              <td><span class="status-pill pill-<?= $ses['estado_entrega'] ?>"><?= ucfirst(str_replace('_',' ',$ses['estado_entrega'])) ?></span></td>
            </tr>
            <?php $i++; endwhile; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="right-col">

        <!-- MINI CALENDARIO -->
        <div class="card">
          <div class="cal-header">
            <div class="cal-month"><?= date('F Y') ?></div>
          </div>
          <div class="cal-grid">
            <?php
            $diasN = ['Lu','Ma','Mi','Ju','Vi','Sa','Do'];
            foreach ($diasN as $d) echo "<div class='cal-day-name'>$d</div>";
            $primerDia = mktime(0,0,0,date('n'),1,date('Y'));
            $diaSemana = date('N', $primerDia);
            $diasMes   = date('t');
            for ($x=1;$x<$diaSemana;$x++) echo "<div class='cal-day dim'></div>";
            for ($d=1;$d<=$diasMes;$d++) {
                $clase = ($d==date('j'))?'today':'';
                echo "<div class='cal-day $clase'>$d</div>";
            }
            ?>
          </div>
        </div>

        <!-- ALERTAS CON TABS -->
        <div class="card">
          <div class="alertas-tabs">
            <button class="tab-btn active" onclick="switchTab('cobros',this)">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              Por cobrar <span style="background:rgba(255,255,255,.25);border-radius:10px;padding:0 6px;font-size:9px;"><?= $alertasSesiones->num_rows ?></span>
            </button>
            <button class="tab-btn teal" onclick="switchTab('entregas',this)">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
              Entregas <span style="background:rgba(255,255,255,.25);border-radius:10px;padding:0 6px;font-size:9px;"><?= $alertasEntrega->num_rows ?></span>
            </button>
            <button class="tab-btn" onclick="switchTab('citas',this)" style="font-size:10.5px;">
              Citas mañana
            </button>
          </div>

          <!-- TAB: COBROS -->
          <div id="tab-cobros" class="tab-content active">
            <?php if ($alertasSesiones->num_rows === 0): ?>
              <div class="empty-alert">Sin cobros pendientes</div>
            <?php else: while ($a = $alertasSesiones->fetch_assoc()): ?>
            <div class="notif-item">
              <div class="notif-icon ni-rose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
              <div style="flex:1;">
                <div class="notif-title"><?= htmlspecialchars($a['cliente']) ?></div>
                <div class="notif-desc"><?= formatoFecha($a['fecha_ref']) ?></div>
                <div class="notif-monto">Saldo: <?= formatoPeso($a['saldo']) ?></div>
              </div>
              <a href="modulos/sesiones.php" style="font-size:10px;color:var(--rose-deep);font-weight:700;text-decoration:none;white-space:nowrap;">Ver →</a>
            </div>
            <?php endwhile; endif; ?>
          </div>

          <!-- TAB: ENTREGAS -->
          <div id="tab-entregas" class="tab-content">
            <?php if ($alertasEntrega->num_rows === 0): ?>
              <div class="empty-alert">Sin entregas pendientes</div>
            <?php else: while ($a = $alertasEntrega->fetch_assoc()): ?>
            <div class="notif-item">
              <div class="notif-icon ni-orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></div>
              <div style="flex:1;">
                <div class="notif-title"><?= htmlspecialchars($a['cliente']) ?></div>
                <div class="notif-desc">Sesión: <?= formatoFecha($a['fecha_ref']) ?> · Fotos pendientes</div>
              </div>
              <a href="modulos/sesiones.php" style="font-size:10px;color:var(--teal-deep);font-weight:700;text-decoration:none;">Ver →</a>
            </div>
            <?php endwhile; endif; ?>
          </div>

          <!-- TAB: CITAS MAÑANA -->
          <div id="tab-citas" class="tab-content">
            <?php if ($alertasCitas->num_rows === 0): ?>
              <div class="empty-alert">Sin citas para mañana</div>
            <?php else: while ($a = $alertasCitas->fetch_assoc()): ?>
            <div class="notif-item">
              <div class="notif-icon ni-teal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
              <div style="flex:1;">
                <div class="notif-title"><?= htmlspecialchars($a['cliente']) ?></div>
                <div class="notif-desc">Cita agendada para mañana</div>
              </div>
            </div>
            <?php endwhile; endif; ?>
          </div>

        </div><!-- /alertas card -->
      </div>
    </div>


<!-- GRÁFICA -->
<div class="card" id="chartCard">
  <div class="card-header">
    <div class="card-title">Ingresos Mensuales</div>
    <div style="display:flex;align-items:center;gap:10px;">
      <?php if ($esAdmin): ?>
      <button class="eye-btn" id="eyeBtnChart" onclick="toggleChart()" title="Mostrar/ocultar" style="position:static;">
        <svg id="eyeIconChart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15">
          <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
          <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
          <line x1="1" y1="1" x2="23" y2="23"/>
        </svg>
      </button>
      <?php endif; ?>
      <a href="modulos/ingresos.php" class="btn btn-ghost" style="padding:5px 12px;font-size:11px;">Ver detalle →</a>
    </div>
  </div>
  <div class="chart-area" id="chartArea" style="<?= $esAdmin ? '' : 'filter:blur(6px);pointer-events:none;user-select:none;' ?>">
        <?php
        $maxIngreso = 0;
        foreach ($datosMeses as $dm) $maxIngreso = max($maxIngreso, $dm['total']);
        if ($maxIngreso == 0) $maxIngreso = 1;
        $mesActual = (int)date('n');
        ?>
        <div class="chart-wrapper">
          <div class="chart-grid">
            <div class="chart-grid-line"></div>
            <div class="chart-grid-line"></div>
            <div class="chart-grid-line"></div>
            <div class="chart-grid-line"></div>
          </div>
          <div class="chart-bars">
            <?php if (empty($datosMeses)): ?>
              <div style="width:100%;text-align:center;color:var(--text-dim);font-size:12px;padding:20px;">Sin datos de ingresos aún.</div>
            <?php else: foreach ($datosMeses as $dm):
              $pct = round(($dm['total']/$maxIngreso)*100);
              $esActual = ((int)$dm['mes']===$mesActual);
            ?>
            <div class="bar-group" style="position:relative;">
              <div class="bar <?= $esActual?'highlight':'' ?>" style="height:<?= max($pct,8) ?>%;width:100%;max-width:44px;" title="<?= $mesesNombres[$dm['mes']-1] ?>: <?= formatoPeso($dm['total']) ?>"></div>
              <div class="bar-label"><?= $mesesNombres[$dm['mes']-1] ?></div>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <div class="chart-summary">
          <div><div class="chart-sum-label">Total año</div><div class="chart-sum-value"><?= formatoPeso($totalAnio) ?></div></div>
          <div><div class="chart-sum-label">Este mes</div><div class="chart-sum-value"><?= formatoPeso($ingresosMes) ?></div></div>
          <div><div class="chart-sum-label">Saldo pendiente</div><div class="chart-sum-value" style="color:var(--text-mid);"><?= formatoPeso($saldoPendiente) ?></div></div>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
function switchTab(name, btn) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}

function toggleIngresos() {
  const val = document.getElementById('ingresoVal');
  const icon = document.getElementById('eyeIcon');
  const oculto = val.classList.toggle('hidden-val');
  icon.innerHTML = oculto
    ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
}

function toggleChart() {
  const area = document.getElementById('chartArea');
  const icon = document.getElementById('eyeIconChart');
  const oculto = area.style.filter === 'blur(6px)';
  area.style.filter       = oculto ? '' : 'blur(6px)';
  area.style.pointerEvents = oculto ? '' : 'none';
  icon.innerHTML = oculto
    ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
    : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
}
</script>
</body>
</html>