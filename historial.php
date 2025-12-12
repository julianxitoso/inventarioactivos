<?php
session_start();
// auth_check.php DEBE estar primero
require_once 'backend/auth_check.php'; 

// Verificar que esté logueado:
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php?error=sesion_requerida_historial");
    exit;
}

require_once 'backend/db.php';
require_once 'backend/historial_helper.php'; 

// Definiciones de constantes de historial
if (!defined('HISTORIAL_TIPO_CREACION')) define('HISTORIAL_TIPO_CREACION', 'CREACIÓN');
if (!defined('HISTORIAL_TIPO_ACTUALIZACION')) define('HISTORIAL_TIPO_ACTUALIZACION', 'ACTUALIZACIÓN');
if (!defined('HISTORIAL_TIPO_TRASLADO')) define('HISTORIAL_TIPO_TRASLADO', 'TRASLADO');
if (!defined('HISTORIAL_TIPO_BAJA')) define('HISTORIAL_TIPO_BAJA', 'BAJA');
if (!defined('HISTORIAL_TIPO_MANTENIMIENTO')) define('HISTORIAL_TIPO_MANTENIMIENTO', 'MANTENIMIENTO');
if (!defined('HISTORIAL_TIPO_ELIMINACION_FISICA')) define('HISTORIAL_TIPO_ELIMINACION_FISICA', 'ELIMINACIÓN FÍSICA');


function getHistorialEventoBadgeClass($tipo_evento) {
    switch ($tipo_evento) {
        case HISTORIAL_TIPO_CREACION: return 'badge bg-success badge-custom';
        case HISTORIAL_TIPO_ACTUALIZACION: return 'badge bg-info text-dark badge-custom';
        case HISTORIAL_TIPO_TRASLADO: return 'badge bg-primary badge-custom';
        case HISTORIAL_TIPO_BAJA: return 'badge bg-warning text-dark badge-custom';
        case HISTORIAL_TIPO_MANTENIMIENTO: return 'badge bg-secondary badge-custom';
        case HISTORIAL_TIPO_ELIMINACION_FISICA: return 'badge bg-danger badge-custom';
        default: return 'badge bg-secondary badge-custom';
    }
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error) ) {
    $conexion_error_msg = "Error de conexión a la base de datos. No se pudo cargar la información.";
    error_log("Fallo CRÍTICO de conexión a BD en historial.php: " . ($conexion->connect_error ?? 'Error desconocido'));
} else {
    $conexion->set_charset("utf8mb4");
}

if (!isset($_GET['id_activo']) || !filter_var($_GET['id_activo'], FILTER_VALIDATE_INT) || $_GET['id_activo'] <= 0) {
    $_SESSION['error_global'] = "ID de activo no válido o no proporcionado para ver el historial.";
    header("Location: buscar.php"); 
    exit;
}
$id_activo_historial = (int)$_GET['id_activo'];

$activo_info = null;
if (!$conexion_error_msg) {
    $sql_info_activo = "SELECT 
                            a.id, 
                            ta.nombre_tipo_activo, 
                            a.serie, 
                            a.marca, 
                            u.nombre_completo AS nombre_responsable, 
                            u.usuario AS cedula_responsable, 
                            u.regional AS regional_responsable, 
                            u.empresa AS empresa_responsable
                        FROM 
                            activos_tecnologicos a
                        LEFT JOIN 
                            tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
                        LEFT JOIN 
                            usuarios u ON a.id_usuario_responsable = u.id
                        WHERE 
                            a.id = ?";
    $stmt_activo = $conexion->prepare($sql_info_activo);
    if ($stmt_activo) {
        $stmt_activo->bind_param('i', $id_activo_historial);
        $stmt_activo->execute();
        $result_activo = $stmt_activo->get_result();
        $activo_info = $result_activo->fetch_assoc();
        $stmt_activo->close();
    } else {
        error_log("Error al preparar consulta de información del activo en historial.php: " . $conexion->error);
        $conexion_error_msg = ($conexion_error_msg ? $conexion_error_msg . "<br>" : "") . "Error al cargar información detallada del activo.";
    }
}

if (!$activo_info && !$conexion_error_msg) {
    $_SESSION['error_global'] = "Activo con ID " . htmlspecialchars($id_activo_historial) . " no encontrado o no accesible.";
    header("Location: buscar.php"); 
    exit;
}

