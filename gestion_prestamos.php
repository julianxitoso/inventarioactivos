<?php
// Ya no es necesario mostrar los errores, el código está corregido.
// Si en el futuro tienes otro problema, puedes volver a activar estas líneas.
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

session_start();

require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico', 'registrador', 'auditor']);

require_once 'backend/db.php';
require_once 'backend/historial_helper.php';

if (!defined('HISTORIAL_TIPO_CREACION')) define('HISTORIAL_TIPO_CREACION', 'CREACIÓN');
if (!defined('HISTORIAL_TIPO_ACTUALIZACION')) define('HISTORIAL_TIPO_ACTUALIZACION', 'ACTUALIZACIÓN');
if (!defined('HISTORIAL_TIPO_PRESTAMO_INICIO')) define('HISTORIAL_TIPO_PRESTAMO_INICIO', 'INICIO PRÉSTAMO');
if (!defined('HISTORIAL_TIPO_PRESTAMO_FIN')) define('HISTORIAL_TIPO_PRESTAMO_FIN', 'FIN PRÉSTAMO');

$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $db_conn_error_detail = method_exists($conexion, 'connect_error') ? $conexion->connect_error : 'Desconocido';
    error_log("Error de conexión BD en gestion_prestamos.php: " . $db_conn_error_detail);
    $conexion_error_msg = "<div class='alert alert-danger'>Error crítico de conexión a la base de datos. Contacte al administrador.</div>";
} else {
    $conexion->set_charset("utf8mb4");
}

// --- INICIO: LÓGICA PARA RESPONDER A PETICIONES AJAX (BUSCAR ACTIVOS POR RESPONSABLE) ---
if (!$conexion_error_msg && isset($_GET['accion_ajax']) && $_GET['accion_ajax'] === 'buscar_activos_por_cedula_responsable') {
    error_reporting(0);
    ini_set('display_errors', 0);
    
    header('Content-Type: application/json');
    $response_ajax = ['success' => false, 'activos' => [], 'mensaje' => '', 'nombre_responsable' => '', 'id_responsable' => null];
    $cedula_responsable_ajax = trim($_GET['cedula_responsable'] ?? '');

    if (empty($cedula_responsable_ajax)) {
        $response_ajax['mensaje'] = 'Por favor, ingrese una cédula.';
        echo json_encode($response_ajax); exit;
    }
    $stmt_get_user = $conexion->prepare("SELECT id, nombre_completo FROM usuarios WHERE usuario = ? AND activo = 1");
    if (!$stmt_get_user) { $response_ajax['mensaje'] = 'Error P1'; error_log("[AJAX gestion_prestamos] Error prepare (get_user): " . $conexion->error); echo json_encode($response_ajax); exit; }
    $stmt_get_user->bind_param("s", $cedula_responsable_ajax);
    if(!$stmt_get_user->execute()){ $response_ajax['mensaje'] = 'Error E1'; error_log("[AJAX gestion_prestamos] Error execute (get_user): " . $stmt_get_user->error); echo json_encode($response_ajax); exit; }
    $result_user = $stmt_get_user->get_result();
    if ($user_data = $result_user->fetch_assoc()) {
        $id_usuario_responsable_encontrado = $user_data['id'];
        $response_ajax['nombre_responsable'] = $user_data['nombre_completo'];
        $response_ajax['id_responsable'] = $id_usuario_responsable_encontrado;
        $sql_activos_resp = "SELECT a.id as id_activo, a.serie, a.marca, a.estado as estado_actual_activo, ta.nombre_tipo_activo FROM activos_tecnologicos a JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo WHERE a.id_usuario_responsable = ? AND a.estado IN ('Bueno', 'Regular', 'Nuevo', 'Disponible') ORDER BY ta.nombre_tipo_activo, a.marca, a.serie";
        $stmt_activos = $conexion->prepare($sql_activos_resp);
        if (!$stmt_activos) { $response_ajax['mensaje'] = 'Error P2'; error_log("[AJAX gestion_prestamos] Error prepare (get_activos): " . $conexion->error); echo json_encode($response_ajax); exit; }
        $stmt_activos->bind_param("i", $id_usuario_responsable_encontrado);
        if(!$stmt_activos->execute()){ $response_ajax['mensaje'] = 'Error E2'; error_log("[AJAX gestion_prestamos] Error execute (get_activos): " . $stmt_activos->error); echo json_encode($response_ajax); exit; }
        $result_activos_resp = $stmt_activos->get_result();
        while ($row_activo = $result_activos_resp->fetch_assoc()) { $response_ajax['activos'][] = $row_activo; }
        $stmt_activos->close();
        if (!empty($response_ajax['activos'])) { $response_ajax['success'] = true; } 
        else { $response_ajax['mensaje'] = 'El usuario ' . htmlspecialchars($user_data['nombre_completo']) . ' (C.C: ' . htmlspecialchars($cedula_responsable_ajax) . ') no tiene activos disponibles para prestar en este momento.'; }
    } else { $response_ajax['mensaje'] = 'No se encontró un usuario activo con la cédula: ' . htmlspecialchars($cedula_responsable_ajax); }
    $stmt_get_user->close();
    echo json_encode($response_ajax); exit; 
}

// --- INICIO: NUEVA LÓGICA AJAX PARA BUSCAR USUARIOS (PARA SELECT2) ---
elseif (!$conexion_error_msg && isset($_GET['accion_ajax']) && $_GET['accion_ajax'] === 'buscar_usuarios') {
    header('Content-Type: application/json');
    $searchTerm = trim($_GET['term'] ?? '');
    $response_data = ['results' => []];

    if (!empty($searchTerm)) {
        // Buscamos usuarios activos cuyo nombre O cédula coincida con el término de búsqueda
        $sql_buscar_usuarios = "SELECT id, nombre_completo, usuario FROM usuarios 
                                WHERE activo = 1 AND (nombre_completo LIKE ? OR usuario LIKE ?) 
                                ORDER BY nombre_completo ASC LIMIT 15";
        
        $stmt_buscar_usuarios = $conexion->prepare($sql_buscar_usuarios);
        $likeTerm = "%{$searchTerm}%";
        $stmt_buscar_usuarios->bind_param("ss", $likeTerm, $likeTerm);
        $stmt_buscar_usuarios->execute();
        $result = $stmt_buscar_usuarios->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Formateamos la respuesta como espera la librería Select2: un array de objetos con 'id' y 'text'
            $response_data['results'][] = [
                'id' => $row['id'],
                'text' => htmlspecialchars($row['nombre_completo'] . ' (C.C: ' . $row['usuario'] . ')')
            ];
        }
        $stmt_buscar_usuarios->close();
    }
    
    echo json_encode($response_data);
    exit;
}
// --- FIN: NUEVA LÓGICA AJAX PARA BUSCAR USUARIOS ---

