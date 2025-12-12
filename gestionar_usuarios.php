<?php
// =================================================================================
// ARCHIVO: gestionar_usuarios.php
// DESCRIPCIÓN: CRUD Completo de Usuarios (Crear y Editar con Ubicación Dinámica)
// =================================================================================

session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin']); 

require_once 'backend/db.php';

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error crítico de conexión."); }
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';
$id_usuario_actual_sesion = $_SESSION['usuario_id'] ?? null; 

$mensaje_accion = $_SESSION['mensaje_accion_usuarios'] ?? null;
unset($_SESSION['mensaje_accion_usuarios']);

$usuario_para_editar = null;
$abrir_modal_creacion_js = false;

// --- CARGAR DATOS MAESTROS PARA SELECTORES ---
$cargos_form = [];
$res = $conexion->query("SELECT nombre_cargo FROM cargos ORDER BY nombre_cargo ASC");
if($res) while($r=$res->fetch_assoc()) $cargos_form[] = $r['nombre_cargo'];

$empresas_form = [];
$res = $conexion->query("SELECT DISTINCT empresa FROM usuarios WHERE empresa IS NOT NULL AND empresa != '' ORDER BY empresa ASC");
if($res) while($r=$res->fetch_assoc()) $empresas_form[] = $r['empresa'];

$regionales_form = [];
$res = $conexion->query("SELECT id_regional, nombre_regional FROM regionales ORDER BY nombre_regional ASC");
if($res) while($r=$res->fetch_assoc()) $regionales_form[] = $r;

$roles_form = ['admin', 'auditor', 'tecnico', 'registrador']; 

// Funciones Auxiliares
function formatoTitulo($string) { return mb_convert_case(trim($string), MB_CASE_TITLE, "UTF-8"); }
function getRolBadgeClass($rol) {
    switch (strtolower(trim($rol))) {
        case 'admin': return 'badge rounded-pill bg-success';
        case 'auditor': return 'badge rounded-pill bg-primary';
        case 'tecnico': return 'badge rounded-pill bg-danger';
        case 'registrador': return 'badge rounded-pill bg-warning text-dark';
        default: return 'badge rounded-pill bg-secondary';
    }
}

