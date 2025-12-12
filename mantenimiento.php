<?php
// =================================================================
// INICIO: LÓGICA PHP COMPLETA Y CORREGIDA
// =================================================================

// Si vuelves a tener un problema, puedes volver a activar estas líneas para ver el error exacto.
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico', 'auditor', 'registrador']);

require_once 'backend/db.php';
// Asegúrate de que el nombre de la función sea el correcto según tu helper.
// Basado en tu archivo de préstamos, el nombre correcto es 'registrar_evento_historial'.
require_once 'backend/historial_helper.php';

// Definiciones de constantes para el tipo de evento
if (!defined('HISTORIAL_TIPO_MANTENIMIENTO_INICIADO')) define('HISTORIAL_TIPO_MANTENIMIENTO_INICIADO', 'INICIO MANTENIMIENTO');
if (!defined('HISTORIAL_TIPO_MANTENIMIENTO_FINALIZADO')) define('HISTORIAL_TIPO_MANTENIMIENTO_FINALIZADO', 'FIN MANTENIMIENTO');
if (!defined('HISTORIAL_TIPO_BAJA')) define('HISTORIAL_TIPO_BAJA', 'BAJA');

// --- Carga de datos de sesión ---
$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';
$id_usuario_actual_logueado = $_SESSION['usuario_id'] ?? null;
$usuario_actual_sistema_para_historial = $_SESSION['usuario_login'] ?? 'Sistema';

$is_ajax_request = isset($_REQUEST['ajax_request']);

// --- VERIFICACIÓN DE CONEXIÓN A BD ---
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}

if (!isset($conexion) || !$conexion || (is_object($conexion) && property_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $error_critico_db = "Error crítico de conexión a la base de datos.";
    if ($is_ajax_request) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $error_critico_db]);
        exit;
    }
} else {
    $conexion->set_charset("utf8mb4");
}

// --- FUNCIONES AUXILIARES ---
function verificar_permiso_sobre_activo($db_conn, $id_activo, $id_usuario, $rol_usuario) {
    if ($rol_usuario === 'admin' || $rol_usuario === 'tecnico') return true;
    $stmt = $db_conn->prepare("SELECT id_usuario_responsable FROM activos_tecnologicos WHERE id = ?");
    if (!$stmt) return false;
    $stmt->bind_param("i", $id_activo);
    $stmt->execute();
    $result = $stmt->get_result();
    return ($row = $result->fetch_assoc()) ? $row['id_usuario_responsable'] == $id_usuario : false;
}

function fetch_activo_completo($db_conn, $id_activo) {
    $sql = "SELECT a.id, ta.nombre_tipo_activo, a.marca, a.serie, u.nombre_completo AS nombre_responsable, a.estado AS estado_actual FROM activos_tecnologicos a LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo WHERE a.id = ?";
    $stmt = $db_conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param("i", $id_activo);
    $stmt->execute();
    $result = $stmt->get_result();
    $activo = $result->fetch_assoc();
    $stmt->close();
    return $activo;
}


// --- GESTIÓN DE PETICIONES GET (PARA AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_activo_details'])) {
    header('Content-Type: application/json');
    $id_activo = filter_input(INPUT_GET, 'get_activo_details', FILTER_VALIDATE_INT);
    if (!$id_activo) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'ID de activo no válido.']); exit; }

    if (!verificar_permiso_sobre_activo($conexion, $id_activo, $id_usuario_actual_logueado, $rol_usuario_actual_sesion)) {
        http_response_code(403); echo json_encode(['success' => false, 'message' => 'No tiene permiso para ver este activo.']); exit;
    }
    
    $activo_data = fetch_activo_completo($conexion, $id_activo);
    if ($activo_data) {
        echo json_encode(['success' => true, 'activo' => $activo_data, 'permiso_baja' => tiene_permiso_para('dar_baja_activo')]);
    } else {
        http_response_code(404); echo json_encode(['success' => false, 'message' => 'Activo no encontrado.']);
    }
    exit;
}

