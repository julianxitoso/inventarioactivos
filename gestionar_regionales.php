<?php
// =================================================================================
// ARCHIVO: gestionar_regionales.php
// DESCRIPCIÓN: ABM de Regionales y Centros de Costo
// =================================================================================

session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); 

require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || $conexion->connect_error) { die("Error DB"); }
$conexion->set_charset("utf8mb4");

$nombre_usuario = $_SESSION['nombre_usuario_completo'] ?? 'Admin';
$mensaje = $_SESSION['msg_reg'] ?? null;
unset($_SESSION['msg_reg']);

// -------------------------------------------------------------------------
// LÓGICA POST (CREAR / EDITAR / ELIMINAR)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- REGIONALES ---
    if (isset($_POST['guardar_regional'])) {
        $id = !empty($_POST['id_regional']) ? (int)$_POST['id_regional'] : null;
        $nombre = trim($_POST['nombre_regional']);
        $codigo = trim($_POST['cod_regional']);
        
        if ($id) {
            $stmt = $conexion->prepare("UPDATE regionales SET nombre_regional=?, cod_regional=? WHERE id_regional=?");
            $stmt->bind_param("ssi", $nombre, $codigo, $id);
            $_SESSION['msg_reg'] = $stmt->execute() ? "Regional actualizada." : "Error al actualizar.";
        } else {
            $stmt = $conexion->prepare("INSERT INTO regionales (nombre_regional, cod_regional) VALUES (?, ?)");
            $stmt->bind_param("ss", $nombre, $codigo);
            $_SESSION['msg_reg'] = $stmt->execute() ? "Regional creada." : "Error al crear.";
        }
    }
    elseif (isset($_POST['eliminar_regional'])) {
        $id = (int)$_POST['id_eliminar'];
        // Validar si tiene centros hijos
        $res = $conexion->query("SELECT COUNT(*) as c FROM centros_costo WHERE id_regional = $id");
        if ($res->fetch_assoc()['c'] > 0) {
            $_SESSION['msg_reg'] = "No se puede eliminar: Tiene centros de costo asociados.";
        } else {
            $conexion->query("DELETE FROM regionales WHERE id_regional = $id");
            $_SESSION['msg_reg'] = "Regional eliminada.";
        }
    }

    // --- CENTROS DE COSTO ---
    if (isset($_POST['guardar_centro'])) {
        $id_cc = !empty($_POST['id_centro']) ? (int)$_POST['id_centro'] : null;
        $id_reg = (int)$_POST['id_regional_padre'];
        $nombre = trim($_POST['nombre_centro']);
        $codigo = trim($_POST['cod_centro']);

        if ($id_cc) {
            $stmt = $conexion->prepare("UPDATE centros_costo SET nombre_centro_costo=?, cod_centro_costo=?, id_regional=? WHERE id_centro_costo=?");
            $stmt->bind_param("ssii", $nombre, $codigo, $id_reg, $id_cc);
            $_SESSION['msg_reg'] = $stmt->execute() ? "Centro actualizado." : "Error al actualizar.";
        } else {
            $stmt = $conexion->prepare("INSERT INTO centros_costo (nombre_centro_costo, cod_centro_costo, id_regional) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $nombre, $codigo, $id_reg);
            $_SESSION['msg_reg'] = $stmt->execute() ? "Centro creado." : "Error al crear.";
        }
    }
    elseif (isset($_POST['eliminar_centro'])) {
        $id = (int)$_POST['id_eliminar_cc'];
        // Validar si tiene usuarios o activos
        $u = $conexion->query("SELECT COUNT(*) as c FROM usuarios WHERE id_centro_costo = $id")->fetch_assoc()['c'];
        $a = $conexion->query("SELECT COUNT(*) as c FROM activos_tecnologicos WHERE id_centro_costo = $id")->fetch_assoc()['c'];
        
        if ($u > 0 || $a > 0) {
            $_SESSION['msg_reg'] = "No se puede eliminar: Tiene $u usuarios y $a activos asociados.";
        } else {
            $conexion->query("DELETE FROM centros_costo WHERE id_centro_costo = $id");
            $_SESSION['msg_reg'] = "Centro de costo eliminado.";
        }
    }
    
    header("Location: gestionar_regionales.php"); exit;
}