// =================================================================================
// LÓGICA POST (PROCESAR FORMULARIOS)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- 1. CREAR USUARIO ---
    if (isset($_POST['crear_usuario_submit'])) {
        $login = trim($_POST['nuevo_usuario_login']);
        $nombre = formatoTitulo(trim($_POST['nuevo_nombre_completo']));
        $clave = $_POST['nueva_clave'];
        $confirmar = $_POST['confirmar_nueva_clave'];
        $cargo_nombre = trim($_POST['nuevo_cargo']);
        $empresa = $_POST['nueva_empresa'] ?? '';
        $rol = $_POST['nuevo_rol'];
        $activo = isset($_POST['nuevo_activo']) ? 1 : 0;
        
        // Datos Ubicación
        $id_reg = !empty($_POST['nueva_regional']) ? (int)$_POST['nueva_regional'] : null;
        $id_cc = !empty($_POST['nuevo_centro_costo']) ? (int)$_POST['nuevo_centro_costo'] : null;

        // Validaciones
        if (empty($login) || empty($nombre) || empty($clave) || empty($cargo_nombre) || empty($rol)) {
            $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Faltan datos obligatorios.</div>";
            header("Location: gestionar_usuarios.php?error_creacion=1"); exit;
        }
        if ($clave !== $confirmar) {
            $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Las contraseñas no coinciden.</div>";
            header("Location: gestionar_usuarios.php?error_creacion=1"); exit;
        }

        // Obtener ID Cargo
        $id_cargo = null;
        $stmt = $conexion->prepare("SELECT id_cargo FROM cargos WHERE nombre_cargo = ?");
        $stmt->bind_param("s", $cargo_nombre); $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) { $id_cargo = $row['id_cargo']; }
        else {
            $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Cargo no válido.</div>";
            header("Location: gestionar_usuarios.php?error_creacion=1"); exit;
        }

        // Obtener nombre Regional (Texto Legacy)
        $reg_texto = '';
        if ($id_reg) {
            $s = $conexion->prepare("SELECT nombre_regional FROM regionales WHERE id_regional = ?");
            $s->bind_param("i", $id_reg); $s->execute(); $s->bind_result($rt); 
            if($s->fetch()) $reg_texto = $rt; $s->close();
        }

        // Insertar
        $hash = password_hash($clave, PASSWORD_DEFAULT);
        $sql = "INSERT INTO usuarios (usuario, clave, nombre_completo, id_cargo, empresa, regional, rol, activo, id_centro_costo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("sssisssii", $login, $hash, $nombre, $id_cargo, $empresa, $reg_texto, $rol, $activo, $id_cc);
        
        if ($stmt->execute()) {
            $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-success'>Usuario creado correctamente.</div>";
        } else {
            $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Error: " . $conexion->error . "</div>";
        }
        header("Location: gestionar_usuarios.php"); exit;
    }

    // --- 2. EDITAR USUARIO ---
    elseif (isset($_POST['editar_usuario_submit'])) {
        $id_user = (int)$_POST['id_usuario_editar'];
        $login = trim($_POST['edit_usuario_login']);
        $nombre = formatoTitulo(trim($_POST['edit_nombre_completo']));
        $cargo_nombre = trim($_POST['edit_cargo']);
        $empresa = $_POST['edit_empresa'] ?? '';
        $rol = $_POST['edit_rol'];
        $activo = isset($_POST['edit_activo']) ? 1 : 0;
        
        // Ubicación Editada
        $id_reg = !empty($_POST['edit_regional']) ? (int)$_POST['edit_regional'] : null;
        $id_cc = !empty($_POST['edit_centro_costo']) ? (int)$_POST['edit_centro_costo'] : null;

        // Validar Cargo
        $id_cargo = null;
        $stmt = $conexion->prepare("SELECT id_cargo FROM cargos WHERE nombre_cargo = ?");
        $stmt->bind_param("s", $cargo_nombre); $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) $id_cargo = $row['id_cargo'];
        $stmt->close();

        // Texto Regional Legacy
        $reg_texto = '';
        if ($id_reg) {
            $s = $conexion->prepare("SELECT nombre_regional FROM regionales WHERE id_regional = ?");
            $s->bind_param("i", $id_reg); $s->execute(); $s->bind_result($rt); 
            if($s->fetch()) $reg_texto = $rt; $s->close();
        }

        // Construir Update Dinámico (por si cambia clave)
        $sql = "UPDATE usuarios SET usuario=?, nombre_completo=?, id_cargo=?, empresa=?, regional=?, rol=?, activo=?, id_centro_costo=? ";
        $params = [$login, $nombre, $id_cargo, $empresa, $reg_texto, $rol, $activo, $id_cc];
        $types = "ssisssii";

        if (isset($_POST['edit_cambiar_clave_check']) && !empty($_POST['edit_clave'])) {
            if ($_POST['edit_clave'] === $_POST['edit_confirmar_clave']) {
                $sql .= ", clave=? ";
                $params[] = password_hash($_POST['edit_clave'], PASSWORD_DEFAULT);
                $types .= "s";
            } else {
                $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Las contraseñas no coinciden.</div>";
                header("Location: gestionar_usuarios.php?accion=editar&id=$id_user"); exit;
            }
        }
        $sql .= " WHERE id=?";
        $params[] = $id_user;
        $types .= "i";

        $stmt = $conexion->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-success'>Usuario actualizado.</div>";
        } else {
            $_SESSION['mensaje_accion_usuarios'] = "<div class='alert alert-danger'>Error: " . $stmt->error . "</div>";
        }
        header("Location: gestionar_usuarios.php"); exit;
    }
}

