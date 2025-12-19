<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// =================================================================================
// ARCHIVO: index.php (Formulario Principal de Registro de Activos)
// =================================================================================

// 1. AUTENTICACIÓN Y SEGURIDAD
require_once 'backend/auth_check.php';
// Verificamos permiso de crear activos
verificar_permiso_o_morir('crear_activo');

require_once 'backend/db.php';
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error crítico: No hay conexión a la base de datos."); }
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

// 2. CARGA DE DATOS MAESTROS (Para llenar los selects iniciales)

// A. Cargar Categorías
$lista_categorias = [];
$sql_cats = "SELECT id_categoria, nombre_categoria FROM categorias_activo ORDER BY nombre_categoria ASC";
$res_cats = $conexion->query($sql_cats);
if ($res_cats) {
    while($row = $res_cats->fetch_assoc()) { $lista_categorias[] = $row; }
}

// B. Cargar Regionales (Desde DB)
$lista_regionales = [];
$sql_regs = "SELECT id_regional, nombre_regional, cod_regional FROM regionales ORDER BY nombre_regional ASC";
$res_regs = $conexion->query($sql_regs);
if ($res_regs) {
    while($row = $res_regs->fetch_assoc()) { $lista_regionales[] = $row; }
}

// 3. LÓGICA DEL ROL "REGISTRADOR" (Datos Pre-cargados)
$es_registrador = ($rol_usuario_actual_sesion === 'registrador');
$apps_registrador_array = [];
$otros_app_texto_registrador = '';
$cedula_registrador = '';
$nombre_registrador = '';
$cargo_registrador = '';
// Nota: Eliminamos la carga de regional/empresa desde sesión aquí para dejar que AJAX lo haga con precisión
$apps_previamente_registradas = false;
$mensaje_apps = '';

if ($es_registrador) {
    // Datos desde la sesión de PHP
    $cedula_registrador = $_SESSION['usuario_login'] ?? '';
    $nombre_registrador = $_SESSION['nombre_usuario_completo'] ?? '';
    $cargo_registrador = $_SESSION['cargo_usuario'] ?? '';
    
    $aplicaciones_registrador_str = ''; // Inicializar

    // Hacemos una consulta directa a la BD para obtener las apps
    if (!empty($cedula_registrador)) {
        $sql_apps = "SELECT aplicaciones_usadas FROM usuarios WHERE usuario = ? LIMIT 1";
        $stmt_apps = $conexion->prepare($sql_apps);
        
        if ($stmt_apps) {
            $stmt_apps->bind_param("s", $cedula_registrador);
            $stmt_apps->execute();
            $stmt_apps->bind_result($aplicaciones_desde_db);
            
            if ($stmt_apps->fetch()) {
                $aplicaciones_registrador_str = $aplicaciones_desde_db;
            }
            $stmt_apps->close();
        }
    }

    if (!empty($aplicaciones_registrador_str)) {
        $apps_previamente_registradas = true;
        $temp_apps = array_map('trim', explode(',', $aplicaciones_registrador_str));

        foreach ($temp_apps as $app) {
            if (strpos($app, 'Otros: ') === 0) {
                $otros_app_texto_registrador = substr($app, strlen('Otros: '));
                $apps_registrador_array[] = 'Otros';
            } else {
                $apps_registrador_array[] = $app;
            }
        }
    }

    // Lógica de mensaje
    if ($apps_previamente_registradas) {
        $apps_display_string = htmlspecialchars($aplicaciones_registrador_str, ENT_QUOTES, 'UTF-8');
        $mensaje_apps = "📝 <strong>Información:</strong> Este responsable ya tiene las siguientes aplicaciones asignadas: <br><strong>{$apps_display_string}</strong>.<br>Estos campos han sido bloqueados. Para modificarlos, contacta al administrador.";
    } else {
        $mensaje_apps = 'Este responsable aún no tiene aplicaciones frecuentes registradas. Por favor, selecciona las aplicaciones que más usas.';
    }
}

// 4. DEFINICIÓN DE LISTAS ESTÁTICAS
$empresas_disponibles = ['Arpesod', 'Finansueños'];
$opciones_tipo_equipo = ['Portátil', 'Mesa', 'Todo en 1'];
$opciones_red = ['Cableada', 'Inalámbrica', 'Ambas'];
$opciones_estado_general = ['Bueno', 'Regular', 'Malo'];
$opciones_so = ['Windows 10', 'Windows 11', 'Linux', 'MacOS'];
$opciones_offimatica = ['Office 365', 'Office Home And Business', 'Office 2021', 'Office 2019', 'Office 2016', 'LibreOffice', 'Google Workspace'];
$opciones_antivirus = ['Microsoft Defender', 'Bitdefender', 'ESET NOD32 Antivirus', 'McAfee Total Protection', 'Kaspersky'];
$aplicaciones_mas_usadas = ['Manager', 'Excel', 'Word', 'Power Point', 'WhatsApp Web', 'Siesa', 'Finansueños', 'Correo', 'Internet', 'Otros'];

