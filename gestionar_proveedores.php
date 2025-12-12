<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); 

require_once 'backend/db.php';

define('RUTA_SUBIDA_RUT', 'uploads/proveedores_rut/');

$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $db_conn_error_detail = method_exists($conexion, 'connect_error') ? $conexion->connect_error : 'Desconocido';
    error_log("Error de conexión BD en gestionar_proveedores.php: " . $db_conn_error_detail);
    $conexion_error_msg = "<div class='alert alert-danger'>Error crítico de conexión a la base de datos. No se pueden cargar ni guardar datos. Contacte al administrador.</div>";
} else {
    $conexion->set_charset("utf8mb4");
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';

$mensaje_accion = $_SESSION['mensaje_accion_proveedores'] ?? null;
if ($conexion_error_msg && empty($mensaje_accion)) {
    $mensaje_accion = $conexion_error_msg;
}
unset($_SESSION['mensaje_accion_proveedores']);

$proveedor_para_editar = null;
$abrir_modal_creacion_proveedor_js = false;
$abrir_modal_editar_proveedor_js = false;

// --- PROCESAR ACCIONES POST (Crear, Actualizar, Eliminar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$conexion_error_msg) {
    // ------ CREAR PROVEEDOR ------
    if (isset($_POST['crear_proveedor_submit'])) {
        $nombre_proveedor = trim($_POST['nombre_proveedor_crear'] ?? '');
        $ciudad = trim($_POST['ciudad_crear'] ?? '');
        $rut_nit = trim($_POST['rut_nit_crear'] ?? '');
        $contacto_nombre = trim($_POST['contacto_nombre_crear'] ?? '');
        $contacto_telefono = trim($_POST['contacto_telefono_crear'] ?? '');
        $contacto_email_raw = trim($_POST['contacto_email_crear'] ?? '');
        $contacto_email = filter_var($contacto_email_raw, FILTER_VALIDATE_EMAIL) ? $contacto_email_raw : null;

        $errores = [];
        if (empty($nombre_proveedor)) $errores[] = "El nombre del proveedor es obligatorio.";
        if (empty($rut_nit)) $errores[] = "El RUT/NIT es obligatorio.";

        $nombre_archivo_rut = null;
        if (isset($_FILES['archivo_rut_crear']) && $_FILES['archivo_rut_crear']['error'] == UPLOAD_ERR_OK) {
            $file_info = $_FILES['archivo_rut_crear'];
            if ($file_info['type'] !== 'application/pdf') {
                $errores[] = "El archivo RUT debe ser un PDF.";
            } elseif ($file_info['size'] > 5000000) { // Límite de 5MB
                $errores[] = "El archivo PDF es demasiado grande (máximo 5MB).";
            } else {
                if (!is_dir(RUTA_SUBIDA_RUT) && !mkdir(RUTA_SUBIDA_RUT, 0777, true)) {
                    $errores[] = "Error crítico: No se puede crear el directorio de subida.";
                } else {
                    $nombre_archivo_rut = uniqid('rut_', true) . '_' . basename($file_info['name']);
                    $ruta_destino = RUTA_SUBIDA_RUT . $nombre_archivo_rut;
                    if (!move_uploaded_file($file_info['tmp_name'], $ruta_destino)) {
                        $errores[] = "Error al mover el archivo subido.";
                        $nombre_archivo_rut = null;
                    }
                }
            }
        }

        if (empty($errores)) {
            $stmt_check = $conexion->prepare("SELECT id FROM proveedores_mantenimiento WHERE nombre_proveedor = ? OR rut_nit = ?");
            $stmt_check->bind_param("ss", $nombre_proveedor, $rut_nit);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $errores[] = "Ya existe un proveedor con ese Nombre o RUT/NIT.";
            }
            $stmt_check->close();
        }

        if (empty($errores)) {
            $sql_insert = "INSERT INTO proveedores_mantenimiento (nombre_proveedor, ciudad, rut_nit, contacto_nombre, contacto_telefono, contacto_email, archivo_rut) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_insert = $conexion->prepare($sql_insert);
            $stmt_insert->bind_param("sssssss", $nombre_proveedor, $ciudad, $rut_nit, $contacto_nombre, $contacto_telefono, $contacto_email, $nombre_archivo_rut);
            if ($stmt_insert->execute()) {
                $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-success'>Proveedor '" . htmlspecialchars($nombre_proveedor) . "' creado exitosamente.</div>";
                header("Location: gestionar_proveedores.php"); exit;
            } else {
                $errores[] = "Error al crear el proveedor: " . $stmt_insert->error;
                error_log("Error DB proveedores (insert): " . $stmt_insert->error);
            }
            $stmt_insert->close();
        }

        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Creación fallida: " . implode('<br>', $errores) . "</div>";
        header("Location: gestionar_proveedores.php?error_creacion_proveedor=1");
        exit;
    }
    // ------ ACTUALIZAR PROVEEDOR ------
    elseif (isset($_POST['editar_proveedor_submit'])) {
        $id_proveedor_editar = filter_input(INPUT_POST, 'id_proveedor_editar', FILTER_VALIDATE_INT);
        $nombre_proveedor_editar = trim($_POST['nombre_proveedor_editar_modal'] ?? '');
        $ciudad_editar = trim($_POST['ciudad_editar_modal'] ?? '');
        $rut_nit_editar = trim($_POST['rut_nit_editar_modal'] ?? '');
        $contacto_nombre_editar = trim($_POST['contacto_nombre_editar_modal'] ?? '');
        $contacto_telefono_editar = trim($_POST['contacto_telefono_editar_modal'] ?? '');
        $contacto_email_editar = filter_var(trim($_POST['contacto_email_editar_modal'] ?? ''), FILTER_VALIDATE_EMAIL) ? trim($_POST['contacto_email_editar_modal']) : null;
        
        $errores = [];
        if (empty($nombre_proveedor_editar)) $errores[] = "El nombre del proveedor es obligatorio.";
        if (empty($rut_nit_editar)) $errores[] = "El RUT/NIT es obligatorio.";
        if (!$id_proveedor_editar) $errores[] = "ID de proveedor inválido.";

        if (empty($errores)) {
            $stmt_check = $conexion->prepare("SELECT id FROM proveedores_mantenimiento WHERE (nombre_proveedor = ? OR rut_nit = ?) AND id != ?");
            $stmt_check->bind_param("ssi", $nombre_proveedor_editar, $rut_nit_editar, $id_proveedor_editar);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $errores[] = "Ya existe otro proveedor con ese Nombre o RUT/NIT.";
            }
            $stmt_check->close();
        }

        $nombre_archivo_rut_actualizar = $_POST['archivo_rut_actual'] ?? null;
        if (isset($_FILES['archivo_rut_editar_modal']) && $_FILES['archivo_rut_editar_modal']['error'] == UPLOAD_ERR_OK) {
             if (!is_dir(RUTA_SUBIDA_RUT)) mkdir(RUTA_SUBIDA_RUT, 0777, true);
             $nuevo_nombre_archivo = uniqid('rut_', true) . '_' . basename($_FILES['archivo_rut_editar_modal']['name']);
             $ruta_destino_nuevo = RUTA_SUBIDA_RUT . $nuevo_nombre_archivo;
             if (move_uploaded_file($_FILES['archivo_rut_editar_modal']['tmp_name'], $ruta_destino_nuevo)) {
                if (!empty($nombre_archivo_rut_actualizar) && file_exists(RUTA_SUBIDA_RUT . $nombre_archivo_rut_actualizar)) {
                    unlink(RUTA_SUBIDA_RUT . $nombre_archivo_rut_actualizar);
                }
                $nombre_archivo_rut_actualizar = $nuevo_nombre_archivo;
             } else {
                 $errores[] = "Error al subir el nuevo archivo RUT.";
             }
        }

        if (empty($errores)) {
            $sql_update = "UPDATE proveedores_mantenimiento SET nombre_proveedor = ?, ciudad = ?, rut_nit = ?, contacto_nombre = ?, contacto_telefono = ?, contacto_email = ?, archivo_rut = ? WHERE id = ?";
            $stmt_update = $conexion->prepare($sql_update);
            $stmt_update->bind_param("sssssssi", $nombre_proveedor_editar, $ciudad_editar, $rut_nit_editar, $contacto_nombre_editar, $contacto_telefono_editar, $contacto_email_editar, $nombre_archivo_rut_actualizar, $id_proveedor_editar);
            if ($stmt_update->execute()) {
                $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-success'>Proveedor '" . htmlspecialchars($nombre_proveedor_editar) . "' actualizado exitosamente.</div>";
                header("Location: gestionar_proveedores.php"); exit;
            } else {
                $errores[] = "Error al actualizar el proveedor: " . $stmt_update->error;
            }
            $stmt_update->close();
        }

        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Edición fallida: " . implode('<br>', $errores) . "</div>";
        header("Location: gestionar_proveedores.php?accion=editar&id=" . $id_proveedor_editar . "&error_edicion_proveedor=1");
        exit;
    }
    // ------ ELIMINAR PROVEEDOR ------
    elseif (isset($_POST['eliminar_proveedor_submit'])) {
        $id_proveedor_eliminar = filter_input(INPUT_POST, 'id_proveedor_eliminar', FILTER_VALIDATE_INT);
        if ($id_proveedor_eliminar) {
            $stmt_get_file = $conexion->prepare("SELECT archivo_rut FROM proveedores_mantenimiento WHERE id = ?");
            $stmt_get_file->bind_param("i", $id_proveedor_eliminar);
            $stmt_get_file->execute();
            $result_file = $stmt_get_file->get_result();
            if ($row = $result_file->fetch_assoc()) {
                if (!empty($row['archivo_rut']) && file_exists(RUTA_SUBIDA_RUT . $row['archivo_rut'])) {
                    unlink(RUTA_SUBIDA_RUT . $row['archivo_rut']);
                }
            }
            $stmt_get_file->close();

            $sql_delete = "DELETE FROM proveedores_mantenimiento WHERE id = ?";
            $stmt_delete = $conexion->prepare($sql_delete);
            if ($stmt_delete) {
                $stmt_delete->bind_param("i", $id_proveedor_eliminar);
                if ($stmt_delete->execute()) {
                    if ($stmt_delete->affected_rows > 0) {
                        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-info'>Proveedor eliminado exitosamente.</div>";
                    } else {
                        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-warning'>No se encontró el proveedor para eliminar.</div>";
                    }
                } else {
                     if ($conexion->errno == 1451) {
                        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Error al eliminar: Este proveedor está en uso y no puede ser eliminado.</div>";
                    } else {
                        $_SESSION['mensaje_accion_proveedores'] = "<div class='alert alert-danger'>Error al eliminar el proveedor: " . $stmt_delete->error . ".</div>";
                    }
                }
                $stmt_delete->close();
            }
            header("Location: gestionar_proveedores.php");
            exit;
        }
    }
}

