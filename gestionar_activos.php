<?php
// =================================================================================
// ARCHIVO: gestionar_activos.php
// DESCRIPCIÓN: Gestión de TIPOS de activo (Maestro de Tipos y Categorías)
// =================================================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']);

require_once 'backend/db.php';

// Validar conexión
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || $conexion->connect_error) {
    die("<div class='alert alert-danger'>Error crítico de conexión a la base de datos.</div>");
}
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';

// Mensajes
$mensaje_accion = $_SESSION['mensaje_accion_gestion'] ?? null;
unset($_SESSION['mensaje_accion_gestion']);

$id_columna_pk = 'id_tipo_activo';
$nombre_columna_tipo = 'nombre_tipo_activo';
$tipo_activo_para_editar = null;
$abrir_modal_creacion_tipo_js = false;
$abrir_modal_editar_tipo_js = false;

// --- 1. CARGAR LISTA DE CATEGORÍAS (Para los Selects) ---
$lista_categorias = [];
$sql_cats = "SELECT id_categoria, nombre_categoria FROM categorias_activo ORDER BY nombre_categoria ASC";
$res_cats = $conexion->query($sql_cats);
if ($res_cats) {
    while ($cat = $res_cats->fetch_assoc()) {
        $lista_categorias[] = $cat;
    }
}

// =================================================================================
// LÓGICA POST (CREAR / EDITAR)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- CREAR TIPO ---
    if (isset($_POST['crear_tipo_activo_submit'])) {
        $nuevo_tipo_nombre = trim($_POST['nuevo_tipo_nombre_modal']);
        $descripcion = trim($_POST['descripcion_modal']) ?: null;
        $vida_util = !empty($_POST['vida_util_sugerida_modal']) ? (int)$_POST['vida_util_sugerida_modal'] : null;
        $campos_especificos = isset($_POST['campos_especificos_modal']) ? 1 : 0;
        $id_categoria = !empty($_POST['id_categoria_modal']) ? (int)$_POST['id_categoria_modal'] : null;

        if (!empty($nuevo_tipo_nombre) && !empty($id_categoria)) {
            // Verificar duplicados
            $stmt_check = $conexion->prepare("SELECT $id_columna_pk FROM tipos_activo WHERE $nombre_columna_tipo = ?");
            $stmt_check->bind_param("s", $nuevo_tipo_nombre);
            $stmt_check->execute();
            $stmt_check->store_result();
            
            if ($stmt_check->num_rows > 0) {
                $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Error: El tipo '$nuevo_tipo_nombre' ya existe.</div>";
            } else {
                // Insertar con Categoría
                $sql_insert = "INSERT INTO tipos_activo (nombre_tipo_activo, descripcion, vida_util_sugerida, campos_especificos, id_categoria) VALUES (?, ?, ?, ?, ?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param("ssiii", $nuevo_tipo_nombre, $descripcion, $vida_util, $campos_especificos, $id_categoria);
                
                if ($stmt_insert->execute()) {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-success'>Tipo creado exitosamente.</div>";
                    header("Location: gestionar_activos.php"); exit;
                } else {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Error al guardar: " . $stmt_insert->error . "</div>";
                }
                $stmt_insert->close();
            }
            $stmt_check->close();
        } else {
            $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Nombre y Categoría son obligatorios.</div>";
        }
        header("Location: gestionar_activos.php?error_creacion_tipo=1"); 
        exit;
    }
    
    // --- EDITAR TIPO ---
    elseif (isset($_POST['editar_tipo_activo_submit'])) {
        $id_tipo_editar = filter_input(INPUT_POST, 'id_tipo_activo_editar', FILTER_VALIDATE_INT);
        $nombre_editado = trim($_POST['edit_tipo_nombre_modal']);
        $descripcion_editada = trim($_POST['edit_descripcion_modal']) ?: null;
        $vida_util_editada = !empty($_POST['edit_vida_util_sugerida_modal']) ? (int)$_POST['edit_vida_util_sugerida_modal'] : null;
        $campos_especificos_editados = isset($_POST['edit_campos_especificos_modal']) ? 1 : 0;
        $id_categoria_editada = !empty($_POST['edit_id_categoria_modal']) ? (int)$_POST['edit_id_categoria_modal'] : null;

        if ($id_tipo_editar && !empty($nombre_editado) && !empty($id_categoria_editada)) {
            // Verificar nombre duplicado (excluyendo el actual)
            $stmt_check_edit = $conexion->prepare("SELECT $id_columna_pk FROM tipos_activo WHERE $nombre_columna_tipo = ? AND $id_columna_pk != ?");
            $stmt_check_edit->bind_param("si", $nombre_editado, $id_tipo_editar);
            $stmt_check_edit->execute();
            $stmt_check_edit->store_result();

            if ($stmt_check_edit->num_rows > 0) {
                $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>El nombre ya está en uso.</div>";
                header("Location: gestionar_activos.php?accion=editar_tipo&id=" . $id_tipo_editar . "&error_edicion_tipo=1"); exit;
            } else {
                // Update con Categoría
                $sql_update = "UPDATE tipos_activo SET nombre_tipo_activo = ?, descripcion = ?, vida_util_sugerida = ?, campos_especificos = ?, id_categoria = ? WHERE id_tipo_activo = ?";
                $stmt_update = $conexion->prepare($sql_update);
                $stmt_update->bind_param("ssiiii", $nombre_editado, $descripcion_editada, $vida_util_editada, $campos_especificos_editados, $id_categoria_editada, $id_tipo_editar);
                
                if ($stmt_update->execute()) {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-success'>Actualizado correctamente.</div>";
                    header("Location: gestionar_activos.php"); exit;
                } else {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Error SQL: " . $stmt_update->error . "</div>";
                    header("Location: gestionar_activos.php?accion=editar_tipo&id=" . $id_tipo_editar . "&error_edicion_tipo=1"); exit;
                }
                $stmt_update->close();
            }
            $stmt_check_edit->close();
        } else {
            $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Faltan datos obligatorios.</div>";
            header("Location: gestionar_activos.php" . ($id_tipo_editar ? "?accion=editar_tipo&id=".$id_tipo_editar."&error_edicion_tipo=1" : "?error_edicion_tipo=1")); exit;
        }
    }
}

