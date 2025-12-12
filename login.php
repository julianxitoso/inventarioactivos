<?php
session_start();

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: menu.php");
    exit;
}

require_once 'backend/db.php'; 

$error_login = "";

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn; 
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login_submit'])) {
    if (empty(trim($_POST["usuario"])) || empty(trim($_POST["clave"]))) {
        $error_login = "Por favor, ingrese usuario y contraseña.";
    } else {
        $usuario_ingresado_form = trim($_POST["usuario"]); 
        $clave_ingresada_form = trim($_POST["clave"]);

        if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) { 
            $error_login = "Error de conexión a la base de datos. Intente más tarde.";
        } else {
            $conexion->set_charset("utf8mb4");
            $sql = "SELECT u.id, u.usuario, u.clave, u.nombre_completo, u.rol, u.activo, 
                           c.nombre_cargo, u.empresa, u.regional 
                    FROM usuarios u
                    LEFT JOIN cargos c ON u.id_cargo = c.id_cargo 
                    WHERE u.usuario = ?"; 
            if ($stmt = $conexion->prepare($sql)) {
                $stmt->bind_param("s", $usuario_ingresado_form);
                if ($stmt->execute()) {
                    $stmt->store_result();
                    if ($stmt->num_rows == 1) {
                        $stmt->bind_result($id_db, $usuario_db_col, $clave_hash_db_col, $nombre_completo_db, $rol_db, $activo_db, $nombre_cargo_db, $empresa_db, $regional_db);
                        if ($stmt->fetch()) {
                            if ($activo_db == 1 || $activo_db === TRUE) {
                                if (password_verify($clave_ingresada_form, $clave_hash_db_col)) {
                                    session_regenerate_id(true); 
                                    $_SESSION["loggedin"] = true;
                                    $_SESSION["usuario_id"] = $id_db; 
                                    $_SESSION["usuario_login"] = $usuario_db_col; 
                                    $_SESSION["nombre_usuario_completo"] = $nombre_completo_db;
                                    $_SESSION["rol_usuario"] = $rol_db;
                                    $_SESSION["cargo_usuario"] = $nombre_cargo_db;
                                    $_SESSION["empresa_usuario"] = $empresa_db;
                                    $_SESSION["regional_usuario"] = $regional_db;
                                    header("location: menu.php");
                                    exit;
                                } else { $error_login = "La contraseña ingresada no es válida."; }
                            } else { $error_login = "Esta cuenta de usuario ha sido desactivada."; }
                        } else { $error_login = "Error al obtener los datos del usuario."; }
                    } else { $error_login = "No se encontró una cuenta con esa cédula/usuario."; }
                } else { $error_login = "Oops! Algo salió mal al consultar."; }
                $stmt->close();
            } else { $error_login = "Oops! Error al preparar la consulta."; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Inventario de Activos</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        html, body {
            height: 100%; 
        }
        body {
            display: flex;
            align-items: center; /* Centrar verticalmente el contenido del login */
            justify-content: center; /* Centrar horizontalmente el contenido del login */
            background-color: #f0f2f5; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0; 
            padding: 20px; /* Padding para que el login-container no pegue a los bordes */
        }
        .login-container {
            background-color: #fff;
            padding: 2.5rem; 
            border-radius: 10px; 
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); 
            width: 100%;
            max-width: 420px; 
        }
        .login-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .login-header img {
            max-width: 180px; 
            margin-bottom: 1rem;
        }
        .login-header h2 {
            color: #333;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .form-floating label {
            color: #6c757d;
        }
        .form-control:focus { 
            border-color: #007bff; 
            box-shadow: 0 0 0 0.25rem rgba(0, 123, 255, 0.25);
        }
        .btn-login {
            background-color: #007bff; 
            border: none;
            padding: 0.75rem;
            font-size: 1.05rem;
            font-weight: 500;
        }
        .btn-login:hover {
            background-color: #0056b3; 
        }
        .alert-danger {
            font-size: 0.9rem;
        }
        .extra-links { text-align: center; margin-top: 1.5rem; }
        .extra-links a { font-size: 0.9em; }
        .btn-outline-principal {
            color: #007bff; 
            border-color: #007bff; 
        }
        .btn-outline-principal:hover {
            background-color: #007bff; 
            color: #ffffff; 
        }
        .btn-outline-principal i { color: inherit; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="imagenes/logo.png" alt="Logo Empresa"> <h2>Inventario de Activos</h2>
            <p class="text-muted">ARPESOD ASOCIADOS SAS</p>
        </div>

        <?php if (!empty($error_login)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_login); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" novalidate>
            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="usuario" name="usuario" placeholder="Cédula de Usuario" required autofocus>
                <label for="usuario"><i class="bi bi-person-fill"></i> Cédula de Usuario</label>
            </div>
            <div class="form-floating mb-3">
                <input type="password" class="form-control" id="clave" name="clave" placeholder="Contraseña" required>
                <label for="clave"><i class="bi bi-lock-fill"></i> Contraseña</label>
            </div>
            <div class="d-grid">
                <button class="btn btn-primary btn-login" type="submit" name="login_submit">Ingresar</button>
            </div>
        </form>

        <div class="extra-links">
            <p class="mb-1">¿Eres nuevo y necesitas registrar activos?</p>
            <a href="registro.php" class="btn btn-outline-principal btn-sm">
                <i class="bi bi-person-plus"></i> Regístrate aquí como Registrador
            </a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
