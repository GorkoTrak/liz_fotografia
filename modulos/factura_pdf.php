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
           s.fecha_sesion, s.hora_sesion,
           p.nombre AS servicio, p.precio AS servicio_precio, p.categoria AS servicio_categoria
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

// Historial de abonos: ingresos ligados a la factura o a su sesión
// (los abonos registrados desde Sesiones quedan con sesion_id).
$sesionId = (int)($f['sesion_id'] ?? 0);
$stmtP = $db->prepare("
    SELECT monto, metodo_pago, fecha, concepto
    FROM ingresos
    WHERE tipo = 'ingreso'
      AND (factura_id = ? OR (? > 0 AND sesion_id = ?))
    ORDER BY fecha ASC, id ASC
");
$stmtP->bind_param("iii", $id, $sesionId, $sesionId);
$stmtP->execute();
$pagos = $stmtP->get_result();
$stmtP->close();

// Paquetes y adicionales de la sesión
$adicionales = [];
if ($sesionId > 0) {
    $stmtSP = $db->prepare("
        SELECT sp.cantidad, sp.precio, p.nombre, p.categoria
        FROM sesion_productos sp
        JOIN productos p ON sp.producto_id = p.id
        WHERE sp.sesion_id = ?
        ORDER BY sp.id ASC
    ");
    $stmtSP->bind_param("i", $sesionId);
    $stmtSP->execute();
    $resSP = $stmtSP->get_result();
    while ($sp = $resSP->fetch_assoc()) {
        $adicionales[] = $sp;
    }
    $stmtSP->close();
}

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
    'abonada'   => '#b07a30',
    'pendiente' => '#c4547a',
    'cancelada' => '#a899b5',
];
$estadoBg = [
    'pagada'    => '#e8f8f4',
    'abonada'   => '#fff4e0',
    'pendiente' => '#fce8f0',
    'cancelada' => '#e8eaf6',
];
$estadoLabel = [
    'pagada'    => 'PAGADA',
    'abonada'   => 'ABONADA',
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
$posiblesLogos = [
    UPLOAD_DIR . 'perfiles/logo_header.jpg',
    UPLOAD_DIR . 'logo_header.jpg',
    __DIR__ . '/../uploads/perfiles/logo_header.jpg',
    __DIR__ . '/../uploads/logo_header.jpg',
    UPLOAD_DIR . 'perfiles/logo_circulo.png',
    UPLOAD_DIR . 'logo_circulo.png',
    __DIR__ . '/../uploads/perfiles/logo_circulo.png',
    __DIR__ . '/../uploads/logo_circulo.png',
    UPLOAD_DIR . 'perfiles/logo.jpg',
    UPLOAD_DIR . 'logo.jpg',
    __DIR__ . '/../uploads/perfiles/logo.jpg',
    __DIR__ . '/../uploads/logo.jpg',
    __DIR__ . '/logo.jpg',
];
$logoFile = '';
foreach ($posiblesLogos as $pl) {
    if (file_exists($pl)) {
        $logoFile = $pl;
        break;
    }
}
if ($logoFile) {
    $ext = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
    $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
    $logoData = base64_encode(file_get_contents($logoFile));
    $logoTag  = '<img src="data:' . $mime . ';base64,' . $logoData . '" class="logo-img" alt="Logo">';
}

// Lista de abonos
$pagosList = [];
while ($pago = $pagos->fetch_assoc()) {
    $pagosList[] = $pago;
}
// Factura con abono pero sin movimientos registrados (datos antiguos): se muestra como un único abono
if (empty($pagosList) && (float)$f['abono'] > 0) {
    $pagosList[] = [
        'monto'       => $f['abono'],
        'metodo_pago' => $f['metodo_pago'],
        'fecha'       => $f['fecha_sesion'] ?: $f['fecha_emision'],
        'concepto'    => 'Abono registrado con la sesión',
    ];
}

$pagosHTML  = '';
$totalPagos = 0;
foreach ($pagosList as $i => $pago) {
    $totalPagos += (float)$pago['monto'];
    $pagosHTML .= '
        <tr' . ($i % 2 ? ' class="alt"' : '') . '>
            <td class="td-num">' . ($i + 1) . '</td>
            <td>' . formatoFecha($pago['fecha']) . '</td>
            <td>' . ($metodosLabel[$pago['metodo_pago']] ?? ucfirst((string)$pago['metodo_pago'])) . '</td>
            <td class="td-concepto">' . htmlspecialchars((string)$pago['concepto']) . '</td>
            <td class="td-right">' . formatoPeso($pago['monto']) . '</td>
        </tr>';
}
if ($pagosHTML === '') {
    $pagosHTML = '<tr><td colspan="5" class="td-empty">Aún no se han registrado abonos para esta factura.</td></tr>';
}

// Fila de datos del cliente / sesión
function filaDato($k, $v) {
    return '<tr><td class="key">' . $k . '</td><td class="val">' . $v . '</td></tr>';
}
$clienteFilas = '';
if (!empty($f['telefono'])) $clienteFilas .= filaDato('Teléfono', htmlspecialchars($f['telefono']));
if (!empty($f['email']))    $clienteFilas .= filaDato('Email', htmlspecialchars($f['email']));
if ($clienteFilas === '')   $clienteFilas  = filaDato('Contacto', 'Sin datos de contacto');

$sesionFilas  = filaDato('Servicio', $f['servicio'] ? htmlspecialchars($f['servicio']) : 'Sin servicio específico');
$sesionFilas .= filaDato('Fecha', $f['fecha_sesion'] ? formatoFecha($f['fecha_sesion']) : '—');
if (!empty($f['hora_sesion'])) $sesionFilas .= filaDato('Hora', formatoHora($f['hora_sesion']));

// Construir lista de paquetes y adicionales
$itemsList = [];
$sumaAdicionales = 0;
foreach ($adicionales as $ad) {
    $sumaAdicionales += (float)$ad['precio'] * max(1, (int)$ad['cantidad']);
}

// 1. Paquete principal de la sesión
if (!empty($f['servicio'])) {
    $precioBase = !empty($f['servicio_precio']) ? (float)$f['servicio_precio'] : max(0, (float)$f['total'] - $sumaAdicionales);
    $itemsList[] = [
        'nombre'    => 'Paquete ' . $f['servicio'],
        'categoria' => !empty($f['servicio_categoria']) ? $f['servicio_categoria'] : 'Paquete de Sesión',
        'cantidad'  => 1,
        'precio'    => $precioBase,
        'subtotal'  => $precioBase
    ];
}

// 2. Adicionales adquiridos
foreach ($adicionales as $ad) {
    $qty = max(1, (int)$ad['cantidad']);
    $prc = (float)$ad['precio'];
    $itemsList[] = [
        'nombre'    => $ad['nombre'],
        'categoria' => !empty($ad['categoria']) ? $ad['categoria'] : 'Foto / Adicional',
        'cantidad'  => $qty,
        'precio'    => $prc,
        'subtotal'  => $qty * $prc
    ];
}

// 3. Fallback si no hay ítems detallados
if (empty($itemsList)) {
    $itemsList[] = [
        'nombre'    => 'Servicio Fotográfico Profesional',
        'categoria' => 'Servicio',
        'cantidad'  => 1,
        'precio'    => (float)$f['total'],
        'subtotal'  => (float)$f['total']
    ];
}

$itemsHTML = '';
foreach ($itemsList as $i => $it) {
    $itemsHTML .= '
        <tr' . ($i % 2 ? ' class="alt"' : '') . '>
            <td class="td-num">' . ($i + 1) . '</td>
            <td>
                <div style="font-weight:700;color:#1a1f3c;">' . htmlspecialchars($it['nombre']) . '</div>
            </td>
            <td><span class="cat-pill">' . htmlspecialchars($it['categoria']) . '</span></td>
            <td class="td-center">' . (int)$it['cantidad'] . '</td>
            <td class="td-right" style="color:#6b5e7a;">' . formatoPeso($it['precio']) . '</td>
            <td class="td-right" style="color:#1a1f3c;">' . formatoPeso($it['subtotal']) . '</td>
        </tr>';
}

$html = '
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 10.5px;
    color: #1e1e2e;
    line-height: 1.5;
  }
  table { border-collapse: collapse; }

  /* ═══ CABECERA ═══ */
  .header { width: 100%; background: #1a1f3c; }
  .header td { padding: 26px 32px 22px; vertical-align: middle; }
  .logo-circle {
    width: 66px; height: 66px; border-radius: 33px;
    background: #f5b8ce; text-align: center; line-height: 66px;
    font-size: 28px; font-weight: 700; color: #1a1f3c;
  }
  .logo-img { width: 66px; height: 66px; }
  .brand-name    { font-size: 19px; font-weight: 700; color: #ffffff; }
  .brand-tagline { font-size: 8px; color: #9ddbd8; letter-spacing: 2px; text-transform: uppercase; font-weight: 700; margin-top: 2px; }
  .factura-label { font-size: 8.5px; color: #a899b5; text-transform: uppercase; letter-spacing: 2px; font-weight: 700; }
  .factura-num   { font-size: 24px; font-weight: 700; color: #ffffff; }
  .factura-fecha { font-size: 9px; color: #9ddbd8; margin-top: 3px; }

  /* ═══ ESTADO ═══ */
  .estado { width: 100%; background: ' . $eBg . '; border-bottom: 2px solid ' . $eColor . '; }
  .estado td { padding: 9px 32px; vertical-align: middle; font-size: 9.5px; color: #6b5e7a; }
  .estado-pill {
    background: ' . $eColor . '; color: #ffffff;
    font-size: 8px; font-weight: 700; letter-spacing: 1.5px;
    padding: 3px 12px; border-radius: 10px;
  }
  .estado strong { color: #1a1f3c; }

  /* ═══ CUERPO ═══ */
  .body-wrap { padding: 24px 32px 10px; }

  .sec-label {
    font-size: 8px; font-weight: 700; letter-spacing: 2px;
    text-transform: uppercase; color: #a899b5;
    padding-bottom: 5px; margin-bottom: 8px;
    border-bottom: 1px solid #f0e8f0;
  }

  /* Cliente / Sesión */
  .cols { width: 100%; margin-bottom: 22px; }
  .box {
    border: 1px solid #ede0ea; border-radius: 8px;
    background: #fdfbfd; padding: 12px 16px;
  }
  .box-rose { border-top: 3px solid #e8789a; }
  .box-lav  { border-top: 3px solid #9e8bc9; }
  .box-title { font-size: 13px; font-weight: 700; color: #1a1f3c; margin-bottom: 6px; }
  .datos { width: 100%; }
  .datos td.key { width: 68px; padding: 2px 0; font-size: 8.5px; color: #a899b5; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
  .datos td.val { padding: 2px 0; font-size: 10px; color: #2a2040; font-weight: 600; }

  /* Paquetes y adicionales */
  .items-table { width: 100%; margin-bottom: 20px; }
  .items-table th {
    background: #1a1f3c; color: #f5b8ce;
    font-size: 8px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
    padding: 8px 12px; text-align: left;
  }
  .items-table th.right  { text-align: right; }
  .items-table th.center { text-align: center; }
  .items-table th.num    { text-align: center; width: 30px; }
  .items-table td {
    padding: 8px 12px; font-size: 10px; color: #4a3d5a;
    border-bottom: 1px solid #f0e8f4;
  }
  .items-table tr.alt td { background: #fdf5f8; }
  .td-center   { text-align: center; font-weight: 600; color: #2a2040; }
  .cat-pill    { display: inline-block; background: #f0ecfb; color: #9e8bc9; font-size: 7.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; text-transform: uppercase; }

  /* Historial de abonos */
  .pagos-table { width: 100%; margin-bottom: 20px; }
  .pagos-table th {
    background: #1a1f3c; color: #f5b8ce;
    font-size: 8px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
    padding: 8px 12px; text-align: left;
  }
  .pagos-table th.right { text-align: right; }
  .pagos-table th.num   { text-align: center; width: 30px; }
  .pagos-table td {
    padding: 8px 12px; font-size: 10px; color: #4a3d5a;
    border-bottom: 1px solid #f0e8f4;
  }
  .pagos-table tr.alt td { background: #fdf5f8; }
  .td-num      { text-align: center; font-weight: 700; color: #3a9994; }
  .td-concepto { color: #6b5e7a; font-size: 9.5px; }
  .td-right    { text-align: right; font-weight: 700; color: #3a9994; }
  .td-empty    { text-align: center; color: #a899b5; font-style: italic; padding: 14px; }
  .pagos-total td {
    background: #e0f5f4; font-weight: 700; color: #1a1f3c;
    border-bottom: none; padding: 9px 12px;
  }

  /* Resumen */
  .resumen-wrap { width: 100%; margin-top: 18px; }
  .resumen { width: 100%; }
  .resumen td { padding: 6px 14px; font-size: 10.5px; }
  .resumen td.rk { color: #6b5e7a; }
  .resumen td.rv { text-align: right; font-weight: 700; color: #1a1f3c; }
  .resumen tr.total td { background: #1a1f3c; color: #ffffff; font-size: 11px; padding: 10px 14px; }
  .resumen tr.total td.rv { color: #f5b8ce; font-size: 17px; }
  .resumen tr.saldo td  { background: #fce8f0; color: #c4547a; font-weight: 700; }
  .resumen tr.pagada td { background: #e8f8f4; color: #2a9d73; font-weight: 700; text-align: center; }

  /* Notas */
  .notes-box {
    margin-top: 20px; border-left: 3px solid #e8789a;
    background: #fdf5f8; padding: 10px 14px;
    font-size: 10px; color: #6b5e7a; line-height: 1.6;
  }
  .notes-title { font-weight: 700; color: #c4547a; font-size: 8.5px; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 4px; }

  /* ═══ FOOTER ═══ */
  .footer { width: 100%; background: #1a1f3c; }
  .footer td { padding: 13px 32px; vertical-align: middle; }
  .footer-brand { font-size: 11px; font-weight: 700; color: #f5b8ce; }
  .footer-sub   { font-size: 8.5px; color: #8a8fb0; margin-top: 2px; }
  .footer-num   { font-size: 9px; color: #b0b4cc; text-align: right; }
  .footer-gen   { font-size: 8px; color: #8a8fb0; text-align: right; }
</style>
</head>
<body>

  <!-- ══ CABECERA ══ -->
  <table class="header">
    <tr>
      <td style="width:60%;">
        <table>
          <tr>
            <td style="padding:0; vertical-align:middle; width:70px;">
              ' . ($logoTag ?: '<div class="logo-circle">L</div>') . '
            </td>
            <td style="padding:0 0 0 16px; vertical-align:middle;">
              <div class="brand-name">' . htmlspecialchars(SITE_NAME) . '</div>
              <div class="brand-tagline">Fotoestudio Profesional</div>
            </td>
          </tr>
        </table>
      </td>
      <td style="text-align:right;">
        <div class="factura-label">Factura</div>
        <div class="factura-num">' . htmlspecialchars($f['numero_factura']) . '</div>
        <div class="factura-fecha">Emitida el ' . formatoFecha($f['fecha_emision']) . '</div>
      </td>
    </tr>
  </table>

  <!-- ══ ESTADO ══ -->
  <table class="estado">
    <tr>
      <td>
        <span class="estado-pill">' . $eLabel . '</span>
        &nbsp;&nbsp;Método: <strong>' . htmlspecialchars($metodo) . '</strong>
      </td>
      <td style="text-align:right;">' . ($f['fecha_vencimiento'] ? 'Vence: <strong>' . formatoFecha($f['fecha_vencimiento']) . '</strong>' : '') . '</td>
    </tr>
  </table>

  <!-- ══ CUERPO ══ -->
  <div class="body-wrap">

    <!-- CLIENTE + SESIÓN -->
    <table class="cols">
      <tr>
        <td style="width:50%;vertical-align:top;padding-right:9px;">
          <div class="sec-label">Facturado a</div>
          <div class="box box-rose">
            <div class="box-title">' . htmlspecialchars(trim($f['nombre'] . ' ' . $f['apellido'])) . '</div>
            <table class="datos">' . $clienteFilas . '</table>
          </div>
        </td>
        <td style="width:50%;vertical-align:top;padding-left:9px;">
          <div class="sec-label">Sesión</div>
          <div class="box box-lav">
            <table class="datos">' . $sesionFilas . '</table>
          </div>
        </td>
      </tr>
    </table>

    <!-- PAQUETES Y ADICIONALES ADQUIRIDOS -->
    <div class="sec-label">Paquetes y adicionales adquiridos (' . count($itemsList) . ')</div>
    <table class="items-table">
      <thead>
        <tr>
          <th class="num">#</th>
          <th>Descripción</th>
          <th>Categoría</th>
          <th class="center" style="width:45px;">Cant.</th>
          <th class="right" style="width:75px;">Precio unit.</th>
          <th class="right" style="width:80px;">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        ' . $itemsHTML . '
      </tbody>
    </table>

    <!-- HISTORIAL DE ABONOS -->
    <div class="sec-label">Historial de abonos (' . count($pagosList) . ')</div>
    <table class="pagos-table">
      <thead>
        <tr>
          <th class="num">#</th>
          <th>Fecha</th>
          <th>Método</th>
          <th>Concepto</th>
          <th class="right">Monto</th>
        </tr>
      </thead>
      <tbody>
        ' . $pagosHTML . '
        ' . (!empty($pagosList) ? '
        <tr class="pagos-total">
          <td colspan="4">Total abonado</td>
          <td style="text-align:right;color:#3a9994;">' . formatoPeso($totalPagos) . '</td>
        </tr>' : '') . '
      </tbody>
    </table>

    <!-- RESUMEN -->
    <table class="resumen-wrap">
      <tr>
        <td style="width:50%;"></td>
        <td style="width:50%;">
          <div class="sec-label">Resumen</div>
          <table class="resumen">
            <tr class="total">
              <td class="rk" style="color:#ffffff;">Total factura</td>
              <td class="rv">' . formatoPeso($f['total']) . '</td>
            </tr>
            <tr>
              <td class="rk">Abonado</td>
              <td class="rv" style="color:#2a9d73;">' . formatoPeso($f['abono']) . '</td>
            </tr>
            ' . ($saldo > 0
              ? '<tr class="saldo"><td>Saldo pendiente</td><td style="text-align:right;">' . formatoPeso($saldo) . '</td></tr>'
              : '<tr class="pagada"><td colspan="2">Completamente pagada</td></tr>') . '
          </table>
        </td>
      </tr>
    </table>

    <!-- NOTAS -->
    ' . (!empty($f['notas']) ? '
    <div class="notes-box">
      <div class="notes-title">Notas</div>
      ' . nl2br(htmlspecialchars($f['notas'])) . '
    </div>' : '') . '

  </div>
';

$footerHtml = '
  <table class="footer">
    <tr>
      <td>
        <div class="footer-brand">' . htmlspecialchars(SITE_NAME) . '</div>
        <div class="footer-sub">Gracias por confiar en nosotros</div>
      </td>
      <td>
        <div class="footer-num">' . htmlspecialchars($f['numero_factura']) . '</div>
        <div class="footer-gen">Generado el ' . date('d/m/Y \a \l\a\s H:i') . '</div>
      </td>
    </tr>
  </table>';

// ─── Renderizar con mPDF ──────────────────────────────────────────────────────
$mpdf = new \Mpdf\Mpdf([
    'mode'          => 'utf-8',
    'format'        => 'A4',
    'margin_top'    => 0,
    'margin_bottom' => 18,
    'margin_left'   => 0,
    'margin_right'  => 0,
    'margin_footer' => 0,
    'default_font'  => 'dejavusans',
]);

$mpdf->SetTitle('Factura ' . $f['numero_factura']);
$mpdf->SetAuthor(SITE_NAME);
$mpdf->SetCreator(SITE_NAME);

$mpdf->SetHTMLFooter($footerHtml);
$mpdf->WriteHTML($html);

$nombreArchivo = 'Factura_' . preg_replace('/[^A-Za-z0-9\-]/', '_', $f['numero_factura']) . '.pdf';
// ?ver=1 → mostrar dentro del navegador (panel de Sesiones). Sin el parámetro se descarga como siempre.
$destinoPdf = (isset($_GET['ver']) && $_GET['ver'] === '1')
    ? \Mpdf\Output\Destination::INLINE
    : \Mpdf\Output\Destination::DOWNLOAD;
$mpdf->Output($nombreArchivo, $destinoPdf);
exit;