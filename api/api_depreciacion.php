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

// --- Constantes para depreciación ---
define('VALOR_UVT_ACTUAL', 47065); // Valor UVT para 2024 (Ajustar anualmente si es necesario)
define('UMBRAL_UVT_DEPRECIACION', 50);
$umbral_valor_minimo_cop = VALOR_UVT_ACTUAL * UMBRAL_UVT_DEPRECIACION;

// --- Recolección de filtros ---
$q = trim($_GET['q'] ?? '');
$tipo_activo = trim($_GET['tipo_activo'] ?? '');
$regional = trim($_GET['regional'] ?? '');
$empresa = trim($_GET['empresa'] ?? '');
$fecha_desde = trim($_GET['fecha_desde'] ?? '');
$fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
$estado_depreciacion = trim($_GET['estado_depreciacion'] ?? '');

// --- Construcción de la consulta ---
$sql = "SELECT 
            a.id, a.serie, a.marca, a.estado, a.valor_aproximado, a.valor_residual, 
            a.fecha_compra, a.metodo_depreciacion, a.detalles, 
            u.nombre_completo AS nombre_responsable,
            u.usuario AS cedula_responsable,
            c.nombre_cargo AS cargo_responsable,
            ta.nombre_tipo_activo AS nombre_tipo_activo,
            ta.vida_util_sugerida AS vida_util_sugerida
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

// === INICIO DEL CAMBIO: Lógica de filtro de depreciación corregida ===
if (!empty($estado_depreciacion)) {
    
    // Subconsulta SQL para calcular dinámicamente la fecha de fin de vida útil.
    // NOTA: Esta lógica asume vida útil en AÑOS. Ajustar si se usan meses.
    // Se ha simplificado la lógica del CASE, asumiendo que la vida útil por defecto viene de la tabla tipos_activo.
    $fechaFinVidaUtilSQL = "DATE_ADD(a.fecha_compra, INTERVAL ta.vida_util_sugerida YEAR)";

    // Mapeo de los valores del filtro a los nombres que usas en la descripción
    // 'depreciado' -> Totalmente despreciable
    // 'proximo'    -> Próximo a vencer (6m)
    // 'en_curso'   -> En curso
    // 'no_aplica'  -> No aplica para depreciar

    // Condición base para que un activo sea considerado depreciable
    $esDepreciableSQL = " (a.valor_aproximado >= ? AND a.fecha_compra IS NOT NULL AND ta.vida_util_sugerida > 0) ";
    
    switch ($estado_depreciacion) {
        case 'depreciado': // 1. Totalmente despreciable
            $condiciones[] = $esDepreciableSQL;
            $params[] = $umbral_valor_minimo_cop;
            $types .= 'd';
            
            // CORRECCIÓN: Usa <= para incluir los que se deprecian HOY MISMO.
            $condiciones[] = "$fechaFinVidaUtilSQL <= CURDATE()";
            break;

        case 'proximo': // 2. Próximo a vencer (6m)
            $condiciones[] = $esDepreciableSQL;
            $params[] = $umbral_valor_minimo_cop;
            $types .= 'd';
            
            // CORRECCIÓN: Se define un rango estricto.
            // > CURDATE()       -> Excluye los que YA están depreciados.
            // <= ... 6 MONTH   -> Incluye los que vencen dentro de los próximos 6 meses.
            $condiciones[] = "($fechaFinVidaUtilSQL > CURDATE() AND $fechaFinVidaUtilSQL <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH))";
            break;

        case 'en_curso': // 3. En curso (y no próximo a vencer)
            $condiciones[] = $esDepreciableSQL;
            $params[] = $umbral_valor_minimo_cop;
            $types .= 'd';

            // LÓGICA: Su depreciación termina DESPUÉS de los próximos 6 meses.
            $condiciones[] = "$fechaFinVidaUtilSQL > DATE_ADD(CURDATE(), INTERVAL 6 MONTH)";
            break;
        
        case 'no_aplica': // 4. No aplica para depreciar
            // CORRECCIÓN: La condición es la INVERSA a "esDepreciable".
            // Usa OR porque basta con que una de las condiciones falle para que no aplique.
            $condiciones[] = " (a.valor_aproximado < ? OR a.fecha_compra IS NULL OR IFNULL(ta.vida_util_sugerida, 0) <= 0) ";
            $params[] = $umbral_valor_minimo_cop;
            $types .= 'd';
            break;
    }
}
// === FIN DEL CAMBIO ===

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