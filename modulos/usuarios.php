<?php
require_once '../config.php';
requerirAdmin();

$db      = getDB();
$mensaje = '';
$error   = '';

$uploadDir = UPLOAD_DIR . 'perfiles/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ── ACCIONES ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $id       = sanitizeInt($_POST['id'] ?? 0);
        $nombre   = sanitize($_POST['nombre'] ?? '');
        $email    = sanitize($_POST['email'] ?? '');
        $rol      = sanitize($_POST['rol'] ?? 'usuario');
        $password = $_POST['password'] ?? '';

        // Foto de perfil
        $foto_perfil = null;
        if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto_perfil']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
                $nombre_foto = 'perfil_' . ($id ?: time()) . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $nombre_foto;
                if (move_uploaded_file($_FILES['foto_perfil']['tmp_name'], $dest)) {
                    $foto_perfil = 'perfiles/' . $nombre_foto;
                }
            }
        }

        if (empty($nombre) || empty($email)) {
            $error = 'Nombre y correo son obligatorios.';
        } elseif ($id === 0 && empty($password)) {
            $error = 'La contraseña es obligatoria al crear un usuario.';
        } else {
            if ($id > 0) {
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($foto_perfil) {
                        $stmt = $db->prepare("UPDATE usuarios SET nombre=?, email=?, rol=?, password=?, foto_perfil=? WHERE id=?");
                        $stmt->bind_param("sssssi", $nombre, $email, $rol, $hash, $foto_perfil, $id);
                    } else {
                        $stmt = $db->prepare("UPDATE usuarios SET nombre=?, email=?, rol=?, password=? WHERE id=?");
                        $stmt->bind_param("ssssi", $nombre, $email, $rol, $hash, $id);
                    }
                } else {
                    if ($foto_perfil) {
                        $stmt = $db->prepare("UPDATE usuarios SET nombre=?, email=?, rol=?, foto_perfil=? WHERE id=?");
                        $stmt->bind_param("ssssi", $nombre, $email, $rol, $foto_perfil, $id);
                    } else {
                        $stmt = $db->prepare("UPDATE usuarios SET nombre=?, email=?, rol=? WHERE id=?");
                        $stmt->bind_param("sssi", $nombre, $email, $rol, $id);
                    }
                }
                $mensaje = 'Usuario actualizado.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($foto_perfil) {
                    $stmt = $db->prepare("INSERT INTO usuarios (nombre, email, password, rol, foto_perfil) VALUES (?,?,?,?,?)");
                    $stmt->bind_param("sssss", $nombre, $email, $hash, $rol, $foto_perfil);
                } else {
                    $stmt = $db->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?,?,?,?)");
                    $stmt->bind_param("ssss", $nombre, $email, $hash, $rol);
                }
                $mensaje = 'Usuario creado correctamente.';
            }
            if ($stmt->execute()) {
                // Si se acaba de crear, actualizar la foto con el ID correcto
                if ($id === 0 && $foto_perfil) {
                    $newId = $stmt->insert_id;
                    $nombre_foto_final = 'perfil_' . $newId . '_' . time() . '.' . $ext;
                    rename($uploadDir . basename($foto_perfil), $uploadDir . $nombre_foto_final);
                    $fp = 'perfiles/' . $nombre_foto_final;
                    $db->query("UPDATE usuarios SET foto_perfil='$fp' WHERE id=$newId");
                }
            } else {
                $error   = 'Error: es posible que el correo ya esté registrado.';
                $mensaje = '';
            }
            $stmt->close();
        }
    }

    if ($accion === 'eliminar') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id === sanitizeInt($_SESSION['usuario_id'])) {
            $error = 'No puedes eliminar tu propia cuenta.';
        } elseif ($id > 0) {
            // Borrar foto
            $r = $db->query("SELECT foto_perfil FROM usuarios WHERE id=$id");
            if ($row = $r->fetch_assoc()) {
                if ($row['foto_perfil']) {
                    $f = UPLOAD_DIR . $row['foto_perfil'];
                    if (file_exists($f)) unlink($f);
                }
            }
            $stmt = $db->prepare("DELETE FROM usuarios WHERE id=?");
            $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
            $mensaje = 'Usuario eliminado.';
        }
    }
}