// --- Lógica para cargar datos para editar (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id']) && !$conexion_error_msg) {
    $id_proveedor_get = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id_proveedor_get) {
        $stmt_edit_fetch = $conexion->prepare("SELECT * FROM proveedores_mantenimiento WHERE id = ?");
        if ($stmt_edit_fetch) {
            $stmt_edit_fetch->bind_param("i", $id_proveedor_get);
            $stmt_edit_fetch->execute();
            $result_edit_fetch = $stmt_edit_fetch->get_result();
            if ($result_edit_fetch->num_rows === 1) {
                $proveedor_para_editar = $result_edit_fetch->fetch_assoc();
                $abrir_modal_editar_proveedor_js = true;
            }
            $stmt_edit_fetch->close();
        }
    }
}

// --- Obtener lista de proveedores para mostrar ---
$proveedores_listados = [];
if (!$conexion_error_msg) {
    $sql_listar = "SELECT id, nombre_proveedor, ciudad, rut_nit, contacto_nombre, contacto_telefono, contacto_email, archivo_rut, fecha_creacion FROM proveedores_mantenimiento ORDER BY nombre_proveedor ASC";
    $result_listar = $conexion->query($sql_listar);
    if ($result_listar) {
        while ($row = $result_listar->fetch_assoc()) {
            $proveedores_listados[] = $row;
        }
    }
}

