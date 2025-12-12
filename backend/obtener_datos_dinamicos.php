<?php
// ARCHIVO: backend/obtener_datos_dinamicos.php

ob_start();
require_once 'db.php'; 
ob_clean(); 

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }

if (!isset($conexion) || $conexion === null || $conexion->connect_error) {
    echo json_encode(['error' => 'Error crítico de conexión BD en backend']);
    exit;
}

$conexion->set_charset("utf8mb4");
$accion = $_GET['accion'] ?? '';

if ($accion === 'obtener_centros_costo_por_regional') {
    $id = isset($_GET['id_regional']) ? (int)$_GET['id_regional'] : 0;
    
    if ($id > 0) {
        $stmt = $conexion->prepare("SELECT id_centro_costo, cod_centro_costo, nombre_centro_costo FROM centros_costo WHERE id_regional = ? ORDER BY nombre_centro_costo ASC");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $data = [];
            while ($r = $res->fetch_assoc()) {
                $data[] = array_map(function($v){ 
                    return is_string($v) ? mb_convert_encoding($v, 'UTF-8', 'UTF-8') : $v; 
                }, $r);
            }
            echo json_encode($data);
            $stmt->close();
        } else {
            echo json_encode([]);
        }
    } else {
        echo json_encode([]);
    }
} 
elseif ($accion === 'obtener_tipos_por_categoria') {
    $id = isset($_GET['id_categoria']) ? (int)$_GET['id_categoria'] : 0;
    
    if ($id > 0) {
        $stmt = $conexion->prepare("SELECT nombre_tipo_activo, vida_util_sugerida FROM tipos_activo WHERE id_categoria = ? ORDER BY nombre_tipo_activo ASC");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $data = [];
            while ($r = $res->fetch_assoc()) {
                $data[] = array_map(function($v){ return is_string($v) ? mb_convert_encoding($v, 'UTF-8', 'UTF-8') : $v; }, $r);
            }
            echo json_encode($data);
            $stmt->close();
        } else {
            echo json_encode([]);
        }
    } else {
        echo json_encode([]);
    }
}
else {
    echo json_encode(['error' => 'Acción no válida']);
}

$conexion->close();
?>