$usuarios = $db->query("SELECT id, nombre, email, rol, foto_perfil, fecha_registro FROM usuarios ORDER BY rol ASC, nombre ASC");
$totalA   = $db->query("SELECT COUNT(*) as t FROM usuarios WHERE rol='admin'")->fetch_assoc()['t'];
$totalU   = $db->query("SELECT COUNT(*) as t FROM usuarios WHERE rol='usuario'")->fetch_assoc()['t'];
$avClasses = ['av-a','av-b','av-c','av-d','av-e'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Usuarios – <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;600;700&family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#faf8f6;--surface:#fff;--surface2:#fdf5f8;--navy:#1a1f3c;--navy-mid:#2d3460;--navy-soft:#e8eaf6;--rose:#e8789a;--rose-light:#f5b8ce;--rose-pale:#fce8f0;--rose-deep:#c4547a;--teal:#5bbcb8;--teal-light:#9ddbd8;--teal-pale:#e0f5f4;--teal-deep:#3a9994;--lavender:#9e8bc9;--lav-light:#c5b8e8;--lav-pale:#f0ecfb;--text:#2a2040;--text-mid:#6b5e7a;--text-dim:#a899b5;--border:#ede0ea;--sidebar-w:230px;--shadow-sm:0 2px 8px rgba(180,120,160,.10);--shadow-md:0 4px 20px rgba(180,120,160,.15);}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;font-size:13px;}
  .sidebar{width:var(--sidebar-w);background:var(--navy);display:flex;flex-direction:column;flex-shrink:0;position:relative;overflow:hidden;}
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
  .main{flex:1;display:flex;flex-direction:column;overflow:hidden;}
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
  .content{flex:1;overflow-y:auto;padding:22px 26px;display:flex;flex-direction:column;gap:16px;scrollbar-width:thin;scrollbar-color:var(--border) transparent;}
  .stats-row{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;max-width:420px;}
  .stat-card{background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:16px 20px;box-shadow:var(--shadow-sm);position:relative;overflow:hidden;}
  .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:14px 14px 0 0;}
  .sc1::before{background:linear-gradient(90deg,var(--rose),var(--rose-light))}
  .sc2::before{background:linear-gradient(90deg,var(--teal),var(--teal-light))}
  .stat-label{font-size:10px;letter-spacing:.1em;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-bottom:4px;}
  .stat-value{font-family:'Dancing Script',cursive;font-size:32px;font-weight:700;color:var(--navy);}
  .card{background:var(--surface);border:1.5px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);}
  .card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--surface2);}
  .card-title{font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;color:var(--navy);flex:1;}
  table{width:100%;border-collapse:collapse;}
  th{text-align:left;padding:11px 20px;font-size:10px;letter-spacing:.14em;color:var(--text-dim);text-transform:uppercase;border-bottom:1.5px solid var(--border);font-weight:700;background:#fdf9fb;}
  td{padding:12px 20px;border-bottom:1px solid var(--border);font-size:12.5px;color:var(--text-mid);vertical-align:middle;}
  tr:last-child td{border-bottom:none;}
  tr:hover td{background:var(--rose-pale);}
  .user-cell{display:flex;align-items:center;gap:12px;}
  .user-avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:20px;font-weight:700;flex-shrink:0;overflow:hidden;}
  .user-avatar img{width:100%;height:100%;object-fit:cover;}
  .av-a{background:var(--rose-pale);color:var(--rose-deep)}.av-b{background:var(--teal-pale);color:var(--teal-deep)}.av-c{background:var(--lav-pale);color:var(--lavender)}.av-d{background:var(--navy-soft);color:var(--navy-mid)}.av-e{background:#fef3e2;color:#b07a30}
  .rol-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;}
  .rol-admin{background:var(--rose-pale);color:var(--rose-deep);}
  .rol-usuario{background:var(--teal-pale);color:var(--teal-deep);}
  /* Modal */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(26,31,60,.45);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
  .modal-overlay.open{display:flex;}
  .modal{background:var(--surface);border-radius:20px;width:100%;max-width:480px;box-shadow:var(--shadow-md);animation:fadeUp .3s ease both;max-height:92vh;overflow-y:auto;}
  .modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;background:var(--surface2);border-radius:20px 20px 0 0;}
  .modal-title{font-family:'Dancing Script',cursive;font-size:22px;font-weight:700;color:var(--navy);flex:1;}
  .modal-close{width:28px;height:28px;border-radius:50%;background:var(--rose-pale);border:none;color:var(--rose-deep);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;font-weight:700;}
  .modal-body{padding:20px 24px;}
  .modal-footer{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--surface2);border-radius:0 0 20px 20px;}
  .form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:13px;}
  label{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);font-weight:700;}
  .form-input{background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;color:var(--navy);font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:600;outline:none;transition:border-color .2s;width:100%;}
  .form-input:focus{border-color:var(--rose-light);}
  /* Foto preview */
  .foto-preview-wrap{display:flex;align-items:center;gap:14px;margin-bottom:13px;}
  .foto-preview{width:64px;height:64px;border-radius:50%;background:var(--rose-pale);border:2px solid var(--rose-light);overflow:hidden;display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:28px;color:var(--rose-deep);flex-shrink:0;}
  .foto-preview img{width:100%;height:100%;object-fit:cover;}
  .rol-selector{display:flex;gap:8px;}
  .rol-opt{display:none;}
  .rol-opt+label{padding:8px 20px;border-radius:20px;border:1.5px solid var(--border);cursor:pointer;font-size:12px;font-weight:700;color:var(--text-mid);transition:all .2s;}
  .rol-opt:checked+label.adm-lbl{background:var(--rose-pale);border-color:var(--rose-light);color:var(--rose-deep);}
  .rol-opt:checked+label.usr-lbl{background:var(--teal-pale);border-color:var(--teal-light);color:var(--teal-deep);}
  .alert{padding:10px 16px;border-radius:10px;font-size:12px;font-weight:600;}
  .alert-success{background:var(--teal-pale);color:var(--teal-deep);border:1.5px solid var(--teal-light);}
  .alert-error{background:var(--rose-pale);color:var(--rose-deep);border:1.5px solid var(--rose-light);}
  @keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
