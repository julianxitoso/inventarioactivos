<?php

error_reporting(E_ALL);
ini_set('display_errors', 0); 

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

// --- 1. CARGA DE LISTAS PARA FILTROS ---
$listas = [];
if ($conexion) {
    $listas['empresas'] = $conexion->query("SELECT DISTINCT empresa FROM usuarios WHERE empresa != '' ORDER BY empresa")->fetch_all(MYSQLI_ASSOC);
    $listas['regionales'] = $conexion->query("SELECT nombre_regional FROM regionales ORDER BY nombre_regional")->fetch_all(MYSQLI_ASSOC);
    $listas['categorias'] = $conexion->query("SELECT nombre_categoria FROM categorias_activo ORDER BY nombre_categoria")->fetch_all(MYSQLI_ASSOC);
}

// --- 2. RECEPCIÓN DE PARÁMETROS ---
$tipo_informe = $_GET['tipo_informe'] ?? 'seleccione';
// Filtros
$f_desde = $_GET['f_desde'] ?? '';
$f_hasta = $_GET['f_hasta'] ?? '';
$f_empresa = $_GET['f_empresa'] ?? '';
$f_regional = $_GET['f_regional'] ?? '';
$f_categoria = $_GET['f_categoria'] ?? '';
$q_busqueda = $_GET['q_busqueda'] ?? '';

$titulo_pagina = "Informes";
$titulo_actual = "";
$datos = [];
$cols_html = [];

// --- 3. DEFINICIÓN LIMPIA DE CAMPOS PARA EXPORTACIÓN ---
$campos_exportables = [
    'Identificación y Estado' => [
        'at.id' => 'ID Sistema',
        'at.Codigo_Inv' => 'Cód. Inventario Interno',
        'at.serie' => 'Número de Serie',
        'ta.nombre_tipo_activo' => 'Tipo de Activo',
        'cat.nombre_categoria' => 'Categoría',
        'at.estado' => 'Estado Físico',
        'at.marca' => 'Marca'
    ],
    'Responsable y Ubicación' => [
        'u.nombre_completo' => 'Responsable Asignado',
        'u.usuario' => 'Cédula / ID Responsable',
        'u.empresa' => 'Empresa Responsable',
        'c.nombre_cargo' => 'Cargo',
        'r.nombre_regional' => 'Regional',
        'cc.nombre_centro_costo' => 'Centro de Costo',
        'cc.cod_centro_costo' => 'Cód. Centro Costo'
    ],
    'Información Financiera Completa' => [
        'at.fecha_compra' => 'Fecha de Compra',
        'at.valor_aproximado' => 'Costo de Adquisición',
        'at.vida_util' => 'Vida Útil (Años)',
        'at.valor_residual' => 'Valor Residual',
        'ta.vida_util_sugerida' => 'Vida Útil Estándar (Catálogo)',
        'CALCULADO_VALOR_LIBROS' => 'Valor Neto en Libros (Actual)',
        'CALCULADO_DEP_ACUMULADA' => 'Depreciación Acumulada',
        'CALCULADO_DEP_MENSUAL' => 'Gasto Depreciación Mensual'
    ],
    'Especificaciones Técnicas' => [
        'at.procesador' => 'Procesador',
        'at.ram' => 'Memoria RAM',
        'at.disco_duro' => 'Disco Duro / Almacenamiento',
        'at.sistema_operativo' => 'Sistema Operativo',
        'at.licencia_so' => 'Licencia SO',
        'at.offimatica' => 'Suite Ofimática',
        'at.antivirus' => 'Antivirus'
    ],
    'Observaciones' => [
        'at.detalles' => 'Detalles / Observaciones',
        'at.satisfaccion_rating' => 'Calificación Usuario'
    ]
];