// =================================================================================
// LÓGICA GET (CARGAR PARA EDITAR / ELIMINAR)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && isset($_GET['id'])) {
    $id_get = (int)$_GET['id'];
    if ($id_get > 0) {
        // Eliminar
        if ($_GET['accion'] === 'eliminar') {
            $stmt = $conexion->prepare("DELETE FROM tipos_activo WHERE $id_columna_pk = ?");
            $stmt->bind_param("i", $id_get);
            if ($stmt->execute()) {
                $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-info'>Tipo eliminado.</div>";
            } else {
                // Error 1451 es Foreign Key Constraint (está en uso)
                if ($conexion->errno == 1451) { 
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>No se puede eliminar: Este tipo está siendo usado por activos registrados.</div>";
                } else {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-danger'>Error: " . $stmt->error . "</div>";
                }
            }
            $stmt->close();
            header("Location: gestionar_activos.php"); exit;
        } 
        // Cargar para Editar
        elseif ($_GET['accion'] === 'editar_tipo') {
            $stmt_edit_get = $conexion->prepare("SELECT * FROM tipos_activo WHERE $id_columna_pk = ?");
            if ($stmt_edit_get) {
                $stmt_edit_get->bind_param("i", $id_get);
                $stmt_edit_get->execute();
                $result_edit_get = $stmt_edit_get->get_result();
                if ($result_edit_get->num_rows === 1) {
                    $tipo_activo_para_editar = $result_edit_get->fetch_assoc();
                    $abrir_modal_editar_tipo_js = true;
                } else {
                    $_SESSION['mensaje_accion_gestion'] = "<div class='alert alert-warning'>Tipo no encontrado.</div>";
                }
                $stmt_edit_get->close();
            }
        }
    }
}

// --- OBTENER LISTA PARA TABLA (CON NOMBRE DE CATEGORÍA) ---
$tipos_activo_listados = [];
$sql_tipos = "SELECT t.*, c.nombre_categoria 
              FROM tipos_activo t 
              LEFT JOIN categorias_activo c ON t.id_categoria = c.id_categoria 
              ORDER BY c.nombre_categoria ASC, t.nombre_tipo_activo ASC";
$result_tipos_list = $conexion->query($sql_tipos);
if ($result_tipos_list) {
    while ($row_list = $result_tipos_list->fetch_assoc()) {
        $tipos_activo_listados[] = $row_list;
    }
}

