<?php // en backend/historial_helper.php

// Definir constantes para los tipos de evento (opcional pero recomendado)
define('HISTORIAL_TIPO_CREACION', 'CREACIÓN');
define('HISTORIAL_TIPO_ACTUALIZACION', 'ACTUALIZACIÓN');
define('HISTORIAL_TIPO_TRASLADO', 'TRASLADO');
define('HISTORIAL_TIPO_BAJA', 'BAJA');
// define('HISTORIAL_TIPO_MANTENIMIENTO', 'MANTENIMIENTO'); // Para el futuro

/**
 * Registra un evento en el historial de un activo.
 *
 * @param mysqli $conexion Conexión a la base de datos.
 * @param int $id_activo ID del activo.
 * @param string $tipo_evento Tipo de evento (usar constantes definidas).
 * @param string $descripcion_evento Descripción legible del evento.
 * @param string|null $usuario_responsable Usuario que realiza la acción.
 * @param array|null $datos_anteriores Array asociativo con datos antes del cambio.
 * @param array|null $datos_nuevos Array asociativo con datos después del cambio.
 * @return bool True si se registró correctamente, false en caso contrario.
 */
function registrar_evento_historial($conexion, $id_activo, $tipo_evento, $descripcion_evento, $usuario_responsable = null, $datos_anteriores_array = null, $datos_nuevos_array = null) {
    $datos_anteriores_json = $datos_anteriores_array ? json_encode($datos_anteriores_array, JSON_UNESCAPED_UNICODE) : null;
    $datos_nuevos_json = $datos_nuevos_array ? json_encode($datos_nuevos_array, JSON_UNESCAPED_UNICODE) : null;

    $sql = "INSERT INTO historial_activos (id_activo, tipo_evento, descripcion_evento, usuario_responsable, datos_anteriores, datos_nuevos)
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = $conexion->prepare($sql);
    if ($stmt) {
        $stmt->bind_param(
            "isssss",
            $id_activo,
            $tipo_evento,
            $descripcion_evento,
            $usuario_responsable,
            $datos_anteriores_json,
            $datos_nuevos_json
        );

        if ($stmt->execute()) {
            $stmt->close();
            return true;
        } else {
            error_log("Error al registrar evento en historial (execute): " . $stmt->error . " - ID Activo: " . $id_activo);
        }
        $stmt->close();
    } else {
        error_log("Error al preparar statement para historial: " . $conexion->error);
    }
    return false;
}
?>