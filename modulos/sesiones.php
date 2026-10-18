<?php
require_once '../config.php';
date_default_timezone_set('America/Bogota');
requerirLogin();

$db = getDB();
$mensaje = '';
$error   = '';

// ── Auto-asegurar columnas para grupos de fotos y abonos ──
function asegurarColumna($db, $tabla, $columna, $def) {
    $r = $db->query("SHOW COLUMNS FROM `$tabla` LIKE '$columna'");
    if ($r && $r->num_rows === 0) {
        $db->query("ALTER TABLE `$tabla` ADD COLUMN `$columna` $def");
    }
}
asegurarColumna($db, 'ingresos', 'sesion_id', 'INT NULL AFTER factura_id');
asegurarColumna($db, 'sesion_fotos', 'categoria', "VARCHAR(100) NOT NULL DEFAULT 'sesion' AFTER sesion_id");
asegurarColumna($db, 'sesion_fotos', 'grupo_desc', 'VARCHAR(255) NULL AFTER categoria');

// ── Sincronización segura de enlaces contables existentes ──
function autoSincronizarAbonos($db) {
    // 1. Vincular ingresos que ya tengan factura_id explícita a la sesión de esa factura (excepto filas históricas desvinculadas)
    $db->query("UPDATE ingresos i JOIN facturas f ON i.factura_id = f.id SET i.sesion_id = f.sesion_id WHERE i.sesion_id IS NULL AND f.sesion_id IS NOT NULL AND i.id NOT IN (19, 21, 27, 36)");

    // 2. Mapeo seguro e idempotente de abonos históricos originales (sin borrados y sin duplicados)
    $db->query("UPDATE ingresos SET sesion_id = 12, factura_id = 12 WHERE id = 17 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 32, factura_id = 32 WHERE id = 18 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 16, factura_id = 16 WHERE id = 20 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 14, factura_id = 14 WHERE id = 22 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 57, factura_id = 57 WHERE id IN (23, 28) AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 19, factura_id = 19 WHERE id = 24 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 18, factura_id = 18 WHERE id IN (25, 26) AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 71, factura_id = 71 WHERE id = 29 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 29, factura_id = 29 WHERE id = 30 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 72, factura_id = 72 WHERE id = 31 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 56, factura_id = 56 WHERE id = 32 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 13, factura_id = 13 WHERE id = 33 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 17, factura_id = 17 WHERE id = 34 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 47, factura_id = 47 WHERE id = 35 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 73, factura_id = 73 WHERE id = 37 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 74, factura_id = 74 WHERE id = 38 AND sesion_id IS NULL");
    $db->query("UPDATE ingresos SET sesion_id = 70, factura_id = 70 WHERE id = 39 AND sesion_id IS NULL");
}
autoSincronizarAbonos($db);


