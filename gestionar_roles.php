<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); 

require_once 'backend/db.php';

$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $db_conn_error_detail = method_exists($conexion, 'connect_error') ? $conexion->connect_error : 'Desconocido';
    error_log("Error de conexión BD en gestionar_roles.php: " . $db_conn_error_detail);
    $conexion_error_msg = "<div class='alert alert-danger'>Error crítico de conexión a la base de datos. No se pueden cargar ni guardar datos. Contacte al administrador.</div>";
} else {
    $conexion->set_charset("utf8mb4");
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';

$mensaje_accion = $_SESSION['mensaje_accion_roles'] ?? null;
if ($conexion_error_msg && empty($mensaje_accion)) {
    $mensaje_accion = $conexion_error_msg;
}
unset($_SESSION['mensaje_accion_roles']);

$rol_para_editar = null;
$abrir_modal_creacion_rol_js = false;
$abrir_modal_editar_rol_js = false;
$id_columna_pk = 'id_rol'; // Para claridad

// --- LÓGICA POST (Crear, Actualizar, Eliminar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$conexion_error_msg) {
    if (isset($_POST['crear_rol_submit'])) {
        $nombre_rol = trim($_POST['nombre_rol_crear'] ?? '');
        $descripcion_rol = trim($_POST['descripcion_rol_crear'] ?? '');

        if (empty($nombre_rol)) {
            $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>Creación: El nombre del rol es obligatorio.</div>";
        } else {
            $stmt_check = $conexion->prepare("SELECT id_rol FROM roles WHERE nombre_rol = ?");
            $stmt_check->bind_param("s", $nombre_rol);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>Creación: El nombre del rol '" . htmlspecialchars($nombre_rol) . "' ya existe.</div>";
            } else {
                $sql_insert = "INSERT INTO roles (nombre_rol, descripcion_rol) VALUES (?, ?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param("ss", $nombre_rol, $descripcion_rol);
                if ($stmt_insert->execute()) {
                    $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-success'>Rol '" . htmlspecialchars($nombre_rol) . "' creado exitosamente. <br><small>Recuerde definir sus permisos en <code>backend/auth_check.php</code> si es un rol con acceso a páginas restringidas.</small></div>";
                    header("Location: gestionar_roles.php"); exit;
                } else {
                    $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>Creación: Error al crear el rol: " . $stmt_insert->error . "</div>";
                }
                $stmt_insert->close();
            }
            $stmt_check->close();
        }
        header("Location: gestionar_roles.php?error_creacion_rol=1"); 
        exit;
    }
    elseif (isset($_POST['editar_rol_submit'])) {
        $id_rol_editar = filter_input(INPUT_POST, 'id_rol_editar', FILTER_VALIDATE_INT);
        $nombre_rol_editar = trim($_POST['nombre_rol_editar_modal'] ?? ''); // Corregido para coincidir con modal
        $descripcion_rol_editar = trim($_POST['descripcion_rol_editar_modal'] ?? ''); // Corregido

        if (empty($nombre_rol_editar)) {
            $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>Edición: El nombre del rol es obligatorio.</div>";
        } elseif (!$id_rol_editar) {
            $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>Edición: ID de rol inválido.</div>";
        } else {
            $stmt_check_nombre = $conexion->prepare("SELECT id_rol FROM roles WHERE nombre_rol = ? AND id_rol != ?");
            $stmt_check_nombre->bind_param("si", $nombre_rol_editar, $id_rol_editar);
            $stmt_check_nombre->execute();
            $stmt_check_nombre->store_result();
            if ($stmt_check_nombre->num_rows > 0) {
                $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>Edición: El nombre del rol '" . htmlspecialchars($nombre_rol_editar) . "' ya existe.</div>";
            } else {
                $sql_update = "UPDATE roles SET nombre_rol = ?, descripcion_rol = ? WHERE id_rol = ?";
                $stmt_update = $conexion->prepare($sql_update);
                $stmt_update->bind_param("ssi", $nombre_rol_editar, $descripcion_rol_editar, $id_rol_editar);
                if ($stmt_update->execute()) {
                     if ($stmt_update->affected_rows > 0) {
                        $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-success'>Rol '" . htmlspecialchars($nombre_rol_editar) . "' actualizado exitosamente.</div>";
                    } else {
                        $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-info'>No se detectaron cambios en el rol o el rol no fue encontrado.</div>";
                    }
                    header("Location: gestionar_roles.php"); exit;
                } else {
                    $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>Edición: Error al actualizar el rol: " . $stmt_update->error . "</div>";
                }
                $stmt_update->close();
            }
            $stmt_check_nombre->close();
        }
        header("Location: gestionar_roles.php?accion=editar&id=" . $id_rol_editar . "&error_edicion_rol=1");
        exit;
    }
    elseif (isset($_POST['eliminar_rol_submit'])) {
        $id_rol_eliminar = filter_input(INPUT_POST, 'id_rol_eliminar', FILTER_VALIDATE_INT);
        if ($id_rol_eliminar) {
            $stmt_get_rol_name = $conexion->prepare("SELECT nombre_rol FROM roles WHERE id_rol = ?");
            $stmt_get_rol_name->bind_param("i", $id_rol_eliminar);
            $stmt_get_rol_name->execute();
            $rol_name_result = $stmt_get_rol_name->get_result();
            $rol_name_to_delete_row = $rol_name_result->fetch_assoc();
            $rol_name_to_delete = $rol_name_to_delete_row ? $rol_name_to_delete_row['nombre_rol'] : 'desconocido';
            $stmt_get_rol_name->close();

            $roles_criticos = ['admin', 'superadmin', 'tecnico', 'auditor', 'registrador']; 
            if (in_array(strtolower($rol_name_to_delete), $roles_criticos)) { 
                $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>El rol '" . htmlspecialchars($rol_name_to_delete) . "' es crítico y no puede ser eliminado.</div>";
            } else {
                $stmt_check_uso = $conexion->prepare("SELECT COUNT(*) as total FROM usuarios WHERE rol = ?");
                $stmt_check_uso->bind_param("s", $rol_name_to_delete); 
                $stmt_check_uso->execute();
                $res_check_uso = $stmt_check_uso->get_result()->fetch_assoc();
                $stmt_check_uso->close();
                if ($res_check_uso['total'] > 0) {
                    $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-danger'>No se puede eliminar el rol '" . htmlspecialchars($rol_name_to_delete) . "' porque está asignado a " . $res_check_uso['total'] . " usuario(s).</div>";
                } else {
                    $sql_delete = "DELETE FROM roles WHERE id_rol = ?";
                    $stmt_delete = $conexion->prepare($sql_delete);
                    if ($stmt_delete) { /* ... (resto de la lógica de eliminación) ... */ }
                }
            }
            header("Location: gestionar_roles.php"); exit;
        }
    }
}

