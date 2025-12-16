<?php
require_once 'backend/db.php';
$conexion->set_charset("utf8mb4");
$res = $conexion->query("SELECT nombre_regional FROM regionales LIMIT 1");
if($res) {
    $row = $res->fetch_assoc();
    echo "Conexión OK. Dato de prueba: " . $row['nombre_regional'];
} else {
    echo "Error SQL: " . $conexion->error;
}
?>