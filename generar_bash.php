<?php
// ESTE SCRIPT ES PARA USARSE UNA SOLA VEZ.
// BORRARLO DEL SERVIDOR DESPUÉS DE USARLO.

header('Content-Type: text/html; charset=utf-8');

// --- 1. CONFIGURACIÓN DE LA BASE DE DATOS ---
// ¡¡¡IMPORTANTE: RELLENA ESTOS DATOS CON LOS DE TU SERVIDOR!!!
$servidor_db = "inventarioti.electrocreditosdelcauca.com";
$usuario_db  = "electroc_webmast"; // ej: 'root' o el usuario que creaste
$clave_db    = "4Rxf1vNLYW_w";
$nombre_db   = "electroc_inventario";

// --- 2. CONEXIÓN DIRECTA Y GARANTIZADA ---
$conexion = new mysqli($servidor_db, $usuario_db, $clave_db, $nombre_db);

// Verificar conexión
if ($conexion->connect_error) {
    die("Falló la conexión a la base de datos: " . $conexion->connect_error);
}
$conexion->set_charset("utf8mb4");

// --- 3. LÓGICA DE ACTUALIZACIÓN ---
$nueva_clave_para_todos = '123456';
$nuevo_hash_para_todos = password_hash($nueva_clave_para_todos, PASSWORD_DEFAULT);

echo "<h1>Actualizador Final de Contraseñas</h1>";
echo "<p>Se intentará conectar a la base de datos '<strong>" . htmlspecialchars($nombre_db) . "</strong>' en '<strong>" . htmlspecialchars($servidor_db) . "</strong>'.</p>";
echo "<p>Se usará la siguiente contraseña para todos los usuarios: <strong>" . htmlspecialchars($nueva_clave_para_todos) . "</strong></p>";
echo "<p>El nuevo hash que se guardará es: <br><code>" . htmlspecialchars($nuevo_hash_para_todos) . "</code></p><hr>";

// Preparamos la consulta para actualizar TODOS los usuarios
$sql = "UPDATE usuarios SET clave = ?";
$stmt = $conexion->prepare($sql);

if ($stmt === false) {
    die("Error al preparar la consulta: " . htmlspecialchars($conexion->error));
}

$stmt->bind_param("s", $nuevo_hash_para_todos);

if ($stmt->execute()) {
    $filas_afectadas = $stmt->affected_rows;
    echo "<h2>¡ÉXITO!</h2>";
    echo "<p style='color:green;'>Se han actualizado las contraseñas para <strong>" . $filas_afectadas . "</strong> usuarios.</p>";
    echo "<p>Ahora todos los usuarios pueden iniciar sesión con la contraseña: <strong>123456</strong></p>";
} else {
    echo "<h2>¡ERROR!</h2>";
    echo "<p style='color:red;'>No se pudo ejecutar la actualización: " . htmlspecialchars($stmt->error) . "</p>";
}

$stmt->close();
$conexion->close();

echo "<hr>";
echo "<h3 style='color:red;'>¡MUY IMPORTANTE!</h3>";
echo "<p>Ahora que el proceso ha terminado, por favor <strong>BORRA ESTE ARCHIVO (actualizar_claves_final.php)</strong> de tu servidor por seguridad.</p>";

?>