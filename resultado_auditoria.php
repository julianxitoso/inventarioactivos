<?php
// =================================================================================
// ARCHIVO: resultado_auditoria.php
// ESTADO: REDISEÑADO (Con Barra Superior, Logo y Navegación Estandarizada)
// =================================================================================

session_start();
require_once 'backend/auth_check.php';
require_once 'backend/db.php';

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Auditor';
$id_auditoria = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 1. Obtener Datos de Cabecera
$stmt = $conexion->prepare("SELECT a.*, u.nombre_completo as auditor FROM auditorias a JOIN usuarios u ON a.id_usuario_auditor = u.id WHERE a.id_auditoria = ?");
$stmt->bind_param("i", $id_auditoria);
$stmt->execute();
$auditoria = $stmt->get_result()->fetch_assoc();

if (!$auditoria) die("Auditoría no encontrada.");

// 2. Calcular Estadísticas
$stats = $conexion->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN estado_auditoria = 'Encontrado' THEN 1 ELSE 0 END) as encontrados,
        SUM(CASE WHEN estado_auditoria = 'No Encontrado' THEN 1 ELSE 0 END) as perdidos,
        SUM(CASE WHEN estado_auditoria LIKE '%Malo%' OR (observacion_auditor IS NOT NULL AND observacion_auditor != '') THEN 1 ELSE 0 END) as con_novedad
    FROM auditoria_detalles 
    WHERE id_auditoria = $id_auditoria
")->fetch_assoc();

$efectividad = $stats['total'] > 0 ? round(($stats['encontrados'] / $stats['total']) * 100, 1) : 0;

// 3. Obtener Listados Detallados
$sql_detalles = "SELECT d.*, a.serie, a.Codigo_Inv, a.marca, ta.nombre_tipo_activo, u.nombre_completo as responsable
                 FROM auditoria_detalles d
                 JOIN activos_tecnologicos a ON d.id_activo = a.id
                 LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                 LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
                 WHERE d.id_auditoria = $id_auditoria
                 ORDER BY d.estado_auditoria ASC";
$result_detalles = $conexion->query($sql_detalles);
$detalles = $result_detalles->fetch_all(MYSQLI_ASSOC);

