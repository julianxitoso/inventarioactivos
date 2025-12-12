<?php
// =================================================================================
// ARCHIVO: guardar_activo.php
// DESCRIPCIÓN: Procesa el formulario, guarda activos y actualiza al responsable
// =================================================================================

require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'tecnico', 'registrador']);

require_once 'backend/db.php';

// Validar conexión
if (!isset($conexion) || $conexion->connect_error) {
    die("Error crítico: Fallo en la conexión a la base de datos.");
}
$conexion->set_charset("utf8mb4");

// --- 1. RECIBIR DATOS DEL RESPONSABLE ---
$cedula_responsable = trim($_POST['responsable_cedula'] ?? '');
$nombre_responsable = mb_strtoupper(trim($_POST['responsable_nombre'] ?? ''), 'UTF-8');
$cargo_responsable  = mb_strtoupper(trim($_POST['responsable_cargo'] ?? ''), 'UTF-8');
$empresa_responsable = $_POST['responsable_empresa'] ?? '';

// Datos Nuevos de Ubicación (IDs)
$id_regional_resp    = !empty($_POST['responsable_id_regional']) ? (int)$_POST['responsable_id_regional'] : null;
$id_centro_costo_resp = !empty($_POST['responsable_id_centro_costo']) ? (int)$_POST['responsable_id_centro_costo'] : null;

// Obtener nombre de regional para guardar en campo de texto legacy (compatibilidad)
$regional_texto_legacy = '';
if ($id_regional_resp) {
    $stmt_reg = $conexion->prepare("SELECT nombre_regional FROM regionales WHERE id_regional = ?");
    $stmt_reg->bind_param("i", $id_regional_resp);
    $stmt_reg->execute();
    $stmt_reg->bind_result($reg_nombre);
    if ($stmt_reg->fetch()) { $regional_texto_legacy = $reg_nombre; }
    $stmt_reg->close();
}

// Aplicaciones
$apps_seleccionadas = $_POST['responsable_aplicaciones'] ?? [];
$otros_apps_texto = trim($_POST['responsable_aplicaciones_otros_texto'] ?? '');
if (!empty($otros_apps_texto)) {
    $apps_seleccionadas[] = "Otros: " . $otros_apps_texto;
}
$aplicaciones_string = implode(', ', $apps_seleccionadas);

// --- 2. GESTIÓN DEL USUARIO (RESPONSABLE) ---
// Verificamos si existe para actualizarlo o crearlo
$id_usuario_responsable = null;
$stmt_check_user = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
$stmt_check_user->bind_param("s", $cedula_responsable);
$stmt_check_user->execute();
$res_user = $stmt_check_user->get_result();

if ($row_user = $res_user->fetch_assoc()) {
    // A. EL USUARIO EXISTE: ACTUALIZAMOS SUS DATOS
    $id_usuario_responsable = $row_user['id'];
    
    // Actualizamos Cargo, Empresa, Regional (Texto + ID) y Centro de Costo (ID)
    // Nota: Solo actualizamos aplicaciones si el campo no viene vacío (para no borrar historial si estaba bloqueado)
    $sql_update_user = "UPDATE usuarios SET 
                        nombre_completo = ?, 
                        empresa = ?, 
                        regional = ?,       -- Campo texto legacy
                        id_centro_costo = ? -- Campo nuevo ID
                        WHERE id = ?";
                        
    $stmt_update = $conexion->prepare($sql_update_user);
    $stmt_update->bind_param("sssii", $nombre_responsable, $empresa_responsable, $regional_texto_legacy, $id_centro_costo_resp, $id_usuario_responsable);
    $stmt_update->execute();
    $stmt_update->close();

    // Actualizar apps solo si se enviaron nuevas
    if (!empty($aplicaciones_string)) {
        $stmt_app = $conexion->prepare("UPDATE usuarios SET aplicaciones_usadas = ? WHERE id = ?");
        $stmt_app->bind_param("si", $aplicaciones_string, $id_usuario_responsable);
        $stmt_app->execute();
        $stmt_app->close();
    }

} else {
    // B. USUARIO NUEVO: LO CREAMOS
    // Primero gestionamos el cargo (tabla cargos)
    $id_cargo = null;
    $stmt_check_cargo = $conexion->prepare("SELECT id_cargo FROM cargos WHERE nombre_cargo = ?");
    $stmt_check_cargo->bind_param("s", $cargo_responsable);
    $stmt_check_cargo->execute();
    $res_cargo = $stmt_check_cargo->get_result();
    
    if ($row_cargo = $res_cargo->fetch_assoc()) {
        $id_cargo = $row_cargo['id_cargo'];
    } else {
        $stmt_ins_cargo = $conexion->prepare("INSERT INTO cargos (nombre_cargo) VALUES (?)");
        $stmt_ins_cargo->bind_param("s", $cargo_responsable);
        $stmt_ins_cargo->execute();
        $id_cargo = $stmt_ins_cargo->insert_id;
        $stmt_ins_cargo->close();
    }
    $stmt_check_cargo->close();

    // Insertar Usuario
    $pass_default = password_hash($cedula_responsable, PASSWORD_DEFAULT); // Clave por defecto = cédula
    $rol_defecto = 'registrador'; // Rol base
    
    $sql_insert_user = "INSERT INTO usuarios (usuario, clave, nombre_completo, id_cargo, empresa, regional, rol, aplicaciones_usadas, id_centro_costo) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_insert = $conexion->prepare($sql_insert_user);
    $stmt_insert->bind_param("sssissssi", $cedula_responsable, $pass_default, $nombre_responsable, $id_cargo, $empresa_responsable, $regional_texto_legacy, $rol_defecto, $aplicaciones_string, $id_centro_costo_resp);
    
    if ($stmt_insert->execute()) {
        $id_usuario_responsable = $stmt_insert->insert_id;
    } else {
        die("Error al crear usuario: " . $stmt_insert->error);
    }
    $stmt_insert->close();
}
$stmt_check_user->close();


