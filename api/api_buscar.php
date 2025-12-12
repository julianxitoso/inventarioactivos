<?php
// api/api_buscar.php
header('Content-Type: application/json');

// Se usa __DIR__ para asegurar que la ruta sea correcta sin importar cómo se llame al script.
require_once __DIR__ . '/../backend/auth_check.php';
// La restricción de página es correcta, solo roles autorizados pueden usar la API.
restringir_acceso_pagina(['admin', 'tecnico', 'auditor', 'registrador']);

require_once __DIR__ . '/../backend/db.php';

// Verificación de conexión
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || $conexion === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión a la base de datos desde la API.']);
    exit;
}
$conexion->set_charset("utf8mb4");

// --- RECOLECCIÓN DE TODOS LOS FILTROS ---
$q = trim($_GET['q'] ?? ''); // Búsqueda general
$tipo_activo = trim($_GET['tipo_activo'] ?? '');
$estado = trim($_GET['estado'] ?? '');
$regional = trim($_GET['regional'] ?? '');
$empresa = trim($_GET['empresa'] ?? '');
$fecha_desde = trim($_GET['fecha_desde'] ?? '');
$fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
$incluir_bajas = isset($_GET['incluir_bajas']) && $_GET['incluir_bajas'] === '1';

// ===== NUEVO: Capturar el filtro de tipo de equipo =====
$tipo_equipo = trim($_GET['tipo_equipo'] ?? '');

if (empty($q) && empty($tipo_activo) && empty($estado) && empty($regional) && empty($empresa) && empty($fecha_desde) && empty($fecha_hasta) && empty($tipo_equipo)) {
    // Si no se proporcionó ningún filtro, devolvemos un array JSON vacío y terminamos el script.
    echo json_encode([]);
    exit;
}

// --- CONSTRUCCIÓN DE LA CONSULTA DINÁMICA Y SEGURA ---
$sql = "SELECT 
            a.*, 
            u.usuario AS cedula_responsable,
            u.nombre_completo AS nombre_responsable,
            c.nombre_cargo AS cargo_responsable,
            u.regional AS regional_responsable,
            u.empresa AS empresa_del_responsable,
            ta.nombre_tipo_activo,
            ta.vida_util_sugerida
        FROM 
            activos_tecnologicos a
        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
        WHERE 1=1";

$params = [];
$types = '';
$condiciones = [];

$rol_usuario_actual = $_SESSION['rol_usuario'] ?? null;
$id_usuario_logueado = $_SESSION['usuario_id'] ?? null;

if ($rol_usuario_actual !== 'admin' && $rol_usuario_actual !== 'tecnico') {
    if ($id_usuario_logueado) {
        $condiciones[] = "a.id_usuario_responsable = ?";
        $params[] = $id_usuario_logueado;
        $types .= 'i';
    } else {
        echo json_encode([]);
        exit;
    }
}

if (!$incluir_bajas) {
    $condiciones[] = "a.estado != 'Dado de Baja'";
}
if (!empty($q)) {
    $condiciones[] = "(u.usuario LIKE ? OR u.nombre_completo LIKE ? OR a.serie LIKE ? OR a.Codigo_Inv LIKE ?)";
    $searchTerm = "%{$q}%";
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    $types .= 'ssss';
}
if (!empty($tipo_activo)) {
    $condiciones[] = "a.id_tipo_activo = ?";
    $params[] = $tipo_activo;
    $types .= 'i';
}
// ===== NUEVO: Añadir la condición del tipo de equipo a la consulta =====
if (!empty($tipo_equipo)) {
    $condiciones[] = "a.tipo_equipo = ?";
    $params[] = $tipo_equipo;
    $types .= 's';
}
if (!empty($estado)) {
    $condiciones[] = "a.estado = ?";
    $params[] = $estado;
    $types .= 's';
}
if (!empty($regional)) {
    $condiciones[] = "u.regional = ?";
    $params[] = $regional;
    $types .= 's';
}
if (!empty($empresa)) {
    $condiciones[] = "u.empresa = ?";
    $params[] = $empresa;
    $types .= 's';
}
if (!empty($fecha_desde)) {
    $condiciones[] = "a.fecha_compra >= ?";
    $params[] = $fecha_desde;
    $types .= 's';
}
if (!empty($fecha_hasta)) {
    $condiciones[] = "a.fecha_compra <= ?";
    $params[] = $fecha_hasta;
    $types .= 's';
}

if (count($condiciones) > 0) {
    $sql .= " AND " . implode(" AND ", $condiciones);
}

$sql .= " ORDER BY u.nombre_completo ASC, u.usuario ASC, a.id ASC";


$stmt = $conexion->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if ($stmt->execute()) {
        $resultado = $stmt->get_result();
        $activos = $resultado->fetch_all(MYSQLI_ASSOC);
        echo json_encode($activos);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al ejecutar la búsqueda.', 'sql_error' => $stmt->error]);
    }
    $stmt->close();
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Error al preparar la consulta.', 'sql_error' => $conexion->error]);
}

$conexion->close();
?>