<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); // Solo administradores pueden gestionar cargos

require_once 'backend/db.php';

// Inicializar mensaje de error de conexión
$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) {
    $conexion = $conn;
}
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $db_conn_error_detail = method_exists($conexion, 'connect_error') ? $conexion->connect_error : 'Desconocido';
    error_log("Error de conexión BD en gestionar_cargos.php: " . $db_conn_error_detail);
    $conexion_error_msg = "<div class='alert alert-danger'>Error crítico de conexión a la base de datos. No se pueden cargar ni guardar datos. Contacte al administrador.</div>";
} else {
    $conexion->set_charset("utf8mb4");
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';

$mensaje_accion = $_SESSION['mensaje_accion_cargos'] ?? null;
if ($conexion_error_msg && empty($mensaje_accion)) {
    $mensaje_accion = $conexion_error_msg;
}
unset($_SESSION['mensaje_accion_cargos']);

$cargo_para_editar = null;
$abrir_modal_creacion_cargo_js = false;
$abrir_modal_editar_cargo_js = false;

// Función para convertir a formato Título
if (!function_exists('formatoTitulo')) {
    function formatoTitulo($string) {
        return mb_convert_case(trim($string), MB_CASE_TITLE, "UTF-8");
    }
}

// --- PROCESAR ACCIONES POST (Crear, Actualizar, Eliminar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$conexion_error_msg) {
    // ------ CREAR CARGO ------
    if (isset($_POST['crear_cargo_submit'])) {
        $nombre_cargo_original = trim($_POST['nombre_cargo_crear'] ?? '');
        $nombre_cargo = formatoTitulo($nombre_cargo_original);
        $descripcion_cargo = trim($_POST['descripcion_cargo_crear'] ?? '');

        if (empty($nombre_cargo)) {
            $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Creación: El nombre del cargo es obligatorio.</div>";
        } else {
            $stmt_check = $conexion->prepare("SELECT id_cargo FROM cargos WHERE nombre_cargo = ?");
            $stmt_check->bind_param("s", $nombre_cargo);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Creación: El nombre del cargo '" . htmlspecialchars($nombre_cargo) . "' ya existe.</div>";
            } else {
                $sql_insert = "INSERT INTO cargos (nombre_cargo, descripcion_cargo) VALUES (?, ?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param("ss", $nombre_cargo, $descripcion_cargo);
                if ($stmt_insert->execute()) {
                    $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-success'>Cargo '" . htmlspecialchars($nombre_cargo) . "' creado exitosamente.</div>";
                    header("Location: gestionar_cargos.php"); exit;
                } else {
                    $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Creación: Error al crear el cargo: " . $stmt_insert->error . "</div>";
                    error_log("Error DB cargos (insert): " . $stmt_insert->error);
                }
                $stmt_insert->close();
            }
            $stmt_check->close();
        }
        header("Location: gestionar_cargos.php?error_creacion_cargo=1"); 
        exit;
    }
    // ------ ACTUALIZAR CARGO ------
    elseif (isset($_POST['editar_cargo_submit'])) {
        $id_cargo_editar = filter_input(INPUT_POST, 'id_cargo_editar', FILTER_VALIDATE_INT);
        $nombre_cargo_original_editar = trim($_POST['nombre_cargo_editar_modal'] ?? '');
        $nombre_cargo_editar = formatoTitulo($nombre_cargo_original_editar);
        $descripcion_cargo_editar = trim($_POST['descripcion_cargo_editar_modal'] ?? '');

        if (empty($nombre_cargo_editar)) {
            $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Edición: El nombre del cargo es obligatorio.</div>";
        } elseif (!$id_cargo_editar) {
            $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Edición: ID de cargo inválido.</div>";
        } else {
            $sql_check_nombre = "SELECT id_cargo FROM cargos WHERE nombre_cargo = ? AND id_cargo != ?";
            $stmt_check = $conexion->prepare($sql_check_nombre);
            $stmt_check->bind_param("si", $nombre_cargo_editar, $id_cargo_editar);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Edición: El nombre del cargo '" . htmlspecialchars($nombre_cargo_editar) . "' ya existe.</div>";
            } else {
                $sql_update = "UPDATE cargos SET nombre_cargo = ?, descripcion_cargo = ? WHERE id_cargo = ?";
                $stmt_update = $conexion->prepare($sql_update);
                $stmt_update->bind_param("ssi", $nombre_cargo_editar, $descripcion_cargo_editar, $id_cargo_editar);
                if ($stmt_update->execute()) {
                    if ($stmt_update->affected_rows > 0) {
                        $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-success'>Cargo '" . htmlspecialchars($nombre_cargo_editar) . "' actualizado exitosamente.</div>";
                    } else {
                         $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-info'>No se detectaron cambios en el cargo.</div>";
                    }
                    header("Location: gestionar_cargos.php"); exit;
                } else {
                    $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Edición: Error al actualizar el cargo: " . $stmt_update->error . "</div>";
                    error_log("Error DB cargos (update): " . $stmt_update->error);
                }
                $stmt_update->close();
            }
            $stmt_check->close();
        }
        header("Location: gestionar_cargos.php?accion=editar&id=" . $id_cargo_editar . "&error_edicion_cargo=1");
        exit;
    }
    // ------ ELIMINAR CARGO ------
    elseif (isset($_POST['eliminar_cargo_submit'])) {
        $id_cargo_eliminar = filter_input(INPUT_POST, 'id_cargo_eliminar', FILTER_VALIDATE_INT);
        if ($id_cargo_eliminar) {
            $stmt_check_uso = $conexion->prepare("SELECT COUNT(*) as total FROM usuarios WHERE id_cargo = ?");
            $stmt_check_uso->bind_param("i", $id_cargo_eliminar);
            $stmt_check_uso->execute();
            $res_check_uso = $stmt_check_uso->get_result()->fetch_assoc();
            $stmt_check_uso->close();

            if ($res_check_uso['total'] > 0) {
                $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>No se puede eliminar el cargo porque está asignado a " . $res_check_uso['total'] . " usuario(s). Reasigne los usuarios a otro cargo primero.</div>";
            } else {
                $sql_delete = "DELETE FROM cargos WHERE id_cargo = ?";
                $stmt_delete = $conexion->prepare($sql_delete);
                if ($stmt_delete) {
                    $stmt_delete->bind_param("i", $id_cargo_eliminar);
                    if ($stmt_delete->execute()) {
                        if ($stmt_delete->affected_rows > 0) {
                            $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-info'>Cargo eliminado exitosamente.</div>";
                        } else {
                             $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-warning'>No se encontró el cargo para eliminar o ya fue eliminado.</div>";
                        }
                    } else {
                        $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Error al eliminar el cargo: " . $stmt_delete->error . "</div>";
                    }
                    $stmt_delete->close();
                } else {
                     $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-danger'>Error al preparar la eliminación del cargo.</div>";
                }
            }
            header("Location: gestionar_cargos.php");
            exit;
        }
    }
}