// --- 3. GUARDADO DE ACTIVOS ---
$activos = $_POST['activos'] ?? [];
$conteo_exitos = 0;
$errores = [];

if (is_array($activos) && count($activos) > 0) {
    
    // Preparamos la consulta de inserción de activos (optimizada)
    $sql_activo = "INSERT INTO activos_tecnologicos (
        id_usuario_responsable, 
        id_tipo_activo, 
        marca, 
        serie, 
        estado, 
        valor_aproximado, 
        fecha_compra, 
        Codigo_Inv, 
        vida_util, 
        detalles, 
        satisfaccion_rating,
        procesador, ram, disco_duro, sistema_operativo, offimatica, antivirus, tipo_equipo, red,
        id_centro_costo -- Importante: Guardar la ubicación en el activo también
    ) VALUES (?, (SELECT id_tipo_activo FROM tipos_activo WHERE nombre_tipo_activo = ? LIMIT 1), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt_activo = $conexion->prepare($sql_activo);

    // Preparamos historial
    $sql_historial = "INSERT INTO historial_activos (id_activo, tipo_evento, descripcion_evento, usuario_responsable, fecha_evento) VALUES (?, 'ASIGNACIÓN INICIAL', ?, ?, NOW())";
    $stmt_historial = $conexion->prepare($sql_historial);

    foreach ($activos as $index => $activo) {
        // Validaciones básicas
        $tipo = $activo['tipo_activo'] ?? '';
        if ($activo['tipo_impresora']) { $tipo = 'Impresora'; } // Ajuste para buscar ID correcto si es impresora

        $marca = $activo['marca'];
        $serie = $activo['serie'];
        $estado = $activo['estado'];
        $valor = (float)$activo['valor_aproximado'];
        $fecha = $activo['fecha_compra'];
        $codigo = $activo['codigo_inv'];
        $vida = (int)$activo['vida_util'];
        $detalles = $activo['detalles'];
        $rating = (int)$activo['satisfaccion_rating'];
        
        // Campos PC
        $proc = $activo['procesador'] ?? null;
        $ram = $activo['ram'] ?? null;
        $disco = $activo['disco_duro'] ?? null;
        $so = $activo['sistema_operativo'] ?? null;
        $office = $activo['offimatica'] ?? null;
        $av = $activo['antivirus'] ?? null;
        $tipo_eq = $activo['tipo_impresora'] ? $activo['tipo_impresora'] : ($activo['tipo_equipo'] ?? null);
        $red = $activo['red'] ?? null;

        // Ejecutar Insert Activo
        $stmt_activo->bind_param("issssdssisssssssssii", 
            $id_usuario_responsable, 
            $tipo, 
            $marca, 
            $serie, 
            $estado, 
            $valor, 
            $fecha, 
            $codigo, 
            $vida, 
            $detalles, 
            $rating,
            $proc, $ram, $disco, $so, $office, $av, $tipo_eq, $red,
            $id_centro_costo_resp // Guardamos el mismo centro de costo del usuario
        );

        if ($stmt_activo->execute()) {
            $id_nuevo_activo = $conexion->insert_id;
            $conteo_exitos++;

            // Guardar Historial
            $desc_historial = "Activo asignado a $nombre_responsable ($cedula_responsable). Ubicación: $regional_texto_legacy.";
            $user_sesion = $_SESSION['usuario_login'] ?? 'System';
            $stmt_historial->bind_param("iss", $id_nuevo_activo, $desc_historial, $user_sesion);
            $stmt_historial->execute();

        } else {
            $errores[] = "Error en activo serie $serie: " . $stmt_activo->error;
        }
    }
    
    $stmt_activo->close();
    $stmt_historial->close();
}

$conexion->close();

// Redireccionar con mensajes
if (count($errores) > 0) {
    $_SESSION['error_global'] = "Se guardaron $conteo_exitos activos, pero hubo errores: " . implode(", ", $errores);
} else {
    $_SESSION['mensaje_global'] = "¡Éxito! Se registraron $conteo_exitos activos para $nombre_responsable correctamente.";
}

header("Location: index.php");
exit;
?>