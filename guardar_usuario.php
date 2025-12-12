<?php
session_start();
// Se necesitan ambos para verificar la sesión y conectar a la BD
require_once 'backend/auth_check.php'; 
require_once 'backend/db.php';

// Redirigir si no es una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); 
    exit;
}

if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}

// --- 1. CAPTURAR DATOS DEL FORMULARIO ---
$cedula = trim($_POST['cedula'] ?? '');
$nombre_completo = trim($_POST['nombre_completo'] ?? '');
$contrasena = $_POST['contrasena'] ?? '';
$confirmar_contrasena = $_POST['confirmar_contrasena'] ?? '';
$cargo = trim($_POST['cargo'] ?? '');
$empresa = $_POST['empresa_usuario'] ?? '';
$regional = $_POST['regional_usuario'] ?? '';
$rol_desde_formulario = $_POST['rol_usuario'] ?? 'registrador'; // Rol enviado desde el form

// --- 2. VALIDACIONES ---
if (empty($cedula) || empty($nombre_completo) || empty($contrasena) || empty($cargo) || empty($empresa) || empty($regional)) {
    $_SESSION['error_form_usuario'] = "Todos los campos marcados con * son obligatorios.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'registro.php'));
    exit;
}
if ($contrasena !== $confirmar_contrasena) {
    $_SESSION['error_form_usuario'] = "Las contraseñas no coinciden.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'registro.php'));
    exit;
}
if (strlen($contrasena) < 6) { 
    $_SESSION['error_form_usuario'] = "La contraseña debe tener al menos 6 caracteres.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'registro.php'));
    exit;
}

// --- 3. LÓGICA DE ASIGNACIÓN DE ROL ---
$rol_final_asignado = 'registrador'; 

if (isset($_SESSION['usuario_login']) && obtener_rol_usuario() === 'admin') {
    $rol_final_asignado = $rol_desde_formulario;
}

// --- 4. PROCESAMIENTO EN BASE DE DATOS ---
$stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
$stmt_check->bind_param('s', $cedula);
$stmt_check->execute();
$stmt_check->store_result();

if ($stmt_check->num_rows > 0) {
    // ESTE ES EL MENSAJE QUE registro.php BUSCARÁ PARA EL MODAL
    $_SESSION['error_form_usuario'] = "El usuario (cédula) '" . htmlspecialchars($cedula) . "' ya existe. Intente iniciar sesión o use otra cédula.";
    $stmt_check->close();
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'registro.php'));
    exit;
}
$stmt_check->close();

$contrasena_hashed = password_hash($contrasena, PASSWORD_DEFAULT);
$sql_insert = "INSERT INTO usuarios (usuario, clave, nombre_completo, cargo, empresa, regional, rol, activo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
$stmt_insert = $conexion->prepare($sql_insert);
$stmt_insert->bind_param('sssssss', $cedula, $contrasena_hashed, $nombre_completo, $cargo, $empresa, $regional, $rol_final_asignado);

if ($stmt_insert->execute()) {
    // Si la creación fue exitosa, decidimos a dónde redirigir.
    if (isset($_SESSION['usuario_login']) && obtener_rol_usuario() === 'admin') { // Asegurarnos que 'admin' es quien está en sesión
        $_SESSION['mensaje_creacion_usuario'] = "¡Usuario '" . htmlspecialchars($nombre_completo) . "' creado con el rol de '" . htmlspecialchars($rol_final_asignado) . "'!";
        header("Location: crear_usuario.php"); // <<< CORRECCIÓN: Redirigir a crear_usuario.php
    } else {
        $_SESSION['mensaje_login'] = "¡Registro exitoso! Ya puedes iniciar sesión con tu cédula y contraseña.";
        header("Location: login.php");
    }
} else {
    $_SESSION['error_form_usuario'] = "Error al guardar el usuario: " . $stmt_insert->error;
    // Si viene de crear_usuario.php (admin), redirige allí, sino a registro.php
    $referer_page = (isset($_SESSION['usuario_login']) && obtener_rol_usuario() === 'admin') ? 'crear_usuario.php' : 'registro.php';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? $referer_page));
}

$stmt_insert->close();
$conexion->close();
exit;
?>