// --- FIN LÓGICA AJAX ---

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';
$id_usuario_actual_logueado = $_SESSION['usuario_id'] ?? null; 
$cedula_usuario_actual_logueado = $_SESSION['usuario_login'] ?? null; 

$mensaje_accion = $_SESSION['mensaje_accion_prestamos'] ?? null;
if ($conexion_error_msg && empty($mensaje_accion)) { $mensaje_accion = $conexion_error_msg; }
unset($_SESSION['mensaje_accion_prestamos']);

$prestamo_para_editar = null; 
$abrir_modal_creacion_prestamo_js = false;
$abrir_modal_devolucion_prestamo_js = false; 


// --- LÓGICA POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$conexion_error_msg) {
    
    $id_usuario_que_registra = $_SESSION['usuario_id'] ?? null;

    if (empty($id_usuario_que_registra)) { 
        $_SESSION['mensaje_accion_prestamos'] = "<div class='alert alert-danger'>Error de sesión: No se pudo identificar al usuario que registra el préstamo. Por favor, inicie sesión nuevamente.</div>";
        error_log("Error en gestion_prestamos.php: id_usuario_que_registra (desde \$_SESSION['usuario_id']) es nulo. \$_SESSION['usuario_id'] = " . ($_SESSION['usuario_id'] ?? 'NO DEFINIDO'));
        header("Location: gestion_prestamos.php?error_creacion_prestamo=1"); 
        exit;
    }

    // ------ CREAR NUEVO PRÉSTAMO ------
    if (isset($_POST['registrar_prestamo_submit'])) {
        $id_activo = filter_input(INPUT_POST, 'id_activo_prestamo', FILTER_VALIDATE_INT);
        $id_usuario_recibe = filter_input(INPUT_POST, 'id_usuario_recibe_prestamo', FILTER_VALIDATE_INT);
        $fecha_prestamo_str = trim($_POST['fecha_prestamo_modal'] ?? '');
        $fecha_devolucion_esperada_str = trim($_POST['fecha_devolucion_esperada_modal'] ?? '');
        $estado_activo_al_prestar = trim($_POST['estado_activo_al_prestar_hidden'] ?? '');
        $observaciones_prestamo = trim($_POST['observaciones_prestamo_modal'] ?? '');
        $id_responsable_anterior_prestamo = filter_input(INPUT_POST, 'id_responsable_prestador_hidden', FILTER_VALIDATE_INT);

        if (empty($id_activo) || empty($id_usuario_recibe) || empty($fecha_prestamo_str) || empty($fecha_devolucion_esperada_str) || empty($id_responsable_anterior_prestamo)) {
            $_SESSION['mensaje_accion_prestamos'] = "<div class='alert alert-danger'>Creación Préstamo: Activo, Usuario Prestador, Usuario Receptor, Fecha de Préstamo y Fecha Esperada de Devolución son obligatorios.</div>";
            header("Location: gestion_prestamos.php?error_creacion_prestamo=1"); exit;
        }
        
        $fecha_prestamo = date('Y-m-d', strtotime($fecha_prestamo_str));
        $fecha_devolucion_esperada = date('Y-m-d', strtotime($fecha_devolucion_esperada_str));

        if (!$fecha_prestamo || !$fecha_devolucion_esperada || $fecha_devolucion_esperada < $fecha_prestamo) {
             $_SESSION['mensaje_accion_prestamos'] = "<div class='alert alert-danger'>Creación Préstamo: Las fechas proporcionadas no son válidas o la fecha de devolución es anterior a la de préstamo.</div>";
             header("Location: gestion_prestamos.php?error_creacion_prestamo=1"); exit;
        }
        
        $conexion->begin_transaction();
        try {
            $sql_insert_prestamo = "INSERT INTO prestamos_activos 
                                    (id_activo, id_usuario_presta, id_usuario_recibe, id_departamento_recibe, fecha_prestamo, fecha_devolucion_esperada, estado_activo_prestamo, observaciones_prestamo, estado_prestamo) 
                                    VALUES (?, ?, ?, NULL, ?, ?, ?, ?, 'Activo')";
            $stmt_prestamo = $conexion->prepare($sql_insert_prestamo);
            if (!$stmt_prestamo) throw new Exception("Error al preparar inserción de préstamo: " . $conexion->error);
            
            $stmt_prestamo->bind_param("iiissss", 
                $id_activo, 
                $id_usuario_que_registra, 
                $id_usuario_recibe, 
                $fecha_prestamo, 
                $fecha_devolucion_esperada, 
                $estado_activo_al_prestar, 
                $observaciones_prestamo
            );
            
            if (!$stmt_prestamo->execute()) throw new Exception("Error al registrar el préstamo: " . $stmt_prestamo->error);
            $id_prestamo_creado = $conexion->insert_id;
            $stmt_prestamo->close();

            $sql_update_activo = "UPDATE activos_tecnologicos SET estado = 'En Préstamo', id_usuario_responsable = ? WHERE id = ?";
            $stmt_update_activo = $conexion->prepare($sql_update_activo);
            if (!$stmt_update_activo) throw new Exception("Error al preparar actualización de estado/responsable del activo: " . $conexion->error);
            
            $stmt_update_activo->bind_param("ii", $id_usuario_recibe, $id_activo);
            if (!$stmt_update_activo->execute()) throw new Exception("Error al actualizar estado/responsable del activo: " . $stmt_update_activo->error);
            $stmt_update_activo->close();

            // =================================================================================
            // ### INICIO DE LA CORRECCIÓN ###
            // Se obtiene la información del usuario receptor directamente desde la base de datos,
            // en lugar de depender de un array pre-cargado que ya no existe.
            
            $nombre_receptor = "ID Usuario: " . $id_usuario_recibe; // Valor por defecto si no se encuentra
            $stmt_receptor = $conexion->prepare("SELECT nombre_completo, usuario FROM usuarios WHERE id = ?");
            if ($stmt_receptor) {
                $stmt_receptor->bind_param("i", $id_usuario_recibe);
                if ($stmt_receptor->execute()) {
                    $result_receptor = $stmt_receptor->get_result();
                    if ($receptor_data = $result_receptor->fetch_assoc()) {
                        $nombre_receptor = htmlspecialchars($receptor_data['nombre_completo']) . " (C.C: " . htmlspecialchars($receptor_data['usuario']) . ")";
                    }
                }
                $stmt_receptor->close();
            }
            // ### FIN DE LA CORRECCIÓN ###
            // =================================================================================
            
            $nombre_responsable_anterior_hist = "Desconocido";
            if($id_responsable_anterior_prestamo){ 
                $stmt_resp_ant = $conexion->prepare("SELECT nombre_completo, usuario FROM usuarios WHERE id = ?");
                if($stmt_resp_ant){
                    $stmt_resp_ant->bind_param("i", $id_responsable_anterior_prestamo);
                    $stmt_resp_ant->execute();
                    $res_resp_ant = $stmt_resp_ant->get_result();
                    if($row_resp_ant = $res_resp_ant->fetch_assoc()){
                        $nombre_responsable_anterior_hist = $row_resp_ant['nombre_completo'] . " (C.C: ".$row_resp_ant['usuario'].")";
                    }
                    $stmt_resp_ant->close();
                }
            }
            $descripcion_historial = "Activo prestado. De: " . $nombre_responsable_anterior_hist . " A: " . $nombre_receptor . ". ";
            $descripcion_historial .= "Fecha préstamo: " . date('d/m/Y', strtotime($fecha_prestamo)) . ". ";
            $descripcion_historial .= "Devolución esperada: " . date('d/m/Y', strtotime($fecha_devolucion_esperada)) . ".";
            if(!empty($estado_activo_al_prestar)) $descripcion_historial .= " Estado al prestar (registrado): " . $estado_activo_al_prestar . ".";

            $datos_anteriores_historial = ['id_usuario_responsable' => $id_responsable_anterior_prestamo, 'nombre_responsable' => $nombre_responsable_anterior_hist, 'estado_activo' => $estado_activo_al_prestar];
            $datos_nuevos_historial = [
                'id_prestamo' => $id_prestamo_creado,
                'id_usuario_responsable' => $id_usuario_recibe, 
                'nombre_responsable' => $nombre_receptor,
                'estado_activo' => 'En Préstamo',
                'fecha_prestamo' => $fecha_prestamo,
                'fecha_devolucion_esperada' => $fecha_devolucion_esperada,
                'estado_registrado_al_prestar' => $estado_activo_al_prestar,
                'observaciones_prestamo' => $observaciones_prestamo
            ];
            registrar_evento_historial($conexion, $id_activo, HISTORIAL_TIPO_PRESTAMO_INICIO, $descripcion_historial, $_SESSION['usuario_login'] ?? 'sistema', $datos_anteriores_historial, $datos_nuevos_historial);

            $conexion->commit();
            $_SESSION['mensaje_accion_prestamos'] = "<div class='alert alert-success'>Préstamo registrado exitosamente (ID Préstamo: {$id_prestamo_creado}). El activo ha sido asignado al nuevo responsable y su estado es 'En Préstamo'.</div>";
            header("Location: gestion_prestamos.php"); exit;

        } catch (Exception $e) {
            $conexion->rollback();
            error_log("Error en transacción de préstamo: " . $e->getMessage());
            $_SESSION['mensaje_accion_prestamos'] = "<div class='alert alert-danger'>Error en la operación: " . htmlspecialchars($e->getMessage()) . "</div>";
            header("Location: gestion_prestamos.php?error_creacion_prestamo=1"); exit;
        }
    }
    // ------ REGISTRAR DEVOLUCIÓN DE PRÉSTAMO ------
    elseif (isset($_POST['registrar_devolucion_submit'])) {
        $id_prestamo_devolver = filter_input(INPUT_POST, 'id_prestamo_devolucion', FILTER_VALIDATE_INT);
        $id_activo_devuelto = filter_input(INPUT_POST, 'id_activo_devolucion_hidden', FILTER_VALIDATE_INT); 
        $id_usuario_presta_original = filter_input(INPUT_POST, 'id_usuario_presta_original_hidden', FILTER_VALIDATE_INT); 
        
        $fecha_devolucion_real_str = trim($_POST['fecha_devolucion_real_modal'] ?? '');
        $estado_activo_devolucion = trim($_POST['estado_activo_devolucion_modal'] ?? '');
        $observaciones_devolucion = trim($_POST['observaciones_devolucion_modal'] ?? '');

        if (empty($id_prestamo_devolver) || empty($id_activo_devuelto) || empty($fecha_devolucion_real_str) || empty($estado_activo_devolucion) || empty($id_usuario_presta_original)) {
            $_SESSION['mensaje_accion_prestamos'] = "<div class='alert alert-danger'>Devolución: Faltan datos obligatorios.</div>";
            header("Location: gestion_prestamos.php?error_devolucion_prestamo=1&id_prestamo_err=" . $id_prestamo_devolver); exit;
        }

        $fecha_devolucion_real = date('Y-m-d', strtotime($fecha_devolucion_real_str));
        if (!$fecha_devolucion_real) {
            $_SESSION['mensaje_accion_prestamos'] = "<div class='alert alert-danger'>Devolución: Formato de fecha de devolución inválido.</div>";
            header("Location: gestion_prestamos.php?error_devolucion_prestamo=1&id_prestamo_err=" . $id_prestamo_devolver); exit;
        }

        $conexion->begin_transaction();
        try {
            $sql_update_prestamo = "UPDATE prestamos_activos SET 
                                        fecha_devolucion_real = ?, estado_activo_devolucion = ?,
                                        observaciones_devolucion = ?, estado_prestamo = 'Devuelto' 
                                    WHERE id_prestamo = ?";
            $stmt_upd_prestamo = $conexion->prepare($sql_update_prestamo);
            if (!$stmt_upd_prestamo) throw new Exception("Error al preparar actualización de préstamo: " . $conexion->error);
            $stmt_upd_prestamo->bind_param("sssi", $fecha_devolucion_real, $estado_activo_devolucion, $observaciones_devolucion, $id_prestamo_devolver);
            if (!$stmt_upd_prestamo->execute()) throw new Exception("Error al actualizar el registro de préstamo: " . $stmt_upd_prestamo->error);
            $stmt_upd_prestamo->close();

            $sql_update_activo_dev = "UPDATE activos_tecnologicos SET estado = ?, id_usuario_responsable = ? WHERE id = ?";
            $stmt_upd_activo_dev = $conexion->prepare($sql_update_activo_dev);
            if (!$stmt_upd_activo_dev) throw new Exception("Error al preparar actualización de activo devuelto: " . $conexion->error);
            
            $stmt_upd_activo_dev->bind_param("sii", $estado_activo_devolucion, $id_usuario_presta_original, $id_activo_devuelto);
            if (!$stmt_upd_activo_dev->execute()) throw new Exception("Error al actualizar estado/responsable del activo devuelto: " . $stmt_upd_activo_dev->error);
            $stmt_upd_activo_dev->close();

            $stmt_info_hist = $conexion->prepare("SELECT a.serie, ur.nombre_completo as nombre_receptor, ur.usuario as cedula_receptor, p.id_usuario_recibe FROM prestamos_activos p JOIN activos_tecnologicos a ON p.id_activo = a.id JOIN usuarios ur ON p.id_usuario_recibe = ur.id WHERE p.id_prestamo = ?");
            $info_para_historial = null; 
            if($stmt_info_hist){
                $stmt_info_hist->bind_param("i", $id_prestamo_devolver);
                $stmt_info_hist->execute();
                $res_info_hist = $stmt_info_hist->get_result();
                $info_para_historial = $res_info_hist->fetch_assoc();
                $stmt_info_hist->close();
            } else {
                error_log("Error al preparar stmt_info_hist para devolución: " . $conexion->error);
            }
            
            $descripcion_historial = "Activo devuelto. Serie: " . ($info_para_historial['serie'] ?? 'N/A') . ". ";
            $descripcion_historial .= "Devuelto por: " . ($info_para_historial['nombre_receptor'] ?? 'N/A') . " (C.C: " . ($info_para_historial['cedula_receptor'] ?? 'N/A') . "). ";
            $descripcion_historial .= "Fecha devolución: " . date('d/m/Y', strtotime($fecha_devolucion_real)) . ". ";
            $descripcion_historial .= "Estado al devolver: " . $estado_activo_devolucion . ".";

            $datos_anteriores_historial_dev = ['estado_activo' => 'En Préstamo', 'id_usuario_responsable' => ($info_para_historial['id_usuario_recibe'] ?? $id_usuario_presta_original)]; 
            $datos_nuevos_historial_dev = [
                'id_prestamo_finalizado' => $id_prestamo_devolver,
                'estado_activo' => $estado_activo_devolucion,
                'id_usuario_responsable' => $id_usuario_presta_original, 
                'fecha_devolucion_real' => $fecha_devolucion_real,
                'estado_activo_devolucion' => $estado_activo_devolucion,
                'observaciones_devolucion' => $observaciones_devolucion
            ];
            registrar_evento_historial($conexion, $id_activo_devuelto, HISTORIAL_TIPO_PRESTAMO_FIN, $descripcion_historial, $_SESSION['usuario_login'] ?? 'sistema', $datos_anteriores_historial_dev, $datos_nuevos_historial_dev);

            $conexion->commit();
            $_SESSION['mensaje_accion_prestamos'] = "<div class='alert alert-success'>Devolución del préstamo ID {$id_prestamo_devolver} registrada exitosamente. El activo ha sido reasignado y su estado actualizado.</div>";
            header("Location: gestion_prestamos.php"); exit;

        } catch (Exception $e) {
            $conexion->rollback();
            error_log("Error en transacción de devolución de préstamo: " . $e->getMessage());
            $_SESSION['mensaje_accion_prestamos'] = "<div class='alert alert-danger'>Error en la operación de devolución: " . htmlspecialchars($e->getMessage()) . "</div>";
            header("Location: gestion_prestamos.php?error_devolucion_prestamo=1&id_prestamo_err=" . $id_prestamo_devolver); exit;
        }
    }
}

