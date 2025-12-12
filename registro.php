<?php
// --- registro.php (PÁGINA PÚBLICA) ---
session_start();

require_once 'backend/db.php'; // Para la conexión $conexion

// Inicializar conexión
$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $db_conn_error_detail = method_exists($conexion, 'connect_error') ? $conexion->connect_error : 'Desconocido';
    error_log("Error de conexión BD en registro.php: " . $db_conn_error_detail);
    $conexion_error_msg = "<div class='alert alert-danger'>Error crítico de conexión a la base de datos. No se puede completar el registro.</div>";
} else {
    $conexion->set_charset("utf8mb4");
}

// Función para convertir a formato Título (primera letra de cada palabra en mayúscula)
if (!function_exists('formatoTitulo')) {
    function formatoTitulo($string) {
        return mb_convert_case(trim($string), MB_CASE_TITLE, "UTF-8");
    }
}

$regionales_usuarios = ['Popayan', 'Bordo', 'Santander', 'Valle', 'Pasto', 'Tuquerres', 'Huila', 'Nacional', 'Popayan 7', 'Puerto Tejada'];
$empresas_usuarios = ['Arpesod', 'Finansueños'];
$rol_fijo_para_registro = 'registrador';

// Cargar cargos desde la base de datos
$cargos_disponibles = [];
if (!$conexion_error_msg) {
    $sql_cargos = "SELECT id_cargo, nombre_cargo FROM cargos ORDER BY nombre_cargo ASC";
    $result_cargos = $conexion->query($sql_cargos);
    if ($result_cargos) {
        while ($row_cargo = $result_cargos->fetch_assoc()) {
            $cargos_disponibles[] = $row_cargo;
        }
    } else {
        error_log("Error al cargar cargos en registro.php: " . $conexion->error);
        // Podrías añadir un mensaje de error si la carga de cargos falla y es crítico
        // $error_general_registro = ($error_general_registro ? $error_general_registro . "<br>" : "") . "Error al cargar la lista de cargos.";
    }
}


// Mensajes flash
$mensaje_exito_registro = $_SESSION['mensaje_registro'] ?? ($_SESSION['mensaje_login'] ?? null);
$error_general_registro = $_SESSION['error_form_usuario'] ?? null;

$mostrar_modal_cedula_existente = false;
$mensaje_para_modal = '';

if (
    $error_general_registro &&
    (stripos(strtolower($error_general_registro), 'cédula') !== false || stripos(strtolower($error_general_registro), 'usuario') !== false) &&
    (stripos(strtolower($error_general_registro), 'ya existe') !== false || stripos(strtolower($error_general_registro), 'ya está registrada') !== false)
) {
    $mostrar_modal_cedula_existente = true;
    $mensaje_para_modal = $error_general_registro;
    $error_general_registro = null; 
}

