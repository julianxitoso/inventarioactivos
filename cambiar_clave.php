<?php
// session_start(); // auth_check.php ya lo incluye y gestiona
require_once 'backend/auth_check.php';

// Solo usuarios logueados pueden acceder a cambiar su propia clave.
// restringir_acceso_pagina() sin argumentos o con todos los roles podría ir aquí
// pero la siguiente verificación de !isset($_SESSION["loggedin"]) ya cumple el propósito.
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4");

$mensaje_cambio = "";
$error_cambio = "";

// Obtener datos del usuario de la sesión para la barra superior y para la consulta
$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido'; // Para la barra superior
$cedula_usuario_sesion = $_SESSION['usuario_login'] ?? null; // Cédula del usuario logueado (login identifier)

if (!$cedula_usuario_sesion) {
    $error_cambio = "Error: No se pudo identificar al usuario actual. Intente iniciar sesión de nuevo.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $cedula_usuario_sesion && empty($error_cambio)) {
    $contrasena_actual = $_POST['contrasena_actual'] ?? '';
    $nueva_contrasena = $_POST['nueva_contrasena'] ?? '';
    $confirmar_nueva_contrasena = $_POST['confirmar_nueva_contrasena'] ?? '';

    if (empty($contrasena_actual) || empty($nueva_contrasena) || empty($confirmar_nueva_contrasena)) {
        $error_cambio = "Todos los campos son obligatorios.";
    } elseif ($nueva_contrasena !== $confirmar_nueva_contrasena) {
        $error_cambio = "La nueva contraseña y su confirmación no coinciden.";
    } elseif (strlen($nueva_contrasena) < 6) { 
        $error_cambio = "La nueva contraseña debe tener al menos 6 caracteres.";
    } else {
        // Asumimos que la columna de login es 'cedula' y la de hash es 'password_hash' en la tabla 'usuarios'
        // Si tu columna de login es 'usuario' y la de hash es 'clave', AJUSTA ESTAS CONSULTAS.
        $sql_select = "SELECT clave FROM usuarios WHERE usuario = ?"; // OJO: ¿Es 'cedula' o 'usuario' tu columna de login?
        if ($stmt_select = $conexion->prepare($sql_select)) {
            $stmt_select->bind_param("s", $cedula_usuario_sesion);
            $stmt_select->execute();
            $stmt_select->store_result();

            if ($stmt_select->num_rows == 1) {
                $stmt_select->bind_result($hash_actual_db);
                $stmt_select->fetch();

                if (password_verify($contrasena_actual, $hash_actual_db)) {
                    $nuevo_password_hash = password_hash($nueva_contrasena, PASSWORD_DEFAULT);

                    if ($nuevo_password_hash === false) {
                        $error_cambio = "Error crítico al procesar la nueva contraseña.";
                        error_log("Error en password_hash() para el usuario: " . $cedula_usuario_sesion);
                    } else {
                        $sql_update = "UPDATE usuarios SET clave = ? WHERE usuario = ?"; // OJO con 'cedula' y 'password_hash'
                        if ($stmt_update = $conexion->prepare($sql_update)) {
                            $stmt_update->bind_param("ss", $nuevo_password_hash, $cedula_usuario_sesion);
                            if ($stmt_update->execute()) {
                                $mensaje_cambio = "¡Contraseña actualizada exitosamente!";
                            } else {
                                $error_cambio = "Error al actualizar la contraseña en la base de datos.";
                                error_log("Error en UPDATE de contraseña para ".$cedula_usuario_sesion.": " . $stmt_update->error);
                            }
                            $stmt_update->close();
                        } else {
                            $error_cambio = "Error al preparar la actualización de la contraseña.";
                            error_log("Error preparando UPDATE de contraseña: " . $conexion->error);
                        }
                    }
                } else {
                    $error_cambio = "La contraseña actual ingresada es incorrecta.";
                }
            } else {
                $error_cambio = "No se encontró el usuario actual en la base de datos (esto no debería ocurrir si está logueado).";
            }
            $stmt_select->close();
        } else {
            $error_cambio = "Error al consultar la información del usuario.";
            error_log("Error preparando SELECT de contraseña: " . $conexion->error . " SQL: " . $sql_select);
        }
    }
}
// No cierres la conexión aquí si se usa globalmente y otras partes podrían necesitarla
// if(isset($conexion)) { $conexion->close(); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cambiar Contraseña - Inventario</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { 
            background-color: #ffffff !important; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 80px; /* Espacio para la barra superior fija */
        }
        .top-bar-custom {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1030;
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.5rem 1.5rem; background-color: #f8f9fa; 
            border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        
        .container-main { margin-top: 20px; margin-bottom: 40px; max-width: 600px; }
        .page-header-title { color: #191970; font-weight: 600; }
        .card.form-card { box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: 1px solid #e0e0e0; }
        .form-label { font-weight: 500; color: #495057; }
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div class="logo-container-top">
        <a href="menu.php" title="Ir a Inicio">
            <img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS">
        </a>
    </div>
    <div class="d-flex align-items-center">
        <span class="text-dark me-3 user-info-top">
            <i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> 
            (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)
        </span>
        <form action="logout.php" method="post" class="d-flex">
            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
        </form>
    </div>
</div>

<div class="container-main container">
    <div class="card form-card p-4 p-md-5">
        <h3 class="page-header-title text-center mb-4">Cambiar Mi Contraseña</h3>

        <?php if ($mensaje_cambio): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($mensaje_cambio) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>
        <?php if ($error_cambio): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($error_cambio) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>

        <?php if ($cedula_usuario_sesion): ?>
        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="formCambiarClave">
            <div class="mb-3">
                <label for="contrasena_actual" class="form-label">Contraseña Actual <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="contrasena_actual" name="contrasena_actual" required>
            </div>
            <hr>
            <div class="mb-3">
                <label for="nueva_contrasena" class="form-label">Nueva Contraseña <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="nueva_contrasena" name="nueva_contrasena" required minlength="6">
                <div id="passwordHelpBlock" class="form-text">
                    Tu nueva contraseña debe tener al menos 6 caracteres.
                </div>
            </div>
            <div class="mb-4">
                <label for="confirmar_nueva_contrasena" class="form-label">Confirmar Nueva Contraseña <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="confirmar_nueva_contrasena" name="confirmar_nueva_contrasena" required>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-key-fill"></i> Cambiar Contraseña</button>
            </div>
        </form>
        <?php elseif(empty($error_cambio)): // Solo mostrar si no hay ya un error de identificación ?>
            <div class="alert alert-warning">No se puede cambiar la contraseña porque no se ha identificado al usuario actual. Por favor, <a href="login.php">inicie sesión</a>.</div>
        <?php endif; ?>
    </div>
</div>

<script>
    const nuevaPassword = document.getElementById("nueva_contrasena");
    const confirmarNuevaPassword = document.getElementById("confirmar_nueva_contrasena");

    function validateNewPassword(){
      if(nuevaPassword && confirmarNuevaPassword && nuevaPassword.value !== confirmarNuevaPassword.value) {
        confirmarNuevaPassword.setCustomValidity("Las nuevas contraseñas no coinciden.");
      } else if (confirmarNuevaPassword) {
        confirmarNuevaPassword.setCustomValidity('');
      }
    }
    if (nuevaPassword) nuevaPassword.onchange = validateNewPassword;
    if (confirmarNuevaPassword) confirmarNuevaPassword.onkeyup = validateNewPassword;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>