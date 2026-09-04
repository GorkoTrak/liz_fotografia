<?php
require_once 'config.php';
if (estaLogueado()) redirect(SITE_URL . '/index.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Por favor completa todos los campos.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id, nombre, password, rol FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $u = $result->fetch_assoc();
            if (password_verify($password, $u['password'])) {
                $_SESSION['usuario_id']     = $u['id'];
                $_SESSION['usuario_nombre'] = $u['nombre'];
                $_SESSION['usuario_rol']    = $u['rol'];
                redirect(SITE_URL . '/index.php');
            } else {
                $error = 'Contraseña incorrecta.';
            }
        } else {
            $error = 'No existe una cuenta con ese correo.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Iniciar Sesión – <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#faf8f6;--surface:#fff;--navy:#1a1f3c;--rose:#e8789a;--rose-light:#f5b8ce;--rose-pale:#fce8f0;--rose-deep:#c4547a;--teal:#5bbcb8;--teal-light:#9ddbd8;--text:#2a2040;--text-mid:#6b5e7a;--text-dim:#a899b5;--border:#ede0ea;}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Nunito',sans-serif;background:var(--bg);min-height:100vh;display:flex;align-items:center;justify-content:center;overflow:hidden;}
  body::before{content:'';position:absolute;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(232,120,154,.12) 0%,transparent 70%);top:-150px;right:-100px;pointer-events:none;}
  body::after{content:'';position:absolute;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(91,188,184,.10) 0%,transparent 70%);bottom:-100px;left:-80px;pointer-events:none;}
  .card{background:var(--surface);border:1.5px solid var(--border);border-radius:24px;padding:44px 40px;width:100%;max-width:400px;box-shadow:0 8px 40px rgba(180,120,160,.15);position:relative;z-index:1;animation:fadeUp .5s ease both;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
  .logo-area{display:flex;flex-direction:column;align-items:center;margin-bottom:32px;}
  .logo-circle{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--rose-light),var(--teal-light));display:flex;align-items:center;justify-content:center;font-family:'Dancing Script',cursive;font-size:38px;color:var(--navy);font-weight:700;margin-bottom:12px;box-shadow:0 4px 20px rgba(232,120,154,.3);}
  .logo-name{font-family:'Dancing Script',cursive;font-size:26px;font-weight:700;color:var(--navy);}
  .logo-sub{font-size:10px;color:var(--teal);letter-spacing:.22em;text-transform:uppercase;font-weight:600;margin-top:4px;}
  h2{font-size:14px;color:var(--text-mid);font-weight:600;text-align:center;margin-bottom:24px;}
  .form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:16px;}
  label{font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--text-dim);font-weight:700;}
  .form-input{background:#fdf5f8;border:1.5px solid var(--border);border-radius:12px;padding:11px 14px;color:var(--navy);font-family:'Nunito',sans-serif;font-size:13px;font-weight:600;outline:none;transition:border-color .2s;width:100%;}
  .form-input:focus{border-color:var(--rose-light);box-shadow:0 0 0 3px rgba(232,120,154,.12);}
  .btn-login{width:100%;padding:13px;border-radius:14px;background:linear-gradient(135deg,var(--rose),var(--rose-deep));color:#fff;font-family:'Nunito',sans-serif;font-size:14px;font-weight:700;border:none;cursor:pointer;box-shadow:0 4px 16px rgba(196,84,122,.3);transition:all .2s;margin-top:6px;}
  .btn-login:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(196,84,122,.4);}
  .error-msg{background:var(--rose-pale);border:1.5px solid var(--rose-light);color:var(--rose-deep);border-radius:10px;padding:10px 14px;font-size:12px;font-weight:600;margin-bottom:16px;text-align:center;}
</style>
</head>
<body>
<div class="card">
  <div class="logo-area">
    <div class="logo-circle">L</div>
    <div class="logo-name">Lizdy Pineda</div>
    <div class="logo-sub">Fotoestudio</div>
  </div>
  <h2>Accede a tu sistema de gestión</h2>
  <?php if ($error): ?><div class="error-msg"><?= $error ?></div><?php endif; ?>
  <form method="POST">
    <div class="form-group">
      <label>Correo electrónico</label>
      <input class="form-input" type="email" name="email" placeholder="admin@lizfotoestudio.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
    </div>
    <div class="form-group">
      <label>Contraseña</label>
      <input class="form-input" type="password" name="password" placeholder="••••••••" required>
    </div>
    <button class="btn-login" type="submit">Iniciar Sesión</button>
  </form>
</div>
</body>
</html>
