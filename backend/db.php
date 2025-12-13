<?php
// =================================================================================
// ARCHIVO: backend/db.php
// CONFIGURACIÓN PARA LOCALHOST (XAMPP)
// =================================================================================

$servidor = "localhost";
$usuario  = "root";      // Usuario por defecto en XAMPP
$password = "@Ap200905";          // Contraseña actualizada por el usuario
$base_datos = "inventario_tecnologia"; // Asegúrate que este nombre sea exacto

// Crear conexión
$conexion = new mysqli($servidor, $usuario, $password, $base_datos);

// Verificar conexión
if ($conexion->connect_error) {
    // Si falla, mostramos el error para depurar rápido en local
    die("Fallo crítico de conexión: " . $conexion->connect_error);
}

// Configurar caracteres a UTF-8 (Vital para tildes y ñ)
$conexion->set_charset("utf8mb4");

// Variable de compatibilidad por si usas $conn en otros archivos viejos
$conn = $conexion;
?>