// --- Lógica para cargar datos para editar (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id']) && !$conexion_error_msg) {
    $id_rol_get = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id_rol_get) {
        $stmt_edit_fetch = $conexion->prepare("SELECT id_rol, nombre_rol, descripcion_rol FROM roles WHERE id_rol = ?");
        if ($stmt_edit_fetch) {
            $stmt_edit_fetch->bind_param("i", $id_rol_get);
            $stmt_edit_fetch->execute();
            $result_edit_fetch = $stmt_edit_fetch->get_result();
            if ($result_edit_fetch->num_rows === 1) {
                $rol_para_editar = $result_edit_fetch->fetch_assoc();
                $abrir_modal_editar_rol_js = true;
            } else {
                $_SESSION['mensaje_accion_roles'] = "<div class='alert alert-warning'>Rol no encontrado para editar (ID: {$id_rol_get}).</div>";
            }
            $stmt_edit_fetch->close();
        } else { /* ... error ... */ }
    }
    if(isset($_GET['error_edicion_rol']) && $_GET['error_edicion_rol'] == '1' && $id_rol_get && !$rol_para_editar){
       // Si hubo error de POST edición y no se cargó $rol_para_editar, intentar recargarlo
       $stmt_reload = $conexion->prepare("SELECT id_rol, nombre_rol, descripcion_rol FROM roles WHERE id_rol = ?");
       if($stmt_reload){
           $stmt_reload->bind_param("i", $id_rol_get); $stmt_reload->execute();
           $result_reload = $stmt_reload->get_result();
           if($result_reload->num_rows === 1) $rol_para_editar = $result_reload->fetch_assoc();
           $stmt_reload->close();
       }
       if($rol_para_editar) $abrir_modal_editar_rol_js = true;
    }
}

