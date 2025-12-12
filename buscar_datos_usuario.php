<?php
// =================================================================================
// ARCHIVO: buscar_datos_usuario.php
// UBICACIÓN: Raíz (misma carpeta que index.php)
// =================================================================================

ob_start();
require_once 'backend/db.php'; 
ob_clean();

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($conexion) || $conexion->connect_error) {
    echo json_encode(['encontrado' => false, 'error' => 'Error BD']);
    exit;
}

$conexion->set_charset("utf8mb4");
$cedula = $_GET['cedula'] ?? '';

if (!empty($cedula)) {
    // Consulta híbrida: Trae texto plano Y relaciones si existen
    $sql = "SELECT 
                u.nombre_completo, 
                c.nombre_cargo, 
                u.empresa, 
                u.regional AS regional_texto, 
                u.id_centro_costo,
                u.aplicaciones_usadas,
                
                -- Intentamos obtener el ID de la regional a través del centro de costo si existe
                cc.id_regional AS id_regional_relacional
            FROM usuarios u
            LEFT JOIN cargos c ON u.id_cargo = c.id_cargo 
            LEFT JOIN centros_costo cc ON u.id_centro_costo = cc.id_centro_costo
            WHERE u.usuario = ? LIMIT 1";

    $stmt = $conexion->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("s", $cedula);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($fila = $resultado->fetch_assoc()) {
            echo json_encode([
                'encontrado' => true,
                'nombre_completo' => mb_convert_encoding($fila['nombre_completo'] ?? '', 'UTF-8', 'UTF-8'),
                'cargo' => mb_convert_encoding($fila['nombre_cargo'] ?? '', 'UTF-8', 'UTF-8'),
                
                // Texto tal cual está en la BD (ej: "Arpesod")
                'empresa_texto' => mb_convert_encoding($fila['empresa'] ?? '', 'UTF-8', 'UTF-8'),
                
                // Texto tal cual está en la BD (ej: "NACIONAL")
                'regional_texto' => mb_convert_encoding($fila['regional_texto'] ?? '', 'UTF-8', 'UTF-8'),
                
                // IDs numéricos para selección precisa
                'id_regional' => $fila['id_regional_relacional'], 
                'id_centro_costo' => $fila['id_centro_costo'],
                
                'aplicaciones_usadas' => mb_convert_encoding($fila['aplicaciones_usadas'] ?? '', 'UTF-8', 'UTF-8')
            ]);
        } else {
            echo json_encode(['encontrado' => false]);
        }
        $stmt->close();
    } else {
        echo json_encode(['encontrado' => false, 'error' => 'Error consulta']);
    }
} else {
    echo json_encode(['encontrado' => false]);
}
$conexion->close();
?>