// --- GESTIÓN DE PETICIONES POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mensaje = ""; $error_mensaje = ""; $id_activo_afectado_post = null;

    if (empty($id_usuario_actual_logueado)) {
        $error_mensaje = "Error de sesión. Por favor, inicie sesión nuevamente.";
        if ($is_ajax_request) { http_response_code(401); echo json_encode(['success' => false, 'message' => $error_mensaje]); exit; }
        $_SESSION['mantenimiento_error_mensaje'] = $error_mensaje; header("Location: mantenimiento.php"); exit;
    }

    if (!function_exists('registrar_evento_historial')) {
        throw new Exception("Error crítico: La función 'registrar_evento_historial' no está disponible en 'backend/historial_helper.php'.");
    }

    $conexion->begin_transaction();
    try {
        // ------ REGISTRAR NUEVO MANTENIMIENTO ------
        if (isset($_POST['registrar_nuevo_mantenimiento_submit'])) {
            $id_activo = filter_input(INPUT_POST, 'id_activo_mantenimiento', FILTER_VALIDATE_INT);
            if (!$id_activo) throw new Exception("ID de activo no válido.");
            if (!verificar_permiso_sobre_activo($conexion, $id_activo, $id_usuario_actual_logueado, $rol_usuario_actual_sesion)) throw new Exception("No tiene permiso para iniciar mantenimiento.");

            $activo_anterior = fetch_activo_completo($conexion, $id_activo);
            if (!$activo_anterior) throw new Exception("No se encontró el activo a modificar.");
            
            $stmt_update = $conexion->prepare("UPDATE activos_tecnologicos SET estado = 'En Mantenimiento' WHERE id = ?");
            $stmt_update->bind_param("i", $id_activo);
            $stmt_update->execute();
            $stmt_update->close();
            
            $diagnostico = trim($_POST['diagnostico_nuevo_mant'] ?? 'N/A');
            $descripcion = "Diagnóstico: $diagnostico. " . trim($_POST['detalle_trabajo_inicial_mant'] ?? '');

            $datos_anteriores = ['estado' => $activo_anterior['estado_actual']];
            $datos_nuevos = [
                'estado' => 'En Mantenimiento',
                'diagnostico' => $diagnostico,
                'costo_estimado' => (float) ($_POST['costo_estimado_mant'] ?? 0),
                'id_proveedor' => filter_input(INPUT_POST, 'proveedor_id_nuevo_mant', FILTER_VALIDATE_INT) ?: null,
                'id_tecnico' => filter_input(INPUT_POST, 'tecnico_interno_id_nuevo_mant', FILTER_VALIDATE_INT) ?: null,
            ];

            registrar_evento_historial($conexion, $id_activo, HISTORIAL_TIPO_MANTENIMIENTO_INICIADO, $descripcion, $usuario_actual_sistema_para_historial, $datos_anteriores, $datos_nuevos);
            $mensaje = "Mantenimiento iniciado exitosamente.";
            $id_activo_afectado_post = $id_activo;
        }
        // ------ FINALIZAR MANTENIMIENTO EXISTENTE ------
        elseif (isset($_POST['finalizar_mantenimiento_existente_submit'])) {
            $id_activo = filter_input(INPUT_POST, 'id_activo_finalizar', FILTER_VALIDATE_INT);
            if (!$id_activo) throw new Exception("ID de activo no válido.");
            if (!verificar_permiso_sobre_activo($conexion, $id_activo, $id_usuario_actual_logueado, $rol_usuario_actual_sesion)) throw new Exception("No tiene permiso para finalizar mantenimiento.");

            $estado_final = trim($_POST['estado_final_existente_mant'] ?? 'Bueno');
            $stmt_update = $conexion->prepare("UPDATE activos_tecnologicos SET estado = ? WHERE id = ?");
            $stmt_update->bind_param("si", $estado_final, $id_activo);
            $stmt_update->execute();
            $stmt_update->close();

            $descripcion = "Nuevo estado: $estado_final. " . trim($_POST['observaciones_finalizacion_mant'] ?? '');
            
            $datos_anteriores = ['estado' => 'En Mantenimiento'];
            $datos_nuevos = [
                'estado' => $estado_final,
                'costo_adicional' => (float) ($_POST['costo_adicional_final_mant'] ?? 0),
                'observaciones' => trim($_POST['observaciones_finalizacion_mant'] ?? '')
            ];
            
            registrar_evento_historial($conexion, $id_activo, HISTORIAL_TIPO_MANTENIMIENTO_FINALIZADO, $descripcion, $usuario_actual_sistema_para_historial, $datos_anteriores, $datos_nuevos);
            $mensaje = "Mantenimiento finalizado exitosamente.";
            $id_activo_afectado_post = $id_activo;
        }
        // ------ DAR DE BAJA ------
        elseif (isset($_POST['submit_dar_baja_desde_mantenimiento'])) {
            $id_activo = filter_input(INPUT_POST, 'id_activo_baja_mantenimiento', FILTER_VALIDATE_INT);
            if (!$id_activo) throw new Exception("ID de activo no válido.");
            if (!verificar_permiso_sobre_activo($conexion, $id_activo, $id_usuario_actual_logueado, $rol_usuario_actual_sesion)) throw new Exception("No tiene permiso para dar de baja este activo.");

            $activo_anterior = fetch_activo_completo($conexion, $id_activo);

            $stmt_baja = $conexion->prepare("UPDATE activos_tecnologicos SET estado = 'Dado de Baja' WHERE id = ?");
            $stmt_baja->bind_param('i', $id_activo);
            $stmt_baja->execute();
            $stmt_baja->close();

            $descripcion = "Motivo: " . trim($_POST['motivo_baja_mantenimiento'] ?? 'N/A') . ". " . trim($_POST['observaciones_baja_mantenimiento'] ?? '');
            
            $datos_anteriores = ['estado' => $activo_anterior['estado_actual']];
            $datos_nuevos = ['estado' => 'Dado de Baja'];

            registrar_evento_historial($conexion, $id_activo, HISTORIAL_TIPO_BAJA, $descripcion, $usuario_actual_sistema_para_historial, $datos_anteriores, $datos_nuevos);
            $mensaje = "Activo dado de baja exitosamente.";
            
            if ($is_ajax_request) { echo json_encode(['success' => true, 'message' => $mensaje, 'accion' => 'baja_exitosa']); $conexion->commit(); exit; }
        }

        $conexion->commit();
        
        if ($is_ajax_request && $id_activo_afectado_post) {
            $activo_actualizado = fetch_activo_completo($conexion, $id_activo_afectado_post);
            echo json_encode(['success' => true, 'message' => $mensaje, 'activo' => $activo_actualizado, 'permiso_baja' => tiene_permiso_para('dar_baja_activo')]);
            exit;
        }

    } catch (Exception $e) {
        if (isset($conexion) && $conexion->in_transaction) $conexion->rollback();
        $error_mensaje = "Error en la operación: " . $e->getMessage();
        if ($is_ajax_request) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $error_mensaje]);
            exit;
        }
    }

    $_SESSION['mantenimiento_mensaje'] = $mensaje;
    $_SESSION['mantenimiento_error_mensaje'] = $error_mensaje;
    header("Location: mantenimiento.php");
    exit;
}

