<?php
// =================================================================================
// ARCHIVO: gestionar_categorias.php
// DESCRIPCIÓN: CRUD Completo de Categorías de Activo (Con Modal y validación de borrado)
// =================================================================================

session_start();
require_once 'backend/auth_check.php';
require_once 'backend/db.php';

restringir_acceso_pagina(['admin']); // Solo admin

$mensaje = "";
$tipo_mensaje = "";

// 1. PROCESAR FORMULARIO (CREAR O EDITAR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $nombre = trim($_POST['nombre_categoria']);
    $desc = trim($_POST['descripcion']);
    $cod = (int)$_POST['cod_contable'];
    $id = isset($_POST['id_categoria']) ? (int)$_POST['id_categoria'] : 0;

    if (!empty($nombre)) {
        if ($_POST['accion'] === 'crear') {
            $stmt = $conexion->prepare("INSERT INTO categorias_activo (nombre_categoria, descripcion, cod_contable) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $nombre, $desc, $cod);
            if ($stmt->execute()) {
                $mensaje = "Categoría creada correctamente.";
                $tipo_mensaje = "success";
            } else {
                $mensaje = "Error al crear: " . $stmt->error;
                $tipo_mensaje = "danger";
            }
        } elseif ($_POST['accion'] === 'editar' && $id > 0) {
            $stmt = $conexion->prepare("UPDATE categorias_activo SET nombre_categoria=?, descripcion=?, cod_contable=? WHERE id_categoria=?");
            $stmt->bind_param("ssii", $nombre, $desc, $cod, $id);
            if ($stmt->execute()) {
                $mensaje = "Categoría actualizada correctamente.";
                $tipo_mensaje = "success";
            } else {
                $mensaje = "Error al actualizar: " . $stmt->error;
                $tipo_mensaje = "danger";
            }
        }
    } else {
        $mensaje = "El nombre de la categoría es obligatorio.";
        $tipo_mensaje = "warning";
    }
}

// 2. PROCESAR ELIMINACIÓN
if (isset($_GET['eliminar'])) {
    $id_borrar = (int)$_GET['eliminar'];
    // Verificar si está en uso por Tipos de Activo
    $check = $conexion->query("SELECT id_tipo_activo FROM tipos_activo WHERE id_categoria = $id_borrar LIMIT 1");
    if ($check->num_rows > 0) {
        $mensaje = "No se puede eliminar: Esta categoría tiene Tipos de Activo asociados. Borra primero los tipos.";
        $tipo_mensaje = "danger";
    } else {
        $conexion->query("DELETE FROM categorias_activo WHERE id_categoria = $id_borrar");
        $mensaje = "Categoría eliminada correctamente.";
        $tipo_mensaje = "success";
    }
}

// 3. OBTENER DATOS (Lista completa)
$categorias = $conexion->query("SELECT * FROM categorias_activo ORDER BY nombre_categoria ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Categorías - Inventario TI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { padding-top: 80px; background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; background: #fff; border-bottom: 1px solid #dee2e6; padding: 0.5rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        .table-container { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div><a href="menu.php"><img src="imagenes/logo.png" height="60" alt="Logo"></a></div>
    <div>
        <a href="centro_gestion.php" class="btn btn-outline-secondary btn-sm me-2"><i class="bi bi-arrow-left"></i> Volver</a>
        <a href="logout.php" class="btn btn-outline-danger btn-sm">Salir</a>
    </div>
</div>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="text-primary"><i class="bi bi-diagram-3-fill"></i> Gestión de Categorías</h3>
        <button class="btn btn-primary" onclick="abrirModalCrear()">
            <i class="bi bi-plus-circle"></i> Nueva Categoría
        </button>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show" role="alert">
            <?= $mensaje ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="table-container">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Nombre Categoría</th>
                    <th>Código Contable</th>
                    <th>Descripción</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($categorias && $categorias->num_rows > 0): ?>
                    <?php while($row = $categorias->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id_categoria'] ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($row['nombre_categoria']) ?></td>
                            <td>
                                <?php if($row['cod_contable'] > 0): ?>
                                    <span class="badge bg-secondary"><?= $row['cod_contable'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['descripcion']) ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-warning me-1" 
                                        onclick='abrirModalEditar(<?= json_encode($row) ?>)' title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a href="?eliminar=<?= $row['id_categoria'] ?>" 
                                   class="btn btn-sm btn-danger" 
                                   onclick="return confirm('¿Seguro que deseas eliminar esta categoría? Si tiene activos asociados podría dar error.');" title="Eliminar">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No hay categorías registradas.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalCategoria" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitulo">Nueva Categoría</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" id="accion" value="crear">
                <input type="hidden" name="id_categoria" id="id_categoria" value="">

                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre Categoría <span class="text-danger">*</span></label>
                    <input type="text" name="nombre_categoria" id="nombre_categoria" class="form-control" required placeholder="Ej: Tecnología, Muebles...">
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Código Contable (PUC)</label>
                    <input type="number" name="cod_contable" id="cod_contable" class="form-control" placeholder="Ej: 1528">
                    <div class="form-text">Código numérico para integración contable.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Descripción</label>
                    <textarea name="descripcion" id="descripcion" class="form-control" rows="3" placeholder="Descripción opcional..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary fw-bold">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const modalEl = document.getElementById('modalCategoria');
    const modal = new bootstrap.Modal(modalEl);

    function abrirModalCrear() {
        document.getElementById('modalTitulo').innerText = "Nueva Categoría";
        document.getElementById('accion').value = "crear";
        document.getElementById('id_categoria').value = "";
        
        // Limpiar campos
        document.getElementById('nombre_categoria').value = "";
        document.getElementById('cod_contable').value = "";
        document.getElementById('descripcion').value = "";
        
        modal.show();
    }

    function abrirModalEditar(datos) {
        document.getElementById('modalTitulo').innerText = "Editar Categoría";
        document.getElementById('accion').value = "editar";
        document.getElementById('id_categoria').value = datos.id_categoria;
        
        // Llenar campos con los datos recibidos
        document.getElementById('nombre_categoria').value = datos.nombre_categoria;
        document.getElementById('cod_contable').value = datos.cod_contable;
        document.getElementById('descripcion').value = datos.descripcion;
        
        modal.show();
    }
</script>

</body>
</html>