// --- Lógica para cargar datos para editar (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id']) && !$conexion_error_msg) {
    $id_cargo_get = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id_cargo_get) {
        $stmt_edit_fetch = $conexion->prepare("SELECT id_cargo, nombre_cargo, descripcion_cargo FROM cargos WHERE id_cargo = ?");
        if ($stmt_edit_fetch) {
            $stmt_edit_fetch->bind_param("i", $id_cargo_get);
            $stmt_edit_fetch->execute();
            $result_edit_fetch = $stmt_edit_fetch->get_result();
            if ($result_edit_fetch->num_rows === 1) {
                $cargo_para_editar = $result_edit_fetch->fetch_assoc();
                $abrir_modal_editar_cargo_js = true;
            } else {
                $_SESSION['mensaje_accion_cargos'] = "<div class='alert alert-warning'>Cargo no encontrado para editar (ID: {$id_cargo_get}).</div>";
            }
            $stmt_edit_fetch->close();
        } else { /* ... error ... */ }
    }
     if(isset($_GET['error_edicion_cargo']) && $_GET['error_edicion_cargo'] == '1' && $id_cargo_get && !$cargo_para_editar){
       $stmt_reload = $conexion->prepare("SELECT id_cargo, nombre_cargo, descripcion_cargo FROM cargos WHERE id_cargo = ?");
       if($stmt_reload){
           $stmt_reload->bind_param("i", $id_cargo_get); $stmt_reload->execute();
           $result_reload = $stmt_reload->get_result();
           if($result_reload->num_rows === 1) $cargo_para_editar = $result_reload->fetch_assoc();
           $stmt_reload->close();
       }
       if($cargo_para_editar) $abrir_modal_editar_cargo_js = true;
    }
}

