<?php
// =================================================================================
// ARCHIVO: enriquecer_activos_tecnologicos.php
// DESCRIPCIÓN: Script para actualizar campos técnicos de activos importados
//              usando datos de una tabla de respaldo.
// =================================================================================

// Habilitar visualización de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/backend/db.php'; // Asegúrate de que esta ruta sea correcta
require_once __DIR__ . '/backend/historial_helper.php'; // Para registrar eventos

// --- VERIFICACIÓN DE CONEXIÓN A BD ---
if (!isset($conexion) || !$conexion || (is_object($conexion) && property_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    die("Error crítico de conexión a la base de datos. No se puede continuar.");
}
$conexion->set_charset("utf8mb4");

$mensaje = "";
$activos_enriquecidos_count = 0;
$activos_no_encontrados_backup = [];
$activos_sin_cambios = [];

// Definir los campos técnicos a enriquecer
$campos_tecnicos = [
    'procesador', 'ram', 'disco_duro', 'tipo_equipo', 'red', 
    'sistema_operativo', 'offimatica', 'antivirus', 'satisfaccion_rating', 'vida_util'
];

// Iniciar transacción
$conexion->begin_transaction();

try {
    // 1. Obtener todos los activos de la tabla principal (recién importados)
    $stmt_get_imported_assets = $conexion->prepare("SELECT id, serie, Codigo_Inv, detalles FROM activos_tecnologicos");
    if (!$stmt_get_imported_assets) throw new Exception("Error al preparar consulta de activos importados: " . $conexion->error);
    $stmt_get_imported_assets->execute();
    $result_imported_assets = $stmt_get_imported_assets->get_result();
    $imported_assets = $result_imported_assets->fetch_all(MYSQLI_ASSOC);
    $stmt_get_imported_assets->close();

    // 2. Preparar statement para buscar en el backup
    $stmt_get_backup_asset = $conexion->prepare("SELECT * FROM activos_tecnologicos_backup WHERE serie = ? OR Codigo_Inv = ?");
    if (!$stmt_get_backup_asset) throw new Exception("Error al preparar consulta de backup: " . $conexion->error);

    // 3. Preparar statement para actualizar el activo principal
    $sql_update_main_asset = "UPDATE activos_tecnologicos SET 
                                procesador = ?, ram = ?, disco_duro = ?, tipo_equipo = ?, red = ?, 
                                sistema_operativo = ?, offimatica = ?, antivirus = ?, 
                                satisfaccion_rating = ?, vida_util = ?, detalles = ?
                              WHERE id = ?";
    $stmt_update_main_asset = $conexion->prepare($sql_update_main_asset);
    if (!$stmt_update_main_asset) throw new Exception("Error al preparar actualización de activo principal: " . $conexion->error);

    foreach ($imported_assets as $imported_asset) {
        $id_activo_main = $imported_asset['id'];
        $serie_main = $imported_asset['serie'];
        $codigo_inv_main = $imported_asset['Codigo_Inv'];
        $detalles_main = $imported_asset['detalles'];
        
        $backup_asset = null;
        $stmt_get_backup_asset->bind_param("ss", $serie_main, $codigo_inv_main);
        $stmt_get_backup_asset->execute();
        $result_backup_asset = $stmt_get_backup_asset->get_result();
        if ($row_backup = $result_backup_asset->fetch_assoc()) {
            $backup_asset = $row_backup;
        }

        if ($backup_asset) {
            $cambios_detectados = false;
            $datos_anteriores_hist = [];
            $datos_nuevos_hist = [];

            // Recorrer campos técnicos y actualizar si hay diferencia o si el campo en main está vacío
            foreach ($campos_tecnicos as $campo) {
                $valor_backup = $backup_asset[$campo] ?? null;
                $valor_main = $imported_asset[$campo] ?? null; // Asumiendo que imported_asset ya tiene estos campos (vacíos)

                // Solo actualizar si el valor del backup no es nulo/vacío Y es diferente al actual en main
                // O si el valor en main es nulo/vacío y el de backup no lo es
                if (!empty($valor_backup) && ($valor_backup != $valor_main || empty($valor_main))) {
                    $datos_anteriores_hist[$campo] = $valor_main;
                    $imported_asset[$campo] = $valor_backup; // Actualizar el array para la inserción
                    $datos_nuevos_hist[$campo] = $valor_backup;
                    $cambios_detectados = true;
                }
            }

            // Manejar el campo 'detalles' de forma especial para fusionar o priorizar
            $detalles_backup = $backup_asset['detalles'] ?? '';
            if (!empty($detalles_backup) && $detalles_backup !== $detalles_main) {
                // Si los detalles del backup son más completos o diferentes, fusionar o reemplazar
                // Aquí optamos por fusionar si ambos tienen contenido, o tomar el del backup si el main está vacío
                $new_detalles = $detalles_main;
                if (empty($detalles_main)) {
                    $new_detalles = $detalles_backup;
                } elseif (strpos($detalles_backup, $detalles_main) === false) { // Evitar duplicar si ya está contenido
                    $new_detalles .= ". " . $detalles_backup;
                }
                
                if ($new_detalles !== $detalles_main) {
                    $datos_anteriores_hist['detalles'] = $detalles_main;
                    $imported_asset['detalles'] = $new_detalles;
                    $datos_nuevos_hist['detalles'] = $new_detalles;
                    $cambios_detectados = true;
                }
            }

            if ($cambios_detectados) {
                $stmt_update_main_asset->bind_param("sssssssssisi",
                    $imported_asset['procesador'],
                    $imported_asset['ram'],
                    $imported_asset['disco_duro'],
                    $imported_asset['tipo_equipo'],
                    $imported_asset['red'],
                    $imported_asset['sistema_operativo'],
                    $imported_asset['offimatica'],
                    $imported_asset['antivirus'],
                    $imported_asset['satisfaccion_rating'],
                    $imported_asset['vida_util'],
                    $imported_asset['detalles'],
                    $id_activo_main
                );
                if ($stmt_update_main_asset->execute()) {
                    $activos_enriquecidos_count++;
                    // Registrar en historial
                    $descripcion_historial = "Activo enriquecido con datos técnicos del backup. Serie: " . htmlspecialchars($serie_main);
                    registrar_evento_historial($conexion, $id_activo_main, 'ENRIQUECIMIENTO', $descripcion_historial, 'Sistema (Enriquecimiento)', $datos_anteriores_hist, $datos_nuevos_hist);
                } else {
                    $errores_importacion[] = "Error al enriquecer activo ID " . $id_activo_main . " (Serie: " . htmlspecialchars($serie_main) . "): " . $stmt_update_main_asset->error;
                }
            } else {
                $activos_sin_cambios[] = "Activo ID " . $id_activo_main . " (Serie: " . htmlspecialchars($serie_main) . ") no requirió enriquecimiento o no se encontraron diferencias.";
            }
        } else {
            $activos_no_encontrados_backup[] = "Activo ID " . $id_activo_main . " (Serie: " . htmlspecialchars($serie_main) . ", Cód. Inv: " . htmlspecialchars($codigo_inv_main) . ") no encontrado en la tabla de respaldo.";
        }
    }

    $stmt_get_backup_asset->close();
    $stmt_update_main_asset->close();

    $conexion->commit();
    $mensaje = "<div class='alert alert-success'>Proceso de enriquecimiento finalizado. Se enriquecieron {$activos_enriquecidos_count} activos.</div>";
    if (!empty($errores_importacion)) {
        $mensaje .= "<div class='alert alert-danger'>Errores durante el enriquecimiento:<ul>";
        foreach ($errores_importacion as $error) {
            $mensaje .= "<li>" . htmlspecialchars($error) . "</li>";
        }
        $mensaje .= "</ul></div>";
    }
    if (!empty($activos_no_encontrados_backup)) {
        $mensaje .= "<div class='alert alert-warning'>Activos no encontrados en el backup:<ul>";
        foreach ($activos_no_encontrados_backup as $item) {
            $mensaje .= "<li>" . htmlspecialchars($item) . "</li>";
        }
        $mensaje .= "</ul></div>";
    }
    if (!empty($activos_sin_cambios)) {
        $mensaje .= "<div class='alert alert-info'>Activos que no requirieron cambios: " . count($activos_sin_cambios) . "</div>";
    }

} catch (Exception $e) {
    $conexion->rollback();
    $mensaje = "<div class='alert alert-danger'>Error crítico durante el proceso de enriquecimiento: " . htmlspecialchars($e->getMessage()) . "</div>";
} finally {
    $conexion->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enriquecer Activos Tecnológicos</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 800px; margin-top: 50px; }
        .card { border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .card-header { background-color: #191970; color: white; border-radius: 10px 10px 0 0; }
        .btn-primary { background-color: #191970; border-color: #191970; }
        .btn-primary:hover { background-color: #14145a; border-color: #14191970; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header text-center">
                <h4 class="mb-0"><i class="bi bi-magic me-2"></i> Enriquecer Activos Tecnológicos</h4>
            </div>
            <div class="card-body">
                <?php if ($mensaje) echo $mensaje; ?>

                <p class="text-muted">Este script toma los datos técnicos de la tabla de respaldo (`activos_tecnologicos_backup`) y los aplica a los activos correspondientes en la tabla principal (`activos_tecnologicos`) basándose en la serie o el código de inventario.</p>
                <p class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill"></i> **Importante:** Asegúrese de haber ejecutado el script de importación de activos desde Excel **antes** de ejecutar este script de enriquecimiento.
                </p>
                
                <form action="enriquecer_activos_tecnologicos.php" method="post">
                    <button type="submit" class="btn btn-primary" name="iniciar_enriquecimiento">
                        <i class="bi bi-arrow-clockwise me-2"></i> Iniciar Proceso de Enriquecimiento
                    </button>
                </form>
            </div>
        </div>
        <div class="text-center mt-3">
            <a href="menu.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle"></i> Volver al Menú Principal</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>