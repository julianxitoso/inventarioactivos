<?php
session_start();
require_once __DIR__ . '/backend/auth_check.php';
restringir_acceso_pagina(['admin']);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/backend/db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion)) { die("Error de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4");

if (isset($_FILES['archivo_excel']) && $_FILES['archivo_excel']['error'] == UPLOAD_ERR_OK) {
    $nombreArchivo = $_FILES['archivo_excel']['tmp_name'];
    
    $spreadsheet = IOFactory::load($nombreArchivo);
    $worksheet = $spreadsheet->getActiveSheet();
    $filas = $worksheet->getRowIterator(2); // Empezar desde la segunda fila

    $filas_importadas = 0;
    $filas_omitidas = 0;
    $errores = [];

    // Pre-cargar tipos de activo existentes para validación de duplicados
    $tipos_existentes = [];
    $result = $conexion->query("SELECT LOWER(nombre_tipo_activo) as nombre FROM tipos_activo");
    while ($row = $result->fetch_assoc()) {
        $tipos_existentes[] = $row['nombre'];
    }

    foreach ($filas as $fila) {
        $datosFila = [];
        $cellIterator = $fila->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);
        
        foreach ($cellIterator as $index => $celda) {
            $datosFila[$index] = $celda->getValue();
        }

        $nombre_tipo_activo = trim($datosFila[0] ?? '');
        $descripcion = trim($datosFila[1] ?? '');
        $vida_util = trim($datosFila[2] ?? '');
        $campos_especificos_raw = trim($datosFila[3] ?? '0');

        // --- VALIDACIONES ---
        if (empty($nombre_tipo_activo)) {
            $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. El 'nombre_tipo_activo' no puede estar vacío.";
            $filas_omitidas++;
            continue;
        }
        if (!is_numeric($vida_util) || intval($vida_util) <= 0) {
            $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. La 'vida_util_sugerida' debe ser un número mayor a 0.";
            $filas_omitidas++;
            continue;
        }
        if (in_array(strtolower($nombre_tipo_activo), $tipos_existentes)) {
            $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. El tipo de activo '{$nombre_tipo_activo}' ya existe.";
            $filas_omitidas++;
            continue;
        }

        $campos_especificos = (in_array(strtolower($campos_especificos_raw), ['1', 'si', 'true'])) ? 1 : 0;
        
        // --- INSERCIÓN ---
        $sql_insert = "INSERT INTO tipos_activo (nombre_tipo_activo, descripcion, vida_util_sugerida, campos_especificos) VALUES (?, ?, ?, ?)";
        $stmt_insert = $conexion->prepare($sql_insert);
        $stmt_insert->bind_param("ssii", $nombre_tipo_activo, $descripcion, $vida_util, $campos_especificos);
        
        if ($stmt_insert->execute()) {
            $filas_importadas++;
            $tipos_existentes[] = strtolower($nombre_tipo_activo); // Añadir al array para evitar duplicados en el mismo archivo
        } else {
            $errores[] = "Fila " . $fila->getRowIndex() . ": Error al insertar en la base de datos - " . $stmt_insert->error;
            $filas_omitidas++;
        }
        $stmt_insert->close();
    }

    $_SESSION['import_success_message'] = "Importación de Tipos de Activo completada. Se crearon exitosamente **{$filas_importadas}** nuevos tipos.";
    if ($filas_omitidas > 0) {
        $_SESSION['import_error_message'] = "Se omitieron **{$filas_omitidas}** filas por errores o duplicados.";
        $_SESSION['import_errors'] = $errores;
    }

} else {
    $_SESSION['import_error_message'] = "Error al subir el archivo. Por favor, inténtelo de nuevo.";
}

header("Location: importar.php");
exit;
?>