// --- PRE-CARGA DE DATOS PARA FILTROS Y FORMULARIOS ---
if(isset($conexion) && $conexion) {
    $tipos_activo = $conexion->query("SELECT id_tipo_activo, nombre_tipo_activo FROM tipos_activo ORDER BY nombre_tipo_activo")->fetch_all(MYSQLI_ASSOC);
    $estados_activos = ['Bueno', 'Regular', 'Malo', 'En Mantenimiento', 'Dado de Baja'];
    $regionales = $conexion->query("SELECT DISTINCT regional FROM usuarios WHERE regional IS NOT NULL AND regional != '' ORDER BY regional")->fetch_all(MYSQLI_ASSOC);
    $empresas = $conexion->query("SELECT DISTINCT empresa FROM usuarios WHERE empresa IS NOT NULL AND empresa != '' ORDER BY empresa")->fetch_all(MYSQLI_ASSOC);
    $opciones_diagnostico = ['Falla de Hardware (General)', 'Falla de Componente Específico', 'Falla de Software (Sistema Operativo)', 'Falla de Software (Aplicación)', 'Mantenimiento Preventivo', 'Limpieza Interna/Externa', 'Actualización de Componentes', 'Actualización de Software/Firmware', 'Error de Configuración', 'Daño Físico Accidental', 'Problema de Red/Conectividad', 'Falla Eléctrica', 'Infección Virus/Malware', 'Desgaste por Uso', 'Otro (Detallar)'];
    $opciones_motivo_baja = ['Obsolescencia', 'Daño irreparable (Confirmado post-mantenimiento)', 'Pérdida', 'Robo', 'Venta', 'Donación', 'Fin de vida útil', 'Otro (especificar en observaciones)'];
    $estados_finales_operativos = ['Bueno', 'Regular', 'Malo'];
    $proveedores = $conexion->query("SELECT id, nombre_proveedor FROM proveedores_mantenimiento ORDER BY nombre_proveedor")->fetch_all(MYSQLI_ASSOC);
    $tecnicos_internos = $conexion->query("SELECT id, usuario, nombre_completo, rol FROM usuarios WHERE rol IN ('admin', 'tecnico') AND activo = 1 ORDER BY nombre_completo")->fetch_all(MYSQLI_ASSOC);
} else {
    $tipos_activo = $estados_activos = $regionales = $empresas = $opciones_diagnostico = $opciones_motivo_baja = $estados_finales_operativos = $proveedores = $tecnicos_internos = [];
    $error_critico_db = "Error de conexión a la base de datos. No se pudieron cargar los datos para los filtros.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Mantenimiento</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        html {
            height: 100%;
        }
        body { 
            background-color: #f4f7f6; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            padding-top: 95px; 
            display: flex; 
            flex-direction: column; 
            min-height:100vh; 
        }
        .main-content-area { 
            flex-grow: 1; 
        } 
        .top-bar-custom { 
            position: fixed; 
            top: 0; 
            left: 0; 
            right: 0; 
            z-index: 1030; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 0.5rem 1.5rem; 
            background-color: #ffffff; 
            border-bottom: 1px solid #e0e0e0; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
        }
        .logo-container-top img { height: 75px; }
        .user-info-top { font-size: 0.9rem; }
        .footer-custom { 
            font-size: 0.9rem; 
            background-color: #f8f9fa; 
            border-top: 1px solid #dee2e6; 
            padding: 1rem 0; 
            margin-top: auto; 
        }
        .footer-custom a i { 
            color: #6c757d; 
            transition: color 0.2s; 
        }
        .footer-custom a i:hover { 
            color: #0d6efd; 
        }
    </style>
    </head>
