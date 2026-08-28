<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../backend/auth_check.php';
    restringir_acceso_pagina(['admin', 'auditor', 'registrador', 'tecnico']);

    require_once __DIR__ . '/../backend/db.php';

    if (!isset($conexion) || $conexion->connect_error) {
        throw new Exception("Fallo la conexión a la base de datos.");
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

    // --- Construcción de la consulta (Con la tabla Cargos restaurada y sin Joins extra) ---
    $sql = "SELECT 
                a.id, a.serie, a.marca, a.estado, a.valor_aproximado, a.valor_residual, 
                a.fecha_compra, a.metodo_depreciacion, a.detalles, a.vida_util, a.Codigo_Inv,
                u.nombre_completo AS nombre_responsable,
                u.usuario AS cedula_responsable,
                c.nombre_cargo AS cargo_responsable,
                ta.nombre_tipo_activo AS nombre_tipo_activo
            FROM activos_tecnologicos a
            LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
            LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
            LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
            WHERE a.estado != 'Dado de Baja'";

    $params = [];
    $types = '';
    $condiciones = [];

    $rol_usuario_actual = $_SESSION['rol_usuario'] ?? null;
    $id_usuario_logueado = $_SESSION['usuario_id'] ?? null;

    if ($rol_usuario_actual !== 'admin' && $rol_usuario_actual !== 'auditor') {
        $condiciones[] = "a.id_usuario_responsable = ?";
        $params[] = $id_usuario_logueado;
        $types .= 'i';
    }

    if (!empty($q)) {
        $condiciones[] = "(a.serie LIKE ? OR a.Codigo_Inv LIKE ? OR u.usuario = ? OR u.nombre_completo LIKE ?)";
        $searchTerm = "%{$q}%";
        array_push($params, $searchTerm, $searchTerm, $q, $searchTerm);
        $types .= 'ssss';
    }
    
    // Filtros originales tal cual los tenías
    if (!empty($tipo_activo)) { $condiciones[] = "a.id_tipo_activo = ?"; $params[] = $tipo_activo; $types .= 'i'; }
    if (!empty($regional)) { $condiciones[] = "u.regional = ?"; $params[] = $regional; $types .= 's'; }
    if (!empty($empresa)) { $condiciones[] = "u.empresa = ?"; $params[] = $empresa; $types .= 's'; }
    if (!empty($fecha_desde)) { $condiciones[] = "a.fecha_compra >= ?"; $params[] = $fecha_desde; $types .= 's'; }
    if (!empty($fecha_hasta)) { $condiciones[] = "a.fecha_compra <= ?"; $params[] = $fecha_hasta; $types .= 's'; }

    // === REGLA MATEMÁTICA FINANCIERA EXACTA ===
    if (!empty($estado_depreciacion)) {
        $fechaFinVidaUtilSQL = "DATE_ADD(a.fecha_compra, INTERVAL a.vida_util YEAR)";
        $esDepreciableSQL = " (a.valor_aproximado > 0 AND a.fecha_compra IS NOT NULL AND a.fecha_compra > '1990-01-01' AND a.vida_util > 0) ";
        
        switch ($estado_depreciacion) {
            case 'depreciado': 
                $condiciones[] = $esDepreciableSQL;
                $condiciones[] = "$fechaFinVidaUtilSQL <= '2025-12-31'";
                break;
            case 'en_curso': 
                $condiciones[] = $esDepreciableSQL;
                $condiciones[] = "$fechaFinVidaUtilSQL > '2025-12-31'";
                break;
            case 'no_aplica': 
                $condiciones[] = " (a.valor_aproximado <= 0 OR a.fecha_compra IS NULL OR a.fecha_compra <= '1990-01-01' OR IFNULL(a.vida_util, 0) <= 0) ";
                break;
        }
    }

    if (count($condiciones) > 0) {
        $sql .= " AND " . implode(" AND ", $condiciones);
    }
    $sql .= " ORDER BY a.id DESC";

    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error SQL: " . $conexion->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception("Fallo al ejecutar búsqueda: " . $stmt->error);
    }

    $resultado = $stmt->get_result();
    $activos = $resultado->fetch_all(MYSQLI_ASSOC);

    ob_end_clean();
    echo json_encode($activos);

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>