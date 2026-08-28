<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../backend/auth_check.php';
restringir_acceso_pagina(['admin', 'auditor', 'registrador', 'tecnico']);

require_once __DIR__ . '/../backend/db.php';

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || $conexion === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión a la base de datos desde la API.']);
    exit;
}
$conexion->set_charset("utf8mb4");

// --- Recolección de filtros ---
$q = trim($_GET['q'] ?? '');
$tipo_activo = trim($_GET['tipo_activo'] ?? '');
$regional = trim($_GET['regional'] ?? '');
$empresa = trim($_GET['empresa'] ?? '');
$fecha_desde = trim($_GET['fecha_desde'] ?? '');
$fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
$estado_depreciacion = trim($_GET['estado_depreciacion'] ?? '');

// --- Construcción de la consulta ---
// CORRECCIÓN: Se trae a.vida_util en lugar de ta.vida_util_sugerida
$sql = "SELECT 
            a.id, a.serie, a.marca, a.estado, a.valor_aproximado, a.valor_residual, 
            a.fecha_compra, a.metodo_depreciacion, a.detalles, a.vida_util,
            u.nombre_completo AS nombre_responsable,
            u.usuario AS cedula_responsable,
            c.nombre_cargo AS cargo_responsable,
            ta.nombre_tipo_activo AS nombre_tipo_activo
        FROM 
            activos_tecnologicos a
        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
        WHERE 
            a.estado != 'Dado de Baja'";

$params = [];
$types = '';
$condiciones = [];

// Lógica de Permisos de Visualización por Rol
$rol_usuario_actual = $_SESSION['rol_usuario'] ?? null;
$id_usuario_logueado = $_SESSION['usuario_id'] ?? null;

if ($rol_usuario_actual !== 'admin' && $rol_usuario_actual !== 'auditor') {
    if ($id_usuario_logueado) {
        $condiciones[] = "a.id_usuario_responsable = ?";
        $params[] = $id_usuario_logueado;
        $types .= 'i';
    } else {
        echo json_encode([]);
        exit;
    }
}

// Filtros generales
if (!empty($q)) {
    $condiciones[] = "(a.serie LIKE ? OR a.Codigo_Inv LIKE ? OR u.usuario = ? OR u.nombre_completo LIKE ?)";
    $searchTerm = "%{$q}%";
    array_push($params, $searchTerm, $searchTerm, $q, $searchTerm);
    $types .= 'ssss';
}
if (!empty($tipo_activo)) { $condiciones[] = "a.id_tipo_activo = ?"; $params[] = $tipo_activo; $types .= 'i'; }
if (!empty($regional)) { $condiciones[] = "u.regional = ?"; $params[] = $regional; $types .= 's'; }
if (!empty($empresa)) { $condiciones[] = "u.empresa = ?"; $params[] = $empresa; $types .= 's'; }
if (!empty($fecha_desde)) { $condiciones[] = "a.fecha_compra >= ?"; $params[] = $fecha_desde; $types .= 's'; }
if (!empty($fecha_hasta)) { $condiciones[] = "a.fecha_compra <= ?"; $params[] = $fecha_hasta; $types .= 's'; }

// === FILTROS DE DEPRECIACIÓN SIN TOPES (Ajustado a fórmula financiera) ===
if (!empty($estado_depreciacion)) {
    
    // Fecha final: Fecha de compra + los años de vida_util
    $fechaFinVidaUtilSQL = "DATE_ADD(a.fecha_compra, INTERVAL a.vida_util YEAR)";

    // Condición base: Debe tener valor, fecha válida y vida útil mayor a 0
    $esDepreciableSQL = " (a.valor_aproximado > 0 AND a.fecha_compra IS NOT NULL AND a.fecha_compra > '1990-01-01' AND a.vida_util > 0) ";
    
    switch ($estado_depreciacion) {
        case 'depreciado': // Totalmente depreciado (Ya se venció su fecha)
            $condiciones[] = $esDepreciableSQL;
            $condiciones[] = "$fechaFinVidaUtilSQL <= '2025-12-31'";
            break;

        case 'en_curso': // En curso (Su fecha de fin es posterior al corte)
            $condiciones[] = $esDepreciableSQL;
            $condiciones[] = "$fechaFinVidaUtilSQL > '2025-12-31'";
            break;
        
        case 'no_aplica': // No aplica (Le falta información clave o su valor es 0)
            $condiciones[] = " (a.valor_aproximado <= 0 OR a.fecha_compra IS NULL OR a.fecha_compra <= '1990-01-01' OR IFNULL(a.vida_util, 0) <= 0) ";
            break;
    }
}

if (count($condiciones) > 0) {
    $sql .= " AND " . implode(" AND ", $condiciones);
}

$sql .= " ORDER BY a.id DESC";

$stmt = $conexion->prepare($sql);
if ($stmt) {
    if (!empty($params) && !empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if ($stmt->execute()) {
        $resultado = $stmt->get_result();
        $activos = $resultado->fetch_all(MYSQLI_ASSOC);
        echo json_encode($activos);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al ejecutar la búsqueda.', 'sql_error' => $stmt->error, 'sql_query' => $sql]);
    }
    $stmt->close();
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Error al preparar la consulta.', 'sql_error' => $conexion->error, 'sql_query' => $sql]);
}
$conexion->close();
?>