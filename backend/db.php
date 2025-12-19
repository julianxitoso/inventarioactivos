<?php
// =================================================================================
// ARCHIVO: backend/db.php
// DESCRIPCIÓN: Conexión directa a BD (Sin depender de Dotenv/Composer)
// =================================================================================

// Cargar el autoloader de Composer
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Cargar variables de entorno desde el directorio raíz
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

// Configuración de credenciales desde .env
$servername = $_ENV['DB_HOST'] ?? 'localhost';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';
$dbname = $_ENV['DB_DATABASE'] ?? 'inventario_tecnologia';

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