<body>
    <div class="top-bar-custom">
        <div class="logo-container-top">
            <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo Empresa"></a>
        </div>
        <div class="d-flex align-items-center">
            <span class="text-dark me-3 user-info-top"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
            <form action="logout.php" method="post" class="d-flex">
                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Salir</button>
            </form>
        </div>
    </div>

    <div class="container mt-4 main-content-area">
        <h3 class="mb-4 text-center page-header-title">Mantenimiento de Activos</h3>
        <div id="mensajes-ajax-container"></div>
        
        <?php if (isset($error_critico_db)): ?>
            <div class="alert alert-danger"><?= $error_critico_db ?></div>
        <?php else: ?>

        <div class="accordion mb-4" id="acordeonFiltros">
            <div class="accordion-item">
                <h2 class="accordion-header" id="headingFiltros"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFiltros"><i class="bi bi-search me-2"></i> Búsqueda y Filtros</button></h2>
                <div id="collapseFiltros" class="accordion-collapse collapse show">
                    <div class="accordion-body bg-light">
                        <form id="form-filtros-avanzados" onsubmit="return false;">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6 col-lg-4"><label for="filtro-q" class="form-label">Búsqueda General</label><input type="text" id="filtro-q" name="q" class="form-control form-control-sm" placeholder="Serie, Cód. Inv., Responsable..."></div>
                                <div class="col-md-6 col-lg-4"><label for="filtro-estado" class="form-label">Estado</label><select id="filtro-estado" name="estado" class="form-select form-select-sm"><option value="">Todos</option><?php foreach ($estados_activos as $estado): ?><option value="<?= htmlspecialchars($estado) ?>"><?= htmlspecialchars($estado) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-6 col-lg-4"><label for="filtro-tipo-activo" class="form-label">Tipo de Activo</label><select id="filtro-tipo-activo" name="tipo_activo" class="form-select form-select-sm"><option value="">Todos</option><?php foreach ($tipos_activo as $tipo): ?><option value="<?= htmlspecialchars($tipo['id_tipo_activo']) ?>"><?= htmlspecialchars($tipo['nombre_tipo_activo']) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-6 col-lg-4"><label for="filtro-regional" class="form-label">Regional</label><select id="filtro-regional" name="regional" class="form-select form-select-sm"><option value="">Todas</option><?php foreach ($regionales as $regional): ?><option value="<?= htmlspecialchars($regional['regional']) ?>"><?= htmlspecialchars($regional['regional']) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-6 col-lg-4"><label for="filtro-empresa" class="form-label">Empresa</label><select id="filtro-empresa" name="empresa" class="form-select form-select-sm"><option value="">Todas</option><?php foreach ($empresas as $empresa): ?><option value="<?= htmlspecialchars($empresa['empresa']) ?>"><?= htmlspecialchars($empresa['empresa']) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-6 col-lg-4 d-flex justify-content-end"><button type="button" class="btn btn-sm btn-outline-secondary" id="btn-limpiar-filtros"><i class="bi bi-eraser me-1"></i>Limpiar</button></div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div id="contenedor-resultados-filtrados" class="mt-4"></div>
        <div id="vista-mantenimiento-individual" class="d-none mt-4"></div>
        
        <?php endif; ?>
    </div>
    
    <div class="modal fade" id="modalDarBaja" tabindex="-1" aria-labelledby="modalDarBajaLabel" aria-hidden="true">
        <div class="modal-dialog"><div class="modal-content">
            <form id="formDarBajaMantenimiento" onsubmit="return false;">
                <div class="modal-header"><h5 class="modal-title" id="modalDarBajaLabel"><i class="bi bi-exclamation-triangle-fill text-danger"></i> Confirmar Baja de Activo</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <p>Está a punto de dar de baja el activo con serie: <strong id="serieActivoBajaModal"></strong>.</p>
                    <input type="hidden" name="id_activo_baja_mantenimiento" id="idActivoBajaModal">
                    <div class="mb-3"><label for="motivo_baja_mantenimiento" class="form-label">Motivo <span class="text-danger">*</span></label><select class="form-select" id="motivo_baja_mantenimiento" name="motivo_baja_mantenimiento" required><option value="">Seleccione...</option><?php foreach ($opciones_motivo_baja as $motivo): ?><option value="<?= htmlspecialchars($motivo) ?>"><?= htmlspecialchars($motivo) ?></option><?php endforeach; ?></select></div>
                    <div class="mb-3"><label for="observaciones_baja_mantenimiento" class="form-label">Observaciones</label><textarea class="form-control" id="observaciones_baja_mantenimiento" name="observaciones_baja_mantenimiento" rows="3"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger">Confirmar Baja</button></div>
            </form>
        </div></div>
    </div>