if (isset($_GET['error_creacion_proveedor'])) {
    $abrir_modal_creacion_proveedor_js = true;
}

if(isset($_GET['error_edicion_proveedor'])) {
    // Esta lógica asegura que si hay un error de edición, el modal se vuelva a abrir con los datos.
    if($id_proveedor_editar && !$proveedor_para_editar){ // Si no se recargaron los datos del proveedor
       $stmt_reload = $conexion->prepare("SELECT * FROM proveedores_mantenimiento WHERE id = ?");
       if($stmt_reload){
         $stmt_reload->bind_param("i", $id_proveedor_editar);
         $stmt_reload->execute();
         $result_reload = $stmt_reload->get_result();
         if($result_reload->num_rows === 1){
            $proveedor_para_editar = $result_reload->fetch_assoc();
         }
         $stmt_reload->close();
       }
    }
    if($proveedor_para_editar) $abrir_modal_editar_proveedor_js = true;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestionar Proveedores</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 110px; background-color: #eef2f5; font-size: 0.92rem; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 75px; width: auto; }
        .top-bar-user-info .navbar-text { font-size: 0.8rem; }
        .top-bar-user-info .btn { font-size: 0.8rem; }
        .page-header-custom-area { }
        h1.page-title { color: #0d6efd; font-weight: 600; font-size: 1.75rem; }
        .card { border: none; box-shadow: 0 0 10px rgba(0,0,0,0.06); }
        .card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 500; color: #495057; font-size: 1.05rem; }
        .table thead th { background-color: #4A5568; color: white; font-weight: 500; vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; white-space: nowrap;}
        .table tbody td { vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; }
        .form-label { font-weight: 500; color: #495057; font-size: 0.85rem; }
        .container.mt-4 {max-width: 1200px;} 
        .action-icon { font-size: 1rem; text-decoration: none; margin-right: 0.3rem; }

        @media (max-width: 575.98px) {
            body { padding-top: 150px; } 
            .top-bar-custom { flex-direction: column; padding: 0.75rem 1rem; }
            .logo-container-top { margin-bottom: 0.5rem; text-align: center; width: 100%; }
            .top-bar-user-info { display: flex; flex-direction: column; align-items: center; width: 100%; text-align: center; }
            .top-bar-user-info .navbar-text { margin-right: 0; margin-bottom: 0.5rem; }
            h1.page-title { font-size: 1.4rem !important; margin-top: 0.5rem; margin-bottom: 0.75rem;}
            .page-header-custom-area .btn { margin-bottom: 0.5rem; }
            .page-header-custom-area > div:last-child .btn { margin-bottom: 0; }
        }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php"><img src="imagenes/logo.png" alt="Logo"></a></div>
    <div class="top-bar-user-info">
        <span class="navbar-text me-sm-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
    </div>
</div>

<div class="container mt-4"> 
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between mb-3 page-header-custom-area">
        <div class="mb-2 mb-sm-0 text-center text-sm-start order-sm-1" style="flex-shrink: 0;">
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearProveedor">
                <i class="bi bi-plus-circle"></i> Crear Nuevo Proveedor
            </button>
        </div>
        <div class="flex-fill text-center order-first order-sm-2 px-sm-3">
            <h1 class="page-title my-2 my-sm-0">
                <i class="bi bi-truck"></i> Gestión de Proveedores
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
        <div class="card-header"><i class="bi bi-list-ul"></i> Lista de Proveedores Existentes</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Nombre Proveedor</th>
                            <th>RUT/NIT</th>
                            <th>Ciudad</th>
                            <th>Contacto</th>
                            <th>Teléfono</th>
                            <th>Email</th>
                            <th>Archivo RUT</th>
                            <th>Registrado</th>
                            <th style="min-width: 100px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($proveedores_listados)): ?>
                            <?php foreach ($proveedores_listados as $prov): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($prov['nombre_proveedor']) ?></strong></td>
                                    <td><?= htmlspecialchars($prov['rut_nit'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($prov['ciudad'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($prov['contacto_nombre'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($prov['contacto_telefono'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($prov['contacto_email'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php if (!empty($prov['archivo_rut'])): ?>
                                            <a href="<?= RUTA_SUBIDA_RUT . htmlspecialchars($prov['archivo_rut']) ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Ver RUT">
                                                <i class="bi bi-file-earmark-pdf-fill"></i> Ver
                                            </a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(!empty($prov['fecha_creacion']) ? date("d/m/Y", strtotime($prov['fecha_creacion'])) : 'N/A') ?></td>
                                    <td>
                                        <a href="gestionar_proveedores.php?accion=editar&id=<?= $prov['id'] ?>" class="btn btn-sm btn-outline-warning action-icon" title="Editar Proveedor"><i class="bi bi-pencil-fill"></i></a>
                                        <form method="POST" action="gestionar_proveedores.php" style="display: inline;" onsubmit="return confirm('¿Está seguro que desea eliminar este proveedor: \'<?= htmlspecialchars(addslashes($prov['nombre_proveedor'])) ?>\'? Esta acción no se puede deshacer.');">
                                            <input type="hidden" name="id_proveedor_eliminar" value="<?= $prov['id'] ?>">
                                            <button type="submit" name="eliminar_proveedor_submit" class="btn btn-sm btn-outline-danger action-icon" title="Eliminar Proveedor"><i class="bi bi-trash3-fill"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center p-4">No hay proveedores registrados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCrearProveedor" tabindex="-1" aria-labelledby="modalCrearProveedorLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_proveedores.php" id="formCrearProveedorModal" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearProveedorLabel"><i class="bi bi-plus-circle-fill"></i> Agregar Nuevo Proveedor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nombre_proveedor_crear" class="form-label">Nombre del Proveedor <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="nombre_proveedor_crear" name="nombre_proveedor_crear" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="rut_nit_crear" class="form-label">RUT / NIT <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="rut_nit_crear" name="rut_nit_crear" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="ciudad_crear" class="form-label">Ciudad</label>
                        <input type="text" class="form-control form-control-sm" id="ciudad_crear" name="ciudad_crear">
                    </div>
                    <hr>
                    <h6 class="text-muted">Información de Contacto</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="contacto_nombre_crear" class="form-label">Nombre de Contacto</label>
                            <input type="text" class="form-control form-control-sm" id="contacto_nombre_crear" name="contacto_nombre_crear">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="contacto_telefono_crear" class="form-label">Teléfono de Contacto</label>
                            <input type="text" class="form-control form-control-sm" id="contacto_telefono_crear" name="contacto_telefono_crear">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="contacto_email_crear" class="form-label">Email de Contacto</label>
                        <input type="email" class="form-control form-control-sm" id="contacto_email_crear" name="contacto_email_crear">
                    </div>
                    <div class="mb-3">
                        <label for="archivo_rut_crear" class="form-label">Archivo RUT (PDF, máx 5MB)</label>
                        <input type="file" class="form-control form-control-sm" id="archivo_rut_crear" name="archivo_rut_crear" accept="application/pdf">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="crear_proveedor_submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Agregar Proveedor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($proveedor_para_editar): ?>
<div class="modal fade" id="modalEditarProveedor" tabindex="-1" aria-labelledby="modalEditarProveedorLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_proveedores.php" id="formEditarProveedorModal" enctype="multipart/form-data">
                <input type="hidden" name="id_proveedor_editar" value="<?= htmlspecialchars($proveedor_para_editar['id']) ?>">
                <input type="hidden" name="archivo_rut_actual" value="<?= htmlspecialchars($proveedor_para_editar['archivo_rut'] ?? '') ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarProveedorLabel"><i class="bi bi-pencil-fill"></i> Editar Proveedor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nombre_proveedor_editar_modal" class="form-label">Nombre del Proveedor <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="nombre_proveedor_editar_modal" name="nombre_proveedor_editar_modal" value="<?= htmlspecialchars($proveedor_para_editar['nombre_proveedor']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="rut_nit_editar_modal" class="form-label">RUT / NIT <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="rut_nit_editar_modal" name="rut_nit_editar_modal" value="<?= htmlspecialchars($proveedor_para_editar['rut_nit'] ?? '') ?>" required>
                        </div>
                    </div>
                     <div class="mb-3">
                        <label for="ciudad_editar_modal" class="form-label">Ciudad</label>
                        <input type="text" class="form-control form-control-sm" id="ciudad_editar_modal" name="ciudad_editar_modal" value="<?= htmlspecialchars($proveedor_para_editar['ciudad'] ?? '') ?>">
                    </div>
                    <hr>
                    <h6 class="text-muted">Información de Contacto</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                             <label for="contacto_nombre_editar_modal" class="form-label">Nombre de Contacto</label>
                             <input type="text" class="form-control form-control-sm" id="contacto_nombre_editar_modal" name="contacto_nombre_editar_modal" value="<?= htmlspecialchars($proveedor_para_editar['contacto_nombre'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                             <label for="contacto_telefono_editar_modal" class="form-label">Teléfono de Contacto</label>
                             <input type="text" class="form-control form-control-sm" id="contacto_telefono_editar_modal" name="contacto_telefono_editar_modal" value="<?= htmlspecialchars($proveedor_para_editar['contacto_telefono'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                         <label for="contacto_email_editar_modal" class="form-label">Email de Contacto</label>
                         <input type="email" class="form-control form-control-sm" id="contacto_email_editar_modal" name="contacto_email_editar_modal" value="<?= htmlspecialchars($proveedor_para_editar['contacto_email'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="archivo_rut_editar_modal" class="form-label">Archivo RUT (PDF, máx 5MB)</label>
                        <?php if (!empty($proveedor_para_editar['archivo_rut'])): ?>
                            <div class="alert alert-info py-2 small">
                                Archivo actual: 
                                <a href="<?= RUTA_SUBIDA_RUT . htmlspecialchars($proveedor_para_editar['archivo_rut']) ?>" target="_blank">
                                    <i class="bi bi-file-earmark-pdf-fill"></i> Ver RUT actual
                                </a>
                                <br>
                                <small>Para reemplazarlo, suba un nuevo archivo a continuación.</small>
                            </div>
                        <?php endif; ?>
                        <input type="file" class="form-control form-control-sm" id="archivo_rut_editar_modal" name="archivo_rut_editar_modal" accept="application/pdf">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_proveedor_submit" class="btn btn-primary btn-sm"><i class="bi bi-save-fill"></i> Actualizar Proveedor</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (isset($conexion) && $conexion && !$conexion_error_msg) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Esta parte del código se asegura de que si la página se carga
    // debido a un error en el formulario (crear o editar),
    // el modal correspondiente se abra automáticamente para mostrar el error.

    <?php if ($abrir_modal_creacion_proveedor_js): ?>
    var modalCrearProveedorEl = document.getElementById('modalCrearProveedor');
    if (modalCrearProveedorEl) {
        var modalCrear = new bootstrap.Modal(modalCrearProveedorEl);
        modalCrear.show();
        // Limpiar la URL para que el modal no se vuelva a abrir si el usuario recarga la página
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    <?php endif; ?>

    <?php if ($abrir_modal_editar_proveedor_js): ?>
    var modalEditarProveedorEl = document.getElementById('modalEditarProveedor');
    if (modalEditarProveedorEl) {
        var modalEditar = new bootstrap.Modal(modalEditarProveedorEl);
        modalEditar.show();
        // Limpiar la URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    <?php endif; ?>
});
</script>
</body>
</html>