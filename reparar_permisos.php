<?php
// ARCHIVO: reparar_permisos.php
require_once 'backend/db.php';
session_start();

if (!isset($conexion)) $conexion = $conn;

echo "<h2>🔧 Diagnóstico y Reparación de Permisos</h2>";

// 1. Verificar el Rol en la Sesión
$rol_sesion = $_SESSION['rol_usuario'] ?? 'NULO';
echo "Rol en sesión: <strong>$rol_sesion</strong><br>";

if ($rol_sesion === 'NULO') {
    die("<h3 style='color:red'>Error: Inicia sesión primero para obtener el nombre del rol.</h3>");
}

// 2. Buscar el ID exacto de este rol en la BD
$sql = "SELECT id_rol, nombre_rol FROM roles WHERE nombre_rol = ?";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("s", $rol_sesion);
$stmt->execute();
$res = $stmt->get_result();
$rol_data = $res->fetch_assoc();

if (!$rol_data) {
    die("<h3 style='color:red'>ERROR GRAVE: El rol '$rol_sesion' NO EXISTE en la tabla 'roles'. <br>Solución: Crea el rol en la base de datos o corrige el usuario.</h3>");
}

$id_rol = $rol_data['id_rol'];
echo "✅ Rol encontrado en BD. ID: <strong>$id_rol</strong><br>";

// 3. Verificar cuántos permisos tiene asignados
$sql_check = "SELECT COUNT(*) as total FROM rol_permisos WHERE id_rol = $id_rol";
$total = $conexion->query($sql_check)->fetch_assoc()['total'];

echo "Estado actual: El rol tiene <strong>$total</strong> permisos asignados.<br>";

// 4. REPARACIÓN AUTOMÁTICA
if ($total == 0) {
    echo "<hr><h3>⚠️ Detectado rol vacío. Iniciando reparación...</h3>";
    
    // Inyectar todos los permisos disponibles a este rol
    $sql_repair = "INSERT INTO rol_permisos (id_rol, id_permiso) SELECT $id_rol, id FROM permisos";
    
    if ($conexion->query($sql_repair)) {
        echo "<h3 style='color:green'>¡ÉXITO! Se han asignado todos los permisos al rol '$rol_sesion'.</h3>";
        echo "Por favor, <strong>Cierra Sesión</strong> y vuelve a entrar para ver el menú.";
    } else {
        echo "<h3 style='color:red'>Error al reparar: " . $conexion->error . "</h3>";
    }
} else {
    echo "<hr><h3 style='color:green'>✅ El rol ya tiene permisos.</h3>";
    echo "Si sigues viendo 'VACÍO' en el menú, el problema podría ser el archivo <code>auth_check.php</code>.";
}
?>