if (isset($_GET['error_creacion_tipo']) && $_GET['error_creacion_tipo'] == '1') {
    $abrir_modal_creacion_tipo_js = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestionar Tipos de Activo</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 110px; background-color: #eef2f5; font-size: 0.92rem; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .logo-container-top img { height: 75px; width: auto; }
        h1.page-title { color: #0d6efd; font-weight: 600; font-size: 1.75rem; }
        .card { border: none; box-shadow: 0 0 10px rgba(0,0,0,0.06); }
        .card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 500; color: #495057; }
        .table thead th { background-color: #4A5568; color: white; font-weight: 500; }
        .badge-cat { font-size: 0.85em; font-weight: normal; background-color: #e2e8f0; color: #1e293b; border: 1px solid #cbd5e1; }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php"><img src="imagenes/logo.png" alt="Logo"></a></div>
    <div class="top-bar-user-info">
        <span class="navbar-text me-sm-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm">Salir</a>
    </div>
</div>

<div class="container mt-4">
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between mb-3">
        <div class="mb-2 mb-sm-0">
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearTipoActivo">
                <i class="bi bi-plus-circle"></i> Crear Nuevo Tipo
            </button>
        </div>
        <div class="flex-fill text-center"> 
            <h1 class="page-title my-2 my-sm-0"><i class="bi bi-tags-fill"></i> Tipos de Activo</h1>
        </div>
        <div class="mt-2 mt-sm-0"> 
             <a href="centro_gestion.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left-circle"></i> Volver</a>
        </div>
    </div>
    
    <?php if ($mensaje_accion): ?><div class='mb-3 text-center'><?= $mensaje_accion ?></div><?php endif; ?>

    <div class="card mt-2"> 
        <div class="card-header">Listado Maestro de Tipos</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th>Nombre del Tipo</th>
                            <th>Descripción</th>
                            <th>Vida Útil</th>
                            <th>Campos Extra</th>
                            <th style="min-width: 100px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($tipos_activo_listados)): ?>
                            <?php foreach ($tipos_activo_listados as $tipo): ?>
                                <tr>
                                    <td><span class="badge badge-cat"><?= htmlspecialchars($tipo['nombre_categoria'] ?? 'Sin Categoría') ?></span></td>
                                    <td><strong><?= htmlspecialchars($tipo['nombre_tipo_activo']) ?></strong></td>
                                    <td><small><?= nl2br(htmlspecialchars($tipo['descripcion'] ?? '')) ?></small></td>
                                    <td><?= htmlspecialchars($tipo['vida_util_sugerida'] ?? '') ?> Años</td>
                                    <td>
                                        <?php if ($tipo['campos_especificos']): ?><span class="badge rounded-pill bg-success">Sí</span>
                                        <?php else: ?><span class="badge rounded-pill bg-secondary">No</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="gestionar_activos.php?accion=editar_tipo&id=<?= $tipo[$id_columna_pk] ?>" class="btn btn-sm btn-outline-warning" title="Editar"><i class="bi bi-pencil-square"></i></a>
                                        <a href="gestionar_activos.php?accion=eliminar&id=<?= $tipo[$id_columna_pk] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar este tipo?');"><i class="bi bi-trash"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center p-4">No hay tipos de activo registrados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCrearTipoActivo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_activos.php">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Añadir Nuevo Tipo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Categoría <span class="text-danger">*</span></label>
                        <select class="form-select" name="id_categoria_modal" required>
                            <option value="">Seleccione...</option>
                            <?php foreach($lista_categorias as $cat): ?>
                                <option value="<?= $cat['id_categoria'] ?>"><?= htmlspecialchars($cat['nombre_categoria']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nombre del Tipo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nuevo_tipo_nombre_modal" required placeholder="Ej: Video Beam">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Vida Útil (Años)</label>
                        <input type="number" class="form-control" name="vida_util_sugerida_modal" min="0" placeholder="Ej: 5">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion_modal" rows="2"></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="campos_especificos_modal" value="1">
                        <label class="form-check-label">¿Requiere campos de Computador (CPU, RAM)?</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="crear_tipo_activo_submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($tipo_activo_para_editar): ?>
<div class="modal fade" id="modalEditarTipoActivo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_activos.php">
                <input type="hidden" name="id_tipo_activo_editar" value="<?= htmlspecialchars($tipo_activo_para_editar[$id_columna_pk]) ?>">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Editar Tipo: <?= htmlspecialchars($tipo_activo_para_editar['nombre_tipo_activo']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Categoría <span class="text-danger">*</span></label>
                        <select class="form-select" name="edit_id_categoria_modal" required>
                            <option value="">Seleccione...</option>
                            <?php foreach($lista_categorias as $cat): ?>
                                <option value="<?= $cat['id_categoria'] ?>" 
                                    <?= ($tipo_activo_para_editar['id_categoria'] == $cat['id_categoria']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['nombre_categoria']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nombre del Tipo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="edit_tipo_nombre_modal" value="<?= htmlspecialchars($tipo_activo_para_editar['nombre_tipo_activo']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Vida Útil</label>
                        <input type="number" class="form-control" name="edit_vida_util_sugerida_modal" min="0" value="<?= htmlspecialchars($tipo_activo_para_editar['vida_util_sugerida'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="edit_descripcion_modal" rows="2"><?= htmlspecialchars($tipo_activo_para_editar['descripcion'] ?? '') ?></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="edit_campos_especificos_modal" value="1" <?= ($tipo_activo_para_editar['campos_especificos']) ? 'checked' : '' ?>>
                        <label class="form-check-label">Campos Específicos</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_tipo_activo_submit" class="btn btn-primary">Actualizar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (isset($conexion)) { $conexion->close(); } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($abrir_modal_creacion_tipo_js): ?>
    new bootstrap.Modal(document.getElementById('modalCrearTipoActivo')).show();
    <?php endif; ?>

    <?php if ($abrir_modal_editar_tipo_js && $tipo_activo_para_editar): ?>
    new bootstrap.Modal(document.getElementById('modalEditarTipoActivo')).show();
    <?php endif; ?>
});
</script>
</body>
</html>