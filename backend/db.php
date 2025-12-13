<?php
// =================================================================================
// ARCHIVO: backend/db.php
// CONFIGURACIÓN PARA LOCALHOST (XAMPP)
// =================================================================================

// Cargar autoloader si no se ha cargado (para uso independiente o legacy)
if (!class_exists('Dotenv\Dotenv')) {
    $autoload_path = dirname(__DIR__) . '/vendor/autoload.php';
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
    }
}

// Cargar variables de entorno
try {
    // Intentar cargar .env desde la raíz del proyecto (padre de backend)
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
} catch (Exception $e) {
    // Si falla (por ejemplo en producción si no hay .env y se usan vars de sistema), continuamos silenciosamente
    // o registramos error.
}

$servidor = $_ENV['DB_HOST'] ?? 'localhost';
$usuario  = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? '';
$base_datos = $_ENV['DB_NAME'] ?? 'inventario_tecnologia';

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