$historial_items = [];
$error_historial_carga = null;
if (!$conexion_error_msg && $activo_info) {
    $stmt_hist = $conexion->prepare(
        "SELECT id_historial, fecha_evento, tipo_evento, descripcion_evento, usuario_responsable, datos_anteriores, datos_nuevos
         FROM historial_activos
         WHERE id_activo = ? ORDER BY fecha_evento DESC, id_historial DESC"
    );
    if ($stmt_hist) {
        $stmt_hist->bind_param('i', $id_activo_historial);
        $stmt_hist->execute();
        $result_hist = $stmt_hist->get_result();
        while ($row_hist = $result_hist->fetch_assoc()) {
            $historial_items[] = $row_hist;
        }
        $stmt_hist->close();
    } else {
        error_log("HISTORIAL: Error al preparar consulta para ver historial de activo ID $id_activo_historial: " . $conexion->error);
        $error_historial_carga = "No se pudo cargar el historial completo debido a un error del sistema.";
    }
}

if (isset($conexion) && $conexion && !$conexion_error_msg) {
    $conexion->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial: <?= htmlspecialchars($activo_info['nombre_tipo_activo'] ?? 'Activo') ?> S/N: <?= htmlspecialchars($activo_info['serie'] ?? $id_activo_historial) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #eef2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 100px; line-height: 1.6; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff;  border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.85rem; }
        .container-report { max-width: 960px; margin: 20px auto 40px auto; background-color: #fff; padding: 30px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        .report-title-area { margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid #dee2e6; }
        .report-title-area h3 { color: #0d6efd; font-weight: 600; }
        .activo-info-section { background-color: #f9f9f9; border: 1px solid #e0e0e0; padding: 20px; margin-bottom: 30px; border-radius: 4px; }
        .activo-info-section h5 { color: #004085; margin-bottom: 15px; border-bottom: 1px solid #cce5ff; padding-bottom: 10px; }
        .activo-info-section p { margin-bottom: 0.5rem; font-size: 0.95rem; }
        .activo-info-section p strong { min-width: 150px; display: inline-block; color: #495057; }
        .historial-timeline { list-style: none; padding-left: 0; position: relative; }
        .historial-timeline::before { content: ''; position: absolute; left: 7px; top: 0; bottom: 0; width: 2px; background-color: #dee2e6; }
        .historial-entry { padding: 10px 0 15px 30px; margin-bottom: 15px; background-color: #fff; position: relative; }
        .historial-entry::before { content: ''; position: absolute; left: 0px; top: 22px; width: 16px; height: 16px; border-radius: 50%; background-color: #0d6efd; border: 3px solid #fff; z-index: 1; }
        .historial-header { margin-bottom: 8px; }
        .historial-date { font-weight: 600; color: #333; font-size: 0.9rem; }
        .historial-user { font-size: 0.85rem; color: #6c757d; }
        .badge-custom { font-size: 0.85em; padding: 0.4em 0.7em; vertical-align: middle; }
        .historial-description { margin-bottom: 10px; font-size: 0.95rem; }
        .details-toggle { cursor: pointer; color: #007bff; text-decoration: none; font-size: 0.85em; }
        .details-content { background-color: #f8f9fa; border: 1px solid #e9ecef; padding: 15px; margin-top: 10px; border-radius: 4px; font-size: 0.88rem; }
    </style>
</head>
<body>
    <div class="top-bar-custom d-print-none"> 
        <div class="logo-container-top">
            <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo Empresa"></a>
        </div>
        <div class="d-flex align-items-center">
            <span class="text-dark me-3 user-info-top">
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)
            </span>
            <form action="logout.php" method="post" class="d-flex">
                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="container-report">
        <div class="report-title-area d-flex justify-content-between align-items-center">
            <h3 class="mb-0 page-title"><i class="bi bi-journal-richtext"></i> Historial del Activo</h3>
            <div class="btn-print-actions d-print-none">
                <button type="button" class="btn btn-success btn-sm" onclick="window.print();"><i class="bi bi-printer-fill"></i> Imprimir</button>
                <a href="buscar.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left-circle"></i> Volver a Búsqueda</a>
            </div>
        </div>
        
        <?php if ($activo_info): ?>
        <div class="activo-info-section mb-4">
            <h5><i class="bi bi-archive-fill"></i> Información del Activo </h5>
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Tipo:</strong> <?= htmlspecialchars($activo_info['nombre_tipo_activo'] ?? 'N/A') ?></p>
                    <p><strong>Marca:</strong> <?= htmlspecialchars($activo_info['marca'] ?? 'N/A') ?></p>
                    <p><strong>Serie:</strong> <?= htmlspecialchars($activo_info['serie'] ?? 'N/A') ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Responsable Actual:</strong> <?= htmlspecialchars($activo_info['nombre_responsable'] ?? 'N/A') ?></p>
                    <p><strong>C.C. Responsable:</strong> <?= htmlspecialchars($activo_info['cedula_responsable'] ?? 'N/A') ?></p>
                    <p><strong>Empresa (Resp.):</strong> <?= htmlspecialchars($activo_info['empresa_responsable'] ?? 'N/A') ?></p> 
                </div>
            </div>
        </div>

        <h5><i class="bi bi-list-ol"></i> Eventos Registrados</h5>
        <?php if (!empty($historial_items)): ?>
            <ul class="historial-timeline">
            <?php foreach ($historial_items as $idx => $item_hist): ?>
                <li class="historial-entry">
                    <div class="historial-header">
                        <div class="row">
                            <div class="col-md-7 col-lg-8">
                                <span class="historial-date"><i class="bi bi-calendar3"></i> <?= htmlspecialchars(date("d/m/Y H:i:s", strtotime($item_hist['fecha_evento']))) ?></span>
                                <span class="<?= getHistorialEventoBadgeClass($item_hist['tipo_evento']) ?> ms-2">
                                    <?= htmlspecialchars($item_hist['tipo_evento']) ?>
                                </span>
                            </div>
                            <div class="col-md-5 col-lg-4 text-md-end">
                                <span class="historial-user">
                                    <i class="bi bi-person-gear"></i> <?= htmlspecialchars($item_hist['usuario_responsable'] ?? 'Sistema') ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="historial-description">
                        <p class="mb-1"><?= nl2br(htmlspecialchars($item_hist['descripcion_evento'])) ?></p>
                        
                        <?php
                        // Comprobar si el evento es de Creación o Traslado para mostrar el botón
                        if ($item_hist['tipo_evento'] === HISTORIAL_TIPO_CREACION || $item_hist['tipo_evento'] === HISTORIAL_TIPO_TRASLADO):
                            $url_acta = '';
                            $texto_acta = '';
                            $icono_acta = '';
                            $clase_btn = '';

                            if ($item_hist['tipo_evento'] === HISTORIAL_TIPO_CREACION) {
                                $url_acta = 'generar_acta_entrega_pdf.php?id_historial=' . $item_hist['id_historial'];
                                $texto_acta = 'Generar Acta de Entrega';
                                $icono_acta = 'bi-file-earmark-check';
                                $clase_btn = 'btn-outline-success';
                            } elseif ($item_hist['tipo_evento'] === HISTORIAL_TIPO_TRASLADO) {
                                $url_acta = 'generar_acta_traslado_pdf.php?id_historial=' . $item_hist['id_historial'];
                                $texto_acta = 'Generar Acta de Traslado';
                                $icono_acta = 'bi-file-earmark-arrow-down';
                                $clase_btn = 'btn-outline-primary';
                            }
                        ?>
                            <div class="mt-2">
                                <a href="<?= $url_acta ?>" class="btn btn-sm <?= $clase_btn ?>" target="_blank">
                                    <i class="bi <?= $icono_acta ?>"></i> <?= $texto_acta ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php
                        $datos_anteriores_hist = !empty($item_hist['datos_anteriores']) ? json_decode($item_hist['datos_anteriores'], true) : null;
                        $datos_nuevos_hist = !empty($item_hist['datos_nuevos']) ? json_decode($item_hist['datos_nuevos'], true) : null;
                        if ($datos_anteriores_hist || $datos_nuevos_hist) :
                        ?>
                        <a class="details-toggle d-print-none mt-2 d-inline-block" data-bs-toggle="collapse" href="#detailsCollapse_<?= $idx ?>" role="button">
                            <i class="bi bi-caret-down-fill"></i> Ver Detalles del Evento
                        </a>
                        <div class="collapse" id="detailsCollapse_<?= $idx ?>">
                           <div class="details-content">
                                <?php if (!empty($datos_anteriores_hist)): ?>
                                    <h6>Datos Anteriores:</h6>
                                    <ul>
                                        <?php foreach ($datos_anteriores_hist as $key => $val): ?>
                                            <li><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?>:</strong> <?= htmlspecialchars(is_array($val) ? json_encode($val) : $val) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if (!empty($datos_nuevos_hist)): ?>
                                    <h6>Datos Nuevos/Aplicados:</h6>
                                    <ul>
                                        <?php foreach ($datos_nuevos_hist as $key => $val): ?>
                                            <li><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?>:</strong> <?= htmlspecialchars(is_array($val) ? json_encode($val) : $val) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                           </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="alert alert-secondary mt-3" role="alert">
                <i class="bi bi-info-circle-fill"></i> No hay eventos de historial registrados para este activo.
            </div>
        <?php endif; ?>
        <?php else: ?>
             <div class="alert alert-warning mt-3">No se pudo cargar la información del activo para mostrar el historial.</div>
        <?php endif; ?>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>