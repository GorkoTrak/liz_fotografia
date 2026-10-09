<?php
/**
 * factura_pdf.php
 * Exporta una factura individual como PDF.
 * Requiere mPDF: composer require mpdf/mpdf
 *
 * Uso: factura_pdf.php?id=123
 */
require_once '../config.php';
requerirLogin();

$id = sanitizeInt($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('ID de factura inválido.');
}

$db = getDB();

// Obtener datos completos de la factura
$stmt = $db->prepare("
    SELECT f.*,
           cl.nombre, cl.apellido, cl.telefono, cl.email,
           s.fecha_sesion,
           p.nombre AS servicio
    FROM facturas f
    JOIN clientes cl ON f.cliente_id = cl.id
    LEFT JOIN sesiones s ON f.sesion_id = s.id
    LEFT JOIN productos p ON s.producto_id = p.id
    WHERE f.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$f = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$f) {
    http_response_code(404);
    exit('Factura no encontrada.');
}

// Obtener historial de pagos (ingresos relacionados)
$stmtP = $db->prepare("
    SELECT monto, metodo_pago, fecha, concepto
    FROM ingresos
    WHERE factura_id = ?
    ORDER BY fecha ASC
");
$stmtP->bind_param("i", $id);
$stmtP->execute();
$pagos = $stmtP->get_result();
$stmtP->close();

// ─── Cargar mPDF ─────────────────────────────────────────────────────────────
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($composerAutoload)) {
    http_response_code(500);
    exit('
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>mPDF no instalado</title>
<style>
  body { font-family: sans-serif; background: #faf8f6; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
  .box { background:#fff; border:1.5px solid #ede0ea; border-radius:16px; padding:32px 40px; max-width:480px; text-align:center; }
  h2 { color:#1a1f3c; font-size:20px; margin-bottom:12px; }
  p  { color:#6b5e7a; font-size:13px; line-height:1.7; }
  code { background:#f0ecfb; color:#9e8bc9; padding:2px 6px; border-radius:6px; font-size:12px; }
  .btn { display:inline-block; margin-top:16px; padding:10px 22px; background:linear-gradient(135deg,#e8789a,#c4547a);
         color:#fff; border-radius:20px; text-decoration:none; font-weight:700; font-size:13px; }
</style>
</head>
<body>
  <div class="box">
    <h2>⚙️ Dependencia faltante</h2>
    <p>Para exportar PDFs necesitas instalar <strong>mPDF</strong>.<br>
    Ejecuta este comando en la raíz del proyecto (<code>liz2/</code>):</p>
    <p><code>composer require mpdf/mpdf</code></p>
    <p>Si no tienes Composer instalado:<br>
    <code>curl -sS https://getcomposer.org/installer | php</code></p>
    <a class="btn" href="facturas.php">← Volver a Facturas</a>
  </div>
</body>
</html>
    ');
}

require_once $composerAutoload;

// ─── Generar HTML del PDF ─────────────────────────────────────────────────────
$estadoColor = [
    'pagada'    => '#2a9d73',
    'pendiente' => '#c4547a',
    'cancelada' => '#a899b5',
];
$estadoBg = [
    'pagada'    => '#e8f8f4',
    'pendiente' => '#fce8f0',
    'cancelada' => '#e8eaf6',
];
$estadoLabel = [
    'pagada'    => 'PAGADA',
    'pendiente' => 'PENDIENTE',
    'cancelada' => 'CANCELADA',
];
$metodosLabel = [
    'efectivo'       => 'Efectivo',
    'transferencia'  => 'Transferencia',
    'nequi'          => 'Nequi',
    'daviplata'      => 'Daviplata',
    'tarjeta'        => 'Tarjeta',
];

$eColor  = $estadoColor[$f['estado']]  ?? '#6b5e7a';
$eBg     = $estadoBg[$f['estado']]     ?? '#faf8f6';
$eLabel  = $estadoLabel[$f['estado']]  ?? strtoupper($f['estado']);
$metodo  = $metodosLabel[$f['metodo_pago']] ?? ucfirst($f['metodo_pago']);
$saldo   = $f['saldo'] ?? ($f['total'] - $f['abono']);

// Logo en base64 (si existe)
$logoTag = '';
$logoPath = __DIR__ . '/logo.jpg';
if (file_exists($logoPath)) {
    $logoData = base64_encode(file_get_contents($logoPath));
    $logoTag  = '<img src="data:image/jpeg;base64,' . $logoData . '" class="logo-img" alt="Logo">';
}

// Construir filas de pagos
$pagosHTML = '';
$pagosList = [];
while ($pago = $pagos->fetch_assoc()) {
    $pagosList[] = $pago;
}
if (!empty($pagosList)) {
    foreach ($pagosList as $pago) {
        $pagosHTML .= '
        <tr>
            <td>' . formatoFecha($pago['fecha']) . '</td>
            <td>' . htmlspecialchars($pago['concepto']) . '</td>
            <td>' . ($metodosLabel[$pago['metodo_pago']] ?? ucfirst($pago['metodo_pago'])) . '</td>
            <td class="td-right">' . formatoPeso($pago['monto']) . '</td>
        </tr>';
    }
}

$html = '
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }

  body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 10.5px;
    color: #1e1e2e;
    background: #ffffff;
    line-height: 1.5;
  }

  /* ═══════════════════════════════════════
     CABECERA: barra lateral de acento + contenido
  ═══════════════════════════════════════ */
  .header-accent {
    background: #1a1f3c;
    padding: 0 32px;
    display: table;
    width: 100%;
  }
  .accent-bar {
    display: table-cell;
    width: 5px;
    background: linear-gradient(180deg, #e8789a 0%, #9e8bc9 100%);
    border-radius: 0;
    padding: 0;
  }
  .header-inner {
    display: table;
    width: 100%;
    padding: 28px 0 24px 20px;
  }
  .header-left  { display: table-cell; vertical-align: middle; width: 60%; }
  .header-right { display: table-cell; vertical-align: middle; text-align: right; }

  .logo-wrap { display: table; }
  .logo-cell { display: table-cell; vertical-align: middle; }
  .logo-circle {
    width: 52px; height: 52px; border-radius: 50%;
    background: linear-gradient(135deg, #f5b8ce, #9ddbd8);
    text-align: center; line-height: 52px;
    font-size: 24px; font-weight: 700; color: #1a1f3c;
    overflow: hidden; display: inline-block;
  }
  .logo-img { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; }
  .brand-cell { display: table-cell; vertical-align: middle; padding-left: 14px; }
  .brand-name { font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: -0.3px; }
  .brand-tagline { font-size: 8.5px; color: #9ddbd8; letter-spacing: 0.22em; text-transform: uppercase; margin-top: 3px; font-weight: 600; }

  .factura-label { font-size: 9px; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 0.2em; font-weight: 700; margin-bottom: 6px; }
  .factura-num   { font-size: 26px; font-weight: 700; color: #ffffff; letter-spacing: -0.5px; line-height: 1; }
  .factura-fecha { font-size: 9.5px; color: #9ddbd8; margin-top: 5px; }

  /* ═══════════════════════════════════════
     ESTADO STRIP
  ═══════════════════════════════════════ */
  .estado-strip {
    display: table; width: 100%;
    background: ' . $eBg . ';
    border-bottom: 2px solid ' . $eColor . ';
    padding: 9px 32px;
  }
  .estado-left  { display: table-cell; vertical-align: middle; }
  .estado-right { display: table-cell; vertical-align: middle; text-align: right; }
  .estado-pill {
    display: inline-block;
    background: ' . $eColor . '; color: #fff;
    font-size: 8px; font-weight: 700; letter-spacing: 0.18em;
    padding: 4px 12px; border-radius: 20px;
    vertical-align: middle;
  }
  .estado-metodo {
    font-size: 9.5px; color: #6b5e7a;
    vertical-align: middle; margin-left: 10px;
  }
  .estado-metodo strong { color: #1a1f3c; }
  .estado-vence { font-size: 9px; color: #6b5e7a; }

  /* ═══════════════════════════════════════
     CUERPO
  ═══════════════════════════════════════ */
  .body-wrap { padding: 26px 32px; background: #ffffff; }

  /* ─ Sección label ─ */
  .sec-label {
    font-size: 8px; font-weight: 700; letter-spacing: 0.2em;
    text-transform: uppercase; color: #a899b5;
    margin-bottom: 9px; padding-bottom: 5px;
    border-bottom: 1px solid #f0e8f0;
  }

  /* ─ Dos columnas ─ */
  .cols { display: table; width: 100%; margin-bottom: 24px; border-spacing: 0; }
  .col-l { display: table-cell; width: 54%; vertical-align: top; padding-right: 18px; }
  .col-r { display: table-cell; vertical-align: top; }

  /* ─ Box cliente ─ */
  .client-box {
    border: 1px solid #ede0ea;
    border-top: 3px solid #e8789a;
    border-radius: 8px;
    padding: 14px 16px;
    background: #fdfbfd;
  }
  .client-name   { font-size: 14px; font-weight: 700; color: #1a1f3c; margin-bottom: 8px; }
  .client-row    { display: table; width: 100%; margin-bottom: 3px; }
  .client-key    { display: table-cell; width: 70px; font-size: 9px; color: #a899b5; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; padding-top: 1px; }
  .client-val    { display: table-cell; font-size: 10px; color: #2a2040; font-weight: 600; }

  /* ─ Box resumen financiero ─ */
  .summary-box {
    border: 1px solid #ede0ea;
    border-top: 3px solid #9e8bc9;
    border-radius: 8px;
    padding: 14px 16px;
    background: #fdfbfd;
  }
  .sum-row  { display: table; width: 100%; margin-bottom: 7px; }
  .sum-key  { display: table-cell; font-size: 10px; color: #6b5e7a; }
  .sum-val  { display: table-cell; text-align: right; font-size: 10px; font-weight: 700; color: #1a1f3c; }
  .sum-sep  { border: none; border-top: 1px dashed #ddd0e0; margin: 10px 0; }

  .total-block { background: #1a1f3c; border-radius: 6px; padding: 10px 14px; margin-top: 10px; display: table; width: 100%; }
  .total-key { display: table-cell; font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.7); }
  .total-val { display: table-cell; text-align: right; font-size: 20px; font-weight: 700; color: #f5b8ce; }

  .saldo-block {
    margin-top: 8px; border-radius: 6px; padding: 7px 12px;
    background: #fce8f0; display: table; width: 100%;
  }
  .saldo-key { display: table-cell; font-size: 9.5px; font-weight: 700; color: #c4547a; }
  .saldo-val { display: table-cell; text-align: right; font-size: 12px; font-weight: 700; color: #c4547a; }

  .pagado-block {
    margin-top: 8px; border-radius: 6px; padding: 7px 12px;
    background: #e8f8f4; text-align: center;
    font-size: 10px; font-weight: 700; color: #2a9d73;
  }

  /* ─ Servicio ─ */
  .service-block {
    display: table; width: 100%; margin-bottom: 22px;
    border: 1px solid #e0d8f0; border-radius: 8px;
    background: #f8f5fe; padding: 13px 16px;
  }
  .service-left  { display: table-cell; vertical-align: middle; width: 65%; }
  .service-right { display: table-cell; vertical-align: middle; text-align: right; }
  .service-badge {
    display: inline-block; background: #9e8bc9; color: #fff;
    font-size: 7.5px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;
    padding: 3px 9px; border-radius: 20px; margin-bottom: 5px;
  }
  .service-name { font-size: 13px; font-weight: 700; color: #1a1f3c; }
  .service-date { font-size: 9.5px; color: #6b5e7a; margin-top: 3px; }
  .service-date strong { color: #1a1f3c; }

  /* ─ Tabla de pagos ─ */
  .pagos-section { margin-bottom: 22px; }
  table.pagos-table { width: 100%; border-collapse: collapse; border-radius: 8px; overflow: hidden; }
  table.pagos-table thead tr { background: #1a1f3c; }
  table.pagos-table th {
    color: #f5b8ce; font-size: 8px; font-weight: 700;
    letter-spacing: 0.15em; text-transform: uppercase;
    padding: 9px 12px; text-align: left;
  }
  table.pagos-table th.right { text-align: right; }
  table.pagos-table td {
    padding: 9px 12px; font-size: 10px; color: #4a3d5a;
    border-bottom: 1px solid #f0e8f4;
  }
  table.pagos-table tr:last-child td { border-bottom: none; }
  table.pagos-table tbody tr:nth-child(odd) td  { background: #ffffff; }
  table.pagos-table tbody tr:nth-child(even) td { background: #fdf5f8; }
  .td-right { text-align: right; font-weight: 700; color: #3a9994; }

  /* ─ Notas ─ */
  .notes-box {
    margin-top: 4px; border-radius: 6px;
    border-left: 3px solid #e8789a;
    background: #fdf5f8; padding: 10px 14px;
    font-size: 10px; color: #6b5e7a; line-height: 1.65;
  }
  .notes-title { font-weight: 700; color: #c4547a; font-size: 9px; text-transform: uppercase; letter-spacing: 0.12em; margin-bottom: 5px; }

  /* ═══════════════════════════════════════
     FOOTER
  ═══════════════════════════════════════ */
  .footer {
    background: #1a1f3c;
    padding: 14px 32px;
    display: table; width: 100%;
  }
  .footer-left  { display: table-cell; vertical-align: middle; }
  .footer-right { display: table-cell; vertical-align: middle; text-align: right; }
  .footer-brand { font-size: 11px; font-weight: 700; color: #f5b8ce; }
  .footer-sub   { font-size: 8.5px; color: rgba(255,255,255,0.35); margin-top: 2px; }
  .footer-num   { font-size: 9px; color: rgba(255,255,255,0.5); margin-bottom: 2px; }
  .footer-gen   { font-size: 8px; color: rgba(255,255,255,0.3); }

  /* ─ Separadores decorativos ─ */
  .divider { border: none; border-top: 1px solid #f0e8f0; margin: 20px 0; }
</style>
</head>
<body>

  <!-- ══ CABECERA ══ -->
  <div class="header-accent">
    <div class="header-inner">
      <div class="header-left">
        <div class="logo-wrap">
          <div class="logo-cell">
            <div class="logo-circle">' . ($logoTag ?: 'L') . '</div>
          </div>
          <div class="brand-cell">
            <div class="brand-name">' . htmlspecialchars(SITE_NAME) . '</div>
            <div class="brand-tagline">Fotoestudio Profesional</div>
          </div>
        </div>
      </div>
      <div class="header-right">
        <div class="factura-label">Factura</div>
        <div class="factura-num">' . htmlspecialchars($f['numero_factura']) . '</div>
        <div class="factura-fecha">Emitida el ' . formatoFecha($f['fecha_emision']) . '</div>
        ' . ($f['fecha_vencimiento'] ? '<div class="factura-fecha">Vence: ' . formatoFecha($f['fecha_vencimiento']) . '</div>' : '') . '
      </div>
    </div>
  </div>

  <!-- ══ STRIP DE ESTADO ══ -->
  <div class="estado-strip">
    <div class="estado-left">
      <span class="estado-pill">' . $eLabel . '</span>
      <span class="estado-metodo">Método: <strong>' . htmlspecialchars($metodo) . '</strong></span>
    </div>
    <div class="estado-right">
      ' . ($f['fecha_vencimiento'] ? '<span class="estado-vence">Vence ' . formatoFecha($f['fecha_vencimiento']) . '</span>' : '') . '
    </div>
  </div>

  <!-- ══ CUERPO ══ -->
  <div class="body-wrap">

    <!-- CLIENTE + RESUMEN -->
    <div class="cols">

      <div class="col-l">
        <div class="sec-label">Facturado a</div>
        <div class="client-box">
          <div class="client-name">' . htmlspecialchars($f['nombre'] . ' ' . $f['apellido']) . '</div>
          ' . ($f['telefono'] ? '
          <div class="client-row">
            <div class="client-key">Tel.</div>
            <div class="client-val">' . htmlspecialchars($f['telefono']) . '</div>
          </div>' : '') . '
          ' . ($f['email'] ? '
          <div class="client-row">
            <div class="client-key">Email</div>
            <div class="client-val">' . htmlspecialchars($f['email']) . '</div>
          </div>' : '') . '
        </div>
      </div>

      <div class="col-r">
        <div class="sec-label">Resumen de pago</div>
        <div class="summary-box">
          <div class="sum-row">
            <div class="sum-key">Subtotal</div>
            <div class="sum-val">' . formatoPeso($f['total']) . '</div>
          </div>
          <div class="sum-row">
            <div class="sum-key">Pagado</div>
            <div class="sum-val" style="color:#2a9d73;">' . formatoPeso($f['abono']) . '</div>
          </div>
          <hr class="sum-sep">
          <div class="total-block">
            <div class="total-key">Total</div>
            <div class="total-val">' . formatoPeso($f['total']) . '</div>
          </div>
          ' . ($saldo > 0
            ? '<div class="saldo-block"><div class="saldo-key">Saldo pendiente</div><div class="saldo-val">' . formatoPeso($saldo) . '</div></div>'
            : '<div class="pagado-block">Completamente pagada</div>') . '
        </div>
      </div>

    </div>

    <!-- SERVICIO CONTRATADO -->
    ' . ($f['servicio'] || $f['fecha_sesion'] ? '
    <div class="sec-label">Servicio contratado</div>
    <div class="service-block">
      <div class="service-left">
        <div class="service-badge">Servicio</div>
        <div class="service-name">' . ($f['servicio'] ? htmlspecialchars($f['servicio']) : 'Sin servicio específico') . '</div>
        ' . ($f['fecha_sesion'] ? '<div class="service-date">Sesión: <strong>' . formatoFecha($f['fecha_sesion']) . '</strong></div>' : '') . '
      </div>
      <div class="service-right">
        <div style="font-size:9px;color:#a899b5;font-weight:700;text-transform:uppercase;letter-spacing:.1em;">Valor</div>
        <div style="font-size:16px;font-weight:700;color:#9e8bc9;margin-top:3px;">' . formatoPeso($f['total']) . '</div>
      </div>
    </div>' : '') . '

    <!-- HISTORIAL DE PAGOS -->
    ' . (!empty($pagosList) ? '
    <div class="pagos-section">
      <div class="sec-label">Historial de pagos</div>
      <table class="pagos-table">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Concepto</th>
            <th>Método</th>
            <th class="right">Monto</th>
          </tr>
        </thead>
        <tbody>
          ' . $pagosHTML . '
        </tbody>
      </table>
    </div>' : '') . '

    <!-- NOTAS -->
    ' . (!empty($f['notas']) ? '
    <div class="sec-label">Notas</div>
    <div class="notes-box">
      ' . nl2br(htmlspecialchars($f['notas'])) . '
    </div>' : '') . '

  </div>

  <!-- ══ FOOTER ══ -->
  <div class="footer">
    <div class="footer-left">
      <div class="footer-brand">' . htmlspecialchars(SITE_NAME) . '</div>
      <div class="footer-sub">Gracias por confiar en nosotros</div>
    </div>
    <div class="footer-right">
      <div class="footer-num">' . htmlspecialchars($f['numero_factura']) . '</div>
      <div class="footer-gen">Generado el ' . date('d/m/Y \a \l\a\s H:i') . '</div>
    </div>
  </div>

</body>
</html>';

// ─── Renderizar con mPDF ──────────────────────────────────────────────────────
$mpdf = new \Mpdf\Mpdf([
    'mode'          => 'utf-8',
    'format'        => 'A4',
    'margin_top'    => 0,
    'margin_bottom' => 0,
    'margin_left'   => 0,
    'margin_right'  => 0,
    'default_font'  => 'dejavusans',
]);

$mpdf->SetTitle('Factura ' . $f['numero_factura']);
$mpdf->SetAuthor(SITE_NAME);
$mpdf->SetCreator(SITE_NAME);

$mpdf->WriteHTML($html);

$nombreArchivo = 'Factura_' . preg_replace('/[^A-Za-z0-9\-]/', '_', $f['numero_factura']) . '.pdf';
$mpdf->Output($nombreArchivo, \Mpdf\Output\Destination::DOWNLOAD);
exit;