// --- 4. CONSTRUCCIÓN DE CONSULTA ---
if ($tipo_informe !== 'seleccione' && !$conexion_error_msg) {
    
    $where = ["at.estado != 'Dado de Baja'"]; 
    $params = [];
    $types = "";

    if (!empty($f_desde)) { $where[] = "at.fecha_compra >= ?"; $params[] = $f_desde; $types .= "s"; }
    if (!empty($f_hasta)) { $where[] = "at.fecha_compra <= ?"; $params[] = $f_hasta . ' 23:59:59'; $types .= "s"; }
    if (!empty($f_empresa)) { $where[] = "u.empresa = ?"; $params[] = $f_empresa; $types .= "s"; }
    if (!empty($f_regional)) { $where[] = "r.nombre_regional = ?"; $params[] = $f_regional; $types .= "s"; }
    if (!empty($f_categoria)) { $where[] = "cat.nombre_categoria = ?"; $params[] = $f_categoria; $types .= "s"; }

    if (!empty($q_busqueda)) {
        $term = "%{$q_busqueda}%";
        $where[] = "(u.usuario LIKE ? OR u.nombre_completo LIKE ? OR at.serie LIKE ? OR at.Codigo_Inv LIKE ?)";
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term; 
        $types .= "ssss";
    }

    $base_joins = "FROM activos_tecnologicos at
                   LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                   LEFT JOIN categorias_activo cat ON ta.id_categoria = cat.id_categoria
                   LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
                   LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
                   LEFT JOIN centros_costo cc ON at.id_centro_costo = cc.id_centro_costo
                   LEFT JOIN regionales r ON cc.id_regional = r.id_regional";

    switch ($tipo_informe) {
        case 'general':
            $titulo_actual = "Informe General de Activos";
            $sql = "SELECT at.id, cat.nombre_categoria, ta.nombre_tipo_activo, at.marca, at.serie, at.Codigo_Inv,
                           at.estado, at.valor_aproximado, at.fecha_compra, 
                           cc.nombre_centro_costo, r.nombre_regional, u.nombre_completo, u.usuario as cedula
                    $base_joins WHERE " . implode(' AND ', $where);
            $cols_html = ["Categoría", "Tipo", "Marca", "Serie", "Cód. Inv", "Estado", "Valor", "Ubicación", "Responsable", "Cédula"];
            break;

        case 'movimientos':
            $titulo_actual = "Historial de Movimientos";
            $sql = "SELECT h.fecha_evento, h.tipo_evento, h.descripcion_evento, h.usuario_responsable AS user_sis,
                           at.serie, ta.nombre_tipo_activo, u.nombre_completo
                    FROM historial_activos h
                    JOIN activos_tecnologicos at ON h.id_activo = at.id
                    LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
                    LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
                    LEFT JOIN centros_costo cc ON at.id_centro_costo = cc.id_centro_costo
                    LEFT JOIN regionales r ON cc.id_regional = r.id_regional
                    LEFT JOIN categorias_activo cat ON ta.id_categoria = cat.id_categoria
                    WHERE 1=1 "; 
            $cols_html = ["Fecha", "Evento", "Tipo", "Serie", "Responsable Actual", "Descripción", "Autor"];
            break;

        case 'dados_baja':
            $titulo_actual = "Activos Dados de Baja";
            $sql = "SELECT at.id, ta.nombre_tipo_activo, at.marca, at.serie, at.valor_aproximado, 
                           u.nombre_completo, cc.nombre_centro_costo 
                    $base_joins WHERE at.estado = 'Dado de Baja'";
            $cols_html = ["Tipo", "Marca", "Serie", "Costo Histórico", "Último Resp.", "Ubicación"];
            break;
            
        default: 
            $titulo_actual = "Resultados de Búsqueda";
            $sql = "SELECT at.id, ta.nombre_tipo_activo, at.marca, at.serie, at.estado, at.valor_aproximado 
                    $base_joins WHERE " . implode(' AND ', $where);
            $cols_html = ["Tipo", "Marca", "Serie", "Estado", "Valor"];
            break;
    }

    if(isset($sql)) {
        $stmt = $conexion->prepare($sql);
        if(!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $datos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

function badge($estado) {
    $c = 'secondary';
    if(in_array($estado, ['Bueno','Operativo','Asignado','Nuevo'])) $c = 'success';
    if(in_array($estado, ['Regular','En Mantenimiento'])) $c = 'warning text-dark';
    if(in_array($estado, ['Malo','Dado de Baja'])) $c = 'danger';
    return "<span class='badge bg-$c rounded-pill'>$estado</span>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $titulo_pagina ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    
    <style>
        body { padding-top: 80px; background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; background: #fff; border-bottom: 1px solid #dee2e6; padding: 0.5rem 1.5rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        
        .report-card { transition: all 0.3s; border: none; border-radius: 12px; background: white; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow: hidden; height: 100%; position: relative; }
        .report-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
        .report-card .icon-box { width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 10px; margin-bottom: 15px; font-size: 1.5rem; }
        .report-card h6 { font-weight: 700; color: #2c3e50; }
        .report-card p { font-size: 0.85rem; color: #6c757d; margin: 0; line-height: 1.4; }
        .report-card .stretched-link::after { position: absolute; top: 0; right: 0; bottom: 0; left: 0; z-index: 1; content: ""; }

        .bg-icon-primary { background: rgba(13, 110, 253, 0.1); color: #0d6efd; }
        .bg-icon-success { background: rgba(25, 135, 84, 0.1); color: #198754; }
        .bg-icon-warning { background: rgba(255, 193, 7, 0.1); color: #ffc107; }
        .bg-icon-danger { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
        .bg-icon-info { background: rgba(13, 202, 240, 0.1); color: #0dcaf0; }

        .card-filter { border: none; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); background: white; margin-bottom: 20px; }
        .table-container { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        table.dataTable thead th { background-color: #f8f9fa; color: #495057; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; border-bottom: 2px solid #dee2e6; }
        table.dataTable tbody td { font-size: 0.9rem; vertical-align: middle; }
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div class="d-flex align-items-center">
        <a href="menu.php"><img src="imagenes/logo.png" height="60" alt="Logo"></a>
        </div>
    <div>
        <span class="me-3 fw-bold text-secondary"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">Salir</a>
    </div>
</div>

<div class="container px-4 mb-5" style="margin-top: 20px;">

    <?php if($tipo_informe === 'seleccione'): ?>
        <div class="row mb-4">
            <div class="col-12 text-center py-4">
                <h3 class="fw-bold text-primary mb-2">¿Qué informe deseas consultar hoy?</h3>
                <p class="text-muted">Selecciona una categoría para acceder a los datos detallados.</p>
            </div>
        </div>

        <div class="row g-4">
            
            <div class="col-md-4">
                <div class="report-card p-4 text-center">
                    <div class="icon-box bg-icon-primary mx-auto"><i class="bi bi-grid-fill"></i></div>
                    <h6>Informe General</h6>
                    <p>Listado completo de todos los activos, ubicaciones y responsables.</p>
                    <a href="?tipo_informe=general" class="stretched-link"></a>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="report-card p-4 text-center">
                    <div class="icon-box bg-icon-info mx-auto"><i class="bi bi-clock-history"></i></div>
                    <h6>Movimientos</h6>
                    <p>Historial de traslados, asignaciones y cambios realizados.</p>
                    <a href="?tipo_informe=movimientos" class="stretched-link"></a>
                </div>
            </div>

            <div class="col-md-4">
                <div class="report-card p-4 text-center">
                    <div class="icon-box bg-icon-danger mx-auto"><i class="bi bi-trash-fill"></i></div>
                    <h6>Activos Dados de Baja</h6>
                    <p>Equipos retirados del inventario activo y sus motivos.</p>
                    <a href="?tipo_informe=dados_baja" class="stretched-link"></a>
                </div>
            </div>

        </div>

    <?php else: ?>
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="informes.php" class="text-decoration-none">Inicio</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= $titulo_actual ?></li>
                </ol>
            </nav>
        </div>

        <div class="card card-filter p-3">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="tipo_informe" value="<?= $tipo_informe ?>">
                
                <div class="col-md-3">
                    <label class="small fw-bold text-muted">Buscar (Cédula, Nombre, Serie...)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" name="q_busqueda" value="<?= htmlspecialchars($q_busqueda) ?>" placeholder="Escribe para buscar...">
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Empresa</label>
                    <select class="form-select form-select-sm" name="f_empresa">
                        <option value="">Todas</option>
                        <?php foreach($listas['empresas'] as $i) echo "<option value='{$i['empresa']}' ".($f_empresa==$i['empresa']?'selected':'').">{$i['empresa']}</option>"; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Regional</label>
                    <select class="form-select form-select-sm" name="f_regional">
                        <option value="">Todas</option>
                        <?php foreach($listas['regionales'] as $i) echo "<option value='{$i['nombre_regional']}' ".($f_regional==$i['nombre_regional']?'selected':'').">{$i['nombre_regional']}</option>"; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Categoría</label>
                    <select class="form-select form-select-sm" name="f_categoria">
                        <option value="">Todas</option>
                        <?php foreach($listas['categorias'] as $i) echo "<option value='{$i['nombre_categoria']}' ".($f_categoria==$i['nombre_categoria']?'selected':'').">{$i['nombre_categoria']}</option>"; ?>
                    </select>
                </div>
                
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel"></i> Aplicar</button>
                    <a href="informes.php?tipo_informe=<?= $tipo_informe ?>" class="btn btn-outline-secondary btn-sm" title="Limpiar"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </form>
        </div>

        <div class="table-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="text-dark fw-bold mb-0">
                    <?= $titulo_actual ?> 
                    <span class="badge bg-light text-secondary border ms-2"><?= count($datos) ?> registros</span>
                </h5>
                <button class="btn btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#modalExportar">
                    <i class="bi bi-file-earmark-excel me-2"></i> Exportar Todo
                </button>
            </div>

            <div class="table-responsive">
                <table id="tablaInformes" class="table table-hover w-100">
                    <thead>
                        <tr>
                            <?php foreach($cols_html as $col): ?>
                                <th><?= $col ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($tipo_informe == 'general'): ?>
                            <?php foreach($datos as $r): ?>
                                <tr>
                                    <td><?= $r['nombre_categoria'] ?></td>
                                    <td><?= $r['nombre_tipo_activo'] ?></td>
                                    <td><?= $r['marca'] ?></td>
                                    <td><?= $r['serie'] ?></td>
                                    <td><?= $r['Codigo_Inv'] ?></td>
                                    <td><?= badge($r['estado']) ?></td>
                                    <td>$<?= number_format((float)$r['valor_aproximado'], 0) ?></td>
                                    <td>
                                        <div class="small fw-bold"><?= $r['nombre_regional'] ?></div>
                                        <div class="text-muted small"><?= $r['nombre_centro_costo'] ?></div>
                                    </td>
                                    <td><?= $r['nombre_completo'] ?></td>
                                    <td><?= $r['cedula'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        
                        <?php elseif($tipo_informe == 'movimientos'): ?>
                            <?php foreach($datos as $r): ?>
                                <tr>
                                    <td><?= $r['fecha_evento'] ?></td>
                                    <td><span class="badge bg-secondary"><?= $r['tipo_evento'] ?></span></td>
                                    <td><?= $r['nombre_tipo_activo'] ?></td>
                                    <td><?= $r['serie'] ?></td>
                                    <td><?= $r['nombre_completo'] ?></td>
                                    <td><small class="text-muted"><?= $r['descripcion_evento'] ?></small></td>
                                    <td><?= $r['user_sis'] ?></td>
                                </tr>
                            <?php endforeach; ?>

                        <?php elseif($tipo_informe == 'dados_baja'): ?>
                            <?php foreach($datos as $r): ?>
                                <tr>
                                    <td><?= $r['nombre_tipo_activo'] ?></td>
                                    <td><?= $r['marca'] ?></td>
                                    <td><?= $r['serie'] ?></td>
                                    <td>$<?= number_format((float)$r['valor_aproximado'], 0) ?></td>
                                    <td><?= $r['nombre_completo'] ?></td>
                                    <td><?= $r['nombre_centro_costo'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

</div>

<div class="modal fade" id="modalExportar" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form action="exportar_excel.php" method="post" target="_blank" class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-file-excel"></i> Exportación Completa</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="tipo_informe" value="<?= $tipo_informe ?>">
                <input type="hidden" name="f_desde" value="<?= $f_desde ?>">
                <input type="hidden" name="f_hasta" value="<?= $f_hasta ?>">
                <input type="hidden" name="q_busqueda" value="<?= htmlspecialchars($q_busqueda) ?>">
                
                <div class="alert alert-light border mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="checkAll" checked>
                        <label class="form-check-label fw-bold" for="checkAll">Seleccionar Todas las Columnas (BD Completa)</label>
                    </div>
                </div>

                <div class="row g-3" style="max-height: 400px; overflow-y: auto;">
                    <?php foreach($campos_exportables as $grupo => $campos): ?>
                        <div class="col-md-6">
                            <div class="card h-100 border-0 bg-light">
                                <div class="card-body py-2">
                                    <h6 class="text-success fw-bold border-bottom pb-1 mb-2"><?= $grupo ?></h6>
                                    <?php foreach($campos as $col => $nom): ?>
                                        <div class="form-check mb-1">
                                            <input class="form-check-input chk-col" type="checkbox" name="campos_seleccionados[]" value="<?= $col . '|||' . $nom ?>" checked>
                                            <label class="form-check-label small text-muted"><?= $nom ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="btn btn-success fw-bold px-4">Descargar .XLSX</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#tablaInformes').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
        pageLength: 25,
        responsive: true,
        order: [[0, 'asc']]
    });

    $('#checkAll').change(function() {
        $('.chk-col').prop('checked', $(this).prop('checked'));
    });
});
</script>

</body>
</html>