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
    
    try {
        $spreadsheet = IOFactory::load($nombreArchivo);
        $worksheet = $spreadsheet->getActiveSheet();
        $filas = $worksheet->getRowIterator(2); // Omitir encabezado

        $filas_importadas = 0;
        $filas_omitidas = 0;
        $errores = [];

        // Pre-cargar categorías existentes para evitar duplicados
        $categorias_existentes = [];
        $res = $conexion->query("SELECT LOWER(nombre_categoria) as nombre FROM categorias");
        while ($row = $res->fetch_assoc()) {
            $categorias_existentes[] = $row['nombre'];
        }

        foreach ($filas as $fila) {
            $cellIterator = $fila->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $datosFila = [];
            foreach ($cellIterator as $celda) {
                $datosFila[] = $celda->getValue();
            }

            // Mapeo: A=Nombre, B=Descripción
            $nombre_categoria = trim($datosFila[0] ?? '');
            $descripcion = trim($datosFila[1] ?? '');

            // Validaciones
            if (empty($nombre_categoria)) {
                $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. Nombre de categoría vacío.";
                $filas_omitidas++;
                continue;
            }

            if (in_array(strtolower($nombre_categoria), $categorias_existentes)) {
                $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. La categoría '{$nombre_categoria}' ya existe.";
                $filas_omitidas++;
                continue;
            }

            // Insertar
            $stmt = $conexion->prepare("INSERT INTO categorias (nombre_categoria, descripcion_categoria) VALUES (?, ?)");
            $stmt->bind_param("ss", $nombre_categoria, $descripcion);

            if ($stmt->execute()) {
                $filas_importadas++;
                $categorias_existentes[] = strtolower($nombre_categoria); // Agregar a cache local
            } else {
                $errores[] = "Fila " . $fila->getRowIndex() . ": Error SQL - " . $stmt->error;
                $filas_omitidas++;
            }
            $stmt->close();
        }

        $_SESSION['import_success_message'] = "Proceso finalizado. Categorías creadas: **{$filas_importadas}**.";
        if ($filas_omitidas > 0) {
            $_SESSION['import_error_message'] = "Se omitieron **{$filas_omitidas}** filas.";
            $_SESSION['import_errors'] = $errores;
        }

    } catch (Exception $e) {
        $_SESSION['import_error_message'] = "Error procesando el archivo: " . $e->getMessage();
    }

} else {
    $_SESSION['import_error_message'] = "Error al subir el archivo.";
}

header("Location: importar.php");
exit;
?>