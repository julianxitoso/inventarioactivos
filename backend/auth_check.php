<?php
// =================================================================================
// ARCHIVO: backend/auth_check.php
// ESTADO: BLINDADO (Consulta robusta para id_rol)
// =================================================================================

if (session_status() == PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; 

// 1. Obtener Rol
function obtener_rol_usuario() {
    $rol = $_SESSION['rol_usuario'] ?? null;
    return $rol ? trim($rol) : null;
}

// 2. Verificar Sesión Activa
function verificar_sesion_activa() {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
        header("Location: login.php?error=sesion_requerida");
        exit;
    }
}

// 3. CONSULTA MAESTRA DE PERMISOS
function tiene_permiso($permiso_requerido) {
    global $conexion;
    
    $nombre_rol = obtener_rol_usuario();
    
    if (!$nombre_rol) return false;
    if ($nombre_rol === 'superadmin') return true;

    // --- INICIO LÓGICA BLINDADA ---
    // En lugar de un JOIN complejo que puede fallar por nombres de columnas,
    // lo hacemos en dos pasos simples y seguros.

    // PASO 1: Obtener el ID numérico del rol
    // (Usamos 'id_rol' porque gestionar_roles.php confirmó que así se llama tu columna)
    $sql_rol = "SELECT id_rol FROM roles WHERE nombre_rol = ? LIMIT 1";
    $stmt = $conexion->prepare($sql_rol);
    if (!$stmt) return false;
    
    $stmt->bind_param("s", $nombre_rol);
    $stmt->execute();
    $res_rol = $stmt->get_result();
    $fila_rol = $res_rol->fetch_assoc();
    $stmt->close();

    if (!$fila_rol) return false; // El rol no existe en BD
    
    $id_rol_numerico = $fila_rol['id_rol'];

    // PASO 2: Obtener los permisos asociados a ese ID
    // Aquí asumimos que la tabla 'permisos' usa 'id' (estándar) y 'rol_permisos' usa 'id_permiso'
    $sql_permisos = "SELECT p.clave_permiso 
                     FROM rol_permisos rp
                     INNER JOIN permisos p ON rp.id_permiso = p.id
                     WHERE rp.id_rol = ?";
                     
    $stmt2 = $conexion->prepare($sql_permisos);
    if (!$stmt2) return false;

    $stmt2->bind_param("i", $id_rol_numerico);
    $stmt2->execute();
    $res_permisos = $stmt2->get_result();
    
    // Verificamos si el permiso requerido está en la lista
    while ($row = $res_permisos->fetch_assoc()) {
        if ($row['clave_permiso'] === $permiso_requerido) {
            $stmt2->close();
            return true; // ¡Permiso encontrado!
        }
    }
    $stmt2->close();
    // --- FIN LÓGICA BLINDADA ---

    return false; // No tiene el permiso
}

// 4. BLOQUEO DE PÁGINA
function verificar_permiso_o_morir($permiso_requerido) {
    verificar_sesion_activa();
    if (!tiene_permiso($permiso_requerido)) {
        $_SESSION['error_acceso_pagina'] = "⛔ <strong>Acceso Denegado:</strong> No tienes el permiso necesario ($permiso_requerido).";
        header("Location: menu.php");
        exit;
    }
}

// 5. COMPATIBILIDAD (Para evitar el Fatal Error en centro_gestion.php)
// Si alguna página vieja llama a esta función, la redirigimos a la nueva lógica
function restringir_acceso_pagina($roles_viejos = []) {
    // Si la página es 'centro_gestion.php', requerimos 'ver_usuarios'
    if (strpos($_SERVER['PHP_SELF'], 'centro_gestion.php') !== false) {
        verificar_permiso_o_morir('ver_usuarios');
        return;
    }
    // Por defecto verificamos que pueda ver el menú
    verificar_permiso_o_morir('ver_menu');
}
?>