if (isset($_GET['accion_galeria'])) {
    $sid = sanitizeInt($_GET['sesion_id'] ?? 0);
    $rows = [];
    if ($sid) {
        $r = $db->query("SELECT id, archivo, descripcion, categoria, grupo_desc
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
        $hora_sesion    = sanitize($_POST['hora_sesion'] ?? '');
        $abono          = max(0, floatval($_POST['abono'] ?? 0));
        $estado_entrega = sanitize($_POST['estado_entrega'] ?? 'pendiente');
        $notas          = sanitize($_POST['notas'] ?? '');
        $metodo_pago    = sanitize($_POST['metodo_pago'] ?? 'efectivo');

        // Normalizar hora a formato HH:MM:SS
        if ($hora_sesion !== '' && strlen($hora_sesion) === 5) $hora_sesion .= ':00';

        // Sesión principal.
        $producto_id_principal = sanitizeInt($_POST['producto_principal_id'] ?? 0);
        if (!$producto_id_principal) {
            $producto_id_principal = null;
        }

        // Adicionales y cantidades.
        $adicional_ids = $_POST['adicional_id'] ?? [];
        $adicional_cantidades = $_POST['adicional_cantidad'] ?? [];
        if (!is_array($adicional_ids)) $adicional_ids = [$adicional_ids];
        if (!is_array($adicional_cantidades)) $adicional_cantidades = [$adicional_cantidades];

        $adicionales = [];
        foreach ($adicional_ids as $idx => $aid) {
            $aid = (int)$aid;
            $cantidad = max(1, (int)($adicional_cantidades[$idx] ?? 1));
            if ($aid > 0) $adicionales[$aid] = ($adicionales[$aid] ?? 0) + $cantidad;
        }

        $total = 0;
        $precioPrincipal = 0;
        if ($producto_id_principal > 0) {
            $sp = $db->prepare("SELECT precio FROM productos WHERE id=? AND estado='activo' LIMIT 1");
            $sp->bind_param("i", $producto_id_principal);
            $sp->execute();
            $productoPrincipal = $sp->get_result()->fetch_assoc();
            $sp->close();
            if ($productoPrincipal) {
                $precioPrincipal = (float)$productoPrincipal['precio'];
                $total = $precioPrincipal;
            }
        }

        $preciosAdicionales = [];
        foreach ($adicionales as $producto_id => $cantidad) {
            $sp = $db->prepare("SELECT precio FROM productos WHERE id=? AND estado='activo' LIMIT 1");
            $sp->bind_param("i", $producto_id);
            $sp->execute();
            $producto = $sp->get_result()->fetch_assoc();
            $sp->close();
            if ($producto) {
                $precio = (float)$producto['precio'];
                $preciosAdicionales[$producto_id] = $precio;
                $total += $precio * $cantidad;
            }
        }

        if (!$cliente_id || !$fecha_sesion || !$hora_sesion) {
            $error = 'Cliente, fecha y hora son obligatorios.';
        }

        // Verificar que no haya otra sesión agendada en la misma fecha y hora.
        if (!$error) {
            if ($id > 0) {
                $stmtChk = $db->prepare("SELECT id FROM sesiones WHERE fecha_sesion=? AND hora_sesion=? AND id<>? LIMIT 1");
                $stmtChk->bind_param("ssi", $fecha_sesion, $hora_sesion, $id);
            } else {
                $stmtChk = $db->prepare("SELECT id FROM sesiones WHERE fecha_sesion=? AND hora_sesion=? LIMIT 1");
                $stmtChk->bind_param("ss", $fecha_sesion, $hora_sesion);
            }
            $stmtChk->execute();
            if ($stmtChk->get_result()->num_rows > 0) {
                $error = 'Ya hay una sesión agendada a esa hora. Elige otra hora disponible.';
            }
            $stmtChk->close();
        }

        if (!$error) {
            // El estado de pago se calcula automáticamente según el abono.
            if ($total > 0 && $abono > $total) $abono = $total;
            $estado_pago = ($total > 0 && $abono >= $total) ? 'pagado' : (($total == 0 && $abono > 0) ? 'reserva' : ($abono > 0 ? 'abonado' : 'pendiente'));

            if ($id > 0) {
                // Obtener estado anterior para detectar cambios de ingreso.
                $prev = $db->query("SELECT estado_pago, total, abono FROM sesiones WHERE id=$id")->fetch_assoc();

                $stmt = $db->prepare("UPDATE sesiones SET cliente_id=?,cita_id=?,producto_id=?,fecha_sesion=?,hora_sesion=?,total=?,abono=?,estado_pago=?,estado_entrega=?,notas=?,metodo_pago=? WHERE id=?");
                $stmt->bind_param("iiissddssssi", $cliente_id,$cita_id,$producto_id_principal,$fecha_sesion,$hora_sesion,$total,$abono,$estado_pago,$estado_entrega,$notas,$metodo_pago,$id);
                $stmt->execute(); $stmt->close();

                // Reemplazar únicamente los adicionales de la sesión.
                $stmtSP = $db->prepare("DELETE FROM sesion_productos WHERE sesion_id=?");
                $stmtSP->bind_param("i", $id);
                $stmtSP->execute();
                $stmtSP->close();

                foreach ($preciosAdicionales as $producto_id => $precioProducto) {
                    $cantidad = (int)$adicionales[$producto_id];
                    $si = $db->prepare("INSERT INTO sesion_productos (sesion_id, producto_id, cantidad, precio) VALUES (?, ?, ?, ?)");
                    $si->bind_param("iiid", $id, $producto_id, $cantidad, $precioProducto);
                    $si->execute();
                    $si->close();
                }

                // Actualizar factura vinculada automáticamente.
                $estadoFac = ($total > 0 && $abono >= $total) ? 'pagada' : ($abono > 0 ? 'abonada' : 'pendiente');
                $sfac = $db->prepare("UPDATE facturas SET total=?, abono=?, metodo_pago=?, estado=? WHERE sesion_id=?");
                $sfac->bind_param("ddssi", $total, $abono, $metodo_pago, $estadoFac, $id);
                $sfac->execute(); $sfac->close();

                // Obtenemos factura_id vinculada para enlazar los ingresos contables
                $rfId = $db->query("SELECT id FROM facturas WHERE sesion_id=$id LIMIT 1");
                $facIdVinculada = ($rfId && $rowF = $rfId->fetch_assoc()) ? (int)$rowF['id'] : null;

                // Si cambió a pagado, registrar ingreso automático.
                if ($prev && $prev['estado_pago'] !== 'pagado' && $estado_pago === 'pagado') {
                    $montoIngreso = $total - (float)$prev['abono'];
                    if ($montoIngreso > 0) {
                        $rCount = $db->query("SELECT COUNT(*) as t FROM ingresos WHERE (sesion_id=$id" . ($facIdVinculada ? " OR factura_id=$facIdVinculada" : "") . ") AND tipo='ingreso'");
                        $numAb = ($rCount ? (int)$rCount->fetch_assoc()['t'] : 0) + 1;
                        $concepto  = "Pago saldo final (#$numAb) – " . date('d/m/Y', strtotime($fecha_sesion));
                        $fecha_hoy = $fecha_sesion;
                        $si = $db->prepare("INSERT INTO ingresos (sesion_id, factura_id, cliente_id, concepto, monto, tipo, metodo_pago, fecha) VALUES (?,?,?,?,?,'ingreso',?,?)");
                        $si->bind_param("iiisdss", $id, $facIdVinculada, $cliente_id, $concepto, $montoIngreso, $metodo_pago, $fecha_hoy);
                        $si->execute(); $si->close();
                    }
                }
                // Si el abono aumentó, registrar la diferencia.
                if ($prev && $abono > (float)$prev['abono']) {
                    $diff = $abono - (float)$prev['abono'];
                    $rCount = $db->query("SELECT COUNT(*) as t FROM ingresos WHERE (sesion_id=$id" . ($facIdVinculada ? " OR factura_id=$facIdVinculada" : "") . ") AND tipo='ingreso'");
                    $numAb = ($rCount ? (int)$rCount->fetch_assoc()['t'] : 0) + 1;
                    $concepto  = "Abono #$numAb sesión – " . date('d/m/Y', strtotime($fecha_sesion));
                    $fecha_hoy = $fecha_sesion;
                    $si = $db->prepare("INSERT INTO ingresos (sesion_id, factura_id, cliente_id, concepto, monto, tipo, metodo_pago, fecha) VALUES (?,?,?,?,?,'ingreso',?,?)");
                    $si->bind_param("iiisdss", $id, $facIdVinculada, $cliente_id, $concepto, $diff, $metodo_pago, $fecha_hoy);
                    $si->execute(); $si->close();
                }
                $mensaje = 'Sesión actualizada correctamente.';
            } else {
                $stmt = $db->prepare("INSERT INTO sesiones (cliente_id,cita_id,producto_id,fecha_sesion,hora_sesion,total,abono,estado_pago,estado_entrega,notas,metodo_pago) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("iiissddssss", $cliente_id,$cita_id,$producto_id_principal,$fecha_sesion,$hora_sesion,$total,$abono,$estado_pago,$estado_entrega,$notas,$metodo_pago);
                $stmt->execute();
                $nuevaSesionId = $stmt->insert_id;
                $stmt->close();

                foreach ($preciosAdicionales as $producto_id => $precioProducto) {
                    $cantidad = (int)$adicionales[$producto_id];
                    $si = $db->prepare("INSERT INTO sesion_productos (sesion_id, producto_id, cantidad, precio) VALUES (?, ?, ?, ?)");
                    $si->bind_param("iiid", $nuevaSesionId, $producto_id, $cantidad, $precioProducto);
                    $si->execute();
                    $si->close();
                }

                // Generar factura automáticamente.
                $anio = date('Y');
                $rNum = $db->query("SELECT COUNT(*) as t FROM facturas WHERE YEAR(fecha_emision)=$anio");
                $numFac = $rNum->fetch_assoc()['t'] + 1;
                $numero_factura = 'FAC-' . $anio . '-' . str_pad($numFac, 4, '0', STR_PAD_LEFT);
                $estadoFac = ($total > 0 && $abono >= $total) ? 'pagada' : ($abono > 0 ? 'abonada' : 'pendiente');
                $sfac = $db->prepare("INSERT INTO facturas (sesion_id, cliente_id, numero_factura, total, abono, metodo_pago, estado) VALUES (?,?,?,?,?,?,?)");
                $sfac->bind_param("iisddss", $nuevaSesionId, $cliente_id, $numero_factura, $total, $abono, $metodo_pago, $estadoFac);
                $sfac->execute();
                $nuevaFacturaId = $sfac->insert_id;
                $sfac->close();

                if ($abono > 0) {
                    $concepto  = ($estado_pago === 'pagado' ? "Pago sesión – " : "Abono #1 sesión – ") . date('d/m/Y', strtotime($fecha_sesion));
                    $fecha_hoy = $fecha_sesion;
                    $si = $db->prepare("INSERT INTO ingresos (sesion_id, factura_id, cliente_id, concepto, monto, tipo, metodo_pago, fecha) VALUES (?,?,?,?,?,'ingreso',?,?)");
                    $si->bind_param("iiisdss", $nuevaSesionId, $nuevaFacturaId, $cliente_id, $concepto, $abono, $metodo_pago, $fecha_hoy);
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

    // ── Subir fotos con categorías y grupos ──
    if ($accion === 'subir_fotos') {
        $sesion_id   = sanitizeInt($_POST['sesion_id'] ?? 0);
        $categoria   = sanitize($_POST['categoria'] ?? 'sesion');
        if (empty($categoria)) $categoria = 'sesion';
        $grupo_desc  = sanitize($_POST['grupo_desc'] ?? '');
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
                    $st = $db->prepare("INSERT INTO sesion_fotos (sesion_id, categoria, grupo_desc, archivo, descripcion) VALUES (?,?,?,?,?)");
                    $st->bind_param("issss", $sesion_id, $categoria, $grupo_desc, $rel, $descripcion);
                    $st->execute(); $st->close();
                    $subidos++;
                }
            }
            $mensaje = "$subidos foto(s) subida(s) correctamente al grupo seleccionado.";
        }
    }

    // ── Registrar nuevo abono ordenado en sesión ──
    if ($accion === 'registrar_abono_sesion') {
        $sesion_id   = sanitizeInt($_POST['sesion_id'] ?? 0);
        $monto       = floatval($_POST['monto'] ?? 0);
        $metodo_pago = sanitize($_POST['metodo_pago'] ?? 'efectivo');
        $fecha_abono = sanitize($_POST['fecha'] ?? date('Y-m-d'));
        $notas_abono = sanitize($_POST['notas'] ?? '');

        if ($sesion_id > 0 && $monto > 0) {
            $rS = $db->query("SELECT s.cliente_id, s.total, s.abono, s.fecha_sesion, s.metodo_pago, f.id AS factura_id, f.numero_factura 
                              FROM sesiones s 
                              LEFT JOIN facturas f ON f.sesion_id = s.id 
                              WHERE s.id=$sesion_id");
            if ($rS && $rowS = $rS->fetch_assoc()) {
                $cliente_id = (int)$rowS['cliente_id'];
                $totalS     = (float)$rowS['total'];
                $facId      = !empty($rowS['factura_id']) ? (int)$rowS['factura_id'] : null;

                // Contar cuántos abonos ya existen registrados en ingresos
                $rCount = $db->query("SELECT COUNT(*) as t FROM ingresos WHERE (sesion_id=$sesion_id" . ($facId ? " OR factura_id=$facId" : "") . ") AND tipo='ingreso'");
                $numExistentes = ($rCount ? (int)$rCount->fetch_assoc()['t'] : 0);

                // Si la sesión ya tenía un abono registrado previamente pero aún no existía fila individual en ingresos:
                $abonoPrevio = (float)($rowS['abono'] ?? 0);
                if ($numExistentes === 0 && $abonoPrevio > 0) {
                    $fechaIni    = !empty($rowS['fecha_sesion']) ? $rowS['fecha_sesion'] : date('Y-m-d');
                    $metodoIni   = !empty($rowS['metodo_pago']) ? $rowS['metodo_pago'] : 'efectivo';
                    $conceptoIni = "Abono #1 sesión – " . date('d/m/Y', strtotime($fechaIni));
                    $notasIni    = "Abono inicial registrado con la sesión";
                    $stIni = $db->prepare("INSERT INTO ingresos (sesion_id, factura_id, cliente_id, concepto, monto, tipo, metodo_pago, fecha, notas) VALUES (?,?,?,?,?,'ingreso',?,?,?)");
                    $stIni->bind_param("iiisdsss", $sesion_id, $facId, $cliente_id, $conceptoIni, $abonoPrevio, $metodoIni, $fechaIni, $notasIni);
                    $stIni->execute();
                    $stIni->close();
                    $numExistentes = 1;
                }

                $numAbono = $numExistentes + 1;
                $concepto = "Abono #$numAbono sesión – " . date('d/m/Y', strtotime($fecha_abono));

                $si = $db->prepare("INSERT INTO ingresos (sesion_id, factura_id, cliente_id, concepto, monto, tipo, metodo_pago, fecha, notas) VALUES (?,?,?,?,?,'ingreso',?,?,?)");
                $si->bind_param("iiisdsss", $sesion_id, $facId, $cliente_id, $concepto, $monto, $metodo_pago, $fecha_abono, $notas_abono);
                $si->execute(); $si->close();

                // Recalcular abono total acumulado desde ingresos
                $rSum = $db->query("SELECT COALESCE(SUM(monto),0) as sum_abono FROM ingresos WHERE (sesion_id=$sesion_id" . ($facId ? " OR factura_id=$facId" : "") . ") AND tipo='ingreso'");
                $nuevoAbonoTotal = $rSum ? (float)$rSum->fetch_assoc()['sum_abono'] : $monto;

                $nuevoEstado = ($totalS > 0 && $nuevoAbonoTotal >= $totalS) ? 'pagado' : (($totalS == 0 && $nuevoAbonoTotal > 0) ? 'reserva' : ($nuevoAbonoTotal > 0 ? 'abonado' : 'pendiente'));

                // Actualizar sesión
                $upS = $db->prepare("UPDATE sesiones SET abono=?, estado_pago=?, metodo_pago=? WHERE id=?");
                $upS->bind_param("dssi", $nuevoAbonoTotal, $nuevoEstado, $metodo_pago, $sesion_id);
                $upS->execute(); $upS->close();

                // Actualizar factura vinculada si existe
                if ($facId) {
                    $estFac = ($totalS > 0 && $nuevoAbonoTotal >= $totalS) ? 'pagada' : ($nuevoAbonoTotal > 0 ? 'abonada' : 'pendiente');
                    $upF = $db->prepare("UPDATE facturas SET abono=?, estado=?, metodo_pago=? WHERE id=?");
                    $upF->bind_param("dssi", $nuevoAbonoTotal, $estFac, $metodo_pago, $facId);
                    $upF->execute(); $upF->close();
                }

                $mensaje = "Abono #$numAbono de " . formatoPeso($monto) . " registrado correctamente.";
                $sesionParamId = $sesion_id;
            }
        }
    }

    // ── Eliminar abono individual ──
    if ($accion === 'eliminar_abono_sesion') {
        $abono_id  = sanitizeInt($_POST['abono_id'] ?? 0);
        $sesion_id = sanitizeInt($_POST['sesion_id'] ?? 0);

        if ($abono_id > 0 && $sesion_id > 0) {
            $db->query("DELETE FROM ingresos WHERE id=$abono_id");

            $rS = $db->query("SELECT s.total, f.id AS factura_id FROM sesiones s LEFT JOIN facturas f ON f.sesion_id=s.id WHERE s.id=$sesion_id");
            if ($rS && $rowS = $rS->fetch_assoc()) {
                $totalS = (float)$rowS['total'];
                $facId  = !empty($rowS['factura_id']) ? (int)$rowS['factura_id'] : null;

                $rSum = $db->query("SELECT COALESCE(SUM(monto),0) as sum_abono FROM ingresos WHERE (sesion_id=$sesion_id" . ($facId ? " OR factura_id=$facId" : "") . ") AND tipo='ingreso'");
                $nuevoAbonoTotal = $rSum ? (float)$rSum->fetch_assoc()['sum_abono'] : 0;
                $nuevoEstado = ($totalS > 0 && $nuevoAbonoTotal >= $totalS) ? 'pagado' : (($totalS == 0 && $nuevoAbonoTotal > 0) ? 'reserva' : ($nuevoAbonoTotal > 0 ? 'abonado' : 'pendiente'));

                $db->query("UPDATE sesiones SET abono=$nuevoAbonoTotal, estado_pago='$nuevoEstado' WHERE id=$sesion_id");
                if ($facId) {
                    $estFac = ($totalS > 0 && $nuevoAbonoTotal >= $totalS) ? 'pagada' : ($nuevoAbonoTotal > 0 ? 'abonada' : 'pendiente');
                    $db->query("UPDATE facturas SET abono=$nuevoAbonoTotal, estado='$estFac' WHERE id=$facId");
                }
                $mensaje = "Abono eliminado y saldos recalculados correctamente.";
                $sesionParamId = $sesion_id;
            }
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

    // ── Eliminar sesión con sincronización contable ──
    if ($accion === 'eliminar') {
        $id   = sanitizeInt($_POST['id'] ?? 0);
        $modo = sanitize($_POST['modo_eliminacion'] ?? 'total'); // 'total' o 'anular_factura'

        if ($id > 0) {
            // 1. Borrar fotos físicas del disco
            $r = $db->query("SELECT archivo FROM sesion_fotos WHERE sesion_id=$id");
            while ($row = $r->fetch_assoc()) {
                $f = UPLOAD_DIR . $row['archivo'];
                if (file_exists($f)) unlink($f);
            }

            // 2. Obtener datos de la sesión y factura vinculada
            $rS = $db->query("SELECT s.cliente_id, s.fecha_sesion, f.id AS factura_id, f.numero_factura 
                              FROM sesiones s 
                              LEFT JOIN facturas f ON f.sesion_id = s.id 
                              WHERE s.id=$id");
            $infoS = $rS ? $rS->fetch_assoc() : null;

            if ($infoS) {
                $cliId         = (int)$infoS['cliente_id'];
                $facId         = !empty($infoS['factura_id']) ? (int)$infoS['factura_id'] : null;
                $fechaFmt      = date('d/m/Y', strtotime($infoS['fecha_sesion']));
                $pagoConcepto  = "Pago sesión – $fechaFmt";
                $abonoConcepto = "Abono sesión – $fechaFmt";

                // 3. Eliminar únicamente los ingresos asociados a esta sesión específica o su factura
                if ($facId) {
                    $sI = $db->prepare("DELETE FROM ingresos WHERE sesion_id=? OR factura_id=?");
                    $sI->bind_param("ii", $id, $facId);
                    $sI->execute(); $sI->close();
                } else {
                    $sI = $db->prepare("DELETE FROM ingresos WHERE sesion_id=?");
                    $sI->bind_param("i", $id);
                    $sI->execute(); $sI->close();
                }

                // 4. Gestión de la factura vinculada
                if ($facId) {
                    if ($modo === 'anular_factura') {
                        // Opción B: Dejar factura archivada con estado 'cancelada'
                        $notaCancel = "\n[Sesión eliminada el " . date('d/m/Y') . "]";
                        $sFac = $db->prepare("UPDATE facturas SET estado='cancelada', abono=0, sesion_id=NULL, notas=CONCAT(COALESCE(notas,''), ?) WHERE id=?");
                        $sFac->bind_param("si", $notaCancel, $facId);
                        $sFac->execute(); $sFac->close();
                    } else {
                        // Opción A: Limpieza total (borrar factura)
                        $sFac = $db->prepare("DELETE FROM facturas WHERE id=?");
                        $sFac->bind_param("i", $facId);
                        $sFac->execute(); $sFac->close();
                    }
                }
            }

            // 5. Eliminar productos adicionales y la sesión
            $db->query("DELETE FROM sesion_productos WHERE sesion_id=$id");
            $db->query("DELETE FROM sesion_fotos WHERE sesion_id=$id");
            $s = $db->prepare("DELETE FROM sesiones WHERE id=?");
            $s->bind_param("i", $id); $s->execute(); $s->close();

            $mensaje = ($modo === 'anular_factura')
                ? 'Sesión eliminada. La factura quedó marcada como cancelada y los ingresos fueron retirados del balance.'
                : 'Sesión, factura e ingresos asociados eliminados correctamente.';
        }
    }
}

// ── Calendario ──
$hoy   = new DateTime();
$sesionParamId = sanitizeInt($_GET['sesion_id'] ?? ($_GET['id'] ?? 0));

$anioParam = isset($_GET['anio']) ? sanitizeInt($_GET['anio']) : null;
$mesParam  = isset($_GET['mes'])  ? sanitizeInt($_GET['mes'])  : null;
$diaParam  = isset($_GET['dia'])  ? sanitizeInt($_GET['dia'])  : null;

// Si viene sesion_id y falta algún parámetro de fecha, consultamos la fecha real de la sesión
if ($sesionParamId > 0 && ($anioParam === null || $mesParam === null || $diaParam === null)) {
    $stmtSDate = $db->prepare("SELECT fecha_sesion FROM sesiones WHERE id = ?");
    if ($stmtSDate) {
        $stmtSDate->bind_param("i", $sesionParamId);
        $stmtSDate->execute();
        $resSDate = $stmtSDate->get_result();
        if ($rowSDate = $resSDate->fetch_assoc()) {
            if (!empty($rowSDate['fecha_sesion'])) {
                $dtS = new DateTime($rowSDate['fecha_sesion']);
                if ($anioParam === null) $anioParam = (int)$dtS->format('Y');
                if ($mesParam === null)  $mesParam  = (int)$dtS->format('n');
                if ($diaParam === null)  $diaParam  = (int)$dtS->format('j');
            }
        }
        $stmtSDate->close();
    }
}

$anioC = $anioParam ?? (int)$hoy->format('Y');
$mesC  = $mesParam  ?? (int)$hoy->format('n');
if ($mesC < 1)  { $mesC = 12; $anioC--; }
if ($mesC > 12) { $mesC = 1;  $anioC++; }
$primerDia = new DateTime("$anioC-$mesC-01");
$diasMes   = (int)$primerDia->format('t');
$diaSemana = (int)$primerDia->format('N');

$estadoFiltro = sanitize($_GET['estado'] ?? '');
$pagina       = max(1, sanitizeInt($_GET['p'] ?? 1));
$porPagina    = 12;
$offset       = ($pagina - 1) * $porPagina;

// ── Adicionales asociados a cada sesión ──
$adicionalesPorSesion = [];
$rSP = $db->query("SELECT sesion_id, producto_id, cantidad, precio FROM sesion_productos ORDER BY id ASC");
if ($rSP) {
    while ($rowSP = $rSP->fetch_assoc()) {
        $sidSP = (int)$rowSP['sesion_id'];
        $adicionalesPorSesion[$sidSP][] = [
            'producto_id' => (int)$rowSP['producto_id'],
            'cantidad' => max(1, (int)$rowSP['cantidad']),
            'precio' => (float)$rowSP['precio']
        ];
    }
}

// ── Historial de abonos por sesión ──
$abonosPorSesion = [];
$abonosPorFactura = [];
$rAb = $db->query("SELECT id, sesion_id, factura_id, concepto, monto, metodo_pago, fecha, notas 
                   FROM ingresos 
                   WHERE tipo='ingreso' AND (sesion_id IS NOT NULL OR factura_id IS NOT NULL) 
                   ORDER BY fecha ASC, id ASC");
if ($rAb) {
    while ($abRow = $rAb->fetch_assoc()) {
        $sIdAb = (int)($abRow['sesion_id'] ?? 0);
        $fIdAb = (int)($abRow['factura_id'] ?? 0);
        if ($sIdAb > 0) {
            $abonosPorSesion[$sIdAb][] = $abRow;
        } elseif ($fIdAb > 0) {
            $abonosPorFactura[$fIdAb][] = $abRow;
        }
    }
}

$fechaIni = "$anioC-$mesC-01";
$fechaFin = "$anioC-$mesC-$diasMes";
$stmtCal  = $db->prepare("SELECT s.*,cl.nombre,cl.apellido,cl.telefono,p.nombre AS servicio FROM sesiones s JOIN clientes cl ON s.cliente_id=cl.id LEFT JOIN productos p ON s.producto_id=p.id WHERE s.fecha_sesion BETWEEN ? AND ? ORDER BY s.fecha_sesion, s.hora_sesion, s.id");
$stmtCal->bind_param("ss", $fechaIni, $fechaFin);
$stmtCal->execute();
$sesionesCalendario = [];
$res = $stmtCal->get_result();
while ($row = $res->fetch_assoc()) {
    $sid = (int)$row['id'];

    // Adicionales con cantidades para poder recuperar la edición.
    $row['adicionales'] = $adicionalesPorSesion[$sid] ?? [];
    // Compatibilidad con el formato anterior.
    $row['servicios_ids'] = array_map(function($a){ return (int)$a['producto_id']; }, $row['adicionales']);
    $row['abonos'] = $abonosPorSesion[$sid] ?? [];

    $d = (int)(new DateTime($row['fecha_sesion']))->format('j');
    $sesionesCalendario[$d][] = $row;
}
$stmtCal->close();

$diaSeleccionado = $diaParam ?? (int)$hoy->format('j');
if ($diaSeleccionado > $diasMes) $diaSeleccionado = $diasMes;
if ($diaSeleccionado < 1) $diaSeleccionado = 1;
$sesionesDelDia  = $sesionesCalendario[$diaSeleccionado] ?? [];

// ── Agenda por hora del día seleccionado (08:00 a 20:00) ──
$horasAgendaSesiones = [];
for ($h = 8; $h <= 20; $h++) $horasAgendaSesiones[] = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';

$sesionesPorHora      = [];
$sesionesFueraHorario = [];
foreach ($sesionesDelDia as $se) {
    if (!empty($se['hora_sesion'])) {
        $horaFloor = substr($se['hora_sesion'], 0, 2) . ':00';
        if (in_array($horaFloor, $horasAgendaSesiones, true)) {
            $sesionesPorHora[$horaFloor][] = $se;
        } else {
            $sesionesFueraHorario[] = $se;
        }
    } else {
        $sesionesFueraHorario[] = $se;
    }
}
// Ordenar por minuto exacto dentro de cada hora.
foreach ($sesionesPorHora as $h => &$listaHora) {
    usort($listaHora, function($a, $b){ return strcmp($a['hora_sesion'] ?? '', $b['hora_sesion'] ?? ''); });
}
unset($listaHora);

// Armar la agenda completa (horas fijas + bloque "Otras" si aplica).
$agendaSesionesDelDia = [];
foreach ($horasAgendaSesiones as $hora) $agendaSesionesDelDia[$hora] = $sesionesPorHora[$hora] ?? [];
if (!empty($sesionesFueraHorario)) $agendaSesionesDelDia['Otras'] = $sesionesFueraHorario;

// Mostrar únicamente las horas que sí tienen sesiones agendadas.
$agendaSesionesDelDia = array_filter($agendaSesionesDelDia, function($lista){ return !empty($lista); });

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

$stmtL = $db->prepare("SELECT s.*,cl.nombre,cl.apellido,cl.telefono,p.nombre AS servicio FROM sesiones s JOIN clientes cl ON s.cliente_id=cl.id LEFT JOIN productos p ON s.producto_id=p.id $where ORDER BY s.fecha_sesion DESC, s.hora_sesion DESC, s.id DESC LIMIT ? OFFSET ?");
$tiposL = $tipos.'ii'; $paramsL = array_merge($params, [$porPagina, $offset]);
$stmtL->bind_param($tiposL, ...$paramsL);
$stmtL->execute();
$listadoSesiones = $stmtL->get_result();
$stmtL->close();

// Datos completos para que Editar funcione desde calendario y listado sin depender del onclick.
$sesionesParaJS = [];
// Incluye la factura vinculada (facturas.sesion_id). Si hubiera más de una, se toma la más reciente.
try {
$rJS = $db->query("SELECT s.*,cl.nombre,cl.apellido,cl.telefono,p.nombre AS servicio,
        fv.id AS factura_id, fv.numero_factura AS factura_numero, fv.total AS factura_total,
        fv.abono AS factura_abono, fv.estado AS factura_estado
    FROM sesiones s
    JOIN clientes cl ON s.cliente_id=cl.id
    LEFT JOIN productos p ON s.producto_id=p.id
    LEFT JOIN (SELECT sesion_id, MAX(id) AS fid FROM facturas WHERE sesion_id IS NOT NULL GROUP BY sesion_id) fx ON fx.sesion_id=s.id
    LEFT JOIN facturas fv ON fv.id=fx.fid
    ORDER BY s.fecha_sesion DESC, s.hora_sesion DESC, s.id DESC");
} catch (Throwable $e) { $rJS = false; }
if (!$rJS) {
    // Respaldo: si la consulta con facturas fallara, se usa la consulta original para no romper Editar.
    $rJS = $db->query("SELECT s.*,cl.nombre,cl.apellido,cl.telefono,p.nombre AS servicio FROM sesiones s JOIN clientes cl ON s.cliente_id=cl.id LEFT JOIN productos p ON s.producto_id=p.id ORDER BY s.fecha_sesion DESC, s.hora_sesion DESC, s.id DESC");
}
if ($rJS) {
    while ($rowJS = $rJS->fetch_assoc()) {
        $sidJS = (int)$rowJS['id'];
        $fidJS = (int)($rowJS['factura_id'] ?? 0);
        $rowJS['adicionales'] = $adicionalesPorSesion[$sidJS] ?? [];
        $rowJS['servicios_ids'] = array_map(function($a){ return (int)$a['producto_id']; }, $rowJS['adicionales']);
        $rowJS['abonos'] = $abonosPorSesion[$sidJS] ?? ($fidJS && isset($abonosPorFactura[$fidJS]) ? $abonosPorFactura[$fidJS] : []);
        $sesionesParaJS[] = $rowJS;
    }
}

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
$todosProductos = $db->query("SELECT id,nombre,descripcion,precio,tipo,categoria FROM productos WHERE estado='activo' ORDER BY nombre ASC");
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
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { height: 100%; margin: 0; padding: 0; box-sizing: border-box; }
  body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);display:flex;overflow:hidden;font-size:13px;}  .sidebar{width:var(--sidebar-w);background:var(--navy);display:flex;flex-direction:column;flex-shrink:0;height:100%;position:relative;overflow:hidden;}
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
  .content{flex:1;min-height:0;overflow-y:auto;overflow-x:auto;padding:22px 26px;display:block;scrollbar-width:thin;scrollbar-color:var(--border) transparent;}
  .content > * { margin-bottom: 18px; }
  .content > *:last-child { margin-bottom: 0; }
  .content::-webkit-scrollbar{width:8px;height:8px;}
  .content::-webkit-scrollbar-track{background:rgba(0,0,0,0.03);border-radius:4px;}
  .content::-webkit-scrollbar-thumb{background:var(--rose-light);border-radius:4px;}
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
  .dot-reserva{background:#6750a4;}
  .time-slots{display:flex;flex-direction:column;gap:0;padding:6px 20px 16px;}
  .time-slot{display:flex;gap:14px;align-items:flex-start;min-height:52px;padding:4px 0;border-bottom:1px dashed var(--border);}
  .time-slot:last-child{border-bottom:none;}
  .slot-time{width:50px;font-size:10.5px;color:var(--text-dim);flex-shrink:0;padding-top:7px;font-weight:700;}
  .slot-line{width:1.5px;background:var(--border);flex-shrink:0;align-self:stretch;margin-top:4px;}
  .slot-content{flex:1;padding-bottom:6px;min-width:0;}
  .slot-empty{font-size:11px;color:var(--text-dim);padding-top:7px;font-style:italic;}
  .ses-block{border-radius:10px;padding:9px 13px;cursor:pointer;transition:all .18s;margin-bottom:8px;background:var(--rose-pale);border:1.5px solid var(--rose-light);border-left:4px solid var(--rose);position:relative;}
  .ses-block:hover{transform:translateX(2px);}
  .ses-block.alt{background:var(--teal-pale);border:1.5px solid var(--teal-light);border-left:4px solid var(--teal);}
  .ses-block.alt2{background:var(--lav-pale);border:1.5px solid var(--lav-light);border-left:4px solid var(--lavender);}
  .ses-block.highlight-sesion {
    border: 2px solid var(--rose) !important;
    border-left: 6px solid var(--rose-deep) !important;
    background: #fff4f8 !important;
    box-shadow: 0 0 0 4px rgba(232, 120, 154, 0.4), 0 8px 24px rgba(212, 85, 122, 0.25) !important;
    animation: sesionPulse 2s infinite ease-in-out;
  }
  @keyframes sesionPulse {
    0%, 100% {
      box-shadow: 0 0 0 4px rgba(232, 120, 154, 0.4), 0 8px 20px rgba(212, 85, 122, 0.2);
    }
    50% {
      box-shadow: 0 0 0 8px rgba(232, 120, 154, 0.7), 0 12px 28px rgba(212, 85, 122, 0.35);
      transform: scale(1.015);
    }
  }
  .ses-tag-highlight {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--rose-deep);
    color: #fff;
    font-size: 9.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
    padding: 3px 8px;
    border-radius: 6px;
    margin-bottom: 6px;
    box-shadow: 0 2px 6px rgba(212, 85, 122, 0.3);
  }
  tr.highlight-row td { background: var(--rose-pale) !important; font-weight: 600; }
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
  .pill-reserva{background:#e8def8;color:#6750a4}.pill-reserva::before{background:#6750a4}
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
  /* GALERÍA Y CARPETAS */
  .galeria-tabs{scrollbar-width:thin;}
  .galeria-tabs::-webkit-scrollbar{height:4px;}
  .galeria-tabs::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}
  .galeria-tab{padding:6px 14px;border-radius:20px;font-size:11.5px;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
  .galeria-tab:hover{border-color:var(--rose-light);color:var(--rose-deep);}
  .galeria-tab.active{background:var(--rose-pale);border-color:var(--rose);color:var(--rose-deep);box-shadow:0 2px 8px rgba(232,120,154,.15);}
  .tab-count{background:rgba(0,0,0,0.06);border-radius:10px;padding:1px 6px;font-size:10px;font-weight:800;}
  .galeria-tab.active .tab-count{background:var(--rose);color:#fff;}
  .galeria-grupo-card{transition:transform .15s;}
  .cat-pill{display:inline-block;letter-spacing:.04em;}
  .badge-cat-sesion{background:var(--rose-pale);color:var(--rose-deep);}
  .badge-cat-comprobante{background:#fff4e5;color:#b76e00;}
  .badge-cat-referencia{background:#eef2ff;color:#4338ca;}
  .badge-cat-otro{background:#f1f5f9;color:#475569;}
  .galeria-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;padding:12px 0;}
  .galeria-item{position:relative;border-radius:10px;overflow:hidden;aspect-ratio:1;background:var(--surface2);border:1.5px solid var(--border);}
  .galeria-item img{width:100%;height:100%;object-fit:cover;transition:transform .2s;}
  .galeria-item:hover img{transform:scale(1.05);}
  .galeria-item .foto-overlay{position:absolute;inset:0;background:rgba(26,31,60,.55);opacity:0;transition:opacity .2s;display:flex;align-items:center;justify-content:center;gap:8px;}
  .galeria-item:hover .foto-overlay{opacity:1;}
  .foto-btn{width:28px;height:28px;border-radius:50%;background:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;box-shadow:0 2px 6px rgba(0,0,0,.2);transition:transform .15s;}
  .foto-btn:hover{transform:scale(1.15);}
  .upload-zone{border:2px dashed var(--rose-light);border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;}
  .upload-zone:hover{border-color:var(--rose);background:var(--rose-pale);}
  .upload-zone input{display:none;}

  /* HISTORIAL DE ABONOS */
  .abono-item{display:flex;align-items:center;justify-content:space-between;background:#fff;border:1.5px solid var(--border);border-radius:10px;padding:9px 12px;transition:all .15s;}
  .abono-item:hover{border-color:var(--teal-light);box-shadow:var(--shadow-sm);}
  .abono-num-badge{background:var(--navy);color:#fff;font-size:10.5px;font-weight:800;padding:2px 7px;border-radius:6px;flex-shrink:0;}
  .abono-metodo-pill{font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:6px;text-transform:uppercase;background:var(--navy-soft);color:var(--navy-mid);}
  /* BUSCADORES DE sesiones y adicionales */
  .producto-picker{position:relative;display:flex;flex-direction:column;gap:7px;}
  .producto-categoria{padding:8px 12px;font-size:11.5px;}
  .producto-dropdown{display:none;position:absolute;top:calc(100% - 1px);left:0;right:0;background:var(--surface);border:1.5px solid var(--border);border-radius:10px;max-height:220px;overflow-y:auto;z-index:1000;box-shadow:0 6px 20px rgba(180,120,160,.18);}
  .producto-dropdown.open{display:block;}
  .producto-option{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:11.5px;color:var(--navy);}
  .producto-option:last-child{border-bottom:none;}
  .producto-option:hover{background:var(--rose-pale);}
  .producto-option strong{display:block;font-weight:700;}
  .producto-option small{display:block;color:var(--text-dim);font-size:9.5px;margin-top:2px;}
  .producto-option span{white-space:nowrap;font-weight:700;color:var(--rose-deep);}
  .producto-empty{padding:12px;text-align:center;color:var(--text-dim);font-size:11px;}
  .producto-seleccionado{align-items:center;justify-content:space-between;gap:8px;background:var(--rose-pale);border:1.5px solid var(--rose-light);border-radius:10px;padding:8px 10px;font-size:11.5px;font-weight:700;color:var(--navy);}
  .producto-seleccionado b{margin-left:auto;color:var(--rose-deep);}
  .producto-seleccionado button{width:22px;height:22px;border-radius:50%;border:0;background:var(--rose-light);color:var(--rose-deep);cursor:pointer;font-weight:700;}
  .adicionales-lista{display:flex;flex-direction:column;gap:7px;margin-top:8px;}
  .adicional-item{display:flex;align-items:center;gap:7px;background:var(--teal-pale);border:1.5px solid var(--teal-light);border-radius:10px;padding:7px 9px;}
  .adicional-info{flex:1;min-width:0;}
  .adicional-info strong{display:block;font-size:11.5px;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .adicional-info small{display:block;font-size:9px;color:var(--text-dim);margin-top:2px;}
  .adicional-cantidad{width:60px!important;padding:6px 7px!important;text-align:center;}
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
          <div style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--text-dim);font-weight:600;"><div class="cal-dot dot-reserva" style="display:inline-block;"></div> Reserva</div>
        </div>
        <div style="border-top:1.5px dashed var(--border);margin:0 20px;"></div>
        <div style="padding:12px 20px 6px;display:flex;align-items:center;gap:8px;">
          <span style="font-family:'Dancing Script',cursive;font-size:18px;font-weight:700;color:var(--navy);"><?= $diaSeleccionado ?> <?= $mesesNombres[$mesC] ?></span>
          <span style="font-size:11px;color:var(--text-dim);font-weight:600;margin-left:auto;"><?= count($sesionesDelDia) ?> sesión<?= count($sesionesDelDia)!==1?'es':'' ?></span>
        </div>
        <div class="time-slots">
          <?php if(empty($sesionesDelDia)): ?>
            <div class="empty-dia">Sin sesiones este día</div>
          <?php else: $colorIdx=0; foreach($agendaSesionesDelDia as $horaSlot => $sesionesEnHora): ?>
          <div class="time-slot">
            <div class="slot-time"><?= $horaSlot === 'Otras' ? 'Otras' : formatoHora($horaSlot.':00') ?></div>
            <div class="slot-line"></div>
            <div class="slot-content">
              <?php if(empty($sesionesEnHora)): ?>
                <div class="slot-empty"></div>
              <?php else: foreach($sesionesEnHora as $se): $colorCls=$coloresBloque[$colorIdx%3]; $colorIdx++; $esTarget = ($sesionParamId===(int)$se['id']); ?>
              <div class="ses-block <?= $colorCls ?> <?= $esTarget ? 'highlight-sesion' : '' ?>" id="sesion-block-<?= (int)$se['id'] ?>" onclick="editarSesionPorId(<?= (int)$se['id'] ?>)">
                <div style="display:flex; justify-content:space-between; gap:16px;">
                  <div style="flex:1;">
                    <?php if ($esTarget): ?>
                      <div class="ses-tag-highlight" style="margin-bottom:6px;">📍 Seleccionada desde Dashboard</div>
                    <?php endif; ?>
                    <div class="ses-nombre"><?= htmlspecialchars($se['nombre'].' '.$se['apellido']) ?></div>
                    <div class="ses-service"><?= $se['hora_sesion'] ? formatoHora($se['hora_sesion']).' · ' : '' ?><?= htmlspecialchars($se['servicio']??'Por definir') ?></div>
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
                      <button class="btn btn-teal btn-sm" onclick="event.stopPropagation();abrirGaleria(<?= $se['id'] ?>, '<?= htmlspecialchars($se['nombre']) ?>')">📷 Fotos</button>
                      <button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();abrirModalFactura(<?= $se['id'] ?>)">🧾 Factura</button>
                      <button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();editarSesionPorId(<?= $se['id'] ?>)">Editar</button>
                      <button class="btn btn-danger btn-sm" onclick="event.stopPropagation();confirmarEliminar(<?= $se['id'] ?>,'<?= htmlspecialchars($se['nombre']) ?>')">Eliminar</button>
                    </div>
                  </div>
                  <?php if(!empty($se['notas'])): ?>
                  <div style="flex:1; max-width:40%; padding-left:16px; border-left:1px dashed rgba(0,0,0,0.1); display:flex; flex-direction:column; gap:4px; font-size:11.5px; color:var(--text-dim); align-self:stretch;">
                    <div style="font-weight:700; text-transform:uppercase; font-size:9.5px; letter-spacing:0.05em; display:flex; align-items:center; gap:4px; color:var(--navy);">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                      Observaciones
                    </div>
                    <div style="line-height:1.4; overflow-y:auto; max-height:75px; padding-right:4px; font-weight:500;">
                      <?= nl2br(htmlspecialchars($se['notas'])) ?>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
          <?php endforeach; endif; ?>
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
                  <input type="text" id="qClienteSearch" class="form-input cliente-search-input" placeholder="Buscar por nombre o teléfono..." autocomplete="off"
                    oninput="filtrarClientes('qClienteSearch','qClienteDropdown','qClienteIdInput')"
                    onfocus="mostrarDropdown('qClienteDropdown')">
                </div>
                <div class="cliente-dropdown" id="qClienteDropdown">
                  <?php $todosClientes->data_seek(0); while($cl=$todosClientes->fetch_assoc()): ?>
                  <div class="cliente-option"
                    data-id="<?=$cl['id']?>"
                    data-nombre="<?=htmlspecialchars($cl['nombre'])?>" data-telefono="<?=htmlspecialchars($cl['telefono']??'')?>"
                    onclick="seleccionarCliente(this,'qClienteSearch','qClienteDropdown','qClienteIdInput')">
                    <?=htmlspecialchars($cl['nombre'])?>
                  </div>
                  <?php endwhile; ?>
                </div>
                <input type="hidden" name="cliente_id" id="qClienteIdInput" required>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Fecha *</label>
                <input class="form-input" type="date" name="fecha_sesion" value="<?= "$anioC-".str_pad($mesC,2,'0',STR_PAD_LEFT)."-".str_pad($diaSeleccionado,2,'0',STR_PAD_LEFT) ?>" required>
              </div>
              <div class="form-group">
                <label>Hora *</label>
                <select class="form-input" name="hora_sesion" id="qHora" required>
                  <option value="">Seleccione hora...</option>
                  <?php for($h=6;$h<=22;$h++){ foreach(['00','30'] as $m){ $hr=sprintf("%02d:%s",$h,$m); echo "<option value=\"$hr\">".date("h:i A",strtotime($hr))."</option>"; } } ?>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>Tipo de sesión *</label>
              <div class="producto-picker">
                <input type="text" id="qSesionSearch" class="form-input" placeholder="Buscar sesión por nombre..." autocomplete="off" oninput="filtrarProductos('qSesionSearch','qSesionCategoria','qSesionDropdown','sesion')" onfocus="mostrarProductoDropdown('qSesionDropdown','qSesionSearch','sesion')">
                <select id="qSesionCategoria" class="form-input producto-categoria" onchange="filtrarProductos('qSesionSearch','qSesionCategoria','qSesionDropdown','sesion')">
                  <option value="">Todas las categorías</option>
                </select>
                <div class="producto-dropdown" id="qSesionDropdown"></div>
                <input type="hidden" name="producto_principal_id" id="qSesionIdInput">
                <div class="producto-seleccionado" id="qSesionSeleccionado" style="display:none;"></div>
              </div>
            </div>

            <div class="form-group">
              <label>Adicionales</label>
              <div class="producto-picker">
                <input type="text" id="qAdicionalSearch" class="form-input" placeholder="Buscar adicional por nombre..." autocomplete="off" oninput="filtrarProductos('qAdicionalSearch','qAdicionalCategoria','qAdicionalDropdown','adicional')" onfocus="mostrarProductoDropdown('qAdicionalDropdown','qAdicionalSearch','adicional')">
                <select id="qAdicionalCategoria" class="form-input producto-categoria" onchange="filtrarProductos('qAdicionalSearch','qAdicionalCategoria','qAdicionalDropdown','adicional')">
                  <option value="">Todas las categorías</option>
                </select>
                <div class="producto-dropdown" id="qAdicionalDropdown"></div>
              </div>
              <div id="qAdicionalesLista" class="adicionales-lista"></div>
            </div>

            <hr class="divider">
            <div class="form-row" style="align-items:flex-start;">
              <div class="form-group"><label>Total ($)</label><input class="form-input" type="number" name="total" id="qTotal" min="0" step="1000" placeholder="0" readonly></div>
              <div class="form-group"><label>Abono Reserva ($)</label><input class="form-input" type="number" name="abono" id="qAbono" min="0" step="1000" placeholder="0"></div>
              <div class="form-group">
                <label>Saldo Pendiente</label>
                <div id="qSaldo" style="font-size:16px;font-weight:700;color:var(--rose-deep);padding-top:8px;">$0</div>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>Estado pago</label>
                <input class="form-input" id="qEstadoPagoVista" value="Pendiente" readonly>
                <input type="hidden" name="estado_pago" id="qEstadoPago" value="pendiente">
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
            <div class="form-group"><label>Estado de sesión</label>
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
          <a href="?estado=reserva" class="filtro-btn <?= $estadoFiltro==='reserva'?'active':'' ?>">Por definir servicio</a>
          <a href="?estado=pendiente" class="filtro-btn <?= $estadoFiltro==='pendiente'?'active':'' ?>">Pendientes</a>
          <a href="?estado=abonado"   class="filtro-btn <?= $estadoFiltro==='abonado'  ?'active':'' ?>">Abonadas</a>
          <a href="?estado=pagado"    class="filtro-btn <?= $estadoFiltro==='pagado'   ?'active':'' ?>">Pagadas</a>
        </div>
      </div>
      <div style="overflow-x:auto;">
      <table>
        <thead><tr><th>Cliente</th><th>Servicio</th><th>Fecha</th><th>Hora</th><th>Total / Saldo</th><th>Pago</th><th>Estado de sesión</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php if($listadoSesiones->num_rows===0): ?>
            <tr><td colspan="8" class="empty-row">No hay sesiones con ese filtro.</td></tr>
          <?php else: $i=0; while($s=$listadoSesiones->fetch_assoc()):
            $sid = (int)$s['id'];
            $s['adicionales'] = $adicionalesPorSesion[$sid] ?? [];
            $s['servicios_ids'] = array_map(function($a){ return (int)$a['producto_id']; }, $s['adicionales']);
            $s['abonos'] = $abonosPorSesion[$sid] ?? [];
            $cntAb = count($s['abonos']);
          ?>
          <tr id="sesion-row-<?= (int)$s['id'] ?>" class="<?= ($sesionParamId===(int)$s['id']) ? 'highlight-row' : '' ?>">
            <td>
              <div class="client-cell">
                <div class="client-avatar <?= $avClasses[$i%5] ?>"><?= mb_strtoupper(mb_substr($s['nombre'],0,1)) ?></div>
                <div><div class="client-name"><?= htmlspecialchars($s['nombre'].' '.$s['apellido']) ?></div><div class="client-phone"><?= htmlspecialchars($s['telefono']??'') ?></div></div>
              </div>
            </td>
            <td><?= htmlspecialchars($s['servicio']??'Por definir') ?></td>
            <td style="font-size:11px;font-weight:600;"><?= formatoFecha($s['fecha_sesion']) ?></td>
            <td style="font-size:11px;font-weight:600;"><?= $s['hora_sesion'] ? formatoHora($s['hora_sesion']) : '—' ?></td>
            <td>
              <div style="font-family:'Dancing Script',cursive;font-size:15px;color:var(--navy);"><?= formatoPeso($s['total']) ?></div>
              <?php if(($s['saldo']??0)>0): ?><div style="font-size:10px;color:var(--rose-deep);font-weight:700;">Saldo: <?= formatoPeso($s['saldo']) ?></div><?php endif; ?>
              <?php if(($s['abono']??0)>0): ?>
                <div style="font-size:10px;color:var(--teal-deep);font-weight:700;margin-top:2px;cursor:pointer;" onclick="abrirAbonosSesion(<?= (int)$s['id'] ?>)" title="Ver historial de abonos">
                  💰 <?= formatoPeso($s['abono']) ?><?= $cntAb > 1 ? " <span style='font-size:9px;'>($cntAb abonos)</span>" : "" ?>
                </div>
              <?php endif; ?>
            </td>
            <td><span class="status-pill pill-<?= $s['estado_pago'] ?>"><?= ucfirst($s['estado_pago']) ?></span></td>
            <td><span class="status-pill pill-<?= $s['estado_entrega'] ?>"><?= ucfirst(str_replace('_',' ',$s['estado_entrega'])) ?></span></td>
            <td>
              <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <button class="btn btn-teal btn-sm" onclick="abrirGaleria(<?= $s['id'] ?>,'<?= htmlspecialchars($s['nombre'].' '.$s['apellido']) ?>')">📷</button>
                <button class="btn btn-ghost btn-sm" onclick="abrirModalFactura(<?= (int)$s['id'] ?>)">🧾</button>
                <button class="btn btn-ghost btn-sm" onclick="editarSesionPorId(<?= (int)$s['id'] ?>)">Editar</button>
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
                placeholder="Buscar por nombre o teléfono..."
                autocomplete="off"
                oninput="filtrarClientes('mClienteSearch','mClienteDropdown','mClienteIdInput')"
                onfocus="mostrarDropdown('mClienteDropdown')"
              >
            </div>
            <div class="cliente-dropdown" id="mClienteDropdown">
              <?php $todosClientes->data_seek(0); while($cl=$todosClientes->fetch_assoc()): ?>
              <div class="cliente-option" 
                   data-id="<?=$cl['id']?>" 
                   data-nombre="<?=htmlspecialchars($cl['nombre'])?>" data-telefono="<?=htmlspecialchars($cl['telefono']??'')?>"
                   onclick="seleccionarCliente(this,'mClienteSearch','mClienteDropdown','mClienteIdInput')">
                <?=htmlspecialchars($cl['nombre'])?>
              </div>
              <?php endwhile; ?>
            </div>
            <input type="hidden" name="cliente_id" id="mClienteIdInput" required>
          </div>
        </div>

        <!-- Fecha y hora -->
        <div class="form-row">
          <div class="form-group">
            <label>Fecha *</label>
            <input class="form-input" type="date" name="fecha_sesion" id="mFecha" required>
          </div>
          <div class="form-group">
            <label>Hora *</label>
            <select class="form-input" name="hora_sesion" id="mHora" required>
              <option value="">Seleccione hora...</option>
              <?php for($h=6;$h<=22;$h++){ foreach(['00','30'] as $m){ $hr=sprintf("%02d:%s",$h,$m); echo "<option value=\"$hr\">".date("h:i A",strtotime($hr))."</option>"; } } ?>
            </select>
          </div>
        </div>

        <!-- Tipo de sesión y adicionales -->
        <div class="form-group">
          <label>Tipo de sesión *</label>
          <div class="producto-picker">
            <input type="text" id="mSesionSearch" class="form-input" placeholder="Buscar sesión por nombre..." autocomplete="off" oninput="filtrarProductos('mSesionSearch','mSesionCategoria','mSesionDropdown','sesion')" onfocus="mostrarProductoDropdown('mSesionDropdown','mSesionSearch','sesion')">
            <select id="mSesionCategoria" class="form-input producto-categoria" onchange="filtrarProductos('mSesionSearch','mSesionCategoria','mSesionDropdown','sesion')">
              <option value="">Todas las categorías</option>
            </select>
            <div class="producto-dropdown" id="mSesionDropdown"></div>
            <input type="hidden" name="producto_principal_id" id="mSesionIdInput">
            <div class="producto-seleccionado" id="mSesionSeleccionado" style="display:none;"></div>
          </div>
        </div>

        <div class="form-group">
          <label>Adicionales</label>
          <div class="producto-picker">
            <input type="text" id="mAdicionalSearch" class="form-input" placeholder="Buscar adicional por nombre..." autocomplete="off" oninput="filtrarProductos('mAdicionalSearch','mAdicionalCategoria','mAdicionalDropdown','adicional')">
            <select id="mAdicionalCategoria" class="form-input producto-categoria" onchange="filtrarProductos('mAdicionalSearch','mAdicionalCategoria','mAdicionalDropdown','adicional')">
              <option value="">Todas las categorías</option>
            </select>
            <div class="producto-dropdown" id="mAdicionalDropdown"></div>
          </div>
          <div id="mAdicionalesLista" class="adicionales-lista"></div>
        </div>

        <hr class="divider">

        <!-- Total y Abono -->
        <div class="form-row" style="align-items:flex-start;">
          <div class="form-group"><label>Total ($)</label><input class="form-input" type="number" name="total" id="mTotal" min="0" step="1000" placeholder="0" readonly></div>
          <div class="form-group">
            <label>Abono Acumulado ($)</label>
            <input class="form-input" type="number" name="abono" id="mAbono" min="0" step="1000" placeholder="0">
            <small id="mAbonoHint" style="display:none;font-size:9.5px;color:var(--teal-deep);margin-top:2px;">Calculado de los abonos registrados abajo</small>
          </div>
          <div class="form-group">
            <label>Saldo Pendiente</label>
            <div id="mSaldo" style="font-size:16px;font-weight:700;color:var(--rose-deep);padding-top:8px;">$0</div>
          </div>
        </div>

        <!-- SECCIÓN HISTORIAL DE ABONOS ORDENADOS -->
        <div id="mSeccionAbonos" style="display:none;background:var(--surface2);border:1.5px solid var(--border);border-radius:12px;padding:14px;margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <div style="font-weight:700;font-size:12.5px;color:var(--navy);display:flex;align-items:center;gap:6px;">
              <span>💰 Historial de Abonos</span>
              <span id="mAbonosContador" style="background:var(--rose-pale);color:var(--rose-deep);font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:12px;">0 abonos</span>
            </div>
            <button type="button" class="btn btn-teal btn-sm" id="btnToggleNuevoAbono" onclick="toggleFormNuevoAbono()">
              + Registrar Abono
            </button>
          </div>

          <!-- Formulario rápido inline para registrar un nuevo abono -->
          <div id="formNuevoAbonoWrapper" style="display:none;background:#fff;border:1.5px solid var(--teal-light);border-radius:10px;padding:12px;margin-bottom:12px;box-shadow:var(--shadow-sm);">
            <div style="font-weight:700;font-size:11.5px;color:var(--teal-deep);margin-bottom:8px;display:flex;align-items:center;gap:6px;">
              <span>➕ Registrar Nuevo Abono a la Sesión</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px;">
              <div>
                <label style="font-size:10px;font-weight:700;color:var(--navy);">Monto ($) *</label>
                <input type="number" id="newAbonoMonto" class="form-input" min="1000" step="1000" placeholder="Ej: 50000" style="padding:6px 10px;font-size:12px;font-weight:700;">
              </div>
              <div>
                <label style="font-size:10px;font-weight:700;color:var(--navy);">Método de Pago *</label>
                <select id="newAbonoMetodo" class="form-input" style="padding:6px 10px;font-size:12px;">
                  <option value="efectivo">Efectivo</option>
                  <option value="transferencia">Transferencia</option>
                  <option value="nequi">Nequi</option>
                  <option value="daviplata">Daviplata</option>
                  <option value="tarjeta">Tarjeta</option>
                </select>
              </div>
              <div>
                <label style="font-size:10px;font-weight:700;color:var(--navy);">Fecha *</label>
                <input type="date" id="newAbonoFecha" class="form-input" value="<?= date('Y-m-d') ?>" style="padding:6px 10px;font-size:12px;">
              </div>
            </div>
            <div style="margin-bottom:8px;">
              <label style="font-size:10px;font-weight:700;color:var(--navy);">Notas / Referencia (Opcional)</label>
              <input type="text" id="newAbonoNotas" class="form-input" placeholder="Ej: Comprobante Nequi #9876, Segundo anticipo..." style="padding:6px 10px;font-size:12px;">
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
              <button type="button" class="btn btn-ghost btn-sm" onclick="toggleFormNuevoAbono(false)">Cancelar</button>
              <button type="button" class="btn btn-teal btn-sm" onclick="guardarNuevoAbonoModal()">💾 Guardar Abono</button>
            </div>
          </div>

          <!-- Contenedor dinámico de abonos ordenados -->
          <div id="mListaAbonos" style="display:flex;flex-direction:column;gap:6px;"></div>
        </div>

        <!-- Estado pago y Método -->
        <div class="form-row">
          <div class="form-group"><label>Estado pago</label>
            <input class="form-input" id="mEstadoPagoVista" value="Pendiente" readonly>
            <input type="hidden" name="estado_pago" id="mEstadoPago" value="pendiente">
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
        <div class="form-group"><label>Estado de sesión</label>
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
  <div class="modal" style="max-width:680px;">
    <div class="modal-header">
      <div class="modal-title" id="galeriaTitulo">Fotos de la Sesión</div>
      <button class="modal-close" onclick="cerrarGaleria()">✕</button>
    </div>

    <!-- PESTAÑAS DE CARPETAS / CATEGORÍAS -->
    <div class="galeria-tabs" style="display:flex;gap:6px;padding:12px 18px 8px;border-bottom:1px solid var(--border);overflow-x:auto;background:var(--surface2);">
      <button type="button" class="galeria-tab active" data-cat="todas" onclick="filtrarGaleriaTab('todas')">📁 Todas <span class="tab-count" id="countTodas">0</span></button>
      <button type="button" class="galeria-tab" data-cat="sesion" onclick="filtrarGaleriaTab('sesion')">📸 Sesión <span class="tab-count" id="countSesion">0</span></button>
      <button type="button" class="galeria-tab" data-cat="comprobante" onclick="filtrarGaleriaTab('comprobante')">🧾 Comprobantes <span class="tab-count" id="countComprobante">0</span></button>
      <button type="button" class="galeria-tab" data-cat="referencia" onclick="filtrarGaleriaTab('referencia')">💡 Referencias <span class="tab-count" id="countReferencia">0</span></button>
      <button type="button" class="galeria-tab" data-cat="otro" onclick="filtrarGaleriaTab('otro')">🗂️ Otros <span class="tab-count" id="countOtro">0</span></button>
    </div>

    <div id="galeriaContenido" style="min-height:200px;max-height:48vh;overflow-y:auto;">
      <div style="text-align:center;padding:40px;color:var(--text-dim);">Cargando fotos y carpetas...</div>
    </div>

    <!-- Subir fotos a carpetas -->
    <div style="padding:14px 18px 16px;background:var(--surface2);border-top:1px solid var(--border);">
      <form method="POST" action="" enctype="multipart/form-data" id="formFotos">
        <input type="hidden" name="accion" value="subir_fotos">
        <input type="hidden" name="sesion_id" id="galeriaId">

        <div style="font-weight:700;font-size:12px;color:var(--navy);margin-bottom:10px;display:flex;align-items:center;gap:6px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Subir Fotos a una Carpeta / Grupo
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
          <div>
            <label style="font-size:10px;font-weight:700;color:var(--navy);">Carpeta / Categoría *</label>
            <select class="form-input" name="categoria" id="formFotoCategoria" onchange="actualizarPlaceholderGrupo()" style="padding:7px 10px;font-size:12px;">
              <option value="sesion">📸 Fotos de la Sesión</option>
              <option value="comprobante">🧾 Comprobantes y Facturas</option>
              <option value="referencia">💡 Fotos de Referencia</option>
              <option value="otro">🗂️ Otra Carpeta</option>
            </select>
          </div>
          <div>
            <label style="font-size:10px;font-weight:700;color:var(--navy);">Nombre del Grupo / Carpeta</label>
            <input class="form-input" type="text" name="grupo_desc" id="formFotoGrupoDesc" placeholder="Ej: Fotos en exteriores, Abono #2 Nequi..." style="padding:7px 10px;font-size:12px;">
          </div>
        </div>

        <div class="upload-zone" onclick="document.getElementById('inputFotos').click()" style="margin:0 0 10px 0;padding:16px;">
          <input type="file" name="fotos[]" id="inputFotos" multiple accept="image/*" onchange="previsualizarFotos(this)">
          <div id="uploadText">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--rose)" stroke-width="1.5" style="margin-bottom:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <div style="font-size:11.5px;color:var(--navy);font-weight:700;">Haz clic para seleccionar fotos</div>
            <div style="font-size:10px;color:var(--text-dim);margin-top:2px;">JPG, PNG, WEBP · Puedes subir varias fotos a la vez</div>
          </div>
          <div id="previewFotos" style="display:none;display:flex;flex-wrap:wrap;gap:6px;justify-content:center;"></div>
        </div>

        <div style="margin-bottom:10px;">
          <label style="font-size:10px;font-weight:700;color:var(--navy);">Descripción individual de las fotos (opcional)</label>
          <input class="form-input" type="text" name="descripcion" placeholder="Ej: Comprobante abono por Nequi o foto editada final" style="padding:7px 10px;font-size:12px;">
        </div>

        <button type="submit" class="btn btn-teal" style="width:100%;justify-content:center;padding:9px 16px;">📤 Subir Fotos a esta Carpeta</button>
      </form>
    </div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="this.classList.remove('open')">
  <span class="lightbox-close">✕</span>
  <img src="" id="lightboxImg" alt="">
</div>

<!-- FORMS DE ACCIONES DIRECTAS -->
<form method="POST" id="formEliminar" style="display:none;">
  <input type="hidden" name="accion" value="eliminar">
  <input type="hidden" name="id" id="eliminarId">
  <input type="hidden" name="modo_eliminacion" id="eliminarModo" value="total">
</form>
<form method="POST" id="formElimFoto" style="display:none;">
  <input type="hidden" name="accion" value="eliminar_foto">
  <input type="hidden" name="foto_id" id="elimFotoId">
</form>
<form method="POST" id="formAbonoDirecto" style="display:none;">
  <input type="hidden" name="accion" value="registrar_abono_sesion">
  <input type="hidden" name="sesion_id" id="abonoDirSesionId">
  <input type="hidden" name="monto" id="abonoDirMonto">
  <input type="hidden" name="metodo_pago" id="abonoDirMetodo">
  <input type="hidden" name="fecha" id="abonoDirFecha">
  <input type="hidden" name="notas" id="abonoDirNotas">
</form>
<form method="POST" id="formEliminarAbonoDirecto" style="display:none;">
  <input type="hidden" name="accion" value="eliminar_abono_sesion">
  <input type="hidden" name="abono_id" id="elimAbonoId">
  <input type="hidden" name="sesion_id" id="elimAbonoSesionId">
</form>

<!-- MODAL CONFIRMAR ELIMINAR INTELIGENTE -->
<div class="modal-overlay" id="modalEliminarSesion">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header" style="background:#fff2f5;border-bottom:1px solid #f9d8e2;">
      <span class="modal-title" style="color:var(--rose-deep);display:flex;align-items:center;gap:8px;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
        Eliminar Sesión
      </span>
      <button type="button" class="modal-close" onclick="cerrarModalEliminar()">✕</button>
    </div>
    <div class="modal-body" style="padding:22px 24px;display:flex;flex-direction:column;gap:16px;">
      
      <!-- Resumen de la sesión -->
      <div style="background:var(--surface2);border:1.5px solid var(--border);border-radius:12px;padding:14px 16px;">
        <div style="font-weight:700;font-size:14px;color:var(--navy);margin-bottom:4px;" id="delClienteNombre">—</div>
        <div style="font-size:12px;color:var(--text-mid);display:flex;gap:14px;flex-wrap:wrap;">
          <span>📅 <strong id="delFechaSesion">—</strong></span>
          <span>📸 <span id="delServicio">—</span></span>
        </div>
      </div>

      <!-- Alerta contable si hay factura o abono -->
      <div id="delBloqueContable" style="display:none;background:#fff8ec;border:1.5px solid #f7dfb3;border-radius:12px;padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#a06414;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          Registros Contables Vinculados
        </div>
        <div style="font-size:12px;color:#5a3f12;line-height:1.5;margin-bottom:12px;" id="delDetalleContable"></div>

        <div style="font-size:11.5px;font-weight:700;color:var(--navy);margin-bottom:8px;">¿Qué deseas hacer con la factura y el dinero de caja?</div>

        <div style="display:flex;flex-direction:column;gap:8px;">
          <!-- Opción A -->
          <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;background:#fff;cursor:pointer;">
            <input type="radio" name="opt_modo_del" value="total" checked style="margin-top:3px;">
            <div>
              <div style="font-weight:700;font-size:12px;color:var(--navy);">Opción A: Limpieza Total (Borrar Sesión + Factura + Ingresos)</div>
              <div style="font-size:11px;color:var(--text-dim);line-height:1.35;margin-top:2px;">Recomendado si fue una sesión creada por error o de prueba. Retira el dinero de caja para que el balance contable no quede inflado.</div>
            </div>
          </label>
          <!-- Opción B -->
          <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;background:#fff;cursor:pointer;">
            <input type="radio" name="opt_modo_del" value="anular_factura" style="margin-top:3px;">
            <div>
              <div style="font-weight:700;font-size:12px;color:var(--navy);">Opción B: Anulación Contable (Marcar Factura 'Cancelada' y Descontar Ingresos)</div>
              <div style="font-size:11px;color:var(--text-dim);line-height:1.35;margin-top:2px;">Recomendado si la clienta canceló la cita. La factura queda archivada como cancelada y los abonos se descuentan de caja.</div>
            </div>
          </label>
        </div>
      </div>

      <!-- Alerta simple si NO tiene dinero ni factura -->
      <div id="delBloqueSimple" style="font-size:13px;color:var(--text-mid);line-height:1.5;">
        ¿Estás seguro de que deseas eliminar esta sesión? Esta acción no se puede deshacer y se borrarán las fotos asociadas.
      </div>

    </div>
    <div class="modal-footer" style="background:var(--surface2);display:flex;justify-content:flex-end;gap:10px;">
      <button type="button" class="btn btn-ghost" onclick="cerrarModalEliminar()">Cancelar</button>
      <button type="button" class="btn btn-danger" onclick="ejecutarEliminacionConfirmada()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        Sí, Eliminar Sesión
      </button>
    </div>
  </div>
</div>

<!-- MODAL FACTURA (muestra el PDF original del módulo Facturas) -->
<style>
.fac-modal{max-width:880px;width:95vw;display:flex;flex-direction:column;overflow:hidden;}
.fac-meta{display:flex;align-items:center;gap:8px;margin-left:12px;flex-wrap:wrap;}
.fac-num{font-size:12px;font-weight:700;color:var(--navy-mid);background:var(--navy-soft);border-radius:20px;padding:3px 10px;}
.fac-visor{position:relative;background:#eceaf0;height:68vh;}
.fac-visor iframe{width:100%;height:100%;border:0;display:block;background:#eceaf0;}
.fac-cargando{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:10px;color:var(--text-mid);font-size:13px;pointer-events:none;}
.fac-spinner{width:30px;height:30px;border-radius:50%;border:3px solid var(--rose-pale);border-top-color:var(--rose);animation:facGiro .8s linear infinite;}
@keyframes facGiro{to{transform:rotate(360deg)}}
.fac-vacia{height:auto;padding:46px 24px;text-align:center;color:var(--text-mid);font-size:14px;line-height:1.6;background:var(--surface);}
.fac-vacia .fac-ico{font-size:40px;margin-bottom:8px;}
.btn-wa{background:linear-gradient(135deg,#25d366,#128c7e);color:#fff;box-shadow:0 4px 14px rgba(18,140,126,.28);}
.btn-wa:hover{transform:translateY(-1px);}
.fac-aviso{display:none;margin-right:auto;align-self:center;font-size:12px;color:var(--teal-deep);background:var(--teal-pale);border:1.5px solid var(--teal-light);border-radius:10px;padding:6px 10px;line-height:1.4;}
.fac-aviso.show{display:block;animation:fadeUp .3s ease both;}
.fac-aviso.warn{color:#9a5b12;background:#fef3e2;border-color:#f3d19e;}
@media(max-width:640px){.fac-visor{height:60vh}.fac-aviso{margin-right:0;width:100%}.modal-footer{flex-wrap:wrap}}
</style>
<div class="modal-overlay" id="modalFactura" style="z-index:115;" onclick="if(event.target===this)cerrarModalFactura()">
  <div class="modal fac-modal">
    <div class="modal-header">
      <span class="modal-title" style="flex:0 0 auto;">Factura de la sesión</span>
      <div class="fac-meta">
        <span class="fac-num" id="facNumero"></span>
        <span class="status-pill" id="facEstado"></span>
      </div>
      <span style="flex:1;"></span>
      <button type="button" class="modal-close" onclick="cerrarModalFactura()">✕</button>
    </div>

    <div class="fac-visor" id="facVisor">
      <div class="fac-cargando" id="facCargando"><div class="fac-spinner"></div>Cargando factura…</div>
      <iframe id="facIframe" title="Factura PDF"></iframe>
    </div>

    <div class="fac-visor fac-vacia" id="facVacia" style="display:none;">
      <div class="fac-ico">🧾</div>
      <strong>Esta sesión no tiene una factura vinculada.</strong><br>
      Puedes crearla desde el módulo <a href="facturas.php" style="color:var(--rose-deep);font-weight:700;">Facturas</a> seleccionando esta sesión.
    </div>

    <div class="modal-footer" id="facAcciones">
      <div class="fac-aviso" id="facAviso"></div>
      <a class="btn btn-ghost" id="facDescargar" href="#">⬇ Descargar PDF</a>
      <button type="button" class="btn btn-wa" id="btnEnviarWhatsApp">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="margin-right:6px;"><path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.7.63.71.23 1.36.2 1.87.12.57-.08 1.75-.72 2-1.41.25-.69.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35M12.05 21.5h-.01a9.4 9.4 0 0 1-4.8-1.32l-.34-.2-3.57.94.95-3.48-.22-.36a9.4 9.4 0 0 1-1.44-5.02c0-5.2 4.24-9.43 9.44-9.43 2.52 0 4.89.98 6.67 2.77a9.37 9.37 0 0 1 2.76 6.67c0 5.2-4.23 9.43-9.44 9.43m8.03-17.46A11.27 11.27 0 0 0 12.05.72C5.8.72.7 5.8.7 12.07c0 2 .52 3.95 1.52 5.67L.6 23.62l6.02-1.58a11.33 11.33 0 0 0 5.42 1.38h.01c6.26 0 11.35-5.09 11.35-11.35 0-3.03-1.18-5.88-3.32-8.03"/></svg>
        Enviar por WhatsApp
      </button>
    </div>
  </div>
</div>

<script>
const productosData = <?php
  $todosProductos->data_seek(0); $prods=[];
  while($pr=$todosProductos->fetch_assoc()) $prods[]=$pr;
  echo json_encode($prods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
?>;
const sesionesData = <?php echo json_encode($sesionesParaJS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]'; ?>;
const UPLOAD_URL = '<?= UPLOAD_URL ?>';

// ── Factura (PDF original) + WhatsApp ──
function formatoFechaCorta(f){
  const p = String(f||'').split('-');
  return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : String(f||'');
}
function telefonoWhatsApp(tel){
  // Solo dígitos; si es un celular colombiano de 10 dígitos se antepone el indicativo 57.
  let t = String(tel || '').replace(/[^0-9]/g, '');
  if (t.length === 10 && t.charAt(0) === '3') t = '57' + t;
  return t;
}
function abrirModalFactura(id) {
  const s = sesionesData.find(x => Number(x.id) === Number(id));
  if(!s) return;

  const visor    = document.getElementById('facVisor');
  const vacia    = document.getElementById('facVacia');
  const acciones = document.getElementById('facAcciones');
  const iframe   = document.getElementById('facIframe');
  const cargando = document.getElementById('facCargando');
  const numero   = document.getElementById('facNumero');
  const pill     = document.getElementById('facEstado');
  document.getElementById('facAviso').classList.remove('show');

  // Sesión sin factura vinculada
  if (!s.factura_id) {
    numero.style.display = 'none';
    pill.style.display = 'none';
    visor.style.display = 'none';
    acciones.style.display = 'none';
    vacia.style.display = 'block';
    iframe.src = 'about:blank';
    document.getElementById('modalFactura').classList.add('open');
    return;
  }

  const estadoFac = String(s.factura_estado || s.estado_pago || 'pendiente');
  numero.textContent = s.factura_numero || '';
  numero.style.display = s.factura_numero ? '' : 'none';
  pill.className = 'status-pill pill-' + estadoFac;
  pill.textContent = estadoFac.charAt(0).toUpperCase() + estadoFac.slice(1);
  pill.style.display = '';
  vacia.style.display = 'none';
  visor.style.display = '';
  acciones.style.display = '';

  // Mismo PDF que genera el módulo Facturas (factura_pdf.php)
  const urlPdf = 'factura_pdf.php?id=' + encodeURIComponent(s.factura_id);
  const nombreArchivo = 'Factura_' + String(s.factura_numero || s.factura_id).replace(/[^A-Za-z0-9\-]/g, '_') + '.pdf';
  cargando.style.display = 'flex';
  iframe.onload = function(){ cargando.style.display = 'none'; };
  iframe.src = urlPdf + '&ver=1';

  const btnDesc = document.getElementById('facDescargar');
  btnDesc.href = urlPdf;
  btnDesc.setAttribute('download', nombreArchivo);

  // Mensaje para WhatsApp con los datos de la factura
  const nombre  = (String(s.nombre || '') + ' ' + String(s.apellido || '')).trim();
  const total   = Number(s.factura_total ?? s.total ?? 0);
  const abono   = Number(s.factura_abono ?? s.abono ?? 0);
  const saldo   = Math.max(0, total - abono);
  const lineas  = [
    'Hola ' + (s.nombre || '') + ', te saludamos de *Liz Fotografía* 📸',
    '',
    'Te compartimos la factura *' + (s.factura_numero || '') + '* de tu sesión' + (s.fecha_sesion ? ' del ' + formatoFechaCorta(s.fecha_sesion) : '') + '.',
    '',
    '*Servicio:* ' + (s.servicio || 'Por definir'),
    '*Total:* ' + formatoPrecio(total),
    '*Abono:* ' + formatoPrecio(abono),
    '*Saldo pendiente:* ' + formatoPrecio(saldo),
    '*Estado:* ' + pill.textContent,
    '',
    'Adjuntamos el PDF de la factura. ¡Gracias por tu preferencia! 💖'
  ];
  const mensaje = lineas.join('\n');

  // ── Opción B: compartir el PDF con el panel "Compartir" del sistema (Web Share API) ──
  // Se descarga el PDF en memoria apenas se abre el panel, para que al hacer clic se comparta al instante
  // (los navegadores exigen que la acción de compartir ocurra justo después del clic).
  const aviso = document.getElementById('facAviso');
  const btnWA = document.getElementById('btnEnviarWhatsApp');
  const soportaCompartir = window.isSecureContext && typeof navigator.share === 'function' && typeof navigator.canShare === 'function';
  let archivoPdf = null;
  const promesaPdf = soportaCompartir
    ? fetch(urlPdf, { credentials: 'same-origin' })
        .then(r => {
          const tipo = r.headers.get('Content-Type') || '';
          if (!r.ok || tipo.indexOf('pdf') === -1) throw new Error('No se pudo obtener el PDF');
          return r.blob();
        })
        .then(b => (archivoPdf = new File([b], nombreArchivo, { type: 'application/pdf' })))
        .catch(() => null)
    : Promise.resolve(null);

  btnWA.onclick = async function() {
    if (!soportaCompartir) {
      mostrarAvisoFactura('warn', '⚠️ Este navegador o esta dirección no permiten compartir archivos. Funciona en Chrome/Edge entrando por <strong>localhost</strong> o <strong>https://</strong>. Usa ⬇ Descargar PDF.');
      return;
    }
    const archivo = archivoPdf || await promesaPdf;
    if (!archivo) {
      mostrarAvisoFactura('warn', '⚠️ No se pudo preparar el PDF. Usa ⬇ Descargar PDF.');
      return;
    }
    if (!navigator.canShare({ files: [archivo] })) {
      mostrarAvisoFactura('warn', '⚠️ Este equipo no permite compartir archivos PDF. Usa ⬇ Descargar PDF.');
      return;
    }
    // Copiar automáticamente el número del cliente al portapapeles para pegarlo en el buscador de WhatsApp
    const telCliente = String(s.telefono || '').trim();
    if (telCliente) {
      try { await navigator.clipboard.writeText(telCliente); } catch (e) {}
    }
    try {
      await navigator.share({ files: [archivo], title: 'Factura ' + (s.factura_numero || ''), text: mensaje });
      mostrarAvisoFactura('ok', '✅ Factura compartida. Número (' + escapeHtml(telCliente || 'cliente') + ') copiado: solo pégalo en el buscador de WhatsApp con <strong>Ctrl + V</strong>.');
    } catch (e) {
      if (e && e.name === 'AbortError') return; // la persona cerró el panel de compartir
      mostrarAvisoFactura('warn', '⚠️ No se pudo abrir el panel de compartir (' + escapeHtml(e && e.message ? e.message : 'error') + '). Usa ⬇ Descargar PDF.');
    }
  };

  document.getElementById('modalFactura').classList.add('open');
}
function cerrarModalFactura() {
  document.getElementById('modalFactura').classList.remove('open');
  document.getElementById('facIframe').src = 'about:blank';
}
function mostrarAvisoFactura(tipo, html) {
  const aviso = document.getElementById('facAviso');
  aviso.className = 'fac-aviso show' + (tipo === 'warn' ? ' warn' : '');
  aviso.innerHTML = html;
}

// ── Buscador de clientes ──
function filtrarClientes(inputId, dropdownId, hiddenId){
  const q=document.getElementById(inputId).value.toLowerCase().trim();
  const dropdown=document.getElementById(dropdownId);
  const options=dropdown.querySelectorAll('.cliente-option');
  let hay=false;
  options.forEach(op=>{
    const nombre=(op.dataset.nombre||'').toLowerCase();
    const telefono=(op.dataset.telefono||'').toLowerCase();
    const ok=nombre.includes(q)||telefono.includes(q);
    op.classList.toggle('hidden', !ok);
    if(ok) hay=true;
  });
  dropdown.classList.toggle('open', hay||q==='');
  if(q==='') document.getElementById(hiddenId).value='';
}
function mostrarDropdown(dropdownId){ document.getElementById(dropdownId).classList.add('open'); }
function seleccionarCliente(el,inputId,dropdownId,hiddenId){
  document.getElementById(inputId).value=el.dataset.nombre;
  document.getElementById(hiddenId).value=el.dataset.id;
  document.getElementById(dropdownId).classList.remove('open');
}
document.addEventListener('click',function(e){
  document.querySelectorAll('.cliente-search-wrapper').forEach(wrapper=>{
    if(!wrapper.contains(e.target)) wrapper.querySelector('.cliente-dropdown')?.classList.remove('open');
  });
  document.querySelectorAll('.producto-picker').forEach(wrapper=>{
    if(!wrapper.contains(e.target)) wrapper.querySelector('.producto-dropdown')?.classList.remove('open');
  });
});

// ── Productos: sesiones y adicionales ──
function tipoProductoEsSesion(p){ return ['sesion','combo'].includes(String(p.tipo||'').toLowerCase()); }
function tipoProductoEsAdicional(p){ return ['adicional','producto'].includes(String(p.tipo||'').toLowerCase()); }
function formatoPrecio(v){ return '$'+Number(v||0).toLocaleString('es-CO'); }
function categoriasPorTipo(tipo){
  const set=new Set();
  productosData.forEach(p=>{
    const ok=tipo==='sesion' ? tipoProductoEsSesion(p) : tipoProductoEsAdicional(p);
    if(ok && p.categoria) set.add(p.categoria);
  });
  return Array.from(set).sort((a,b)=>a.localeCompare(b,'es'));
}
function cargarCategorias(selectId,tipo){
  const sel=document.getElementById(selectId); if(!sel) return;
  const actual=sel.value;
  sel.innerHTML='<option value="">Todas las categorías</option>';
  categoriasPorTipo(tipo).forEach(cat=>{
    const o=document.createElement('option'); o.value=cat; o.textContent=cat; sel.appendChild(o);
  });
  if(categoriasPorTipo(tipo).includes(actual)) sel.value=actual;
}
function inicializarCategorias(){
  cargarCategorias('qSesionCategoria','sesion');
  cargarCategorias('qAdicionalCategoria','adicional');
  cargarCategorias('mSesionCategoria','sesion');
  cargarCategorias('mAdicionalCategoria','adicional');
}
function productosFiltrados(inputId,categoriaId,tipo){
  const q=(document.getElementById(inputId)?.value||'').toLowerCase().trim();
  const cat=document.getElementById(categoriaId)?.value||'';
  return productosData.filter(p=>{
    const esTipo=tipo==='sesion' ? tipoProductoEsSesion(p) : tipoProductoEsAdicional(p);
    const nombre=String(p.nombre||'').toLowerCase();
    const categoria=String(p.categoria||'');
    return esTipo && (!q || nombre.includes(q)) && (!cat || categoria===cat);
  });
}
function mostrarProductoDropdown(dropdownId,inputId,tipo){
  filtrarProductos(inputId, inputId.replace('Search','Categoria'), dropdownId, tipo);
}
function filtrarProductos(inputId,categoriaId,dropdownId,tipo){
  const dd=document.getElementById(dropdownId); if(!dd) return;
  const items=productosFiltrados(inputId,categoriaId,tipo);
  if(!items.length){ dd.innerHTML='<div class="producto-empty">No hay resultados.</div>'; dd.classList.add('open'); return; }
  dd.innerHTML=items.map(p=>`<div class="producto-option" onclick="seleccionarProducto('${tipo}','${p.id}')"><div><strong>${escapeHtml(p.nombre)}</strong><small>${escapeHtml(p.categoria||'Sin categoría')}</small></div><span>${formatoPrecio(p.precio)}</span></div>`).join('');
  dd.classList.add('open');
}
function escapeHtml(value){ return String(value??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','\'':'&#39;','"':'&quot;'}[c])); }
function buscarProducto(id){ return productosData.find(p=>Number(p.id)===Number(id)); }
function seleccionarProducto(tipo,id){
  const p=buscarProducto(id); if(!p) return;
  if(tipo==='sesion'){
    const prefix=document.getElementById('mSesionSearch') ? (document.getElementById('mSesionSearch').closest('.modal-overlay')?.classList.contains('open')?'m':'q') : 'q';
    const inputId=prefix==='m'?'mSesionSearch':'qSesionSearch';
    const hiddenId=prefix==='m'?'mSesionIdInput':'qSesionIdInput';
    const ddId=prefix==='m'?'mSesionDropdown':'qSesionDropdown';
    const selectedId=prefix==='m'?'mSesionSeleccionado':'qSesionSeleccionado';
    document.getElementById(inputId).value=p.nombre;
    document.getElementById(hiddenId).value=p.id;
    document.getElementById(selectedId).style.display='flex';
    document.getElementById(selectedId).innerHTML=`<span>${escapeHtml(p.nombre)}</span><b>${formatoPrecio(p.precio)}</b><button type="button" onclick="event.stopPropagation();limpiarSesion('${prefix}')">×</button>`;
    document.getElementById(ddId).classList.remove('open');
    if(prefix==='m') autoTotalModal(); else autoTotal();
  }else{
    const prefix=document.getElementById('mAdicionalSearch') && document.getElementById('mAdicionalSearch').closest('.modal-overlay')?.classList.contains('open')?'m':'q';
    agregarAdicional(prefix,p.id);
    document.getElementById(prefix==='m'?'mAdicionalDropdown':'qAdicionalDropdown').classList.remove('open');
    document.getElementById(prefix==='m'?'mAdicionalSearch':'qAdicionalSearch').value='';
  }
}
function limpiarSesion(prefix){
  const input=prefix==='m'?'mSesionSearch':'qSesionSearch';
  const hidden=prefix==='m'?'mSesionIdInput':'qSesionIdInput';
  const selected=prefix==='m'?'mSesionSeleccionado':'qSesionSeleccionado';
  document.getElementById(input).value=''; document.getElementById(hidden).value=''; document.getElementById(selected).style.display='none';
  prefix==='m'?autoTotalModal():autoTotal();
}

function agregarAdicional(prefix,id,cantidad=1){
  const lista=document.getElementById(prefix==='m'?'mAdicionalesLista':'qAdicionalesLista');
  const existing=lista.querySelector(`.adicional-item[data-id="${id}"]`);
  if(existing){ const qty=existing.querySelector('.adicional-cantidad'); qty.value=parseInt(qty.value||1)+cantidad; prefix==='m'?autoTotalModal():autoTotal(); return; }
  const p=buscarProducto(id); if(!p) return;
  const item=document.createElement('div');
  item.className='adicional-item'; item.dataset.id=id;
  item.innerHTML=`<input type="hidden" name="adicional_id[]" value="${p.id}"><div class="adicional-info"><strong>${escapeHtml(p.nombre)}</strong><small>${escapeHtml(p.categoria||'Sin categoría')} · ${formatoPrecio(p.precio)}</small></div><input class="form-input adicional-cantidad" type="number" name="adicional_cantidad[]" min="1" step="1" value="${cantidad}" onchange="${prefix==='m'?'autoTotalModal()':'autoTotal()'}"><button type="button" class="btn-remove-serv" onclick="this.closest('.adicional-item').remove();${prefix==='m'?'autoTotalModal()':'autoTotal()'}">✕</button>`;
  lista.appendChild(item);
  prefix==='m'?autoTotalModal():autoTotal();
}
function limpiarAdicionales(prefix){ document.getElementById(prefix==='m'?'mAdicionalesLista':'qAdicionalesLista').innerHTML=''; }

// ── Modal editar: se conserva y ahora recibe ID desde calendario/listado ──
function cerrarModal(){ document.getElementById('modalSesion').classList.remove('open'); }
function resetModalProductos(){
  document.getElementById('mSesionSearch').value='';
  document.getElementById('mSesionIdInput').value='';
  document.getElementById('mSesionSeleccionado').style.display='none';
  document.getElementById('mAdicionalesLista').innerHTML='';
}
function editarSesion(s){
  document.getElementById('mTitulo').textContent='Editar Sesión';
  document.getElementById('mId').value=s.id;
  document.getElementById('mClienteSearch').value=(s.nombre||'');
  document.getElementById('mClienteIdInput').value=s.cliente_id;
  document.getElementById('mFecha').value=s.fecha_sesion;
  document.getElementById('mHora').value=s.hora_sesion ? s.hora_sesion.substring(0,5) : '';
  document.getElementById('mAbono').value=s.abono;
  document.getElementById('mEstadoEntrega').value=s.estado_entrega||'pendiente';
  document.getElementById('mMetodoPago').value=s.metodo_pago||'efectivo';
  document.getElementById('mNotas').value=s.notas||'';
  resetModalProductos();

  const principal=buscarProducto(s.producto_id);
  if(principal){
    document.getElementById('mSesionSearch').value=principal.nombre;
    document.getElementById('mSesionIdInput').value=principal.id;
    document.getElementById('mSesionSeleccionado').style.display='flex';
    document.getElementById('mSesionSeleccionado').innerHTML=`<span>${escapeHtml(principal.nombre)}</span><b>${formatoPrecio(principal.precio)}</b><button type="button" onclick="event.stopPropagation();limpiarSesion('m')">×</button>`;
  }
  (s.adicionales||[]).forEach(a=>agregarAdicional('m',a.producto_id,a.cantidad||1));
  autoTotalModal();
  actualizarEstadoPago('m');
  renderizarAbonosModal(s);
  document.getElementById('modalSesion').classList.add('open');
}
function editarSesionPorId(id){
  const s=sesionesData.find(x=>Number(x.id)===Number(id));
  if(s) editarSesion(s);
}
function confirmarEliminar(id,nombre){
  const s = sesionesData.find(x => Number(x.id) === Number(id));
  document.getElementById('eliminarId').value = id;

  const nombreCompleto = s ? ((s.nombre||'') + ' ' + (s.apellido||'')).trim() : nombre;
  document.getElementById('delClienteNombre').textContent = nombreCompleto || 'Cliente';
  document.getElementById('delFechaSesion').textContent = (s && s.fecha_sesion) ? formatoFechaCorta(s.fecha_sesion) : '—';
  document.getElementById('delServicio').textContent = (s && s.servicio) ? s.servicio : 'Sesión';

  const tieneFactura = s && s.factura_id;
  const abonoMonto = s ? Number(s.factura_abono ?? s.abono ?? 0) : 0;
  const totalMonto = s ? Number(s.factura_total ?? s.total ?? 0) : 0;

  const bloqueContable = document.getElementById('delBloqueContable');
  const bloqueSimple = document.getElementById('delBloqueSimple');
  const detalleContable = document.getElementById('delDetalleContable');

  if (tieneFactura || abonoMonto > 0) {
    bloqueContable.style.display = 'block';
    bloqueSimple.style.display = 'none';

    let txt = '';
    if (tieneFactura) {
      txt += '• <strong>Factura:</strong> ' + escapeHtml(s.factura_numero || 'Generada') + ' (Total: ' + formatoPrecio(totalMonto) + ')<br>';
    }
    if (abonoMonto > 0) {
      txt += '• <strong>Abonos registrados en caja:</strong> ' + formatoPrecio(abonoMonto);
    } else {
      txt += '• <em>Sin abonos registrados en caja.</em>';
    }
    detalleContable.innerHTML = txt;
  } else {
    bloqueContable.style.display = 'none';
    bloqueSimple.style.display = 'block';
  }

  // Marcar por defecto 'total'
  const radioTotal = document.querySelector('input[name="opt_modo_del"][value="total"]');
  if (radioTotal) radioTotal.checked = true;

  document.getElementById('modalEliminarSesion').classList.add('open');
}

function cerrarModalEliminar(){
  document.getElementById('modalEliminarSesion').classList.remove('open');
}

function ejecutarEliminacionConfirmada(){
  const modoSeleccionado = document.querySelector('input[name="opt_modo_del"]:checked');
  document.getElementById('eliminarModo').value = modoSeleccionado ? modoSeleccionado.value : 'total';
  document.getElementById('formEliminar').submit();
}

document.getElementById('modalSesion').addEventListener('click',function(e){if(e.target===this)cerrarModal();});
document.getElementById('modalEliminarSesion').addEventListener('click',function(e){if(e.target===this)cerrarModalEliminar();});

function autoTotal(){
  const principal=buscarProducto(document.getElementById('qSesionIdInput').value);
  let suma=principal?Number(principal.precio):0;
  document.querySelectorAll('#qAdicionalesLista .adicional-item').forEach(item=>{
    const p=buscarProducto(item.dataset.id); const qty=parseInt(item.querySelector('.adicional-cantidad').value||1);
    if(p) suma+=Number(p.precio)*qty;
  });
  document.getElementById('qTotal').value=Math.round(suma);
  actualizarEstadoPago('q');
}
function autoTotalModal(){
  const principal=buscarProducto(document.getElementById('mSesionIdInput').value);
  let suma=principal?Number(principal.precio):0;
  document.querySelectorAll('#mAdicionalesLista .adicional-item').forEach(item=>{
    const p=buscarProducto(item.dataset.id); const qty=parseInt(item.querySelector('.adicional-cantidad').value||1);
    if(p) suma+=Number(p.precio)*qty;
  });
  document.getElementById('mTotal').value=Math.round(suma);
  actualizarEstadoPago('m');
}
function actualizarEstadoPago(prefix){
  const total=Number(document.getElementById(prefix==='m'?'mTotal':'qTotal').value)||0;
  const abonoEl=document.getElementById(prefix==='m'?'mAbono':'qAbono');
  let abono=Number(abonoEl.value)||0;
  if(total>0 && abono>total){ abono=total; abonoEl.value=total; }
  const estado=(total>0 && abono>=total)?'pagado':((total===0 && abono>0)?'reserva':(abono>0?'abonado':'pendiente'));
  document.getElementById(prefix==='m'?'mEstadoPago':'qEstadoPago').value=estado;
  document.getElementById(prefix==='m'?'mEstadoPagoVista':'qEstadoPagoVista').value=estado.charAt(0).toUpperCase()+estado.slice(1);
  const saldoEl = document.getElementById(prefix==='m'?'mSaldo':'qSaldo');
  if (saldoEl) {
    if (total === 0 && abono > 0) saldoEl.textContent = 'A favor: $' + abono.toLocaleString('es-CO');
    else {
      const saldo = Math.max(0, total - abono);
      saldoEl.textContent = '$' + saldo.toLocaleString('es-CO');
    }
  }
}

document.getElementById('mAbono').addEventListener('input',()=>actualizarEstadoPago('m'));
document.getElementById('qAbono').addEventListener('input',()=>actualizarEstadoPago('q'));

inicializarCategorias();

// ── Historial de Abonos Ordenados en Modal ──
function renderizarAbonosModal(s) {
  const seccion = document.getElementById('mSeccionAbonos');
  const lista = document.getElementById('mListaAbonos');
  const contador = document.getElementById('mAbonosContador');
  const abonoInput = document.getElementById('mAbono');
  const abonoHint = document.getElementById('mAbonoHint');
  toggleFormNuevoAbono(false);

  if (!s || !s.id || Number(s.id) === 0) {
    seccion.style.display = 'none';
    abonoInput.readOnly = false;
    abonoInput.style.background = '';
    if (abonoHint) abonoHint.style.display = 'none';
    return;
  }

  seccion.style.display = 'block';
  const abonos = Array.isArray(s.abonos) ? s.abonos : [];
  contador.textContent = abonos.length + ' abono' + (abonos.length !== 1 ? 's' : '');

  if (abonos.length > 0) {
    const sumaAbonos = abonos.reduce((acc, a) => acc + Number(a.monto || 0), 0);
    abonoInput.value = sumaAbonos;
    actualizarEstadoPago('m');
    abonoInput.readOnly = true;
    abonoInput.style.background = '#f7f4f8';
    if (abonoHint) abonoHint.style.display = 'block';

    let html = '';
    abonos.forEach((ab, idx) => {
      const num = idx + 1;
      const metodoFmt = (ab.metodo_pago || 'efectivo').toUpperCase();
      const notasFmt = ab.notas ? `<div style="font-size:11px;color:var(--text-dim);margin-top:2px;">📝 ${escapeHtml(ab.notas)}</div>` : '';
      html += `
        <div class="abono-item">
          <div style="display:flex;align-items:center;gap:10px;">
            <span class="abono-num-badge">#${num}</span>
            <div>
              <div style="display:flex;align-items:center;gap:8px;">
                <strong style="font-size:13px;color:var(--navy);">${formatoPrecio(ab.monto)}</strong>
                <span class="abono-metodo-pill">${escapeHtml(metodoFmt)}</span>
              </div>
              <div style="font-size:10.5px;color:var(--text-dim);margin-top:1px;">
                📅 ${formatoFechaCorta(ab.fecha)} · ${escapeHtml(ab.concepto || 'Abono #' + num)}
              </div>
              ${notasFmt}
            </div>
          </div>
          <div>
            <button type="button" class="btn btn-danger btn-sm" onclick="confirmarEliminarAbono(${ab.id}, ${s.id}, '${formatoPrecio(ab.monto)}')" title="Eliminar abono">
              🗑️
            </button>
          </div>
        </div>
      `;
    });
    lista.innerHTML = html;
  } else {
    const abonoMonto = Number(s.abono || 0);
    if (abonoMonto > 0) {
      abonoInput.readOnly = true;
      abonoInput.style.background = '#f7f4f8';
      if (abonoHint) abonoHint.style.display = 'block';
      contador.textContent = '1 abono';
      const fechaFmt = s.fecha_sesion ? formatoFechaCorta(s.fecha_sesion) : 'Registrado';
      const metodoFmt = (s.metodo_pago || 'efectivo').toUpperCase();
      lista.innerHTML = `
        <div class="abono-item">
          <div style="display:flex;align-items:center;gap:10px;">
            <span class="abono-num-badge">#1</span>
            <div>
              <div style="display:flex;align-items:center;gap:8px;">
                <strong style="font-size:13px;color:var(--navy);">${formatoPrecio(abonoMonto)}</strong>
                <span class="abono-metodo-pill">${escapeHtml(metodoFmt)}</span>
              </div>
              <div style="font-size:10.5px;color:var(--text-dim);margin-top:1px;">
                📅 ${fechaFmt} · Abono inicial registrado con la sesión
              </div>
            </div>
          </div>
          <div>
            <span style="font-size:11px;color:var(--teal-deep);font-weight:700;">Abono Inicial</span>
          </div>
        </div>
      `;
    } else {
      abonoInput.readOnly = false;
      abonoInput.style.background = '';
      if (abonoHint) abonoHint.style.display = 'none';
      lista.innerHTML = '<div style="text-align:center;padding:12px;font-size:11.5px;color:var(--text-dim);font-style:italic;">No hay abonos individuales registrados para esta sesión. Usa "+ Registrar Abono" para agregar uno.</div>';
    }
  }
}

function toggleFormNuevoAbono(forzar) {
  const wrapper = document.getElementById('formNuevoAbonoWrapper');
  const btn = document.getElementById('btnToggleNuevoAbono');
  if (!wrapper || !btn) return;
  const mostrar = forzar !== undefined ? forzar : (wrapper.style.display === 'none');
  wrapper.style.display = mostrar ? 'block' : 'none';
  btn.textContent = mostrar ? '✕ Cerrar' : '+ Registrar Abono';

  if (mostrar) {
    const sId = Number(document.getElementById('mId').value) || 0;
    const s = sesionesData.find(x => Number(x.id) === sId);
    const montoEl = document.getElementById('newAbonoMonto');
    montoEl.value = '';
    if (s) {
      const total = Number(s.total || 0);
      const abono = Number(s.abono || 0);
      const saldo = Math.max(0, total - abono);
      if (saldo > 0) montoEl.placeholder = 'Saldo pendiente: ' + formatoPrecio(saldo);
    }
    document.getElementById('newAbonoNotas').value = '';
    montoEl.focus();
  }
}

function guardarNuevoAbonoModal() {
  const sesionId = Number(document.getElementById('mId').value) || 0;
  const monto = parseFloat(document.getElementById('newAbonoMonto').value) || 0;
  const metodo = document.getElementById('newAbonoMetodo').value || 'efectivo';
  const fecha = document.getElementById('newAbonoFecha').value;
  const notas = document.getElementById('newAbonoNotas').value.trim();

  if (sesionId <= 0) {
    alert('Debes guardar la sesión primero antes de registrar abonos adicionales.');
    return;
  }
  if (monto <= 0) {
    alert('Ingresa un monto válido para el abono.');
    document.getElementById('newAbonoMonto').focus();
    return;
  }
  if (!fecha) {
    alert('Selecciona la fecha del abono.');
    return;
  }

  document.getElementById('abonoDirSesionId').value = sesionId;
  document.getElementById('abonoDirMonto').value = monto;
  document.getElementById('abonoDirMetodo').value = metodo;
  document.getElementById('abonoDirFecha').value = fecha;
  document.getElementById('abonoDirNotas').value = notas;
  document.getElementById('formAbonoDirecto').submit();
}

function confirmarEliminarAbono(abonoId, sesionId, montoTexto) {
  if (confirm(`¿Estás seguro de que deseas eliminar este abono de ${montoTexto}? El saldo y estado de la sesión y factura se recalcularán automáticamente.`)) {
    document.getElementById('elimAbonoId').value = abonoId;
    document.getElementById('elimAbonoSesionId').value = sesionId;
    document.getElementById('formEliminarAbonoDirecto').submit();
  }
}

function abrirAbonosSesion(sesionId, abrirForm = false) {
  editarSesionPorId(sesionId);
  setTimeout(() => {
    const sec = document.getElementById('mSeccionAbonos');
    if (sec) {
      sec.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      if (abrirForm) toggleFormNuevoAbono(true);
    }
  }, 300);
}

// ── Galería y Carpetas ──
let fotosCache = [];
let tabActualGaleria = 'todas';

function actualizarPlaceholderGrupo() {
  const elCat = document.getElementById('formFotoCategoria');
  const inputDesc = document.getElementById('formFotoGrupoDesc');
  if (!elCat || !inputDesc) return;
  const cat = elCat.value;
  if (cat === 'comprobante') {
    inputDesc.placeholder = 'Ej: Abono #1 Nequi, Recibo consignación, Factura...';
  } else if (cat === 'referencia') {
    inputDesc.placeholder = 'Ej: Poses estudio, Ideas vestuario, Paleta colores...';
  } else if (cat === 'sesion') {
    inputDesc.placeholder = 'Ej: Fotos finales, Exteriores, Retoque digital...';
  } else {
    inputDesc.placeholder = 'Ej: Nombre de la carpeta personalizada...';
  }
}

function filtrarGaleriaTab(cat) {
  tabActualGaleria = cat;
  document.querySelectorAll('.galeria-tab').forEach(b => {
    b.classList.toggle('active', b.dataset.cat === cat);
  });
  renderFotosGaleria();
}

function nombreCategoria(cat) {
  switch(cat) {
    case 'comprobante': return 'Comprobantes y Facturas';
    case 'referencia': return 'Fotos de Referencia';
    case 'otro': return 'Otras fotos';
    default: return 'Fotos de la Sesión';
  }
}

function iconoCategoria(cat) {
  switch(cat) {
    case 'comprobante': return '🧾';
    case 'referencia': return '💡';
    case 'otro': return '🗂️';
    default: return '📸';
  }
}

function renderFotosGaleria() {
  const cont = document.getElementById('galeriaContenido');
  if (!cont) return;

  if (!fotosCache || !fotosCache.length) {
    cont.innerHTML = '<div style="text-align:center;padding:40px 20px;color:var(--text-dim);font-size:12px;">📁 Esta sesión aún no tiene fotos ni comprobantes guardados.<br><span style="font-size:11px;">Usa el formulario de abajo para subir fotos organizadas en carpetas.</span></div>';
    return;
  }

  const filtradas = (tabActualGaleria === 'todas') 
    ? fotosCache 
    : fotosCache.filter(f => (f.categoria || 'sesion') === tabActualGaleria);

  if (!filtradas.length) {
    cont.innerHTML = `<div style="text-align:center;padding:36px 20px;color:var(--text-dim);font-size:12px;">📂 No hay fotos en la carpeta <strong>${escapeHtml(nombreCategoria(tabActualGaleria))}</strong> todavía.<br><span style="font-size:11px;">Selecciona esta categoría abajo para subir archivos aquí.</span></div>`;
    return;
  }

  const grupos = {};
  filtradas.forEach(f => {
    const nomGrupo = (f.grupo_desc && f.grupo_desc.trim()) ? f.grupo_desc.trim() : nombreCategoria(f.categoria || 'sesion');
    if (!grupos[nomGrupo]) grupos[nomGrupo] = { categoria: f.categoria || 'sesion', items: [] };
    grupos[nomGrupo].items.push(f);
  });

  let html = '<div style="display:flex;flex-direction:column;gap:14px;padding:12px 18px;">';
  for (const [nomGrupo, datos] of Object.entries(grupos)) {
    const badgeCls = 'badge-cat-' + datos.categoria;
    const catIco = iconoCategoria(datos.categoria);
    html += `
      <div class="galeria-grupo-card" style="background:#fff;border:1.5px solid var(--border);border-radius:12px;padding:12px;box-shadow:var(--shadow-sm);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid var(--border);">
          <div style="display:flex;align-items:center;gap:7px;">
            <span style="font-size:15px;">${catIco}</span>
            <div>
              <span style="font-weight:700;font-size:12.5px;color:var(--navy);">${escapeHtml(nomGrupo)}</span>
              <span class="cat-pill ${badgeCls}" style="font-size:9.5px;padding:1px 6px;border-radius:6px;margin-left:6px;font-weight:700;">${escapeHtml(nombreCategoria(datos.categoria))}</span>
            </div>
          </div>
          <span style="font-size:10.5px;color:var(--text-dim);font-weight:600;">${datos.items.length} foto(s)</span>
        </div>
        <div class="galeria-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(100px, 1fr));gap:8px;padding:4px 0;">`;

    datos.items.forEach(f => {
      html += `
        <div class="galeria-item" style="position:relative;border-radius:8px;overflow:hidden;aspect-ratio:1;border:1px solid var(--border);background:var(--surface2);">
          <img src="${UPLOAD_URL + f.archivo}" alt="${escapeHtml(f.descripcion || 'foto')}" style="width:100%;height:100%;object-fit:cover;display:block;">
          <div class="foto-overlay">
            <button type="button" class="foto-btn" onclick="verFoto('${UPLOAD_URL + f.archivo}')" title="Ver foto">🔍</button>
            <button type="button" class="foto-btn" onclick="eliminarFoto(${f.id})" title="Eliminar">🗑️</button>
          </div>
          ${f.descripcion ? `<div style="position:absolute;bottom:0;left:0;right:0;background:rgba(26,31,60,0.75);color:#fff;font-size:9px;padding:2px 4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtml(f.descripcion)}">${escapeHtml(f.descripcion)}</div>` : ''}
        </div>`;
    });

    html += `
        </div>
      </div>`;
  }
  html += '</div>';
  cont.innerHTML = html;
}

function abrirGaleria(sesionId, nombre){
  document.getElementById('galeriaId').value = sesionId;
  document.getElementById('galeriaTitulo').textContent = 'Fotos – ' + nombre;
  document.getElementById('modalGaleria').classList.add('open');
  actualizarPlaceholderGrupo();
  cargarFotos(sesionId);
}
function cerrarGaleria(){ document.getElementById('modalGaleria').classList.remove('open'); }
document.getElementById('modalGaleria').addEventListener('click',function(e){if(e.target===this)cerrarGaleria();});

function cargarFotos(sesionId){
  const cont = document.getElementById('galeriaContenido');
  cont.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-dim);">Cargando fotos y carpetas…</div>';
  fetch('?accion_galeria=1&sesion_id=' + sesionId)
    .then(r => r.json())
    .then(fotos => {
      fotosCache = Array.isArray(fotos) ? fotos : [];

      const totalCount = fotosCache.length;
      const cSesion = fotosCache.filter(f => (f.categoria || 'sesion') === 'sesion').length;
      const cComp = fotosCache.filter(f => f.categoria === 'comprobante').length;
      const cRef = fotosCache.filter(f => f.categoria === 'referencia').length;
      const cOtro = fotosCache.filter(f => f.categoria === 'otro').length;

      document.getElementById('countTodas').textContent = totalCount;
      document.getElementById('countSesion').textContent = cSesion;
      document.getElementById('countComprobante').textContent = cComp;
      document.getElementById('countReferencia').textContent = cRef;
      document.getElementById('countOtro').textContent = cOtro;

      renderFotosGaleria();
    })
    .catch(() => {
      cont.innerHTML = '<div style="padding:20px;text-align:center;color:var(--rose-deep);">Error al cargar fotos de la sesión.</div>';
    });
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

// ── Enfoque y resaltado automático desde Dashboard ──
const targetSesionId = <?= (int)$sesionParamId ?>;
const autoEditar = <?= ((isset($_GET['editar']) && $_GET['editar'] == '1') || in_array($accion ?? '', ['registrar_abono_sesion', 'eliminar_abono_sesion'])) ? 'true' : 'false' ?>;

if (targetSesionId > 0) {
  window.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
      const el = document.getElementById('sesion-block-' + targetSesionId);
      if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      const row = document.getElementById('sesion-row-' + targetSesionId);
      if (row) {
        row.classList.add('highlight-row');
      }
      if (autoEditar) {
        setTimeout(() => {
          editarSesionPorId(targetSesionId);
        }, 350);
      }
    }, 250);
  });
}
</script>
</body>
</html>