<?php require_once '../includes/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <span class="page-title">Usuarios</span>
    <div class="topbar-sep"></div>
    <button class="btn btn-primary" onclick="abrirModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nuevo Usuario
    </button>
  </div>
  <div class="content">
    <?php if($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>
    <div class="stats-row">
      <div class="stat-card sc1"><div class="stat-label">Administradores</div><div class="stat-value"><?= $totalA ?></div></div>
      <div class="stat-card sc2"><div class="stat-label">Usuarios</div><div class="stat-value"><?= $totalU ?></div></div>
    </div>
    <div class="card">
      <div class="card-header"><div class="card-title">Equipo de Trabajo</div></div>
      <table>
        <thead><tr><th>Usuario</th><th>Email</th><th>Rol</th><th>Registro</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php $i=0; while($u=$usuarios->fetch_assoc()): ?>
        <tr>
          <td>
            <div class="user-cell">
              <div class="user-avatar <?= $avClasses[$i%5] ?>">
                <?php if($u['foto_perfil']): ?>
                <img src="<?= UPLOAD_URL . htmlspecialchars($u['foto_perfil']) ?>" alt="<?= htmlspecialchars($u['nombre']) ?>">
                <?php else: ?>
                <?= mb_strtoupper(mb_substr($u['nombre'],0,1)) ?>
                <?php endif; ?>
              </div>
              <div>
                <div style="font-size:13px;color:var(--navy);font-weight:700;"><?= htmlspecialchars($u['nombre']) ?></div>
                <?php if($u['id']===$_SESSION['usuario_id']): ?><div style="font-size:10px;color:var(--teal-deep);font-weight:700;">Tú</div><?php endif; ?>
              </div>
            </div>
          </td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td><span class="rol-badge rol-<?= $u['rol'] ?>"><?= $u['rol']==='admin'?'👑 Admin':'👤 Usuario' ?></span></td>
          <td style="font-size:11px;"><?= formatoFecha($u['fecha_registro']) ?></td>
          <td>
            <div style="display:flex;gap:6px;">
              <button class="btn btn-ghost btn-sm" onclick="editarUsuario(<?= htmlspecialchars(json_encode($u)) ?>)">Editar</button>
              <?php if($u['id']!==$_SESSION['usuario_id']): ?>
              <button class="btn btn-danger btn-sm" onclick="confirmarEliminar(<?= $u['id'] ?>, '<?= htmlspecialchars($u['nombre']) ?>')">Eliminar</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php $i++; endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="modalUsuario">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitulo">Nuevo Usuario</div>
      <button class="modal-close" onclick="cerrarModal()">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="userId" value="0">
      <div class="modal-body">
        <!-- Foto de perfil -->
        <div class="foto-preview-wrap">
          <div class="foto-preview" id="fotoPreview"><span id="fotoInicialLetra">?</span></div>
          <div>
            <label style="margin-bottom:6px;display:block;">Foto de perfil</label>
            <input type="file" name="foto_perfil" id="inputFotoPerfil" accept="image/*" style="font-size:11px;color:var(--text-mid);" onchange="previewFoto(this)">
            <div style="font-size:10px;color:var(--text-dim);margin-top:4px;">JPG, PNG, WEBP</div>
          </div>
        </div>
        <div class="form-group"><label>Nombre completo *</label><input class="form-input" type="text" name="nombre" id="uNombre" required oninput="actualizarLetra(this.value)"></div>
        <div class="form-group"><label>Correo electrónico *</label><input class="form-input" type="email" name="email" id="uEmail" required></div>
        <div class="form-group">
          <label>Rol *</label>
          <div class="rol-selector">
            <input type="radio" name="rol" id="rAdmin" value="admin" class="rol-opt">
            <label for="rAdmin" class="adm-lbl">👑 Admin</label>
            <input type="radio" name="rol" id="rUser" value="usuario" class="rol-opt" checked>
            <label for="rUser" class="usr-lbl">👤 Usuario</label>
          </div>
        </div>
        <div class="form-group"><label>Contraseña <span id="pwdHint" style="color:var(--text-dim);font-size:9px;">(obligatoria al crear)</span></label>
          <input class="form-input" type="password" name="password" id="uPassword" placeholder="Dejar vacío para no cambiar">
        </div>
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
function cerrarModal(){ document.getElementById('modalUsuario').classList.remove('open'); }
function abrirModal(){
  document.getElementById('modalTitulo').textContent='Nuevo Usuario';
  document.getElementById('userId').value='0';
  document.getElementById('uNombre').value='';
  document.getElementById('uEmail').value='';
  document.getElementById('uPassword').value='';
  document.getElementById('fotoPreview').innerHTML='<span id="fotoInicialLetra">?</span>';
  document.querySelector('input[name="rol"][value="usuario"]').checked=true;
  document.getElementById('modalUsuario').classList.add('open');
}
function editarUsuario(u){
  document.getElementById('modalTitulo').textContent='Editar Usuario';
  document.getElementById('userId').value=u.id;
  document.getElementById('uNombre').value=u.nombre;
  document.getElementById('uEmail').value=u.email;
  document.getElementById('uPassword').value='';
  document.querySelector('input[name="rol"][value="'+u.rol+'"]').checked=true;
  const prev=document.getElementById('fotoPreview');
  if(u.foto_perfil){
    prev.innerHTML=`<img src="<?= UPLOAD_URL ?>${u.foto_perfil}" alt="">`;
  } else {
    prev.innerHTML='<span id="fotoInicialLetra">'+u.nombre.charAt(0).toUpperCase()+'</span>';
  }
  document.getElementById('modalUsuario').classList.add('open');
}
function confirmarEliminar(id,nombre){
  if(confirm('¿Eliminar al usuario '+nombre+'?')){
    document.getElementById('elimId').value=id;
    document.getElementById('fElim').submit();
  }
}
function actualizarLetra(nombre){
  const el=document.getElementById('fotoInicialLetra');
  if(el) el.textContent=nombre?nombre.charAt(0).toUpperCase():'?';
}
function previewFoto(input){
  if(input.files&&input.files[0]){
    const reader=new FileReader();
    reader.onload=e=>{
      document.getElementById('fotoPreview').innerHTML=`<img src="${e.target.result}" alt="">`;
    };
    reader.readAsDataURL(input.files[0]);
  }
}
document.getElementById('modalUsuario').addEventListener('click',function(e){if(e.target===this)cerrarModal();});
<?php if($error): ?>document.getElementById('modalUsuario').classList.add('open');<?php endif; ?>
</script>
</body>
</html>