// --- OBTENER LISTA DE PRÉSTAMOS EXISTENTES ---
$prestamos_listados = [];
if (!$conexion_error_msg && !empty($id_usuario_actual_logueado)) { 
    $sql_base_listar_prestamos = "SELECT 
                                        p.id_prestamo, p.id_activo, p.id_usuario_presta, 
                                        p.fecha_prestamo, p.fecha_devolucion_esperada, p.fecha_devolucion_real, p.estado_prestamo,
                                        a.serie as serie_activo, 
                                        ta.nombre_tipo_activo,
                                        up.nombre_completo as nombre_usuario_presta,
                                        ur.nombre_completo as nombre_usuario_recibe,
                                        ur.usuario as cedula_usuario_recibe
                                    FROM prestamos_activos p
                                    JOIN activos_tecnologicos a ON p.id_activo = a.id
                                    JOIN usuarios up ON p.id_usuario_presta = up.id
                                    JOIN usuarios ur ON p.id_usuario_recibe = ur.id
                                    LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo";
    
    // La condición WHERE ahora es obligatoria para TODOS los roles
    $sql_base_listar_prestamos .= " WHERE (p.id_usuario_presta = ? OR p.id_usuario_recibe = ?)";
    $sql_base_listar_prestamos .= " ORDER BY p.estado_prestamo ASC, p.fecha_devolucion_esperada ASC, p.fecha_prestamo DESC";
    
    $stmt_listar_prestamos = $conexion->prepare($sql_base_listar_prestamos);

    if ($stmt_listar_prestamos) {
        $stmt_listar_prestamos->bind_param("ii", $id_usuario_actual_logueado, $id_usuario_actual_logueado);
        if ($stmt_listar_prestamos->execute()) {
            $result_prestamos = $stmt_listar_prestamos->get_result();
            if ($result_prestamos) { 
                while ($row = $result_prestamos->fetch_assoc()) { $prestamos_listados[] = $row; }
            } else { 
                error_log("Error al obtener resultados de préstamos: " . $conexion->error);
                if(empty($mensaje_accion)) $mensaje_accion = "<div class='alert alert-warning'>No se pudieron cargar los resultados de préstamos.</div>";
            }
            $stmt_listar_prestamos->close();
        } else {
            error_log("Error al ejecutar consulta de listar préstamos: " . $stmt_listar_prestamos->error);
            if(empty($mensaje_accion)) $mensaje_accion = "<div class='alert alert-warning'>Error al ejecutar la búsqueda de préstamos.</div>";
        }
    } else { 
        error_log("Error al preparar consulta de listar préstamos: " . $conexion->error);
        if(empty($mensaje_accion)) $mensaje_accion = "<div class='alert alert-warning'>Error al preparar la búsqueda de préstamos.</div>";
    }
} elseif (empty($id_usuario_actual_logueado) && !$conexion_error_msg) {
    if(empty($mensaje_accion)) $mensaje_accion = "<div class='alert alert-info'>Debe iniciar sesión para ver sus préstamos.</div>";
} elseif ($conexion_error_msg) {
    // No hacer nada, el mensaje de error ya está establecido
}

