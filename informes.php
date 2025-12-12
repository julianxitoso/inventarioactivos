<?php
// =================================================================================
// ARCHIVO: informes.php
// ESTADO: FINAL (Tablas Alineadas + Exportación Completa Restaurada)
// =================================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico', 'auditor']);

require_once __DIR__ . '/backend/db.php';

$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || !$conexion) {
    $conexion_error_msg = "Error crítico de conexión.";
} else {
    $conexion->set_charset("utf8mb4");
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';
$tipo_informe_seleccionado = $_GET['tipo_informe'] ?? 'seleccione';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$titulo_pagina_base = "Central de Informes";
$titulo_informe_actual = "";
$datos_para_tabla = [];
$columnas_tabla_html = [];

// --- DEFINICIÓN COMPLETA DE CAMPOS PARA EXPORTACIÓN EXCEL ---
$campos_exportables = [
    'Datos del Activo' => [
        'at.id' => 'ID Activo',
        'ta.nombre_tipo_activo' => 'Tipo de Activo',
        'at.marca' => 'Marca',
        'at.serie' => 'Serie',
        'at.Codigo_Inv' => 'Cód. Inventario',
        'at.estado' => 'Estado Actual',
        'at.valor_aproximado' => 'Valor Compra (Costo Histórico)', // Nombre más técnico
        'at.fecha_compra' => 'Fecha Compra',
        'at.detalles' => 'Detalles / Observaciones'
    ],
    'Ubicación y Responsable' => [
        'cc.nombre_centro_costo' => 'Centro de Costo (Ubicación)',
        'r.nombre_regional' => 'Regional (Ubicación)',
        'u.nombre_completo' => 'Nombre Responsable',
        'u.usuario' => 'Cédula Responsable',
        'c.nombre_cargo' => 'Cargo Responsable',
        'u.empresa' => 'Empresa Responsable'
    ],
    'Detalles Técnicos' => [
        'at.procesador' => 'Procesador',
        'at.ram' => 'Memoria RAM',
        'at.disco_duro' => 'Disco Duro',
        'at.sistema_operativo' => 'Sistema Operativo',
        'at.offimatica' => 'Offimática',
        'at.antivirus' => 'Antivirus',
        'at.tipo_equipo' => 'Tipo Específico'
    ],
    'Cálculo de Depreciación (NIIF/Fiscal)' => [
        'at.vida_util' => 'Vida Útil Base (Años)',
        'CALCULADO_VIDA_UTIL_FISCAL' => 'Vida Útil Fiscal Aplicada (Años)', // Nuevo
        'CALCULADO_SMMLV' => 'SMMLV Año Compra',                         // Nuevo
        'CALCULADO_MESES_USO' => 'Tiempo Transcurrido (Meses)',            // Nuevo
        'CALCULADO_MESES_RESTANTES' => 'Vida Útil Restante (Meses)',       // Nuevo
        'at.valor_residual' => 'Valor Residual',
        'CALCULADO_DEP_MENSUAL' => 'Depreciación Mensual',                 // Nuevo
        'CALCULADO_DEP_ACUMULADA' => 'Depreciación Acumulada',             // Nuevo
        'CALCULADO_VALOR_LIBROS' => 'Valor Neto en Libros'                 // Ya existía
    ],
    'Información de Préstamos' => [
        'p.id_prestamo' => 'ID Préstamo',
        '(SELECT nombre_completo FROM usuarios up WHERE up.id = p.id_usuario_presta)' => 'Prestado Por',
        'p.fecha_prestamo' => 'Fecha Préstamo',
        'p.fecha_devolucion_esperada' => 'Fecha Dev. Esperada',
        'p.estado_prestamo' => 'Estado del Préstamo'
    ]
];

// --- HELPERS ---
if (!function_exists('getEstadoBadgeClass')) {
    function getEstadoBadgeClass($estado) {
        $estadoLower = strtolower(trim($estado ?? ''));
        switch ($estadoLower) {
            case 'asignado': case 'activo': case 'operativo': case 'bueno': case 'nuevo': return 'badge bg-success';
            case 'en mantenimiento': case 'regular': case 'inicio mantenimiento': return 'badge bg-warning text-dark';
            case 'dado de baja': case 'inactivo': case 'malo': return 'badge bg-danger';
            case 'en préstamo': case 'prestado': return 'badge bg-primary';
            case 'fin mantenimiento': return 'badge bg-info text-dark';
            default: return 'badge bg-secondary';
        }
    }
}
if (!function_exists('displayStars')) {
    function displayStars($rating) {
        if ($rating === null || !is_numeric($rating)) return 'N/A';
        $output = "<span style='color: #f5b301; font-size: 1.1em;'>";
        for ($i = 1; $i <= 5; $i++) { $output .= ($rating >= $i) ? '★' : '☆'; }
        return $output . "</span>";
    }
}

// --- FILTROS FECHA ---
$condiciones_fecha_activo = ""; $condiciones_fecha_historial = ""; $condiciones_fecha_prestamo = "";
$params_fecha = []; $types_fecha = "";

if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    $fecha_hasta .= ' 23:59:59';
    $condiciones_fecha_activo = " AND at.fecha_compra BETWEEN ? AND ? ";
    $condiciones_fecha_historial = " AND h.fecha_evento BETWEEN ? AND ? ";
    $condiciones_fecha_prestamo = " AND p.fecha_prestamo BETWEEN ? AND ? ";
    $params_fecha = [$fecha_desde, $fecha_hasta];
    $types_fecha = "ss";
} elseif (!empty($fecha_desde)) {
    $condiciones_fecha_activo = " AND at.fecha_compra >= ? ";
    $condiciones_fecha_historial = " AND h.fecha_evento >= ? ";
    $condiciones_fecha_prestamo = " AND p.fecha_prestamo >= ? ";
    $params_fecha = [$fecha_desde];
    $types_fecha = "s";
}