// Filtrar arrays
$faltantes = array_filter($detalles, fn($i) => $i['estado_auditoria'] === 'No Encontrado');
$novedades = array_filter($detalles, fn($i) => strpos($i['estado_auditoria'], 'Malo') !== false || !empty($i['observacion_auditor']));

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resultados: <?= htmlspecialchars($auditoria['nombre_auditoria']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ESTILOS GLOBALES Y BARRA SUPERIOR */
        body { padding-top: 85px; background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; padding-bottom: 50px; }
        
        .top-bar-custom {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1030;
            background: #fff; border-bottom: 1px solid #dee2e6;
            padding: 10px 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex; justify-content: space-between; align-items: center;
        }
        .logo-container-top img { height: 60px; object-fit: contain; margin-right: 15px; }
        
        /* ESTILOS DE TARJETAS Y REPORTES */
        .stat-card { border: none; border-radius: 10px; padding: 20px; color: white; height: 100%; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .bg-gradient-success { background: linear-gradient(45deg, #198754, #20c997); }
        .bg-gradient-danger { background: linear-gradient(45deg, #dc3545, #f06595); }
        .bg-gradient-warning { background: linear-gradient(45deg, #ffc107, #fd7e14); color: #333; }
        .bg-gradient-info { background: linear-gradient(45deg, #0dcaf0, #0d6efd); }
        .nav-tabs .nav-link.active { font-weight: bold; border-top: 3px solid #0d6efd; background-color: white; }
        
        /* ESTILOS PARA IMPRESIÓN */
        @media print {
            .no-print { display: none !important; }
            body { padding-top: 0; background-color: white; }
            .card { border: 1px solid #ddd !important; box-shadow: none !important; }
            .nav-tabs { display: none; } /* Ocultar pestañas al imprimir, mostrar tablas secuenciales si se desea, o solo la activa */
            .tab-pane { display: block !important; opacity: 1 !important; margin-bottom: 20px; page-break-inside: avoid; }
            .tab-content { border: none; }
        }
    </style>
</head>
<body>

    <div class="top-bar-custom no-print">
        <div class="logo-container-top">
            <a href="menu.php" title="Ir al Menú"><img src="imagenes/logo.png" alt="Logo"></a>
        </div>
        <div class="d-flex align-items-center">
            <span class="me-3 fw-bold text-secondary d-none d-md-block"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?></span>
            <form action="logout.php" method="post" class="m-0">
                <button class="btn btn-outline-danger btn-sm rounded-pill" type="submit">Salir</button>
            </form>
        </div>
    </div>

    <div class="container">
        
        <div class="d-flex justify-content-between align-items-center mb-4 no-print bg-white p-3 rounded shadow-sm border">
            <div class="d-flex align-items-center">
                <a href="auditorias.php" class="btn btn-outline-secondary me-3">
                    <i class="bi bi-arrow-left"></i> Volver
                </a>
                <h4 class="m-0 fw-bold text-primary">Resultados de Auditoría</h4>
            </div>
            <div>
                <a href="exportar_auditoria.php?id=<?= $id_auditoria ?>" class="btn btn-success me-2">
                    <i class="bi bi-file-earmark-excel"></i> Exportar Excel
                </a>
                <button onclick="window.print()" class="btn btn-dark">
                    <i class="bi bi-printer"></i> Imprimir
                </button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12 mb-3">
                <div class="p-3 bg-white rounded border shadow-sm">
                    <h3 class="fw-bold text-dark mb-1"><?= htmlspecialchars($auditoria['nombre_auditoria']) ?></h3>
                    <p class="text-muted mb-2">
                        <i class="bi bi-calendar-check"></i> Cerrada el: <strong><?= date('d/m/Y H:i', strtotime($auditoria['fecha_cierre'])) ?></strong> | 
                        <i class="bi bi-person-badge"></i> Auditor: <strong><?= $auditoria['auditor'] ?></strong>
                    </p>
                    <div class="alert alert-light border small m-0 py-2">
                        <i class="bi bi-funnel"></i> <strong>Filtros aplicados:</strong> <?= $auditoria['filtros_aplicados'] ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card bg-gradient-info">
                    <h2 class="fw-bold display-5"><?= $stats['total'] ?></h2>
                    <span>Total Activos Auditados</span>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card bg-gradient-success">
                    <h2 class="fw-bold display-5"><?= $stats['encontrados'] ?></h2>
                    <span>Encontrados OK</span>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card bg-gradient-danger">
                    <h2 class="fw-bold display-5"><?= $stats['perdidos'] ?></h2>
                    <span>No Encontrados (Pérdidas)</span>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card bg-gradient-warning">
                    <h2 class="fw-bold display-5"><?= $stats['con_novedad'] ?></h2>
                    <span>Con Novedades / Daños</span>
                </div>
            </div>
        </div>

        <div class="card p-3 mb-4 border-0 shadow-sm">
            <h6 class="fw-bold text-muted mb-2">Efectividad del Inventario</h6>
            <div class="progress" style="height: 25px;">
                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $efectividad ?>%">
                    <?= $efectividad ?>% Conciliado
                </div>
                <div class="progress-bar bg-danger" role="progressbar" style="width: <?= 100 - $efectividad ?>%">
                    <?= 100 - $efectividad ?>% Faltante
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-bottom-0 pb-0">
                <ul class="nav nav-tabs card-header-tabs" id="myTab" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active text-danger px-4 py-2" id="faltantes-tab" data-bs-toggle="tab" data-bs-target="#faltantes" type="button">
                            <i class="bi bi-exclamation-octagon-fill"></i> No Encontrados (<?= count($faltantes) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link text-warning px-4 py-2" id="novedades-tab" data-bs-toggle="tab" data-bs-target="#novedades" type="button">
                            <i class="bi bi-pencil-square"></i> Novedades y Daños (<?= count($novedades) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link text-secondary px-4 py-2" id="todos-tab" data-bs-toggle="tab" data-bs-target="#todos" type="button">
                            Listado Completo
                        </button>
                    </li>
                </ul>
            </div>
            
            <div class="card-body">
                <div class="tab-content" id="myTabContent">
                    
                    <div class="tab-pane fade show active" id="faltantes">
                        <?php if(empty($faltantes)): ?>
                            <div class="text-center py-5 text-success">
                                <i class="bi bi-check-circle fs-1 display-1"></i>
                                <h4 class="mt-3">¡Excelente! No hay activos perdidos.</h4>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle-fill fs-4 me-2"></i>
                                <div>Estos activos <strong>no aparecieron</strong> durante la auditoría física.</div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped align-middle">
                                    <thead class="table-danger">
                                        <tr>
                                            <th>Serie</th>
                                            <th>Placa Inventario</th>
                                            <th>Tipo</th>
                                            <th>Marca</th>
                                            <th>Responsable (Sistema)</th>
                                            <th>Observaciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($faltantes as $item): ?>
                                        <tr>
                                            <td class="fw-bold"><?= $item['serie'] ?></td>
                                            <td><?= $item['Codigo_Inv'] ?></td>
                                            <td><?= $item['nombre_tipo_activo'] ?></td>
                                            <td><?= $item['marca'] ?></td>
                                            <td><?= $item['responsable'] ?></td>
                                            <td class="text-danger small fst-italic"><?= $item['observacion_auditor'] ?: 'Sin notas' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="novedades">
                        <?php if(empty($novedades)): ?>
                            <div class="text-center py-5 text-muted">
                                <p>No se reportaron novedades ni daños.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-warning">
                                        <tr>
                                            <th>Serie</th>
                                            <th>Tipo</th>
                                            <th>Responsable</th>
                                            <th>Estado Auditoría</th>
                                            <th>Novedad Reportada</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($novedades as $item): ?>
                                        <tr>
                                            <td class="fw-bold"><?= $item['serie'] ?></td>
                                            <td><?= $item['nombre_tipo_activo'] ?></td>
                                            <td><?= $item['responsable'] ?></td>
                                            <td>
                                                <?php if(strpos($item['estado_auditoria'], 'Malo') !== false): ?>
                                                    <span class="badge bg-warning text-dark">Dañado / Malo</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Encontrado</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-dark"><?= $item['observacion_auditor'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="todos">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Estado</th>
                                        <th>Serie</th>
                                        <th>Placa</th>
                                        <th>Tipo</th>
                                        <th>Responsable</th>
                                        <th>Notas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($detalles as $item): 
                                        $bg = '';
                                        if($item['estado_auditoria'] == 'No Encontrado') $bg = 'table-danger';
                                        elseif(strpos($item['estado_auditoria'], 'Malo') !== false) $bg = 'table-warning';
                                    ?>
                                    <tr class="<?= $bg ?>">
                                        <td><?= $item['estado_auditoria'] ?></td>
                                        <td><?= $item['serie'] ?></td>
                                        <td><?= $item['Codigo_Inv'] ?></td>
                                        <td><?= $item['nombre_tipo_activo'] ?></td>
                                        <td><?= $item['responsable'] ?></td>
                                        <td><?= $item['observacion_auditor'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>