// --- Obtener lista de cargos para mostrar ---
$cargos_listados = [];
if (!$conexion_error_msg) {
    $sql_listar_cargos = "SELECT id_cargo, nombre_cargo, descripcion_cargo, fecha_creacion FROM cargos ORDER BY nombre_cargo ASC";
    $result_listar_cargos = $conexion->query($sql_listar_cargos);
    if ($result_listar_cargos) {
        while ($row = $result_listar_cargos->fetch_assoc()) {
            $cargos_listados[] = $row;
        }
    } else { /* ... error ... */ }
}

// Determinar si el modal de creación debe abrirse automáticamente
if (isset($_GET['error_creacion_cargo']) && $_GET['error_creacion_cargo'] == '1' && !empty($mensaje_accion)) {
    if (is_string($mensaje_accion) && stripos($mensaje_accion, "Creación:") !== false) {
        $abrir_modal_creacion_cargo_js = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestionar Cargos</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 110px; background-color: #eef2f5; font-size: 0.92rem; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 45px; width: auto; }
        .top-bar-user-info .navbar-text { font-size: 0.8rem; }
        .top-bar-user-info .btn { font-size: 0.8rem; }
        .page-header-custom-area { /* No specific styles needed if Bootstrap flex handles it all */ }
        h1.page-title { color: #0d6efd; font-weight: 600; font-size: 1.75rem; }
        .card { border: none; box-shadow: 0 0 10px rgba(0,0,0,0.06); }
        .card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 500; color: #495057; font-size: 1.05rem; }
        .table thead th { background-color: #4A5568; color: white; font-weight: 500; vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; white-space: nowrap;}
        .table tbody td { vertical-align: middle; font-size: 0.85rem; padding: 0.6rem 0.75rem; }
        .form-label { font-weight: 500; color: #495057; font-size: 0.85rem; }
        .container.mt-4 {max-width: 992px;}
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
    <div class="logo-container-top"><a href="menu.php"><img src="imagenes/logo.png"  height="75" alt="Logo"></a></div>
    <div class="top-bar-user-info">
        <span class="navbar-text me-sm-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
    </div>
</div>

<div class="container mt-4"> 
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between mb-3 page-header-custom-area">
        <div class="mb-2 mb-sm-0 text-center text-sm-start order-sm-1" style="flex-shrink: 0;">
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearCargo">
                <i class="bi bi-plus-circle"></i> Crear Nuevo Cargo
            </button>
        </div>
        <div class="flex-fill text-center order-first order-sm-2 px-sm-3">
            <h1 class="page-title my-2 my-sm-0">
                <i class="bi bi-person-badge-fill"></i> Gestión de Cargos
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
        <div class="card-header"><i class="bi bi-list-ul"></i> Lista de Cargos Existentes</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Nombre del Cargo</th>
                            <th>Descripción</th>
                            <th>Fecha Creación</th>
                            <th style="min-width: 100px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($cargos_listados)): ?>
                            <?php foreach ($cargos_listados as $cargo_item): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars(formatoTitulo($cargo_item['nombre_cargo'])) ?></strong></td>
                                    <td><?= nl2br(htmlspecialchars($cargo_item['descripcion_cargo'] ?? 'N/A')) ?></td>
                                    <td><?= htmlspecialchars(!empty($cargo_item['fecha_creacion']) ? date("d/m/Y H:i", strtotime($cargo_item['fecha_creacion'])) : 'N/A') ?></td>
                                    <td>
                                        <a href="gestionar_cargos.php?accion=editar&id=<?= $cargo_item['id_cargo'] ?>" class="btn btn-sm btn-outline-warning action-icon" title="Editar Cargo"><i class="bi bi-pencil-fill"></i></a>
                                        <form method="POST" action="gestionar_cargos.php" style="display: inline;" onsubmit="return confirm('¿Está seguro que desea eliminar este cargo: \'<?= htmlspecialchars(addslashes(formatoTitulo($cargo_item['nombre_cargo']))) ?>\'? Si este cargo está asignado a usuarios, deberá reasignarlos primero.');">
                                            <input type="hidden" name="id_cargo_eliminar" value="<?= $cargo_item['id_cargo'] ?>">
                                            <button type="submit" name="eliminar_cargo_submit" class="btn btn-sm btn-outline-danger action-icon" title="Eliminar Cargo"><i class="bi bi-trash3-fill"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center p-4">No hay cargos registrados o hubo un error al cargarlos.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCrearCargo" tabindex="-1" aria-labelledby="modalCrearCargoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_cargos.php" id="formCrearCargoModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearCargoLabel"><i class="bi bi-plus-circle-fill"></i> Agregar Nuevo Cargo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nombre_cargo_crear" class="form-label">Nombre del Cargo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nombre_cargo_crear" name="nombre_cargo_crear" required>
                    </div>
                    <div class="mb-3">
                        <label for="descripcion_cargo_crear" class="form-label">Descripción del Cargo (Opcional)</label>
                        <textarea class="form-control form-control-sm" id="descripcion_cargo_crear" name="descripcion_cargo_crear" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="crear_cargo_submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg"></i> Agregar Cargo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($cargo_para_editar): ?>
<div class="modal fade" id="modalEditarCargo" tabindex="-1" aria-labelledby="modalEditarCargoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_cargos.php" id="formEditarCargoModal">
                <input type="hidden" name="id_cargo_editar" value="<?= htmlspecialchars($cargo_para_editar['id_cargo']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarCargoLabel"><i class="bi bi-pencil-fill"></i> Editar Cargo: <?= htmlspecialchars(formatoTitulo($cargo_para_editar['nombre_cargo'])) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nombre_cargo_editar_modal" class="form-label">Nombre del Cargo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="nombre_cargo_editar_modal" name="nombre_cargo_editar_modal" 
                               value="<?= htmlspecialchars(formatoTitulo($cargo_para_editar['nombre_cargo'])) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="descripcion_cargo_editar_modal" class="form-label">Descripción del Cargo (Opcional)</label>
                        <textarea class="form-control form-control-sm" id="descripcion_cargo_editar_modal" name="descripcion_cargo_editar_modal" 
                                  rows="3"><?= htmlspecialchars($cargo_para_editar['descripcion_cargo'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_cargo_submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save-fill"></i> Actualizar Cargo
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
    if (currentUrl.searchParams.has('error_creacion_cargo')) {
        currentUrl.searchParams.delete('error_creacion_cargo');
        window.history.replaceState({}, document.title, currentUrl.toString());
    }
    if (currentUrl.searchParams.has('error_edicion_cargo')) {
        currentUrl.searchParams.delete('error_edicion_cargo');
        // Keep accion=editar&id=X if they exist
        const idParam = currentUrl.searchParams.get('id');
        const accionParam = currentUrl.searchParams.get('accion');
        let newSearch = '';
        if(idParam && accionParam && accionParam === 'editar') newSearch = `?accion=${accionParam}&id=${idParam}`;
        window.history.replaceState({}, document.title, currentUrl.pathname + newSearch);
    }

    <?php if ($abrir_modal_creacion_cargo_js): ?>
    const modalCrearCargoEl = document.getElementById('modalCrearCargo');
    if (modalCrearCargoEl) {
        const modalCrear = new bootstrap.Modal(modalCrearCargoEl);
        modalCrear.show();
    }
    <?php endif; ?>

    <?php if ($abrir_modal_editar_cargo_js && $cargo_para_editar): ?>
    const modalEditarCargoEl = document.getElementById('modalEditarCargo');
    if (modalEditarCargoEl) {
        const modalEditar = new bootstrap.Modal(modalEditarCargoEl);
        modalEditar.show(); 
    }
    <?php endif; ?>
});
</script>
</body>
</html>