$mensaje_global = $_SESSION['mensaje_global'] ?? null;
$error_global = $_SESSION['error_global'] ?? null;
unset($_SESSION['mensaje_global']);
unset($_SESSION['error_global']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registrar Activos por Lote</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        html { height: 100%; }
        body { min-height: 100%; display: flex; flex-direction: column; background-color: #ffffff !important; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; }
        .container-main { flex-grow: 1; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        .btn-principal, #btnGuardarTodo, #btnAgregarActivoTabla { background-color: #191970; border-color: #191970; color: #ffffff; }
        .btn-principal:hover, #btnGuardarTodo:hover, #btnAgregarActivoTabla:hover { background-color: #111150; border-color: #111150; color: #ffffff; }
        #infoModal .modal-header { background-color: #191970; color: #ffffff; }
        #infoModal .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        #infoModal .modal-title i { margin-right: 8px; }
        .card.form-card { box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: none; }
        .form-label { font-weight: 500; color: #495057; }
        .form-section { border: 1px solid #e0e0e0; padding: 20px; border-radius: 8px; margin-bottom: 20px; background-color: #fff; }
        .table-activos-agregados th { font-size: 0.9em; }
        .table-activos-agregados td { font-size: 0.85em; vertical-align: middle; }
        .star-rating { display: inline-block; direction: rtl; font-size: 0; }
        .star-rating input[type="radio"] { display: none; }
        .star-rating label.star-label { color: #ccc; font-size: 1.8rem; padding: 0 0.05em; cursor: pointer; display: inline-block; transition: color 0.2s ease-in-out; }
        .star-rating input[type="radio"]:checked ~ label.star-label, .star-rating label.star-label:hover, .star-rating label.star-label:hover ~ label.star-label { color: #f5b301; }
        .star-rating input[type="radio"]:checked + label.star-label:hover, .star-rating input[type="radio"]:checked ~ label.star-label:hover, .star-rating input[type="radio"]:checked ~ label.star-label:hover ~ label.star-label, .star-rating label.star-label:hover ~ input[type="radio"]:checked ~ label.star-label { color: #f5b301; }
        .btn-remove-asset { font-size: 0.8em; padding: 0.2rem 0.5rem; }
        #infoAplicacionesExistentes { font-size: 0.85em; }
        input:read-only, select:disabled, input:disabled { background-color: #e9ecef; cursor: not-allowed; }
        .rating-invalid {
            border: 1px solid #dc3545; /* Color de peligro de Bootstrap */
            border-radius: 8px;
            padding: 5px;
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
        }
        .footer-custom {
            font-size: 0.9rem; background-color: #f8f9fa; 
            border-top: 1px solid #dee2e6; 
        }
        .footer-custom a i { color: #6c757d; transition: color 0.2s ease-in-out; }
        .footer-custom a i:hover { color: #0d6efd !important; }
    </style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top">
        <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS"></a>
    </div>
    <div class="d-flex align-items-center">
        <span class="text-dark me-3 user-info-top"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form>
    </div>
</div>

<div class="container-main container mt-4">
    <h3 class="page-title text-center mb-4">Registrar Activos (por Responsable)</h3>

    <?php if ($mensaje_global): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($mensaje_global) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error_global): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($error_global) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>

    <form action="guardar_activo.php" method="post" id="formRegistrarLoteActivos">
        <div class="form-section" id="seccionResponsable">
            <h5 class="mb-3">1. Información del Responsable</h5>
            <div class="row">
                <div class="col-md-4 mb-3"><label for="cedula" class="form-label">Cédula <span class="text-danger">*</span></label><input type="text" class="form-control" id="cedula" name="responsable_cedula" value="<?= $es_registrador ? htmlspecialchars($cedula_registrador) : '' ?>" <?= $es_registrador ? 'readonly' : '' ?> required></div>
                <div class="col-md-4 mb-3"><label for="nombre" class="form-label">Nombre Completo <span class="text-danger">*</span></label><input type="text" class="form-control" id="nombre" name="responsable_nombre" value="<?= $es_registrador ? htmlspecialchars($nombre_registrador) : '' ?>" <?= $es_registrador ? 'readonly' : '' ?> required></div>
                <div class="col-md-4 mb-3"><label for="cargo" class="form-label">Cargo <span class="text-danger">*</span></label><input type="text" class="form-control" id="cargo" name="responsable_cargo" value="<?= $es_registrador ? htmlspecialchars($cargo_registrador) : '' ?>" <?= $es_registrador ? 'readonly' : '' ?> required></div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="regional" class="form-label">Regional (Asignada al Responsable) <span class="text-danger">*</span></label>
                    <select class="form-select" id="regional" name="responsable_id_regional" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($lista_regionales as $reg): ?>
                        <option value="<?= $reg['id_regional'] ?>"><?= htmlspecialchars($reg['nombre_regional']) ?> (<?= htmlspecialchars($reg['cod_regional']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="centro_costo" class="form-label">Centro de Costo / Ubicación <span class="text-danger">*</span></label>
                    <select class="form-select" id="centro_costo" name="responsable_id_centro_costo" required disabled>
                        <option value="">Primero seleccione una Regional</option>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="empresa_responsable" class="form-label">Empresa (Contratante) <span class="text-danger">*</span></label>
                    <select class="form-select" id="empresa_responsable" name="responsable_empresa" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($empresas_disponibles as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
             <div class="mb-3">
                <label class="form-label">Aplicaciones que más usa el responsable: <span class="text-danger">*</span></label>

                <?php if ($es_registrador && !empty($mensaje_apps)): ?>
                <div class="alert alert-info py-2 small" role="alert">
                    <?= $mensaje_apps ?>
                </div>
                <?php endif; ?>

                <div id="infoAplicacionesExistentes" class="form-text mb-2 p-2 border border-info rounded bg-light" style="display: none;"></div>

                <div class="p-2 border rounded" id="contenedorCheckboxesAplicaciones">
                    <?php foreach ($aplicaciones_mas_usadas as $app): ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" 
                               type="checkbox" 
                               name="responsable_aplicaciones[]" 
                               value="<?= htmlspecialchars($app) ?>" 
                               id="app_<?= htmlspecialchars(str_replace(' ', '_', $app)) ?>"
                               <?php 
                                   if ($es_registrador && in_array($app, $apps_registrador_array)) { echo 'checked'; }
                                   if ($es_registrador && $apps_previamente_registradas) { echo ' disabled'; }
                               ?>>
                        <label class="form-check-label" for="app_<?= htmlspecialchars(str_replace(' ', '_', $app)) ?>"><?= htmlspecialchars($app) ?></label>
                    </div>
                    <?php endforeach; ?>
                    
                    <input type="text" 
                           class="form-control form-control-sm mt-2" 
                           id="responsable_aplicaciones_otros_texto" 
                           name="responsable_aplicaciones_otros_texto" 
                           placeholder="Especifique cuál(es)" 
                           style="display: <?= !empty($otros_app_texto_registrador) ? 'block' : 'none' ?>;"
                           value="<?= htmlspecialchars($otros_app_texto_registrador) ?>"
                           <?= ($es_registrador && $apps_previamente_registradas) ? 'readonly' : '' ?>>
                </div>
            </div> 
            
            <div id="contenedor-botones-responsable">
                <button type="button" class="btn btn-info btn-sm" id="btnConfirmarResponsable">Confirmar Responsable y Agregar Activos</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnEditarResponsable" style="display: none;"><i class="bi bi-pencil"></i> Editar Responsable</button>
            </div>
        </div>
        
        <div class="form-section" id="seccionAgregarActivo" style="display: none;">
             <h5 class="mb-3">2. Agregar Activo para <strong id="nombreResponsableDisplay"></strong></h5>
             
             <div class="row">
                 <div class="col-md-4 mb-3">
                     <label for="categoria_activo" class="form-label">Categoría <span class="text-danger">*</span></label>
                     <select class="form-select" id="categoria_activo" name="activo_id_categoria" required>
                         <option value="">Seleccione Categoría...</option>
                         <?php foreach ($lista_categorias as $cat): ?>
                         <option value="<?= $cat['id_categoria'] ?>"><?= htmlspecialchars($cat['nombre_categoria']) ?></option>
                         <?php endforeach; ?>
                     </select>
                 </div>

                 <div class="col-md-4 mb-3">
                     <label for="tipo_activo" class="form-label">Tipo de Activo <span class="text-danger">*</span></label>
                     <select class="form-select" id="tipo_activo" name="activo_tipo_activo" required disabled>
                         <option value="">Primero seleccione una Categoría</option>
                     </select>
                 </div>

                 <div class="col-md-4 mb-3" id="campo_tipo_impresora_container" style="display: none;">
                     <label for="tipo_impresora" class="form-label">Tipo de Impresora <span class="text-danger">*</span></label>
                     <select class="form-select" id="tipo_impresora" name="activo_tipo_impresora">
                         <option value="">Seleccione...</option>
                         <option value="Laser">Laser</option>
                         <option value="Tinta">Tinta</option>
                         <option value="Termica">Termica</option>
                     </select>
                 </div>
             </div>

             <div class="row">
                 <div class="col-md-4 mb-3"><label for="marca" class="form-label">Marca <span class="text-danger">*</span></label><input type="text" class="form-control" id="marca" name="activo_marca" required></div>
                 
                 <div class="col-md-4 mb-3">
                     <label for="serie" class="form-label">Serie / Serial <span class="text-danger">*</span></label>
                     <div class="input-group">
                         <input type="text" class="form-control" id="serie" name="activo_serie" required>
                         <button class="btn btn-outline-secondary" type="button" id="btnGenerarSerie" title="Generar Serie Automáticamente">
                             <i class="bi bi-magic"></i> Generar
                         </button>
                     </div>
                 </div>
                 <div class="col-md-4 mb-3"><label for="estado" class="form-label">Estado del Activo <span class="text-danger">*</span></label><select class="form-select" id="estado" name="activo_estado" required><option value="Seleccione">Seleccione</option><?php foreach ($opciones_estado_general as $opcion): if($opcion !== 'Nuevo' && $opcion !== 'Dado de Baja') { ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php } endforeach; ?></select></div>
             </div>

             <div class="row">
                 <div class="col-md-4 mb-3"><label for="valor_aproximado" class="form-label">Valor del Activo (Compra) <span class="text-danger">*</span></label><input type="number" class="form-control" id="valor_aproximado" name="activo_valor_aproximado" step="0.01" min="0" required></div>
                 <div class="col-md-4 mb-3"><label for="codigo_inv" class="form-label">Código Inventario (Opcional)</label><input type="text" class="form-control" id="codigo_inv" name="activo_codigo_inv"></div>
             </div>
             
             <hr class="my-3">
             <h6 class="mb-3 text-primary">Información para Depreciación (Automático)</h6>
             <div class="row">
                 <div class="col-md-3 mb-3">
                     <label for="fecha_compra" class="form-label">Fecha de Compra <span class="text-danger">*</span></label>
                     <input type="date" class="form-control" id="fecha_compra" name="activo_fecha_compra" required>
                 </div>
                 <div class="col-md-3 mb-3">
                     <label for="vida_util" class="form-label">Vida Útil (Años)</label>
                     <input type="number" class="form-control" id="vida_util" name="activo_vida_util" readonly title="Se carga automáticamente según el tipo">
                 </div>
                 <div class="col-md-3 mb-3">
                     <label for="metodo_depreciacion" class="form-label">Método Depreciación</label>
                     <select class="form-select" id="metodo_depreciacion" name="activo_metodo_depreciacion" disabled>
                         <option value="Linea Recta" selected>Línea Recta</option>
                     </select>
                 </div>
                 <div class="col-md-3 mb-3">
                     <label for="valor_residual" class="form-label">Valor Residual</label>
                     <input type="number" class="form-control" id="valor_residual" name="activo_valor_residual" value="0" readonly>
                 </div>
             </div>

             <div id="campos_computador_form_activo" style="display: none;">
                 <hr class="my-3"><h6 class="mb-3 text-muted">Detalles Específicos (si es Computador)</h6>
                 <div class="row">
                     <div class="col-md-4 mb-3"><label for="activo_procesador" class="form-label">Procesador</label><input type="text" class="form-control" id="activo_procesador" name="activo_procesador"></div>
                     <div class="col-md-4 mb-3"><label for="activo_ram" class="form-label">RAM</label><input type="text" class="form-control" id="activo_ram" name="activo_ram"></div>
                     <div class="col-md-4 mb-3"><label for="activo_disco_duro" class="form-label">Disco Duro</label><input type="text" class="form-control" id="activo_disco_duro" name="activo_disco_duro"></div>
                 </div>
                 <div class="row">
                     <div class="col-md-3 mb-3"><label for="activo_tipo_equipo" class="form-label">Tipo Equipo</label><select class="form-select" id="activo_tipo_equipo" name="activo_tipo_equipo"><option value="">Seleccione...</option><?php foreach ($opciones_tipo_equipo as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div>
                     <div class="col-md-3 mb-3"><label for="activo_red" class="form-label">Red</label><select class="form-select" id="activo_red" name="activo_red"><option value="">Seleccione...</option><?php foreach ($opciones_red as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div>
                     <div class="col-md-3 mb-3"><label for="activo_so" class="form-label">SO</label><select class="form-select" id="activo_so" name="activo_sistema_operativo"><option value="">Seleccione...</option><?php foreach ($opciones_so as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div>
                     <div class="col-md-3 mb-3"><label for="activo_offimatica" class="form-label">Offimática</label><select class="form-select" id="activo_offimatica" name="activo_offimatica"><option value="">Seleccione...</option><?php foreach ($opciones_offimatica as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div>
                 </div>
                 <div class="row"><div class="col-md-4 mb-3"><label for="activo_antivirus" class="form-label">Antivirus</label><select class="form-select" id="activo_antivirus" name="activo_antivirus"><option value="">Seleccione...</option><?php foreach ($opciones_antivirus as $opcion): ?><option value="<?= htmlspecialchars($opcion) ?>"><?= htmlspecialchars($opcion) ?></option><?php endforeach; ?></select></div></div>
             </div>
             <div class="mb-3"><label for="detalles" class="form-label">Detalles Adicionales (Observaciones)</label><textarea class="form-control" id="detalles" name="activo_detalles" rows="2"></textarea></div>
             <div class="mb-3"><label class="form-label d-block">Califica tu nivel de satisfacción con este activo: <span class="text-danger">*</span></label><div class="star-rating" id="activo_satisfaccion_rating_container"><input type="radio" id="activo_star5" name="activo_satisfaccion_rating" value="5" /><label class="star-label" for="activo_star5" title="5 estrellas">☆</label><input type="radio" id="activo_star4" name="activo_satisfaccion_rating" value="4" /><label class="star-label" for="activo_star4" title="4 estrellas">☆</label><input type="radio" id="activo_star3" name="activo_satisfaccion_rating" value="3" /><label class="star-label" for="activo_star3" title="3 estrellas">☆</label><input type="radio" id="activo_star2" name="activo_satisfaccion_rating" value="2" /><label class="star-label" for="activo_star2" title="2 estrellas">☆</label><input type="radio" id="activo_star1" name="activo_satisfaccion_rating" value="1" /><label class="star-label" for="activo_star1" title="1 estrella">☆</label></div></div>
             <button type="button" class="btn btn-success" id="btnAgregarActivoTabla"><i class="bi bi-plus-circle"></i> Agregar Activo a la Lista</button>
        </div>

        <div class="form-section mt-4" id="seccionTablaActivos" style="display: none;">
            <h5 class="mb-3">3. Activos para Registrar a <strong id="nombreResponsableTabla"></strong></h5>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover table-activos-agregados">
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th>Tipo</th>
                            <th>Marca</th>
                            <th>Serie</th>
                            <th>F. Compra</th>
                            <th>Valor</th>
                            <th>Vida Útil</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody id="tablaActivosBody"></tbody>
                </table>
            </div>
            <p id="noActivosMensaje" class="text-muted">Aún no se han agregado activos a la lista.</p>
        </div>
        
        <div class="mt-4 d-grid gap-2">
            <button type="button" class="btn btn-primary btn-lg" id="btnGuardarTodo" disabled><i class="bi bi-save"></i> Guardar Todos los Activos y Finalizar</button>
        </div>
    </form>
</div>

<div class="modal fade" id="infoModal" tabindex="-1" aria-labelledby="infoModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="infoModalTitle"><i class="bi bi-exclamation-triangle-fill"></i> Atención</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p id="infoModalMessage"></p></div><div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendido</button></div></div></div></div>

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let activosParaGuardar = [];
    let responsableConfirmado = false; 
    let infoModalInstance;
    let vidaUtilMap = {}; 
    const esRegistrador = <?= $es_registrador ? 'true' : 'false' ?>;

    // Elementos del DOM
    const inputCedula = document.getElementById('cedula');
    const inputNombre = document.getElementById('nombre');
    const inputCargo = document.getElementById('cargo');
    const selectEmpresa = document.getElementById('empresa_responsable');
    const selectRegional = document.getElementById('regional');
    const selectCentroCosto = document.getElementById('centro_costo');
    
    // Apps
    const divInfoApps = document.getElementById('infoAplicacionesExistentes');
    const checkboxesApps = document.querySelectorAll('input[name="responsable_aplicaciones[]"]');
    const inputOtrosApps = document.getElementById('responsable_aplicaciones_otros_texto');
    const checkOtros = document.getElementById('app_Otros');

    // Botones
    const btnConfirmarResponsable = document.getElementById('btnConfirmarResponsable');
    const btnEditarResponsable = document.getElementById('btnEditarResponsable');
    const btnAgregarActivoTabla = document.getElementById('btnAgregarActivoTabla');
    const btnGuardarTodo = document.getElementById('btnGuardarTodo');
    
    // Secciones
    const seccionResponsable = document.getElementById('seccionResponsable');
    const seccionAgregarActivo = document.getElementById('seccionAgregarActivo');
    const seccionTablaActivos = document.getElementById('seccionTablaActivos');
    const tablaActivosBody = document.getElementById('tablaActivosBody');
    const noActivosMensaje = document.getElementById('noActivosMensaje');

    // Activos
    const selectCategoria = document.getElementById('categoria_activo');
    const selectTipoActivo = document.getElementById('tipo_activo');
    const inputVidaUtil = document.getElementById('vida_util');
    const campoTipoImpresoraContainer = document.getElementById('campo_tipo_impresora_container');
    const campoTipoImpresoraSelect = document.getElementById('tipo_impresora');

    function normalizarTexto(texto) {
        if (!texto) return '';
        return texto.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim();
    }

    // Función inteligente para seleccionar en Selects (Texto o Valor)
    function seleccionarEnSelect(select, valorBuscado) {
        if (!valorBuscado) return false;
        const buscado = normalizarTexto(valorBuscado.toString());
        
        for (let i = 0; i < select.options.length; i++) {
            const optVal = normalizarTexto(select.options[i].value);
            const optText = normalizarTexto(select.options[i].text);
            
            // Coincidencia exacta de valor o texto parcial
            if (optVal === buscado || optText.includes(buscado) || buscado.includes(optText)) {
                select.selectedIndex = i;
                return true;
            }
        }
        return false;
    }

    function alternarBloqueoCamposResponsable(bloquear) {
        // Solo bloqueamos si no es readonly por defecto (nombre/cargo)
        if(!inputNombre.readOnly) inputNombre.disabled = bloquear;
        if(!inputCargo.readOnly) inputCargo.disabled = bloquear;
        selectEmpresa.disabled = bloquear;
        selectRegional.disabled = bloquear;
        selectCentroCosto.disabled = bloquear;
    }

    // =========================================================
    // 1. AUTOCOMPLETADO (Event Handler)
    // =========================================================
    function buscarDatos(val) {
        if(!val) return;
        
        // 1. DESBLOQUEAR TODO PRIMERO (Para que JS pueda escribir)
        alternarBloqueoCamposResponsable(false);
        
        fetch(`buscar_datos_usuario.php?cedula=${val}`)
        .then(r => r.json())
        .then(data => {
            if(data.encontrado) {
                inputNombre.value = data.nombre_completo || '';
                inputCargo.value = data.cargo || '';
                
                // Seleccionar Empresa
                seleccionarEnSelect(selectEmpresa, data.empresa_texto);

                // Seleccionar Regional (Prioridad: ID > Texto)
                let regionalSet = false;
                if (data.id_regional) {
                    // Intento por ID directo
                    for(let i=0; i<selectRegional.options.length; i++) {
                        if(selectRegional.options[i].value == data.id_regional) {
                            selectRegional.selectedIndex = i;
                            regionalSet = true;
                            break;
                        }
                    }
                }
                if (!regionalSet && data.regional_texto) {
                    regionalSet = seleccionarEnSelect(selectRegional, data.regional_texto);
                }

                // Cargar Centros de Costo si hay regional
                if (selectRegional.value) {
                    cargarCentrosCosto(selectRegional.value, data.id_centro_costo);
                } else {
                    selectCentroCosto.innerHTML = '<option value="">Seleccione Regional...</option>';
                }

                // Apps
                if (data.aplicaciones_usadas && data.aplicaciones_usadas.trim() !== '') {
                    divInfoApps.innerHTML = `📝 <strong>Info:</strong> Apps ya registradas: <b>${data.aplicaciones_usadas}</b>.`;
                    divInfoApps.style.display = 'block';
                }
            } else {
                // Si no se encuentra, dejar campos libres para escribir (si es admin)
                if(!esRegistrador) {
                    // Limpiar visualmente pero dejar habilitado
                    selectCentroCosto.innerHTML = '<option value="">Primero seleccione Regional</option>';
                }
            }
        })
        .finally(() => {
            // Si es Registrador, BLOQUEAMOS todo de nuevo al final
            if (esRegistrador) {
                setTimeout(() => {
                    alternarBloqueoCamposResponsable(true);
                    inputCedula.readOnly = true; // Asegurar
                }, 500); // Pequeño delay para asegurar que centros de costo cargó
            }
        })
        .catch(err => console.error('Error buscar usuario:', err));
    }

    inputCedula.addEventListener('blur', function() {
        buscarDatos(this.value.trim());
    });

    function cargarCentrosCosto(idRegional, idCentroPreseleccionado) {
        selectCentroCosto.innerHTML = '<option value="">Cargando...</option>';
        // Mantener habilitado mientras carga
        selectCentroCosto.disabled = false;
        
        fetch(`backend/obtener_datos_dinamicos.php?accion=obtener_centros_costo_por_regional&id_regional=${idRegional}`)
            .then(res => res.json())
            .then(centros => {
                selectCentroCosto.innerHTML = '<option value="">Seleccione Centro de Costo...</option>';
                
                if (Array.isArray(centros) && centros.length > 0) {
                    centros.forEach(cc => {
                        const opt = document.createElement('option');
                        opt.value = cc.id_centro_costo;
                        opt.textContent = `${cc.nombre_centro_costo} (${cc.cod_centro_costo})`;
                        selectCentroCosto.appendChild(opt);
                    });
                    
                    if (idCentroPreseleccionado) {
                        selectCentroCosto.value = idCentroPreseleccionado;
                    }
                } else {
                    selectCentroCosto.innerHTML = '<option value="">No hay centros</option>';
                }
            })
            .catch(e => {
                console.error(e);
                selectCentroCosto.innerHTML = '<option value="">Error al cargar</option>';
            });
    }

    // Manual change regional
    selectRegional.addEventListener('change', function() {
        cargarCentrosCosto(this.value);
    });

    // =========================================================
    // 2. ACTIVOS
    // =========================================================
    selectCategoria.addEventListener('change', function() {
        const idCat = this.value;
        selectTipoActivo.innerHTML = '<option value="">Cargando...</option>';
        selectTipoActivo.disabled = true;
        inputVidaUtil.value = '';
        document.getElementById('campos_computador_form_activo').style.display = 'none';
        campoTipoImpresoraContainer.style.display = 'none';

        if(idCat) {
            fetch(`backend/obtener_datos_dinamicos.php?accion=obtener_tipos_por_categoria&id_categoria=${idCat}`)
            .then(r => r.json())
            .then(data => {
                selectTipoActivo.innerHTML = '<option value="">Seleccione Tipo...</option>';
                vidaUtilMap = {};
                if(Array.isArray(data) && data.length > 0) {
                    data.forEach(t => {
                        const opt = document.createElement('option');
                        opt.value = t.nombre_tipo_activo;
                        opt.text = t.nombre_tipo_activo;
                        selectTipoActivo.add(opt);
                        vidaUtilMap[t.nombre_tipo_activo] = parseInt(t.vida_util_sugerida) || 0;
                    });
                    selectTipoActivo.disabled = false;
                } else {
                    selectTipoActivo.innerHTML = '<option value="">Sin tipos</option>';
                }
            });
        }
    });

    selectTipoActivo.addEventListener('change', function() {
        const val = this.value;
        inputVidaUtil.value = vidaUtilMap[val] || '';
        const tipoLower = val.toLowerCase();
        const esPC = tipoLower.includes('computador') || tipoLower.includes('portatil') || tipoLower.includes('todo en 1');
        document.getElementById('campos_computador_form_activo').style.display = esPC ? 'block' : 'none';
        const esImp = tipoLower.includes('impresora');
        campoTipoImpresoraContainer.style.display = esImp ? 'block' : 'none';
        campoTipoImpresoraSelect.required = esImp;
    });

    document.getElementById('btnGenerarSerie').addEventListener('click', () => {
        document.getElementById('serie').value = 'GEN-' + Date.now().toString().slice(-6);
    });

    // =========================================================
    // 3. FLUJO PRINCIPAL
    // =========================================================
    btnConfirmarResponsable.addEventListener('click', function() {
        // Validar campos obligatorios
        if(!inputCedula.value || !selectRegional.value || !selectEmpresa.value || !selectCentroCosto.value) {
            mostrarInfoModal('Faltan Datos', 'Por favor complete todos los datos del responsable (Regional, Centro de Costo, Empresa).');
            return;
        }
        
        responsableConfirmado = true;
        
        // Bloqueo visual
        seccionResponsable.style.opacity = '0.6';
        alternarBloqueoCamposResponsable(true);
        inputCedula.readOnly = true; 
        
        // Cambio de Botones
        this.style.display = 'none';
        btnEditarResponsable.style.display = 'inline-block';
        seccionAgregarActivo.style.display = 'block';
        seccionTablaActivos.style.display = 'block';
        
        document.getElementById('nombreResponsableDisplay').textContent = inputNombre.value;
        document.getElementById('nombreResponsableTabla').textContent = inputNombre.value;
    });

    btnEditarResponsable.addEventListener('click', function() {
        responsableConfirmado = false;
        seccionResponsable.style.opacity = '1';
        
        // Si es Admin, desbloqueamos todo. Si es Registrador, NO desbloqueamos lo precargado.
        if (!esRegistrador) {
            alternarBloqueoCamposResponsable(false);
            inputCedula.readOnly = false;
            inputCedula.disabled = false;
        } else {
            // El registrador no puede cambiar sus datos base, solo confirmar
            alert("Como Registrador, no puedes modificar tus datos básicos.");
            return; 
        }
        
        this.style.display = 'none';
        btnConfirmarResponsable.style.display = 'inline-block';
        seccionAgregarActivo.style.display = 'none';
        seccionTablaActivos.style.display = 'none';
    });

    // Agregar a la tabla temporal
    btnAgregarActivoTabla.addEventListener('click', function() {
        if (!responsableConfirmado) {
            mostrarInfoModal('Alto', 'Confirme primero el responsable.');
            return;
        }
        if(!selectTipoActivo.value || !document.getElementById('marca').value || !document.getElementById('serie').value) {
            mostrarInfoModal('Faltan Datos', 'Complete Categoría, Tipo, Marca y Serie.');
            return;
        }
        const rating = document.querySelector('input[name="activo_satisfaccion_rating"]:checked');
        if(!rating) { mostrarInfoModal('Calificación', 'Califique el estado físico.'); return; }

        const activo = {
            id_categoria: selectCategoria.value,
            categoria_nombre: selectCategoria.options[selectCategoria.selectedIndex].text,
            tipo_activo: selectTipoActivo.value,
            tipo_impresora: (campoTipoImpresoraContainer.style.display !== 'none') ? campoTipoImpresoraSelect.value : '',
            marca: document.getElementById('marca').value.trim(),
            serie: document.getElementById('serie').value.trim(),
            estado: document.getElementById('estado').value,
            valor_aproximado: document.getElementById('valor_aproximado').value || 0,
            codigo_inv: document.getElementById('codigo_inv').value.trim(),
            fecha_compra: document.getElementById('fecha_compra').value,
            vida_util: inputVidaUtil.value,
            detalles: document.getElementById('detalles').value.trim(),
            procesador: document.getElementById('activo_procesador').value,
            ram: document.getElementById('activo_ram').value,
            disco: document.getElementById('activo_disco_duro').value,
            sistema_operativo: document.getElementById('activo_so').value,
            offimatica: document.getElementById('activo_offimatica').value,
            antivirus: document.getElementById('activo_antivirus').value,
            tipo_equipo: document.getElementById('activo_tipo_equipo').value,
            red: document.getElementById('activo_red').value,
            satisfaccion_rating: rating.value
        };

        activosParaGuardar.push(activo);
        actualizarTablaActivos();
        limpiarFormularioActivo();
    });

    btnGuardarTodo.addEventListener('click', function() {
        if(activosParaGuardar.length === 0) return;
        
        // Desbloquear para enviar POST
        document.querySelectorAll('input, select').forEach(el => el.disabled = false);
        
        const form = document.getElementById('formRegistrarLoteActivos');
        form.querySelectorAll('input[name^="activos["]').forEach(e => e.remove());

        activosParaGuardar.forEach((activo, index) => {
            for (const propiedad in activo) {
                const inputHidden = document.createElement('input');
                inputHidden.type = 'hidden';
                inputHidden.name = `activos[${index}][${propiedad}]`;
                inputHidden.value = activo[propiedad] === null ? '' : activo[propiedad];
                form.appendChild(inputHidden);
            }
        });
        form.submit();
    });

    function actualizarTablaActivos() {
        tablaActivosBody.innerHTML = '';
        if (activosParaGuardar.length === 0) {
            noActivosMensaje.style.display = 'block';
            btnGuardarTodo.disabled = true;
            return;
        }
        noActivosMensaje.style.display = 'none';
        btnGuardarTodo.disabled = false;
        
        activosParaGuardar.forEach((activo, index) => {
            const fila = tablaActivosBody.insertRow();
            let tipoDisplay = activo.tipo_impresora ? `${activo.tipo_activo} (${activo.tipo_impresora})` : activo.tipo_activo;
            
            fila.insertCell().textContent = `${activo.categoria_nombre} - ${tipoDisplay}`;
            fila.insertCell().textContent = activo.marca;
            fila.insertCell().textContent = activo.serie;
            fila.insertCell().textContent = activo.fecha_compra;
            fila.insertCell().textContent = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP' }).format(activo.valor_aproximado);
            fila.insertCell().textContent = activo.vida_util + ' años';
            
            const celdaAccion = fila.insertCell();
            const btnEliminar = document.createElement('button');
            btnEliminar.type = 'button';
            btnEliminar.className = 'btn btn-danger btn-sm btn-remove-asset';
            btnEliminar.innerHTML = '<i class="bi bi-trash"></i>';
            btnEliminar.onclick = function() { eliminarActivoDeLista(index); };
            celdaAccion.appendChild(btnEliminar);
        });
    }

    function eliminarActivoDeLista(index) {
        activosParaGuardar.splice(index, 1);
        actualizarTablaActivos();
    }

    function limpiarFormularioActivo() {
        document.getElementById('marca').value = '';
        document.getElementById('serie').value = '';
        document.getElementById('codigo_inv').value = '';
        document.getElementById('detalles').value = '';
        document.getElementById('activo_procesador').value = '';
        document.getElementById('activo_ram').value = '';
        document.getElementById('activo_disco_duro').value = '';
        document.querySelectorAll('input[name="activo_satisfaccion_rating"]').forEach(r => r.checked = false);
    }

    function mostrarInfoModal(t, m) {
        if(!infoModalInstance) infoModalInstance = new bootstrap.Modal(document.getElementById('infoModal'));
        document.querySelector('#infoModal .modal-title').textContent = t;
        document.querySelector('#infoModal .modal-body p').innerHTML = m;
        infoModalInstance.show();
    }

    // AUTO-INICIO PARA REGISTRADOR
    document.addEventListener('DOMContentLoaded', () => {
        if(inputCedula.value) {
            buscarDatos(inputCedula.value);
        }
    });
</script>
</body>
</html>