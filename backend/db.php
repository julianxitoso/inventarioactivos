<?php
// =================================================================================
// ARCHIVO: backend/db.php
// DESCRIPCIÓN: Conexión directa a BD (Sin depender de Dotenv/Composer)
// =================================================================================

// Configuración de credenciales para XAMPP
$servername = "localhost";
$username = "root";
$password = ""; // En XAMPP por defecto suele ser vacío
$dbname = "inventario_tecnologia"; // <--- OJO: VERIFICA SI TU BASE DE DATOS SE LLAMA ASÍ O "helpdeskdb"

// Crear conexión
$conexion = new mysqli($servername, $username, $password, $dbname);
$conn = $conexion; // Alias por compatibilidad con código antiguo

// Verificar conexión
if ($conexion->connect_error) {
    die("Fallo crítico de conexión: " . $conexion->connect_error);
}

// Configurar charset para evitar problemas con tildes y ñ
$conexion->set_charset("utf8mb4");

// Si necesitas la fecha y hora de Colombia
date_default_timezone_set('America/Bogota');
?>