unset($_SESSION['mensaje_registro'], $_SESSION['mensaje_login'], $_SESSION['error_form_usuario']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <title>Registro de Usuario - Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        html, body {
            height: 100%;
        }
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: #ffffff !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding-top: 85px; /* Espacio para la barra superior fija */
        }
        .top-bar-public {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            display: flex;
            justify-content: center; /* Centrar el logo */
            align-items: center;
            padding: 0.5rem 1rem;
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            height: 85px;
        }
        .logo-container-top img {
            max-height: 65px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
        }
        .main-content-wrapper { /* Wrapper para el contenido principal */
            flex-grow: 1;
            display: flex;
            align-items: center; 
            justify-content: center;
            padding: 20px 0; 
            width: 100%;
        }
        .registro-container {
            background-color: #fff;
            padding: 2rem 2.5rem;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 700px; 
        }
        .registro-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .registro-header h3 { /* Ajustado de h2 a h3 para coincidir con el HTML */
            color: #0d6efd; 
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .form-label {
            font-weight: 500;
        }
        .btn-principal {
            background-color: #191970;
            border-color: #191970;
            color: #ffffff;
        }
        .btn-principal:hover {
            background-color: #111150;
            border-color: #111150;
            color: #ffffff;
        }
        #modalCedulaExistente .modal-header {
            background-color: #191970;
            color: #ffffff;
        }
        #modalCedulaExistente .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
        #modalCedulaExistente .modal-title i {
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="top-bar-public">
        <div class="logo-container-top">
            <a href="login.php" title="Ir a Login">
                <img src="imagenes/logo.png" alt="Logo Empresa">
            </a>
        </div>
    </div>

    <main class="main-content-wrapper">
        <div class="registro-container">
            <div class="registro-header">
                <h3 class="page-header-title">Registro de Nueva Cuenta</h3>
                <p class="text-center text-muted mb-4">Crea tu cuenta para poder registrar activos en el sistema.</p>
            </div>

            <?php if ($conexion_error_msg): ?>
                <div class="alert alert-danger"><?= $conexion_error_msg ?></div>
            <?php endif; ?>
            <?php if ($mensaje_exito_registro): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($mensaje_exito_registro) ?> <a href="login.php" class="alert-link fw-bold ms-2">Iniciar Sesión</a>.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($error_general_registro): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error_general_registro) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!$mensaje_exito_registro): // Solo mostrar formulario si no hay mensaje de éxito ?>
            <form action="guardar_usuario.php" method="post" id="formRegistrarUsuario">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="cedula" class="form-label">Cédula (Será tu usuario) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="cedula" name="cedula" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="nombre_completo" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nombre_completo" name="nombre_completo" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="contrasena" class="form-label">Contraseña <span class="text-danger">*</span></label>
                        <input type="password" class="form-control form-control-sm" id="contrasena" name="contrasena" required minlength="6">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="confirmar_contrasena" class="form-label">Confirmar Contraseña <span class="text-danger">*</span></label>
                        <input type="password" class="form-control form-control-sm" id="confirmar_contrasena" name="confirmar_contrasena" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="id_cargo" class="form-label">Tu Cargo <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" id="id_cargo" name="id_cargo" required>
                        <option value="">Seleccione un cargo...</option>
                        <?php if (!empty($cargos_disponibles)): ?>
                            <?php foreach ($cargos_disponibles as $cargo): ?>
                                <option value="<?= htmlspecialchars($cargo['id_cargo']) ?>">
                                    <?= htmlspecialchars(formatoTitulo($cargo['nombre_cargo'])) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>No hay cargos disponibles</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="empresa_usuario" class="form-label">Empresa <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="empresa_usuario" name="empresa_usuario" required>
                            <option value="">Seleccione...</option>
                            <?php foreach ($empresas_usuarios as $e): ?>
                                <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="regional_usuario" class="form-label">Regional <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="regional_usuario" name="regional_usuario" required>
                            <option value="">Seleccione...</option>
                            <?php foreach ($regionales_usuarios as $r): ?>
                                <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <input type="hidden" name="rol_usuario" value="<?= htmlspecialchars($rol_fijo_para_registro) ?>">
                <input type="hidden" name="origen_registro" value="publico">


                <div class="d-grid gap-2 mt-4">
                    <button class="btn btn-primary btn-login" type="submit" name="registrar_submit_publicot">Registrarse</button>
                </div>
                <div class="text-center mt-3">
                    <a href="login.php">¿Ya tienes cuenta? Inicia Sesión</a>
                </div>
            </form>
            <?php endif; // Fin de if (!$mensaje_exito_registro) ?>
        </div>
    </main>

    {/* Modal para Cédula Existente */}
    <div class="modal fade" id="modalCedulaExistente" tabindex="-1" aria-labelledby="modalCedulaExistenteLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCedulaExistenteLabel"><i class="bi bi-exclamation-triangle-fill"></i> Cédula Ya Registrada</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="mensajeModalCedulaExistente"></p>
                    <p>Por favor, intente <a href="login.php" class="alert-link">iniciar sesión</a> o utilice una cédula diferente.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendido</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const password = document.getElementById("contrasena");
        const confirm_password = document.getElementById("confirmar_contrasena");

        function validatePassword() {
            if (password && confirm_password && password.value != confirm_password.value) {
                confirm_password.setCustomValidity("Las contraseñas no coinciden.");
            } else if (confirm_password) {
                confirm_password.setCustomValidity('');
            }
        }
        if (password) password.onchange = validatePassword;
        if (confirm_password) confirm_password.onkeyup = validatePassword;

        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($mostrar_modal_cedula_existente && !empty($mensaje_para_modal)): ?>
                const modalCedulaElement = document.getElementById('modalCedulaExistente');
                if (modalCedulaElement) {
                    const modalCedula = new bootstrap.Modal(modalCedulaElement);
                    const mensajeModalP = document.getElementById('mensajeModalCedulaExistente');
                    if (mensajeModalP) {
                        mensajeModalP.textContent = <?= json_encode($mensaje_para_modal) ?>;
                    }
                    modalCedula.show();
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>