// --- Obtener lista de roles para mostrar ---
$roles_listados = [];
if (!$conexion_error_msg) {
    $sql_listar_roles = "SELECT id_rol, nombre_rol, descripcion_rol, fecha_creacion FROM roles ORDER BY nombre_rol ASC";
    $result_listar_roles = $conexion->query($sql_listar_roles);
    if ($result_listar_roles) {
        while ($row = $result_listar_roles->fetch_assoc()) { $roles_listados[] = $row; }
    } else { /* ... error ... */ }
}

// Determinar si el modal de creación debe abrirse automáticamente
if (isset($_GET['error_creacion_rol']) && $_GET['error_creacion_rol'] == '1' && !empty($mensaje_accion)) {
    if (is_string($mensaje_accion) && stripos($mensaje_accion, "Creación:") !== false) {
        $abrir_modal_creacion_rol_js = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestionar Roles</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 110px; background-color: #eef2f5; font-size: 0.92rem; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 75px; width: auto; }
        .top-bar-user-info .navbar-text { font-size: 0.8rem; }
        .top-bar-user-info .btn { font-size: 0.8rem; }
        .page-header-custom-area { /* Contenedor del título y botones */ }
        h1.page-title { color: #0d6efd; font-weight: 600; font-size: 1.75rem; }
        .card { border: none; box-shadow: 0 0 10px rgba(0,0,0,0.06); }
        .card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 500; color: #495057; font-size: 1.05rem; }
        .table thead th { background-color: #4A5568; color: white; font-weight: 500; vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; white-space: nowrap;}
        .table tbody td { vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; }
        .form-label { font-weight: 500; color: #495057; font-size: 0.85rem; }
        .container.mt-4 {max-width: 992px;} /* Ancho del contenedor principal */
        .action-icon { font-size: 1rem; text-decoration: none; margin-right: 0.3rem; }

        @media (max-width: 575.98px) { /* xs screens */
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
    <div class="logo-container-top"><a href="menu.php"><img src="imagenes/logo.png" height="75" alt="Logo"></a></div>
    <div class="top-bar-user-info">
        <span class="navbar-text me-sm-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
    </div>
</div>

<div class="container mt-4"> 
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between mb-3 page-header-custom-area">
        <div class="mb-2 mb-sm-0 text-center text-sm-start order-sm-1" style="flex-shrink: 0;">
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearRol">
                <i class="bi bi-plus-circle"></i> Crear Nuevo Rol
            </button>
        </div>
        <div class="flex-fill text-center order-first order-sm-2 px-sm-3">
            <h1 class="page-title my-2 my-sm-0">
                <i class="bi bi-shield-lock-fill"></i> Gestión de Roles
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
        <div class="card-header"><i class="bi bi-list-ul"></i> Lista de Roles Existentes</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Nombre del Rol</th>
                            <th>Descripción</th>
                            <th>Fecha Creación</th>
                            <th style="min-width: 130px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($roles_listados)): ?>
                            <?php foreach ($roles_listados as $rol_item): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($rol_item['nombre_rol']) ?></strong></td>
                                    <td><?= nl2br(htmlspecialchars($rol_item['descripcion_rol'] ?? 'N/A')) ?></td>
                                    <td><?= htmlspecialchars(!empty($rol_item['fecha_creacion']) ? date("d/m/Y H:i", strtotime($rol_item['fecha_creacion'])) : 'N/A') ?></td>
                                    <td>
                                        <a href="gestionar_roles.php?accion=editar&id=<?= $rol_item['id_rol'] ?>" class="btn btn-sm btn-outline-warning action-icon" title="Editar Rol"><i class="bi bi-pencil-fill"></i></a>
                                        
                                        <a href="editar_rol.php?id=<?= $rol_item['id_rol'] ?>" class="btn btn-sm btn-outline-primary action-icon" title="Configurar Permisos"><i class="bi bi-shield-lock-fill"></i></a>
                                        
                                        <?php 
                                        $roles_base_no_eliminables = ['admin', 'tecnico', 'auditor', 'registrador', 'superadmin'];
                                        if (!in_array(strtolower($rol_item['nombre_rol']), $roles_base_no_eliminables)): 
                                        ?>
                                            <form method="POST" action="gestionar_roles.php" style="display: inline;" onsubmit="return confirm('¿Está seguro que desea eliminar este rol: \'<?= htmlspecialchars(addslashes($rol_item['nombre_rol'])) ?>\'? Esta acción no se puede deshacer y podría afectar a usuarios con este rol.');">
                                                <input type="hidden" name="id_rol_eliminar" value="<?= $rol_item['id_rol'] ?>">
                                                <button type="submit" name="eliminar_rol_submit" class="btn btn-sm btn-outline-danger action-icon" title="Eliminar Rol"><i class="bi bi-trash3-fill"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary action-icon" disabled title="Este rol base no se puede eliminar."><i class="bi bi-trash3-fill"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center p-4">No hay roles registrados o hubo un error al cargarlos.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCrearRol" tabindex="-1" aria-labelledby="modalCrearRolLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_roles.php" id="formCrearRolModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearRolLabel"><i class="bi bi-plus-circle-fill"></i> Agregar Nuevo Rol</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nombre_rol_crear" class="form-label">Nombre del Rol <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nombre_rol_crear" name="nombre_rol_crear" 
                               required pattern="[a-z0-9_]+" title="Solo letras minúsculas, números y guion bajo (_). Sin espacios ni mayúsculas.">
                        <small class="form-text text-muted">Ej: 'admin', 'ventas_regional'. Minúsculas, números, '_'.</small>
                    </div>
                    <div class="mb-3">
                        <label for="descripcion_rol_crear" class="form-label">Descripción del Rol</label>
                        <textarea class="form-control form-control-sm" id="descripcion_rol_crear" name="descripcion_rol_crear" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="crear_rol_submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg"></i> Agregar Rol
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($rol_para_editar): ?>
<div class="modal fade" id="modalEditarRol" tabindex="-1" aria-labelledby="modalEditarRolLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_roles.php" id="formEditarRolModal">
                <input type="hidden" name="id_rol_editar" value="<?= htmlspecialchars($rol_para_editar['id_rol']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarRolLabel"><i class="bi bi-pencil-fill"></i> Editar Rol: <?= htmlspecialchars($rol_para_editar['nombre_rol']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nombre_rol_editar_modal" class="form-label">Nombre del Rol <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nombre_rol_editar_modal" name="nombre_rol_editar_modal" 
                               value="<?= htmlspecialchars($rol_para_editar['nombre_rol']) ?>" required 
                               pattern="[a-z0-9_]+" title="Solo letras minúsculas, números y guion bajo (_). Sin espacios ni mayúsculas.">
                        <small class="form-text text-muted">Ej: 'admin', 'ventas_regional'. Minúsculas, números, '_'.</small>
                    </div>
                    <div class="mb-3">
                        <label for="descripcion_rol_editar_modal" class="form-label">Descripción del Rol</label>
                        <textarea class="form-control form-control-sm" id="descripcion_rol_editar_modal" name="descripcion_rol_editar_modal" 
                                  rows="3"><?= htmlspecialchars($rol_para_editar['descripcion_rol'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_rol_submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save-fill"></i> Actualizar Rol
                    </button>
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
    const currentUrl = new URL(window.location);
    if (currentUrl.searchParams.has('error_creacion_rol')) {
        currentUrl.searchParams.delete('error_creacion_rol');
        window.history.replaceState({}, document.title, currentUrl.toString());
    }
    if (currentUrl.searchParams.has('error_edicion_rol')) {
        // Mantener accion=editar&id=X si existen para que el modal sepa qué cargar
        const idParam = currentUrl.searchParams.get('id');
        const accionParam = currentUrl.searchParams.get('accion');
        currentUrl.searchParams.delete('error_edicion_rol'); // Solo borrar el flag de error
        window.history.replaceState({}, document.title, currentUrl.toString());
    }

    <?php if ($abrir_modal_creacion_rol_js): ?>
    const modalCrearRolEl = document.getElementById('modalCrearRol');
    if (modalCrearRolEl) {
        const modalCrear = new bootstrap.Modal(modalCrearRolEl);
        modalCrear.show();
    }
    <?php endif; ?>

    <?php if ($abrir_modal_editar_rol_js && $rol_para_editar): ?>
    const modalEditarRolEl = document.getElementById('modalEditarRol');
    if (modalEditarRolEl) {
        const modalEditar = new bootstrap.Modal(modalEditarRolEl);
        modalEditar.show(); 
    }
    <?php endif; ?>
});
</script>
</body>
</html>