<footer class="footer-custom mt-auto py-3 bg-light border-top shadow-sm">
        <div class="container text-center">
            <div class="row align-items-center">
                <div class="col-md-6 text-md-start mb-2 mb-md-0">
                    <small class="text-muted">Sitio web desarrollado por <a href="https://www.julianxitoso.com" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-primary">@julianxitoso.com</a></small>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="https://facebook.com/tu_pagina" target="_blank" class="text-muted me-3" title="Facebook">
                        <i class="bi bi-facebook" style="font-size: 1.5rem;"></i>
                    </a>
                    <a href="https://instagram.com/tu_usuario" target="_blank" class="text-muted me-3" title="Instagram">
                        <i class="bi bi-instagram" style="font-size: 1.5rem;"></i>
                    </a>
                    <a href="https://tiktok.com/@tu_usuario" target="_blank" class="text-muted" title="TikTok">
                        <i class="bi bi-tiktok" style="font-size: 1.5rem;"></i>
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // =================================================================
    // INICIO: LÓGICA JAVASCRIPT COMPLETA Y FUNCIONAL
    // =================================================================
    document.addEventListener('DOMContentLoaded', function () {
        const opciones_diagnostico = <?= json_encode($opciones_diagnostico) ?>;
        const estados_finales_operativos = <?= json_encode($estados_finales_operativos) ?>;
        const proveedores = <?= json_encode($proveedores) ?>;
        const tecnicos_internos = <?= json_encode($tecnicos_internos) ?>;
        const formFiltros = document.getElementById('form-filtros-avanzados');
        const btnLimpiar = document.getElementById('btn-limpiar-filtros');
        const resultadosContainer = document.getElementById('contenedor-resultados-filtrados');
        const vistaIndividualContainer = document.getElementById('vista-mantenimiento-individual');
        const mensajesContainer = document.getElementById('mensajes-ajax-container');
        const modalDarBajaEl = document.getElementById('modalDarBaja');
        let modalDarBajaInstance = modalDarBajaEl ? new bootstrap.Modal(modalDarBajaEl) : null;

        const debounce = (func, timeout = 400) => { let timer; return (...args) => { clearTimeout(timer); timer = setTimeout(() => { func.apply(this, args); }, timeout); }; };
        
        const buscarConFiltros = async () => {
            const formData = new FormData(formFiltros);
            const params = new URLSearchParams(formData).toString();
            resultadosContainer.innerHTML = '<div class="d-flex justify-content-center p-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Buscando...</span></div></div>';
            vistaIndividualContainer.classList.add('d-none');
            resultadosContainer.classList.remove('d-none');
            try {
                const response = await fetch(`api/api_buscar.php?${params}`);
                if (!response.ok) throw new Error(`Error: ${response.statusText}`);
                const activos = await response.json();
                renderizarListaResultados(activos);
            } catch (error) {
                mostrarMensaje('Error de conexión al realizar la búsqueda.', 'danger');
            }
        };
        formFiltros.addEventListener('input', debounce(buscarConFiltros));
        btnLimpiar.addEventListener('click', () => { formFiltros.reset(); buscarConFiltros(); });

        function renderizarListaResultados(activos) {
            resultadosContainer.innerHTML = '';
            if (!activos || activos.length === 0) {
                resultadosContainer.innerHTML = '<div class="alert alert-light text-center mt-3">No se encontraron activos con los criterios seleccionados.</div>';
                return;
            }
            const lista = document.createElement('div');
            lista.className = 'list-group';
            activos.forEach(activo => {
                const item = document.createElement('a');
                item.href = '#';
                item.className = 'list-group-item list-group-item-action';
                item.dataset.id = activo.id;
                item.innerHTML = `<div class="d-flex w-100 justify-content-between"><h6 class="mb-1 text-primary">${activo.nombre_tipo_activo} - ${activo.marca || 'N/A'}</h6><span class="badge bg-secondary">${activo.estado}</span></div><p class="mb-1 small">S/N: <strong>${activo.serie}</strong> - Cód.Inv: ${activo.Codigo_Inv || 'N/A'}</p><small class="text-muted">Responsable: ${activo.nombre_responsable || 'Sin asignar'}</small>`;
                item.addEventListener('click', e => { e.preventDefault(); cargarVistaIndividual(activo.id); });
                lista.appendChild(item);
            });
            resultadosContainer.appendChild(lista);
        }

        async function cargarVistaIndividual(idActivo) {
            resultadosContainer.classList.add('d-none');
            vistaIndividualContainer.innerHTML = '<div class="d-flex justify-content-center p-5"><div class="spinner-border text-primary" role="status"></div></div>';
            vistaIndividualContainer.classList.remove('d-none');
            try {
                const response = await fetch(`mantenimiento.php?get_activo_details=${idActivo}`);
                if (!response.ok) {
                    // Manejar errores de permiso o no encontrado
                    const errorData = await response.json().catch(() => ({ message: 'Error al procesar la respuesta del servidor.' }));
                    throw new Error(errorData.message || 'El activo no se pudo cargar.');
                }
                const data = await response.json();
                if(data.success) renderizarVistaIndividual(data.activo, data.permiso_baja);
                else mostrarMensaje(data.message, 'danger');
            } catch (error) {
                mostrarMensaje(error.message, 'danger');
                // Opcional: volver a la lista si hay error
                vistaIndividualContainer.classList.add('d-none'); 
                resultadosContainer.classList.remove('d-none');
            }
        }
        
        function renderizarVistaIndividual(activo, permiso_baja) {
            const botonVolver = `<button class="btn btn-sm btn-outline-secondary mb-3" id="btn-volver-lista"><i class="bi bi-arrow-left"></i> Volver a la lista</button>`;
            const infoHTML = `<div class="col-lg-4 mb-4"><div class="card h-100"><div class="card-header"><h5 class="mb-0"><i class="bi bi-info-circle-fill"></i> Detalles</h5></div><div class="card-body"><dl class="row mb-0 small"><dt class="col-sm-5">ID:</dt><dd class="col-sm-7">${activo.id}</dd><dt class="col-sm-5">Tipo:</dt><dd class="col-sm-7">${activo.nombre_tipo_activo}</dd><dt class="col-sm-5">Marca/Serie:</dt><dd class="col-sm-7">${activo.marca} / ${activo.serie}</dd><dt class="col-sm-5">Estado:</dt><dd class="col-sm-7"><strong>${activo.estado_actual}</strong></dd><dt class="col-sm-5">Responsable:</dt><dd class="col-sm-7">${activo.nombre_responsable || 'N/A'}</dd></dl></div></div></div>`;
            const formHTML = activo.estado_actual === 'En Mantenimiento' ? generarFormularioFinalizar(activo, permiso_baja) : generarFormularioIniciar(activo, permiso_baja);
            vistaIndividualContainer.innerHTML = `${botonVolver}<div class="row">${infoHTML}${formHTML}</div>`;
            document.getElementById('btn-volver-lista').addEventListener('click', () => { vistaIndividualContainer.classList.add('d-none'); resultadosContainer.classList.remove('d-none'); });
        }

        vistaIndividualContainer.addEventListener('submit', async function(e){
            e.preventDefault();
            const form = e.target;
            if(form.tagName !== 'FORM') return;
            const submitButton = form.querySelector('button[type="submit"]');
            if(!submitButton) return;
            const formData = new FormData(form);
            formData.append('ajax_request', '1');
            formData.append(submitButton.name, '1');
            const originalButtonHTML = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Procesando...`;
            try {
                const response = await fetch('mantenimiento.php', { method: 'POST', body: formData });
                const data = await response.json();
                if(data.success){
                    mostrarMensaje(data.message, 'success');
                    if(data.accion === 'baja_exitosa'){
                        buscarConFiltros();
                    } else {
                        renderizarVistaIndividual(data.activo, data.permiso_baja);
                    }
                } else {
                    mostrarMensaje(data.message || 'Ocurrió un error.', 'danger');
                }
            } catch (err) {
                mostrarMensaje('Error de conexión.', 'danger');
            } finally {
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonHTML;
            }
        });

        if(modalDarBajaEl) {
            modalDarBajaEl.addEventListener('show.bs.modal', event => {
                const button = event.relatedTarget;
                modalDarBajaEl.querySelector('#idActivoBajaModal').value = button.dataset.idActivo;
                modalDarBajaEl.querySelector('#serieActivoBajaModal').textContent = button.dataset.serieActivo;
            });
            modalDarBajaEl.querySelector('form').addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(e.target);
                formData.append('ajax_request', '1');
                formData.append('submit_dar_baja_desde_mantenimiento', '1');
                try {
                    const response = await fetch('mantenimiento.php', { method: 'POST', body: formData });
                    const data = await response.json();
                    if(data.success){
                        mostrarMensaje(data.message, 'success');
                        modalDarBajaInstance.hide();
                        buscarConFiltros();
                    } else { alert('Error: ' + (data.message || 'Ocurrió un error.'));}
                } catch(error){ alert('Error de conexión al dar de baja.');}
            });
        }
        
        function generarOpcionesSelect(items, valueField, textField, prompt) {
            let options = `<option value="">${prompt}</option>`;
            if (items && Array.isArray(items)) {
                options += items.map(item => `<option value="${item[valueField]}">${item[textField]}</option>`).join('');
            }
            return options;
        }

        function generarFormularioIniciar(activo, permiso_baja) {
            return `<div class="col-lg-8 mb-4"><div class="card h-100"><div class="card-header"><h5 class="mb-0 text-primary"><i class="bi bi-tools"></i> Registrar Mantenimiento</h5></div><div class="card-body">
                <form><input type="hidden" name="id_activo_mantenimiento" value="${activo.id}">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Fecha Inicio <span class="text-danger">*</span></label><input type="date" class="form-control form-control-sm" name="fecha_inicio_mantenimiento" required value="${new Date().toISOString().slice(0, 10)}"></div>
                        <div class="col-md-6"><label class="form-label">Costo Estimado (COP)</label><input type="number" class="form-control form-control-sm" name="costo_estimado_mant" step="0.01" min="0" value="0"></div>
                        <div class="col-md-12"><label class="form-label">Diagnóstico <span class="text-danger">*</span></label><select class="form-select form-select-sm" name="diagnostico_nuevo_mant" required>${generarOpcionesSelect(opciones_diagnostico.map(d => ({v:d, t:d})), 'v', 't', 'Seleccione diagnóstico...')}</select></div>
                        <div class="col-md-12"><label class="form-label">Detalle del Trabajo</label><textarea class="form-control form-control-sm" name="detalle_trabajo_inicial_mant" rows="2"></textarea></div>
                        <div class="col-md-6"><label class="form-label">Proveedor</label><select class="form-select form-select-sm" name="proveedor_id_nuevo_mant">${generarOpcionesSelect(proveedores, 'id', 'nombre_proveedor', 'Interno / N/A')}</select></div>
                        <div class="col-md-6"><label class="form-label">Técnico Interno</label><select class="form-select form-select-sm" name="tecnico_interno_id_nuevo_mant">${generarOpcionesSelect(tecnicos_internos, 'id', 'nombre_completo', 'Externo / N/A')}</select></div>
                    </div>
                    <div class="mt-4 d-flex justify-content-between align-items-center">
                        <button type="submit" name="registrar_nuevo_mantenimiento_submit" class="btn btn-success"><i class="bi bi-save"></i> Iniciar Mantenimiento</button>
                        ${permiso_baja ? `<button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalDarBaja" data-id-activo="${activo.id}" data-serie-activo="${activo.serie}"><i class="bi bi-trash3"></i> Dar de Baja</button>` : ''}
                    </div>
                </form></div></div></div>`;
        }

        function generarFormularioFinalizar(activo, permiso_baja) {
            return `<div class="col-lg-8 mb-4"><div class="card h-100"><div class="card-header"><h5 class="mb-0 text-primary"><i class="bi bi-check2-circle"></i> Finalizar Mantenimiento</h5></div><div class="card-body">
                <p class="alert alert-info small"><i class="bi bi-info-circle-fill"></i> El activo S/N: <strong>${activo.serie}</strong> está "En Mantenimiento".</p>
                <form><input type="hidden" name="id_activo_finalizar" value="${activo.id}">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Fecha Finalización <span class="text-danger">*</span></label><input type="date" class="form-control form-control-sm" name="fecha_finalizacion_mant" value="${new Date().toISOString().slice(0, 10)}" required></div>
                        <div class="col-md-6"><label class="form-label">Nuevo Estado <span class="text-danger">*</span></label><select class="form-select form-select-sm" name="estado_final_existente_mant" required>${generarOpcionesSelect(estados_finales_operativos.map(e => ({v:e, t:e})), 'v', 't', 'Seleccione...')}</select></div>
                        <div class="col-md-12"><label class="form-label">Observaciones</label><textarea class="form-control form-control-sm" name="observaciones_finalizacion_mant" rows="2"></textarea></div>
                        <div class="col-md-6"><label class="form-label">Costo Adicional (COP)</label><input type="number" class="form-control form-control-sm" name="costo_adicional_final_mant" step="0.01" min="0" value="0"></div>
                    </div>
                    <div class="mt-3 d-flex justify-content-between">
                        <button type="submit" name="finalizar_mantenimiento_existente_submit" class="btn btn-success"><i class="bi bi-check-all"></i> Finalizar Mantenimiento</button>
                        ${permiso_baja ? `<button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalDarBaja" data-id-activo="${activo.id}" data-serie-activo="${activo.serie}"><i class="bi bi-trash3"></i> Dar de Baja</button>` : ''}
                    </div>
                </form></div></div></div>`;
        }

        function mostrarMensaje(texto, tipo = 'info') {
            const idAlerta = `alerta-${Date.now()}`;
            mensajesContainer.innerHTML = `<div class="alert alert-${tipo} alert-dismissible fade show" role="alert" id="${idAlerta}">${texto}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
            setTimeout(() => { const alerta = document.getElementById(idAlerta); if (alerta) { const bsAlert = bootstrap.Alert.getOrCreateInstance(alerta); bsAlert.close(); } }, 5000);
        }
        
        if (formFiltros) {
            buscarConFiltros();
        }
    });
    </script>
</body>
</html>