// Determinar si se debe abrir algún modal por error POST
if (isset($_GET['error_creacion_prestamo']) && $_GET['error_creacion_prestamo'] == '1' && !empty($mensaje_accion)) {
    if (is_string($mensaje_accion) && (stripos($mensaje_accion, "Préstamo:") !== false || stripos($mensaje_accion, "Error de sesión:") !== false) ) {
        $abrir_modal_creacion_prestamo_js = true;
    }
}
if (isset($_GET['error_devolucion_prestamo']) && $_GET['error_devolucion_prestamo'] == '1' && !empty($mensaje_accion)) {
    if (is_string($mensaje_accion) && stripos($mensaje_accion, "Devolución:") !== false) {
        $abrir_modal_devolucion_prestamo_js = true; 
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión de Préstamos de Activos</title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        html {
            height: 100%;
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            padding-top: 110px; 
            background-color: #eef2f5; 
            font-size: 0.92rem;
            
            /* Reglas para el Sticky Footer */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .container-main {
            flex-grow: 1; /* Hace que este contenedor crezca */
        }

        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 75px; width: auto; }
        .user-info-top-container .user-info-top { font-size: 0.8rem; } 
        .user-info-top-container .btn { font-size: 0.8rem; } 
        .page-header-custom-area { }
        h1.page-title { color: #0d6efd; font-weight: 600; font-size: 1.75rem; }
        .card { border: none; box-shadow: 0 0 10px rgba(0,0,0,0.06); }
        .card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 500; color: #495057; font-size: 1.05rem; }
        .table thead th { background-color: #4A5568; color: white; font-weight: 500; vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; white-space: nowrap;}
        .table tbody td { vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; }
        .form-label { font-weight: 500; color: #495057; font-size: 0.85rem; }
        .action-icon { font-size: 1rem; text-decoration: none; margin-right: 0.3rem; }
        
        .responsable-actual-info, .estado-actual-info { 
            background-color: #e9ecef; padding: 0.375rem 0.75rem; border-radius: 0.25rem;
            font-size: 0.9em; color: #495057; display: block; 
            min-height: calc(1.5em + 0.75rem + 2px); line-height: 1.5;
        }
        
        #area_info_prestador .form-label { margin-bottom: 0.2rem; } 

        .footer-custom {
            font-size: 0.9rem; 
            background-color: #f8f9fa; 
            border-top: 1px solid #dee2e6; 
            padding: 1rem 0;
            margin-top: auto; /* Importante para flexbox */
        }
        .footer-custom a i { 
            color: #6c757d; 
            transition: color 0.2s ease-in-out; 
        }
        .footer-custom a i:hover { 
            color: #0d6efd !important; 
        }

        @media (max-width: 575.98px) {
            body { padding-top: 150px; } 
            .top-bar-custom { flex-direction: column; padding: 0.75rem 1rem; }
            .logo-container-top { margin-bottom: 0.5rem; text-align: center; width: 100%; }
            .user-info-top-container { display: flex; flex-direction: column; align-items: center; width: 100%; text-align: center; }
            .user-info-top-container .user-info-top { margin-right: 0; margin-bottom: 0.5rem; }
            h1.page-title { font-size: 1.4rem !important; margin-top: 0.5rem; margin-bottom: 0.75rem;}
            .page-header-custom-area .btn { margin-bottom: 0.5rem; }
            .page-header-custom-area > div:last-child .btn { margin-bottom: 0; }
        }
    </style>
    </head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php"><img src="imagenes/logo.png" alt="Logo"></a></div>
    <div class="user-info-top-container d-flex align-items-center">
        <span class="user-info-top text-dark me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form>
    </div>
</div>

<div class="container container-main mt-4"> 
<div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between mb-3 page-header-custom-area">
        <div class="mb-2 mb-sm-0 text-center text-sm-start order-sm-1" style="flex-shrink: 0;">
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalRegistrarPrestamo">
                <i class="bi bi-calendar2-plus-fill"></i> Registrar Nuevo Préstamo
            </button>
        </div>
        <div class="flex-fill text-center order-first order-sm-2 px-sm-3">
            <h1 class="page-title my-2 my-sm-0">
                <i class="bi bi-arrow-left-right-circle"></i> Gestión de Préstamos
            </h1>
        </div>
        <div class="mt-2 mt-sm-0 text-center text-sm-end order-sm-3" style="flex-shrink: 0;">
             <a href="centro_gestion.php" class="btn btn-outline-secondary btn-sm">
                 <i class="bi bi-arrow-left-circle"></i> Volver al Centro de Gestión
             </a>
        </div>
    </div>

    <?php if ($mensaje_accion && is_string($mensaje_accion)) echo "<div class='mb-3 text-center'>{$mensaje_accion}</div>"; ?>

    <div class="card mt-2">
        <div class="card-header"><i class="bi bi-list-task"></i> Préstamos Registrados (Relacionados con usted)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Activo (S/N)</th>
                            <th>Tipo</th>
                            <th>Registró Préstamo</th>
                            <th>Prestado A (C.C.)</th>
                            <th>Fecha Préstamo</th>
                            <th>Dev. Esperada</th>
                            <th>Dev. Real</th>
                            <th>Estado Préstamo</th>
                            <th style="min-width: 160px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($prestamos_listados)): ?>
                            <?php foreach ($prestamos_listados as $prestamo): ?>
                                <tr>
                                    <td><?= htmlspecialchars($prestamo['id_prestamo']) ?></td>
                                    <td><?= htmlspecialchars($prestamo['serie_activo']) ?></td>
                                    <td><?= htmlspecialchars($prestamo['nombre_tipo_activo'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($prestamo['nombre_usuario_presta']) ?></td>
                                    <td><?= htmlspecialchars($prestamo['nombre_usuario_recibe']) ?> (<?= htmlspecialchars($prestamo['cedula_usuario_recibe']) ?>)</td>
                                    <td><?= htmlspecialchars(date("d/m/Y", strtotime($prestamo['fecha_prestamo']))) ?></td>
                                    <td><?= htmlspecialchars(date("d/m/Y", strtotime($prestamo['fecha_devolucion_esperada']))) ?></td>
                                    <td><?= $prestamo['fecha_devolucion_real'] ? htmlspecialchars(date("d/m/Y", strtotime($prestamo['fecha_devolucion_real']))) : 'Pendiente' ?></td>
                                    <td>
                                        <?php
                                        $estado_p = htmlspecialchars($prestamo['estado_prestamo']);
                                        $badge_p_class = 'bg-secondary';
                                        if ($estado_p == 'Activo') $badge_p_class = 'bg-primary';
                                        elseif ($estado_p == 'Devuelto') $badge_p_class = 'bg-success';
                                        elseif ($estado_p == 'Vencido') $badge_p_class = 'bg-danger'; 
                                        ?>
                                        <span class="badge rounded-pill <?= $badge_p_class ?>"><?= $estado_p ?></span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-info action-icon" title="Ver Detalles Préstamo" disabled><i class="bi bi-eye-fill"></i></button>
                                        <?php if ($prestamo['estado_prestamo'] == 'Activo' || $prestamo['estado_prestamo'] == 'Vencido'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success action-icon btn-registrar-devolucion" 
                                                    title="Registrar Devolución"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#modalRegistrarDevolucion"
                                                    data-id-prestamo="<?= $prestamo['id_prestamo'] ?>"
                                                    data-id-activo="<?= $prestamo['id_activo'] ?>"
                                                    data-serie-activo="<?= htmlspecialchars($prestamo['serie_activo']) ?>"
                                                    data-nombre-receptor="<?= htmlspecialchars($prestamo['nombre_usuario_recibe']) ?>"
                                                    data-id-usuario-presta-original="<?= htmlspecialchars($prestamo['id_usuario_presta']) ?>"> 
                                                <i class="bi bi-check2-circle"></i>
                                            </button>
                                            
                                            <a href="generar_acta_prestamo_pdf.php?id_prestamo=<?= $prestamo['id_prestamo'] ?>" 
                                               class="btn btn-sm btn-outline-danger action-icon" 
                                               title="Generar Acta de Préstamo PDF" target="_blank">
                                                <i class="bi bi-file-earmark-pdf-fill"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($prestamo['estado_prestamo'] == 'Devuelto'): ?>
                                            <a href="generar_acta_devolucion_pdf.php?id_prestamo=<?= $prestamo['id_prestamo'] ?>" 
                                               class="btn btn-sm btn-outline-primary action-icon" 
                                               title="Generar Acta de Devolución PDF" target="_blank">
                                                <i class="bi bi-file-earmark-check-fill"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="10" class="text-center p-4">No hay préstamos registrados que cumplan los criterios de visualización para usted.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalRegistrarPrestamo" tabindex="-1" aria-labelledby="modalRegistrarPrestamoLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="gestion_prestamos.php" id="formRegistrarPrestamoModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalRegistrarPrestamoLabel"><i class="bi bi-calendar2-plus-fill"></i> Registrar Nuevo Préstamo de Activo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="seccion_busqueda_prestador" class="row g-3 mb-3 align-items-end">
                        <div class="col-md-5">
                            <label for="cedula_responsable_prestador_modal" class="form-label">Cédula del Responsable Actual (Prestador) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="cedula_responsable_prestador_modal" name="cedula_responsable_prestador_modal" placeholder="Ingrese C.C. y busque">
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-info btn-sm w-100" id="btnBuscarActivosPorResponsable">
                                <i class="bi bi-search"></i> Buscar Activos del Prestador
                            </button>
                        </div>
                        <div class="col-md-4" id="area_info_prestador" style="display: none;">
                             <label class="form-label">Prestador Encontrado:</label>
                             <span id="nombre_responsable_prestador_info" class="responsable-actual-info"></span>
                             <input type="hidden" id="id_responsable_prestador_hidden" name="id_responsable_prestador_hidden">
                        </div>
                    </div>
                     <div id="spinnerBuscarActivos" class="text-center my-2" style="display: none;">
                         <div class="spinner-border spinner-border-sm text-primary" role="status">
                             <span class="visually-hidden">Buscando...</span>
                         </div>
                         Buscando activos...
                     </div>
                    <div id="mensajeBusquedaActivos" class="alert alert-info my-2" style="display: none;"></div>
                    <hr id="divisor_datos_prestamo" style="display:none;">
                    <div id="seccion_detalle_prestamo" style="display:none;"> 
                        <div class="row">
                            <div class="col-md-7 mb-3">
                                <label for="id_activo_prestamo_modal" class="form-label">Activo a Prestar <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" id="id_activo_prestamo_modal" name="id_activo_prestamo" required>
                                    <option value="">Seleccione un activo...</option>
                                </select>
                            </div>
                            <div class="col-md-5 mb-3">
                                <label class="form-label">Estado Actual del Activo Seleccionado:</label>
                                <span id="estado_actual_del_activo_info" class="estado-actual-info">Seleccione un activo para ver</span>
                                <input type="hidden" id="estado_actual_del_activo_hidden" name="estado_activo_al_prestar_hidden"> 
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="id_usuario_recibe_prestamo_modal" class="form-label">Usuario Receptor del Préstamo <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="id_usuario_recibe_prestamo_modal" name="id_usuario_recibe_prestamo" required>
                                <option value="">Busque por nombre o cédula...</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="fecha_prestamo_modal" class="form-label">Fecha de Préstamo <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" id="fecha_prestamo_modal" name="fecha_prestamo_modal" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="fecha_devolucion_esperada_modal" class="form-label">Fecha Devolución Esperada <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" id="fecha_devolucion_esperada_modal" name="fecha_devolucion_esperada_modal" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="observaciones_prestamo_modal" class="form-label">Observaciones Adicionales del Préstamo (Opcional)</label>
                            <textarea class="form-control form-control-sm" id="observaciones_prestamo_modal" name="observaciones_prestamo_modal" rows="2"></textarea>
                        </div>
                    </div> 
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="registrar_prestamo_submit" class="btn btn-primary btn-sm" id="btnSubmitPrestamo" disabled> 
                        <i class="bi bi-check-circle-fill"></i> Registrar Préstamo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalRegistrarDevolucion" tabindex="-1" aria-labelledby="modalRegistrarDevolucionLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestion_prestamos.php" id="formRegistrarDevolucionModal">
                <input type="hidden" name="id_prestamo_devolucion" id="id_prestamo_devolucion_modal">
                <input type="hidden" name="id_activo_devolucion_hidden" id="id_activo_devolucion_modal_hidden">
                <input type="hidden" name="id_usuario_presta_original_hidden" id="id_usuario_presta_original_modal_hidden">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalRegistrarDevolucionLabel"><i class="bi bi-box-arrow-in-left"></i> Registrar Devolución de Activo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Registrando devolución para el activo S/N: <strong id="info_serie_activo_devolucion"></strong>, actualmente prestado a: <strong id="info_usuario_receptor_devolucion"></strong>.</p>
                    <hr>
                    <div class="mb-3">
                        <label for="fecha_devolucion_real_modal" class="form-label">Fecha Real de Devolución <span class="text-danger">*</span></label>
                        <input type="date" class="form-control form-control-sm" id="fecha_devolucion_real_modal" name="fecha_devolucion_real_modal" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="estado_activo_devolucion_modal" class="form-label">Estado del Activo al Devolver <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="estado_activo_devolucion_modal" name="estado_activo_devolucion_modal" required>
                            <option value="">Seleccione el estado...</option>
                            <option value="Bueno">Bueno</option>
                            <option value="Regular">Regular</option>
                            <option value="Malo">Malo (Requiere revisión)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="observaciones_devolucion_modal" class="form-label">Observaciones de la Devolución (Opcional)</label>
                        <textarea class="form-control form-control-sm" id="observaciones_devolucion_modal" name="observaciones_devolucion_modal" rows="3" placeholder="Detalles sobre la condición del activo, novedades, etc..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="registrar_devolucion_submit" class="btn btn-success btn-sm">
                        <i class="bi bi-check2-all"></i> Confirmar Devolución
                    </button>
                </div>
            </form>
        </div>
    </div>
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
<?php if (isset($conexion) && $conexion && !$conexion_error_msg) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    const PHP_VARS = {
        currentUserRole: <?= json_encode($rol_usuario_actual_sesion ?? 'Desconocido') ?>,
        currentUserCedula: <?= json_encode($cedula_usuario_actual_logueado ?? '') ?>
    };

document.addEventListener('DOMContentLoaded', function () {
    // --- INICIO: CÓDIGO DE INICIALIZACIÓN DE SELECT2 (EL PASO QUE FALTABA) ---
try {
    $('#id_usuario_recibe_prestamo_modal').select2({
        // Esto es crucial para que el buscador funcione dentro de un modal de Bootstrap
        dropdownParent: $('#modalRegistrarPrestamo'),

        // Aplica el tema de Bootstrap 5 para que se vea bien
        theme: 'bootstrap-5',

        // El texto que se muestra antes de buscar
        placeholder: 'Busque por nombre o cédula para ver resultados',

        // El usuario debe teclear al menos 2 caracteres para iniciar la búsqueda
        minimumInputLength: 2, 

        // Configuración para la búsqueda dinámica (AJAX)
        ajax: {
            url: 'gestion_prestamos.php', // Apunta a este mismo archivo
            dataType: 'json',
            delay: 250, // Espera 250ms después de teclear antes de buscar
            data: function (params) {
                // Envía los parámetros necesarios al backend
                return {
                    accion_ajax: 'buscar_usuarios', // La acción que creamos en PHP
                    term: params.term // El texto que el usuario está escribiendo
                };
            },
            processResults: function (data) {
                // Mapea la respuesta del servidor al formato que Select2 espera
                return {
                    results: data.results
                };
            },
            cache: true
        }
    });
} catch(e) {
    console.error("Error al inicializar Select2. Asegúrate de que jQuery y Select2 están cargados.", e);
}
// --- FIN: CÓDIGO DE INICIALIZACIÓN DE SELECT2 ---
    const currentUrl = new URL(window.location);
    if (currentUrl.searchParams.has('error_creacion_prestamo')) {
        currentUrl.searchParams.delete('error_creacion_prestamo');
        window.history.replaceState({}, document.title, currentUrl.toString());
    }
    if (currentUrl.searchParams.has('error_devolucion_prestamo')) { 
        currentUrl.searchParams.delete('error_devolucion_prestamo');
        window.history.replaceState({}, document.title, currentUrl.toString());
    }

    const modalRegistrarPrestamoEl = document.getElementById('modalRegistrarPrestamo');
    
    <?php if ($abrir_modal_creacion_prestamo_js): ?>
    if (modalRegistrarPrestamoEl) {
        try {
            const modalCrear = bootstrap.Modal.getOrCreateInstance(modalRegistrarPrestamoEl);
            modalCrear.show();
        } catch (e) {
            console.error("Error al intentar mostrar el modal de creación vía JS por error POST:", e);
        }
    }
    <?php endif; ?>

    const modalRegistrarDevolucion = document.getElementById('modalRegistrarDevolucion');
    if (modalRegistrarDevolucion) {
        modalRegistrarDevolucion.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget; 
            const idPrestamo = button.getAttribute('data-id-prestamo');
            const idActivo = button.getAttribute('data-id-activo');
            const serieActivo = button.getAttribute('data-serie-activo');
            const nombreReceptor = button.getAttribute('data-nombre-receptor');
            const idUsuarioPrestaOriginal = button.getAttribute('data-id-usuario-presta-original');

            const modalTitle = modalRegistrarDevolucion.querySelector('.modal-title');
            const infoSerieEl = modalRegistrarDevolucion.querySelector('#info_serie_activo_devolucion');
            const infoReceptorEl = modalRegistrarDevolucion.querySelector('#info_usuario_receptor_devolucion');
            const inputIdPrestamo = modalRegistrarDevolucion.querySelector('#id_prestamo_devolucion_modal');
            const inputIdActivo = modalRegistrarDevolucion.querySelector('#id_activo_devolucion_modal_hidden');
            const inputIdUsuarioPrestaOrig = modalRegistrarDevolucion.querySelector('#id_usuario_presta_original_modal_hidden');

            modalTitle.textContent = 'Registrar Devolución (Préstamo ID: ' + idPrestamo + ')';
            if(infoSerieEl) infoSerieEl.textContent = serieActivo || 'N/A';
            if(infoReceptorEl) infoReceptorEl.textContent = nombreReceptor || 'N/A';
            if(inputIdPrestamo) inputIdPrestamo.value = idPrestamo;
            if(inputIdActivo) inputIdActivo.value = idActivo;
            if(inputIdUsuarioPrestaOrig) inputIdUsuarioPrestaOrig.value = idUsuarioPrestaOriginal;
            
            const formDevolucion = document.getElementById('formRegistrarDevolucionModal');
            if(formDevolucion) formDevolucion.reset();
            if(document.getElementById('fecha_devolucion_real_modal')) document.getElementById('fecha_devolucion_real_modal').value = "<?= date('Y-m-d') ?>";
            if(inputIdPrestamo) inputIdPrestamo.value = idPrestamo; 
            if(inputIdActivo) inputIdActivo.value = idActivo;
            if(inputIdUsuarioPrestaOrig) inputIdUsuarioPrestaOrig.value = idUsuarioPrestaOriginal;
        });
    }
    <?php if ($abrir_modal_devolucion_prestamo_js && isset($_GET['id_prestamo_err'])): ?>
        const idPrestamoError = <?= json_encode((int)$_GET['id_prestamo_err']) ?>;
        const btnParaReabrirModalDev = document.querySelector(`.btn-registrar-devolucion[data-id-prestamo="${idPrestamoError}"]`);
        if(btnParaReabrirModalDev){
            const modalDev = new bootstrap.Modal(document.getElementById('modalRegistrarDevolucion'));
             setTimeout(() => { 
                 btnParaReabrirModalDev.click(); 
             }, 100);
        }
    <?php endif; ?>
    
    const cedulaPrestadorInput = document.getElementById('cedula_responsable_prestador_modal');
    const btnBuscarActivos = document.getElementById('btnBuscarActivosPorResponsable');
    const selectActivoModal = document.getElementById('id_activo_prestamo_modal');
    const infoEstadoActualEl = document.getElementById('estado_actual_del_activo_info');
    const hiddenEstadoActualEl = document.getElementById('estado_actual_del_activo_hidden'); 
    const infoNombrePrestadorEl = document.getElementById('nombre_responsable_prestador_info');
    const hiddenIdPrestadorEl = document.getElementById('id_responsable_prestador_hidden');
    const seccionDetallePrestamo = document.getElementById('seccion_detalle_prestamo');
    const btnSubmitPrestamo = document.getElementById('btnSubmitPrestamo');
    const spinnerBuscarActivos = document.getElementById('spinnerBuscarActivos');
    const mensajeBusquedaActivos = document.getElementById('mensajeBusquedaActivos');
    const areaInfoPrestador = document.getElementById('area_info_prestador');
    const divisorDatosPrestamo = document.getElementById('divisor_datos_prestamo');
    const seccionBusquedaPrestador = document.getElementById('seccion_busqueda_prestador');

    function limpiarCamposSeleccionActivo() {
        if(selectActivoModal) selectActivoModal.innerHTML = '<option value="">Seleccione un activo...</option>';
        if(infoEstadoActualEl) infoEstadoActualEl.textContent = 'Seleccione un activo para ver';
        if(hiddenEstadoActualEl) hiddenEstadoActualEl.value = '';
        if(btnSubmitPrestamo) btnSubmitPrestamo.disabled = true;
    }
    
    function mostrarMensajeBusqueda(mensaje, tipo = 'info') { 
        if(mensajeBusquedaActivos) {
            mensajeBusquedaActivos.textContent = mensaje;
            mensajeBusquedaActivos.className = 'alert alert-' + tipo + ' my-2'; 
            mensajeBusquedaActivos.style.display = 'block';
        }
    }

    function limpiarModalCreacion() {
        if(cedulaPrestadorInput && !cedulaPrestadorInput.readOnly) cedulaPrestadorInput.value = '';
        const formCrear = document.getElementById('formRegistrarPrestamoModal');
        if(formCrear) {
            if(!cedulaPrestadorInput || !cedulaPrestadorInput.readOnly) {
            }
            if(selectActivoModal) selectActivoModal.innerHTML = '<option value="">Seleccione un activo...</option>';
            if(document.getElementById('id_usuario_recibe_prestamo_modal')) {
                // Limpiar Select2 correctamente
                $('#id_usuario_recibe_prestamo_modal').val(null).trigger('change');
            }
            if(document.getElementById('fecha_prestamo_modal')) document.getElementById('fecha_prestamo_modal').value = "<?= date('Y-m-d') ?>";
            if(document.getElementById('fecha_devolucion_esperada_modal')) document.getElementById('fecha_devolucion_esperada_modal').value = "";
            if(document.getElementById('observaciones_prestamo_modal')) document.getElementById('observaciones_prestamo_modal').value = "";
        }
        
        limpiarCamposSeleccionActivo(); 
        
        if(areaInfoPrestador) areaInfoPrestador.style.display = 'none';
        if(infoNombrePrestadorEl) infoNombrePrestadorEl.textContent = '';
        if(hiddenIdPrestadorEl) hiddenIdPrestadorEl.value = '';
        if(seccionDetallePrestamo) seccionDetallePrestamo.style.display = 'none';
        if(divisorDatosPrestamo) divisorDatosPrestamo.style.display = 'none';
        if(mensajeBusquedaActivos) mensajeBusquedaActivos.style.display = 'none';
    }


    if (modalRegistrarPrestamoEl) {
        modalRegistrarPrestamoEl.addEventListener('show.bs.modal', function () {
            limpiarModalCreacion(); 
            // Lógica ajustada: Este comportamiento ahora se aplica a TODOS los roles.
            if (PHP_VARS.currentUserCedula) {
                if (cedulaPrestadorInput) {
                    cedulaPrestadorInput.value = PHP_VARS.currentUserCedula;
                    cedulaPrestadorInput.readOnly = true;
                }
                if (btnBuscarActivos) {
                    if(seccionBusquedaPrestador) seccionBusquedaPrestador.style.display = 'none'; 
                    btnBuscarActivos.click(); 
                }
            }
        });
    }
    

    if (btnBuscarActivos && cedulaPrestadorInput && selectActivoModal) {
        btnBuscarActivos.addEventListener('click', function() { 
            const cedula = cedulaPrestadorInput.value.trim();
            if (!cedula) { mostrarMensajeBusqueda('Por favor, ingrese la cédula.', 'warning'); return; }
            
            limpiarCamposSeleccionActivo(); 
            
            if(seccionDetallePrestamo) seccionDetallePrestamo.style.display = 'none';
            if(areaInfoPrestador) areaInfoPrestador.style.display = 'none';
            if(divisorDatosPrestamo) divisorDatosPrestamo.style.display = 'none';
            if(infoNombrePrestadorEl) infoNombrePrestadorEl.textContent = '';
            if(hiddenIdPrestadorEl) hiddenIdPrestadorEl.value = '';
            if(spinnerBuscarActivos) spinnerBuscarActivos.style.display = 'block';
            if(mensajeBusquedaActivos) mensajeBusquedaActivos.style.display = 'none';

            fetch(`gestion_prestamos.php?accion_ajax=buscar_activos_por_cedula_responsable&cedula_responsable=${encodeURIComponent(cedula)}`)
                .then(response => {
                    if (!response.ok) { return response.text().then(text => { console.error("Error HTTP en Fetch:", response.status, response.statusText, "Respuesta:", text); throw new Error(`Error HTTP: ${response.status} - ${response.statusText}. Respuesta: ${text}`);}); }
                    return response.json();
                })
                .then(data => {
                    if(spinnerBuscarActivos) spinnerBuscarActivos.style.display = 'none';
                    if (data.success && data.activos.length > 0) {
                        if(areaInfoPrestador) areaInfoPrestador.style.display = 'block';
                        if(infoNombrePrestadorEl) infoNombrePrestadorEl.textContent = data.nombre_responsable + (cedula ? ' (C.C: ' + cedula + ')' : '');
                        if(hiddenIdPrestadorEl) hiddenIdPrestadorEl.value = data.id_responsable;
                        data.activos.forEach(activo => {
                            const option = document.createElement('option');
                            option.value = activo.id_activo;
                            option.textContent = `${activo.nombre_tipo_activo} - ${activo.marca} (S/N: ${activo.serie})`;
                            option.setAttribute('data-estado-actual', activo.estado_actual_activo);
                            selectActivoModal.appendChild(option);
                        });
                        if(seccionDetallePrestamo) seccionDetallePrestamo.style.display = 'block';
                        if(divisorDatosPrestamo) divisorDatosPrestamo.style.display = 'block';
                        mostrarMensajeBusqueda(data.activos.length + ' activo(s) disponible(s) encontrado(s) para ' + data.nombre_responsable + '.', 'success');
                    } else {
                        mostrarMensajeBusqueda(data.mensaje || 'No se encontraron activos disponibles para esta cédula.', 'warning');
                        if(areaInfoPrestador) areaInfoPrestador.style.display = 'none'; 
                    }
                })
                .catch(error => {
                    if(spinnerBuscarActivos) spinnerBuscarActivos.style.display = 'none';
                    mostrarMensajeBusqueda('Error al buscar activos o procesar la respuesta. Verifique la consola para más detalles y los logs del servidor.', 'danger');
                    console.error('Error en fetch o procesamiento JSON:', error); 
                });
        });
    }

    if (selectActivoModal && infoEstadoActualEl && hiddenEstadoActualEl && btnSubmitPrestamo) {
        selectActivoModal.addEventListener('change', function() { 
            const selectedOption = this.options[this.selectedIndex];
            const estadoAct = selectedOption.getAttribute('data-estado-actual');
            if (this.value === "") {
                infoEstadoActualEl.textContent = 'Seleccione un activo para ver';
                hiddenEstadoActualEl.value = '';
                btnSubmitPrestamo.disabled = true;
            } else {
                if (estadoAct) {
                    infoEstadoActualEl.textContent = estadoAct;
                    hiddenEstadoActualEl.value = estadoAct;
                    btnSubmitPrestamo.disabled = false; 
                } else {
                    infoEstadoActualEl.textContent = 'Estado no disponible';
                    hiddenEstadoActualEl.value = '';
                    btnSubmitPrestamo.disabled = true;
                }
            }
        });
        limpiarCamposSeleccionActivo(); 
    }
});
</script>
</body>
</html>