// =================================================================================
// LÓGICA GET (CARGAR USUARIO PARA EDITAR)
// =================================================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // CONSULTA ENRIQUECIDA: Trae datos del usuario + ID Regional (via centro costo)
    $sql = "SELECT u.*, c.nombre_cargo, 
                   cc.id_regional AS id_regional_actual 
            FROM usuarios u 
            LEFT JOIN cargos c ON u.id_cargo = c.id_cargo 
            LEFT JOIN centros_costo cc ON u.id_centro_costo = cc.id_centro_costo
            WHERE u.id = ?";
            
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $usuario_para_editar = $res->fetch_assoc();
}

// Obtener lista completa para la tabla
$usuarios_listados = [];
$res = $conexion->query("SELECT u.*, c.nombre_cargo FROM usuarios u LEFT JOIN cargos c ON u.id_cargo = c.id_cargo ORDER BY u.nombre_completo ASC");
if($res) while($r=$res->fetch_assoc()) $usuarios_listados[] = $r;

// Control de errores de creación
if (isset($_GET['error_creacion'])) $abrir_modal_creacion_js = true;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { padding-top: 110px; background-color: #eef2f5; font-family: 'Segoe UI', sans-serif; display: flex; flex-direction: column; min-height: 100vh; }
        .main-container { flex-grow: 1; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; padding: 0.5rem 1.5rem; background: #fff; border-bottom: 1px solid #dee2e6; }
        .logo-container-top img { height: 75px; }
        .page-title { color: #0d6efd; font-weight: 600; }
        .footer-custom { background: #f8f9fa; border-top: 1px solid #dee2e6; padding: 1rem 0; margin-top: auto; text-align: center; }
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div class="logo-container-top"><a href="menu.php"><img src="imagenes/logo.png" alt="Logo"></a></div>
    <div>
        <span class="me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm">Salir</a>
    </div>
</div>

<div class="container mt-4 main-container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearUsuario"><i class="bi bi-plus-circle"></i> Nuevo Usuario</button>
        <h1 class="page-title">Gestión de Usuarios</h1>
        <a href="centro_gestion.php" class="btn btn-outline-secondary btn-sm">Volver</a>
    </div>

    <?php if ($mensaje_accion): ?><div class='mb-3'><?= $mensaje_accion ?></div><?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-light"><i class="bi bi-list-ul"></i> Usuarios Registrados</div>
        <div class="p-3 bg-white border-bottom">
            <input type="text" id="filtroUsuarios" class="form-control" placeholder="Buscar por cédula o nombre...">
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0" id="tablaUsuarios">
                <thead class="table-dark">
                    <tr><th>Usuario (C.C)</th><th>Nombre</th><th>Cargo</th><th>Empresa</th><th>Regional</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr>
                </thead>
                <tbody id="tablaUsuariosBody">
                    <?php foreach ($usuarios_listados as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['usuario']) ?></td>
                        <td><?= htmlspecialchars($u['nombre_completo']) ?></td>
                        <td><?= htmlspecialchars($u['nombre_cargo']) ?></td>
                        <td><?= htmlspecialchars($u['empresa']) ?></td>
                        <td><?= htmlspecialchars($u['regional']) ?></td>
                        <td><span class="<?= getRolBadgeClass($u['rol']) ?>"><?= ucfirst($u['rol']) ?></span></td>
                        <td><span class="badge rounded-pill <?= $u['activo']?'bg-success':'bg-danger' ?>"><?= $u['activo']?'Activo':'Inactivo' ?></span></td>
                        <td>
                            <a href="gestionar_usuarios.php?accion=editar&id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil-square"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCrearUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_usuarios.php">
                <div class="modal-header bg-primary text-white"><h5 class="modal-title">Crear Usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-6"><label class="form-label">Cédula *</label><input type="text" class="form-control" name="nuevo_usuario_login" required></div>
                        <div class="col-6"><label class="form-label">Nombre *</label><input type="text" class="form-control" name="nuevo_nombre_completo" required></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6"><label class="form-label">Contraseña *</label><input type="password" class="form-control" name="nueva_clave" required></div>
                        <div class="col-6"><label class="form-label">Confirmar *</label><input type="password" class="form-control" name="confirmar_nueva_clave" required></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6"><label class="form-label">Cargo *</label><select class="form-select" name="nuevo_cargo" required><option value="">...</option><?php foreach($cargos_form as $c) echo "<option value='$c'>$c</option>"; ?></select></div>
                        <div class="col-6"><label class="form-label">Rol *</label><select class="form-select" name="nuevo_rol" required><?php foreach($roles_form as $r) echo "<option value='$r'>".ucfirst($r)."</option>"; ?></select></div>
                    </div>
                    
                    <div class="row mb-3 bg-light p-2 rounded">
                        <div class="col-6">
                            <label class="form-label">Regional</label>
                            <select class="form-select" id="nueva_regional" name="nueva_regional">
                                <option value="">Seleccione...</option>
                                <?php foreach($regionales_form as $reg) echo "<option value='{$reg['id_regional']}'>{$reg['nombre_regional']}</option>"; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Centro de Costo</label>
                            <select class="form-select" id="nuevo_centro_costo" name="nuevo_centro_costo" disabled><option value="">Seleccione Regional</option></select>
                        </div>
                    </div>

                    <div class="mb-3"><label class="form-label">Empresa</label><select class="form-select" name="nueva_empresa"><option value="">...</option><?php foreach($empresas_form as $e) echo "<option value='$e'>$e</option>"; ?></select></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="nuevo_activo" value="1" checked><label class="form-check-label">Activo</label></div>
                </div>
                <div class="modal-footer"><button type="submit" name="crear_usuario_submit" class="btn btn-primary">Crear</button></div>
            </form>
        </div>
    </div>
</div>

<?php if ($usuario_para_editar): ?>
<div class="modal fade" id="modalEditarUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="gestionar_usuarios.php">
                <input type="hidden" name="id_usuario_editar" value="<?= $usuario_para_editar['id'] ?>">
                <div class="modal-header bg-warning"><h5 class="modal-title">Editar Usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-6"><label class="form-label">Cédula</label><input type="text" class="form-control" name="edit_usuario_login" value="<?= $usuario_para_editar['usuario'] ?>" required></div>
                        <div class="col-6"><label class="form-label">Nombre</label><input type="text" class="form-control" name="edit_nombre_completo" value="<?= $usuario_para_editar['nombre_completo'] ?>" required></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">Cargo</label>
                            <select class="form-select" name="edit_cargo" required>
                                <?php foreach($cargos_form as $c) echo "<option value='$c' ".($usuario_para_editar['nombre_cargo']==$c?'selected':'').">$c</option>"; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Rol</label>
                            <select class="form-select" name="edit_rol" required>
                                <?php foreach($roles_form as $r) echo "<option value='$r' ".($usuario_para_editar['rol']==$r?'selected':'').">".ucfirst($r)."</option>"; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 bg-light p-2 rounded">
                        <div class="col-6">
                            <label class="form-label">Regional</label>
                            <select class="form-select" id="edit_regional" name="edit_regional">
                                <option value="">Seleccione...</option>
                                <?php foreach($regionales_form as $reg) echo "<option value='{$reg['id_regional']}' ".($usuario_para_editar['id_regional_actual']==$reg['id_regional']?'selected':'').">{$reg['nombre_regional']}</option>"; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Centro de Costo</label>
                            <select class="form-select" id="edit_centro_costo" name="edit_centro_costo" disabled><option value="">Cargando...</option></select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Empresa</label>
                        <select class="form-select" name="edit_empresa">
                            <?php foreach($empresas_form as $e) echo "<option value='$e' ".($usuario_para_editar['empresa']==$e?'selected':'').">$e</option>"; ?>
                        </select>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="edit_activo" value="1" <?= $usuario_para_editar['activo']?'checked':'' ?>>
                        <label class="form-check-label">Usuario Activo</label>
                    </div>
                    
                    <hr>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="check_pass" name="edit_cambiar_clave_check"><label class="form-check-label">Cambiar Contraseña</label></div>
                    <div id="div_pass" style="display:none;" class="mt-2">
                        <div class="row"><div class="col-6"><input type="password" class="form-control" name="edit_clave" placeholder="Nueva"></div><div class="col-6"><input type="password" class="form-control" name="edit_confirmar_clave" placeholder="Confirmar"></div></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" name="editar_usuario_submit" class="btn btn-primary">Actualizar</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<footer class="footer-custom"><div class="container"><small>Sistema de Gestión</small></div></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Filtro Tabla
    const filtro = document.getElementById('filtroUsuarios');
    const filas = document.querySelectorAll('#tablaUsuariosBody tr');
    if(filtro) {
        filtro.addEventListener('input', function() {
            const val = this.value.toLowerCase();
            filas.forEach(f => f.style.display = f.innerText.toLowerCase().includes(val) ? '' : 'none');
        });
    }

    // 2. Función Cargar Centros (Reutilizable)
    function cargarCentros(idReg, idCentroSelect, idCentroPreseleccionado = null) {
        const select = document.getElementById(idCentroSelect);
        if(!select) return;
        
        select.innerHTML = '<option value="">Cargando...</option>';
        select.disabled = true;

        if(idReg) {
            fetch(`backend/obtener_datos_dinamicos.php?accion=obtener_centros_costo_por_regional&id_regional=${idReg}`)
            .then(r => r.json())
            .then(data => {
                select.innerHTML = '<option value="">Seleccione...</option>';
                if(data.length > 0) {
                    data.forEach(cc => {
                        const opt = document.createElement('option');
                        opt.value = cc.id_centro_costo;
                        opt.text = `${cc.nombre_centro_costo} (${cc.cod_centro_costo})`;
                        select.add(opt);
                    });
                    select.disabled = false;
                    if(idCentroPreseleccionado) select.value = idCentroPreseleccionado;
                } else {
                    select.innerHTML = '<option>No hay centros</option>';
                }
            });
        } else {
            select.innerHTML = '<option value="">Seleccione Regional</option>';
        }
    }

    // 3. Eventos Crear
    const selRegNew = document.getElementById('nueva_regional');
    if(selRegNew) selRegNew.addEventListener('change', function() { cargarCentros(this.value, 'nuevo_centro_costo'); });

    // 4. Eventos Editar (Si existe modal)
    <?php if ($usuario_para_editar): ?>
        const modalEdit = new bootstrap.Modal(document.getElementById('modalEditarUsuario'));
        modalEdit.show();

        const selRegEdit = document.getElementById('edit_regional');
        const currentCentro = "<?= $usuario_para_editar['id_centro_costo'] ?? '' ?>";
        
        // Carga inicial al abrir modal
        if(selRegEdit.value) {
            cargarCentros(selRegEdit.value, 'edit_centro_costo', currentCentro);
        }
        
        // Cambio manual en editar
        selRegEdit.addEventListener('change', function() { cargarCentros(this.value, 'edit_centro_costo'); });

        // Toggle Password
        const chkPass = document.getElementById('check_pass');
        const divPass = document.getElementById('div_pass');
        chkPass.addEventListener('change', function() { divPass.style.display = this.checked ? 'block' : 'none'; });
    <?php endif; ?>

    <?php if ($abrir_modal_creacion_js): ?>
    new bootstrap.Modal(document.getElementById('modalCrearUsuario')).show();
    <?php endif; ?>
});
</script>
</body>
</html>