// --- CONSULTAS SQL ---
if ($tipo_informe_seleccionado !== 'seleccione' && !$conexion_error_msg) {
    $query = "";
    $params_query = $params_fecha;
    $types_query = $types_fecha;

    $campos_base = "at.id, at.serie, at.marca, at.estado, at.valor_aproximado, at.fecha_compra, at.detalles,
                    ta.nombre_tipo_activo, u.nombre_completo AS nombre_responsable, u.usuario AS cedula_responsable,
                    u.empresa AS empresa_responsable, c.nombre_cargo AS cargo_responsable, u.aplicaciones_usadas,
                    cc.nombre_centro_costo, r.nombre_regional";

    $joins_base = "FROM activos_tecnologicos at
                   LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                   LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
                   LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
                   LEFT JOIN centros_costo cc ON at.id_centro_costo = cc.id_centro_costo
                   LEFT JOIN regionales r ON cc.id_regional = r.id_regional";

    switch ($tipo_informe_seleccionado) {
        case 'general':
            $titulo_informe_actual = "Informe General Completo";
            $query = "SELECT {$campos_base} {$joins_base} WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo} ORDER BY u.empresa, u.nombre_completo";
            $columnas_tabla_html = ["#", "Tipo", "Marca", "Serie", "Estado", "Valor", "Ubicación (CC)", "Fecha", "Apps", "Detalles"];
            break;

        case 'por_centro_costo':
            $titulo_informe_actual = "Informe por Centro de Costo";
            $query = "SELECT {$campos_base} {$joins_base} WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo} ORDER BY r.nombre_regional, cc.nombre_centro_costo";
            $columnas_tabla_html = ["#", "ID", "Tipo", "Marca", "Serie", "Responsable", "Estado", "Valor", "Fecha Compra"];
            break;

        case 'por_regional':
            $titulo_informe_actual = "Informe por Regional";
            $query = "SELECT {$campos_base} {$joins_base} WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo} ORDER BY r.nombre_regional";
            $columnas_tabla_html = ["#", "ID", "Tipo", "Marca", "Serie", "Centro Costo", "Responsable", "Estado", "Fecha Compra"];
            break;

        case 'por_tipo':
            $titulo_informe_actual = "Informe por Tipo";
            $query = "SELECT {$campos_base} {$joins_base} WHERE at.estado != 'Dado de Baja' {$condiciones_fecha_activo} ORDER BY ta.nombre_tipo_activo";
            $columnas_tabla_html = ["#", "ID", "Marca", "Serie", "Regional", "Centro Costo", "Responsable", "Estado", "Fecha Compra"];
            break;

        case 'por_empresa':
            $titulo_informe_actual = "Informe por Empresa";
            $query = "SELECT {$campos_base} {$joins_base} WHERE at.estado != 'Dado de Baja' AND u.empresa != '' {$condiciones_fecha_activo} ORDER BY u.empresa";
            $columnas_tabla_html = ["#", "ID", "Tipo", "Marca", "Serie", "Responsable", "Estado", "Valor"];
            break;

        case 'por_estado':
            $titulo_informe_actual = "Informe por Estado";
            $query = "SELECT {$campos_base} {$joins_base} WHERE 1=1 {$condiciones_fecha_activo} ORDER BY at.estado";
            $columnas_tabla_html = ["#", "ID", "Tipo", "Marca", "Serie", "Responsable", "Valor", "Fecha Compra"];
            break;

        case 'calificacion_por_tipo':
            $titulo_informe_actual = "Calificaciones";
            $query = "SELECT {$campos_base}, at.satisfaccion_rating {$joins_base} WHERE at.satisfaccion_rating IS NOT NULL {$condiciones_fecha_activo} ORDER BY at.satisfaccion_rating DESC";
            $columnas_tabla_html = ["Tipo", "Marca", "Serie", "Responsable", "Empresa", "Centro Costo", "Estado", "Fecha", "Calificación"];
            break;

        case 'dados_baja':
            $titulo_informe_actual = "Activos Dados de Baja";
            $query = "SELECT at.id, ta.nombre_tipo_activo, at.marca, at.serie, at.estado,
                             u.nombre_completo AS resp, cc.nombre_centro_costo, r.nombre_regional,
                             h_baja.fecha_evento AS fecha_baja, h_baja.descripcion_evento AS motivo
                      FROM activos_tecnologicos at
                      LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                      LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
                      LEFT JOIN centros_costo cc ON at.id_centro_costo = cc.id_centro_costo
                      LEFT JOIN regionales r ON cc.id_regional = r.id_regional
                      LEFT JOIN (
                          SELECT h1.id_activo, h1.descripcion_evento, h1.fecha_evento FROM historial_activos h1
                          JOIN (SELECT id_activo, MAX(id_historial) m FROM historial_activos WHERE tipo_evento='BAJA' GROUP BY id_activo) h2 ON h1.id_activo=h2.id_activo AND h1.id_historial=h2.m
                      ) h_baja ON at.id = h_baja.id_activo
                      WHERE at.estado = 'Dado de Baja' " . str_replace('h.fecha_evento', 'h_baja.fecha_evento', $condiciones_fecha_historial);
            break;

        case 'movimientos':
            $titulo_informe_actual = "Movimientos Recientes";
            $query = "SELECT h.fecha_evento, h.tipo_evento, h.descripcion_evento, h.usuario_responsable AS user_sis,
                             a.id AS id_activo, ta.nombre_tipo_activo, a.serie, a.marca,
                             u.nombre_completo AS resp_actual
                      FROM historial_activos h
                      JOIN activos_tecnologicos a ON h.id_activo = a.id
                      LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                      LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
                      WHERE h.tipo_evento IN ('TRASLADO','ASIGNACIÓN INICIAL','CREACIÓN','REACTIVACIÓN')
                      {$condiciones_fecha_historial} ORDER BY h.fecha_evento DESC LIMIT 100";
            break;

        case 'activos_con_mantenimientos':
            $titulo_informe_actual = "Historial Mantenimientos";
            $query = "SELECT h.fecha_evento, h.tipo_evento, h.descripcion_evento, h.usuario_responsable AS user_sis,
                             at.id, ta.nombre_tipo_activo, at.serie, at.estado, u.nombre_completo
                      FROM historial_activos h
                      JOIN activos_tecnologicos at ON h.id_activo = at.id
                      LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                      LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
                      WHERE h.tipo_evento IN ('Inicio Mantenimiento', 'Fin Mantenimiento') {$condiciones_fecha_historial} ORDER BY h.fecha_evento DESC";
            break;

        case 'activos_en_prestamo':
            $titulo_informe_actual = "Activos en Préstamo";
            $query = "SELECT p.id_prestamo, ta.nombre_tipo_activo, at.marca, at.serie, at.Codigo_Inv,
                             u1.nombre_completo AS presta, u2.nombre_completo AS recibe, c.nombre_cargo AS cargo_recibe,
                             p.fecha_prestamo, p.fecha_devolucion_esperada, p.estado_prestamo, p.observaciones_prestamo
                      FROM prestamos_activos p
                      JOIN activos_tecnologicos at ON p.id_activo = at.id
                      LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                      JOIN usuarios u1 ON p.id_usuario_presta = u1.id
                      JOIN usuarios u2 ON p.id_usuario_recibe = u2.id
                      LEFT JOIN cargos c ON u2.id_cargo = c.id_cargo
                      WHERE p.fecha_devolucion_real IS NULL {$condiciones_fecha_prestamo} ORDER BY p.fecha_prestamo DESC";
            break;
    }

    if (!empty($query)) {
        $stmt = $conexion->prepare($query);
        if ($stmt) {
            if (!empty($params_query)) $stmt->bind_param($types_query, ...$params_query);
            $stmt->execute();
            $datos_para_tabla = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo_pagina_base) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { padding-top: 80px; background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; background: #fff; border-bottom: 1px solid #dee2e6; padding: 0.5rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        .informe-card { cursor: pointer; transition: 0.2s; border: 1px solid #ddd; background: #fff; text-align: center; padding: 1.5rem; border-radius: 8px; height: 100%; display: flex; flex-direction: column; justify-content: center; text-decoration: none; color: #333; }
        .informe-card:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); border-color: #0d6efd; color: #0d6efd; }
        .table-minimalist { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .table-minimalist th { background: #343a40; color: #fff; padding: 10px; font-size: 0.9rem; white-space: nowrap; }
        .table-minimalist td { padding: 8px 10px; border-bottom: 1px solid #eee; font-size: 0.85rem; vertical-align: middle; }
        .user-info-header, .group-info-header { background: #e9ecef; padding: 10px 15px; border-radius: 6px; margin-bottom: 10px; border-left: 4px solid #191970; }
        .report-group-container { background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #eee; }
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div><a href="menu.php"><img src="imagenes/logo.png" height="75" alt="Logo"></a></div>
    <div>
        <span class="me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm">Salir</a>
    </div>
</div>

<div class="container mt-4 mb-5">
    <h3 class="text-center mb-4 text-primary"><?= htmlspecialchars($titulo_pagina_base) ?></h3>

    <div class="text-center mb-3">
        <a class="text-decoration-none fw-bold" data-bs-toggle="collapse" href="#filtros"><i class="bi bi-calendar3"></i> Filtros de Fecha</a>
    </div>
    <div class="collapse mb-4" id="filtros">
        <div class="card card-body bg-light border-0">
            <form method="GET">
                <input type="hidden" name="tipo_informe" value="<?= htmlspecialchars($tipo_informe_seleccionado) ?>">
                <div class="row justify-content-center g-2">
                    <div class="col-auto"><input type="date" class="form-control form-control-sm" name="fecha_desde" value="<?= $fecha_desde ?>"></div>
                    <div class="col-auto"><input type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?= $fecha_hasta ?>"></div>
                    <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm">Filtrar</button></div>
                    <div class="col-auto"><a href="informes.php?tipo_informe=<?= $tipo_informe_seleccionado ?>" class="btn btn-secondary btn-sm">Limpiar</a></div>
                </div>
            </form>
        </div>
    </div>

    <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
        <div class="col"><a href="?tipo_informe=general" class="informe-card"><i class="bi bi-grid-fill text-primary mb-2 fs-2"></i><h5>General</h5></a></div>
        <div class="col"><a href="?tipo_informe=por_centro_costo" class="informe-card"><i class="bi bi-building-fill-gear text-success mb-2 fs-2"></i><h5>Por Centro Costo</h5></a></div>
        <div class="col"><a href="?tipo_informe=por_regional" class="informe-card"><i class="bi bi-geo-alt-fill text-danger mb-2 fs-2"></i><h5>Por Regional</h5></a></div>
        <div class="col"><a href="?tipo_informe=por_tipo" class="informe-card"><i class="bi bi-laptop text-info mb-2 fs-2"></i><h5>Por Tipo</h5></a></div>
        <div class="col"><a href="?tipo_informe=por_empresa" class="informe-card"><i class="bi bi-buildings-fill text-dark mb-2 fs-2"></i><h5>Por Empresa</h5></a></div>
        <div class="col"><a href="?tipo_informe=movimientos" class="informe-card"><i class="bi bi-clock-history text-secondary mb-2 fs-2"></i><h5>Movimientos</h5></a></div>
        <div class="col"><a href="?tipo_informe=dados_baja" class="informe-card"><i class="bi bi-trash text-danger mb-2 fs-2"></i><h5>Bajas</h5></a></div>
        <div class="col"><a href="?tipo_informe=activos_en_prestamo" class="informe-card"><i class="bi bi-arrow-left-right text-info mb-2 fs-2"></i><h5>Préstamos</h5></a></div>
    </div>

    <?php if ($tipo_informe_seleccionado !== 'seleccione' && !empty($datos_para_tabla)): ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="m-0 text-primary"><i class="bi bi-table"></i> <?= $titulo_informe_actual ?></h5>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalExportarPersonalizado"><i class="bi bi-file-excel"></i> Exportar</button>
        </div>

        <?php 
        $count = 1;
        // BLOQUE DE TABLAS MANUALES
        
        if ($tipo_informe_seleccionado == 'general'):
            $current_group = null;
            foreach ($datos_para_tabla as $row):
                if ($row['cedula_responsable'] !== $current_group):
                    if ($current_group !== null) echo '</tbody></table></div></div>';
                    $current_group = $row['cedula_responsable'];
                    $count = 1;
                    echo '<div class="report-group-container">';
                    echo "<div class='user-info-header'><h4>{$row['nombre_responsable']}</h4><small>{$row['cargo_responsable']} | {$row['nombre_regional']} - {$row['nombre_centro_costo']}</small></div>";
                    echo '<div class="table-responsive"><table class="table-minimalist"><thead><tr><th>#</th><th>Tipo</th><th>Marca</th><th>Serie</th><th>Estado</th><th>Valor</th><th>Ubicación</th><th>Fecha</th><th>Detalles</th></tr></thead><tbody>';
                endif;
                ?>
                <tr>
                    <td><?= $count++ ?></td><td><?= $row['nombre_tipo_activo'] ?></td><td><?= $row['marca'] ?></td><td><?= $row['serie'] ?></td>
                    <td><span class="<?= getEstadoBadgeClass($row['estado']) ?>"><?= $row['estado'] ?></span></td>
                    <td>$<?= number_format((float)$row['valor_aproximado'], 0) ?></td><td><?= $row['nombre_centro_costo'] ?></td>
                    <td><?= $row['fecha_compra'] ?></td><td><small><?= $row['detalles'] ?></small></td>
                </tr>
                <?php endforeach; echo '</tbody></table></div></div>';

        elseif ($tipo_informe_seleccionado == 'por_tipo'):
            $current_group = null;
            foreach ($datos_para_tabla as $row):
                if ($row['nombre_tipo_activo'] !== $current_group):
                    if ($current_group !== null) echo '</tbody></table></div></div>';
                    $current_group = $row['nombre_tipo_activo'];
                    $count = 1;
                    echo '<div class="report-group-container"><div class="group-info-header"><h4>'.$current_group.'</h4></div>';
                    // TÍTULOS MANUALES PARA 'POR TIPO'
                    echo '<div class="table-responsive"><table class="table-minimalist"><thead><tr><th>#</th><th>ID</th><th>Marca</th><th>Serie</th><th>Regional</th><th>Centro Costo</th><th>Responsable</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>';
                endif;
                ?>
                <tr>
                    <td><?= $count++ ?></td><td><?= $row['id'] ?></td><td><?= $row['marca'] ?></td><td><?= $row['serie'] ?></td>
                    <td><?= $row['nombre_regional'] ?></td><td><?= $row['nombre_centro_costo'] ?></td>
                    <td><?= $row['nombre_responsable'] ?></td>
                    <td><span class="<?= getEstadoBadgeClass($row['estado']) ?>"><?= $row['estado'] ?></span></td>
                    <td><?= $row['fecha_compra'] ?></td>
                </tr>
                <?php endforeach; echo '</tbody></table></div></div>';

        elseif ($tipo_informe_seleccionado == 'movimientos'):
            // TÍTULOS MANUALES PARA 'MOVIMIENTOS'
            ?>
            <div class="report-group-container"><div class="table-responsive"><table class="table-minimalist">
                <thead><tr><th>Fecha</th><th>Evento</th><th>Tipo</th><th>Serie</th><th>Marca</th><th>Responsable</th><th>Descripción</th><th>Usuario Sis.</th><th>Ver</th></tr></thead>
                <tbody>
                <?php foreach ($datos_para_tabla as $row): ?>
                    <tr>
                        <td><?= $row['fecha_evento'] ?></td><td><?= $row['tipo_evento'] ?></td><td><?= $row['nombre_tipo_activo'] ?></td>
                        <td><?= $row['serie'] ?></td><td><?= $row['marca'] ?></td><td><?= $row['resp_actual'] ?></td>
                        <td><?= nl2br($row['descripcion_evento']) ?></td><td><?= $row['user_sis'] ?></td>
                        <td><a href="editar.php?id_activo_focus=<?= $row['id_activo'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">Ver</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div></div>
            <?php

        elseif ($tipo_informe_seleccionado == 'dados_baja'):
            // TÍTULOS MANUALES PARA 'BAJAS'
            ?>
            <div class="report-group-container"><div class="table-responsive"><table class="table-minimalist">
                <thead><tr><th>ID</th><th>Tipo</th><th>Marca</th><th>Serie</th><th>Ubicación</th><th>Últ. Responsable</th><th>Fecha Baja</th><th>Motivo</th><th>Acción</th></tr></thead>
                <tbody>
                <?php foreach ($datos_para_tabla as $row): ?>
                    <tr>
                        <td><?= $row['id'] ?></td><td><?= $row['nombre_tipo_activo'] ?></td><td><?= $row['marca'] ?></td><td><?= $row['serie'] ?></td>
                        <td><?= $row['nombre_centro_costo'] ?></td><td><?= $row['resp'] ?></td>
                        <td><?= $row['fecha_baja'] ?></td><td><?= nl2br($row['motivo']) ?></td>
                        <td><a href="historial.php?id_activo=<?= $row['id'] ?>" class="btn btn-sm btn-info" target="_blank">Historial</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div></div>
            <?php

        elseif ($tipo_informe_seleccionado == 'activos_en_prestamo'):
            ?>
            <div class="report-group-container"><div class="table-responsive"><table class="table-minimalist">
                <thead><tr><th>ID</th><th>Tipo</th><th>Marca</th><th>Serie</th><th>Prestado Por</th><th>Prestado A</th><th>Fecha Inicio</th><th>Fecha Fin</th><th>Estado</th><th>Obs.</th></tr></thead>
                <tbody>
                <?php foreach ($datos_para_tabla as $row): ?>
                    <tr>
                        <td><?= $row['id_prestamo'] ?></td><td><?= $row['nombre_tipo_activo'] ?></td><td><?= $row['marca'] ?></td><td><?= $row['serie'] ?></td>
                        <td><?= $row['presta'] ?></td><td><?= $row['recibe'] ?></td>
                        <td><?= $row['fecha_prestamo'] ?></td><td><?= $row['fecha_devolucion_esperada'] ?></td>
                        <td><span class="<?= getEstadoBadgeClass($row['estado_prestamo']) ?>"><?= $row['estado_prestamo'] ?></span></td>
                        <td><?= $row['observaciones_prestamo'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div></div>
            <?php

        else:
            $group_field = '';
            if($tipo_informe_seleccionado == 'por_regional') $group_field = 'nombre_regional';
            if($tipo_informe_seleccionado == 'por_centro_costo') $group_field = 'nombre_centro_costo';
            if($tipo_informe_seleccionado == 'por_empresa') $group_field = 'empresa_responsable';
            
            $current_group = null;
            foreach ($datos_para_tabla as $row):
                $val = $row[$group_field] ?? 'Sin Clasificar';
                if ($val !== $current_group):
                    if ($current_group !== null) echo '</tbody></table></div></div>';
                    $current_group = $val;
                    $count = 1;
                    echo '<div class="report-group-container"><div class="group-info-header"><h4>'.$current_group.'</h4></div>';
                    echo '<div class="table-responsive"><table class="table-minimalist"><thead><tr><th>#</th><th>ID</th><th>Tipo</th><th>Marca</th><th>Serie</th><th>Responsable</th><th>Estado</th><th>Valor</th></tr></thead><tbody>';
                endif;
                ?>
                <tr>
                    <td><?= $count++ ?></td><td><?= $row['id'] ?></td><td><?= $row['nombre_tipo_activo'] ?></td>
                    <td><?= $row['marca'] ?></td><td><?= $row['serie'] ?></td><td><?= $row['nombre_responsable'] ?></td>
                    <td><span class="<?= getEstadoBadgeClass($row['estado']) ?>"><?= $row['estado'] ?></span></td>
                    <td>$<?= number_format((float)$row['valor_aproximado'],0) ?></td>
                </tr>
                <?php endforeach; echo '</tbody></table></div></div>';
        endif; 
        ?>

    <?php elseif ($tipo_informe_seleccionado !== 'seleccione'): ?>
        <div class="alert alert-warning text-center mt-4">No se encontraron datos.</div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalExportarPersonalizado" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form action="exportar_excel.php" method="post" target="_blank" class="modal-content">
            <div class="modal-header bg-success text-white"><h5 class="modal-title">Exportar Excel</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="form-check form-switch mb-3 border-bottom pb-2">
                    <input class="form-check-input" type="checkbox" id="selectAll" checked>
                    <label class="form-check-label fw-bold" for="selectAll">Seleccionar Todo</label>
                </div>
                <input type="hidden" name="tipo_informe" value="<?= htmlspecialchars($tipo_informe_seleccionado) ?>">
                <input type="hidden" name="fecha_desde" value="<?= htmlspecialchars($fecha_desde) ?>">
                <input type="hidden" name="fecha_hasta" value="<?= htmlspecialchars($fecha_hasta) ?>">
                <div class="row">
                    <?php foreach($campos_exportables as $grupo => $campos): ?>
                    <div class="col-md-6 mb-3"><h6 class="text-primary border-bottom"><?= $grupo ?></h6>
                        <?php foreach($campos as $col => $nom): ?>
                        <div class="form-check"><input class="form-check-input chk" type="checkbox" name="campos_seleccionados[<?= $col ?>]" value="<?= $nom ?>" checked><label class="form-check-label"><?= $nom ?></label></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-success">Descargar</button></div>
        </form>
    </div>
</div>

<footer class="footer-custom"><div class="container text-center"><small>Sistema de Gestión v3.0 &copy; <?= date('Y') ?></small></div></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('selectAll').addEventListener('change', function() {
        document.querySelectorAll('.chk').forEach(c => c.checked = this.checked);
    });
</script>
</body>
</html>