// -------------------------------------------------------------------------
// OBTENER DATOS
// -------------------------------------------------------------------------
$regionales = [];
$res = $conexion->query("SELECT * FROM regionales ORDER BY nombre_regional ASC");
while($r = $res->fetch_assoc()) {
    // Buscar sus centros
    $centros = [];
    $res_c = $conexion->query("SELECT * FROM centros_costo WHERE id_regional = {$r['id_regional']} ORDER BY nombre_centro_costo ASC");
    while($c = $res_c->fetch_assoc()) { $centros[] = $c; }
    $r['centros'] = $centros;
    $regionales[] = $r;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Ubicaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f0f2f5; padding-top: 80px; font-family: 'Segoe UI', sans-serif; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; background: #fff; border-bottom: 1px solid #dee2e6; padding: 0.5rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        .card-regional { border: none; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 1rem; transition: transform 0.2s; }
        .card-regional:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .table-centros td { font-size: 0.9rem; vertical-align: middle; }
        .btn-action { padding: 0.2rem 0.5rem; font-size: 0.8rem; }
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div><a href="menu.php"><img src="imagenes/logo.png" height="75" alt="Logo"></a></div>
    <div>
        <span class="me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm">Salir</a>
    </div>
</div>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="text-primary fw-bold"><i class="bi bi-geo-alt-fill"></i> Gestión de Ubicaciones</h3>
        <div>
            <button class="btn btn-success btn-sm me-2" onclick="modalRegional()"><i class="bi bi-plus-lg"></i> Nueva Regional</button>
            <a href="centro_gestion.php" class="btn btn-outline-secondary btn-sm">Volver</a>
        </div>
    </div>

    <?php if($mensaje): ?><div class="alert alert-info alert-dismissible fade show"><?= $mensaje ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <div class="row">
        <?php foreach($regionales as $reg): ?>
        <div class="col-lg-6 mb-4">
            <div class="card card-regional h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="m-0 text-dark fw-bold"><?= htmlspecialchars($reg['nombre_regional']) ?> <small class="text-muted">(<?= $reg['cod_regional'] ?>)</small></h5>
                    <div>
                        <button class="btn btn-sm btn-outline-primary btn-action" onclick='modalRegional(<?= json_encode($reg) ?>)'><i class="bi bi-pencil"></i></button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar Regional?');">
                            <input type="hidden" name="id_eliminar" value="<?= $reg['id_regional'] ?>">
                            <button type="submit" name="eliminar_regional" class="btn btn-sm btn-outline-danger btn-action"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-centros mb-0">
                            <thead class="table-light"><tr><th>Cód</th><th>Centro de Costo</th><th class="text-end">Acción</th></tr></thead>
                            <tbody>
                                <?php foreach($reg['centros'] as $cc): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark border"><?= $cc['cod_centro_costo'] ?></span></td>
                                    <td><?= htmlspecialchars($cc['nombre_centro_costo']) ?></td>
                                    <td class="text-end">
                                        <button class="btn btn-link p-0 text-warning me-2" onclick='modalCentro(<?= $reg['id_regional'] ?>, <?= json_encode($cc) ?>)'><i class="bi bi-pencil-square"></i></button>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar Centro?');">
                                            <input type="hidden" name="id_eliminar_cc" value="<?= $cc['id_centro_costo'] ?>">
                                            <button type="submit" name="eliminar_centro" class="btn btn-link p-0 text-danger"><i class="bi bi-trash-fill"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($reg['centros'])): ?>
                                    <tr><td colspan="3" class="text-center text-muted small py-3">Sin centros de costo asignados</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white text-center border-top-0">
                    <button class="btn btn-sm btn-outline-success w-100" onclick="modalCentro(<?= $reg['id_regional'] ?>)"><i class="bi bi-plus-circle"></i> Agregar Centro de Costo</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="modalReg" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title">Regional</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="id_regional" id="reg_id">
                <div class="mb-3"><label>Nombre</label><input type="text" name="nombre_regional" id="reg_nom" class="form-control" required></div>
                <div class="mb-3"><label>Código (Ej: 101)</label><input type="number" name="cod_regional" id="reg_cod" class="form-control" required></div>
            </div>
            <div class="modal-footer"><button type="submit" name="guardar_regional" class="btn btn-primary">Guardar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalCC" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header bg-success text-white"><h5 class="modal-title">Centro de Costo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="id_centro" id="cc_id">
                <input type="hidden" name="id_regional_padre" id="cc_reg_id">
                <div class="mb-3"><label>Nombre</label><input type="text" name="nombre_centro" id="cc_nom" class="form-control" required></div>
                <div class="mb-3"><label>Código (Ej: 10101)</label><input type="text" name="cod_centro" id="cc_cod" class="form-control" required></div>
            </div>
            <div class="modal-footer"><button type="submit" name="guardar_centro" class="btn btn-success">Guardar</button></div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const mReg = new bootstrap.Modal(document.getElementById('modalReg'));
    const mCC = new bootstrap.Modal(document.getElementById('modalCC'));

    function modalRegional(data = null) {
        document.getElementById('reg_id').value = data ? data.id_regional : '';
        document.getElementById('reg_nom').value = data ? data.nombre_regional : '';
        document.getElementById('reg_cod').value = data ? data.cod_regional : '';
        mReg.show();
    }

    function modalCentro(idReg, data = null) {
        document.getElementById('cc_reg_id').value = idReg;
        document.getElementById('cc_id').value = data ? data.id_centro_costo : '';
        document.getElementById('cc_nom').value = data ? data.nombre_centro_costo : '';
        document.getElementById('cc_cod').value = data ? data.cod_centro_costo : '';
        mCC.show();
    }
</script>
</body>
</html>