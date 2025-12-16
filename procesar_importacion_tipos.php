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
        $filas = $worksheet->getRowIterator(2);

        $filas_importadas = 0;
        $filas_omitidas = 0;
        $errores = [];

        // 1. Cargar Tipos Existentes (Para evitar duplicados)
        $tipos_existentes = [];
        $res = $conexion->query("SELECT LOWER(nombre_tipo_activo) as nombre FROM tipos_activo");
        while ($row = $res->fetch_assoc()) $tipos_existentes[] = $row['nombre'];

        // 2. Cargar Categorías (Mapa: Nombre -> ID) para vincular
        $mapa_categorias = [];
        $res_cat = $conexion->query("SELECT id_categoria, LOWER(nombre_categoria) as nombre FROM categorias");
        while ($row = $res_cat->fetch_assoc()) {
            $mapa_categorias[$row['nombre']] = $row['id_categoria'];
        }

        foreach ($filas as $fila) {
            $cellIterator = $fila->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $datos = [];
            foreach ($cellIterator as $celda) $datos[] = $celda->getValue();

            // Mapeo según Plantilla: A=Nombre, B=Categoria, C=Vida Util, D=Campos Esp.
            $nombre_tipo = trim($datos[0] ?? '');
            $nombre_categoria = strtolower(trim($datos[1] ?? '')); // IMPORTANTE: Columna B es Categoría
            $vida_util = trim($datos[2] ?? '');
            
            // Validaciones
            if (empty($nombre_tipo)) {
                $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. Nombre vacío.";
                $filas_omitidas++; continue;
            }
            if (in_array(strtolower($nombre_tipo), $tipos_existentes)) {
                $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. El tipo '{$nombre_tipo}' ya existe.";
                $filas_omitidas++; continue;
            }
            
            // Validar Categoría Padre
            if (empty($nombre_categoria) || !isset($mapa_categorias[$nombre_categoria])) {
                $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. La categoría '{$datos[1]}' no existe. Créela primero.";
                $filas_omitidas++; continue;
            }
            $id_categoria = $mapa_categorias[$nombre_categoria];

            if (!is_numeric($vida_util) || $vida_util < 0) $vida_util = 0;

            // Insertar con ID de Categoría
            $sql = "INSERT INTO tipos_activo (nombre_tipo_activo, id_categoria, vida_util_sugerida) VALUES (?, ?, ?)";
            $stmt = $conexion->prepare($sql);
            $stmt->bind_param("sii", $nombre_tipo, $id_categoria, $vida_util);

            if ($stmt->execute()) {
                $filas_importadas++;
                $tipos_existentes[] = strtolower($nombre_tipo);
            } else {
                $errores[] = "Fila " . $fila->getRowIndex() . ": Error SQL - " . $stmt->error;
                $filas_omitidas++;
            }
            $stmt->close();
        }

        $_SESSION['import_success_message'] = "Tipos importados: **{$filas_importadas}**.";
        if ($filas_omitidas > 0) {
            $_SESSION['import_error_message'] = "Filas omitidas: **{$filas_omitidas}**.";
            $_SESSION['import_errors'] = $errores;
        }

    } catch (Exception $e) {
        $_SESSION['import_error_message'] = "Error crítico: " . $e->getMessage();
    }
} else {
    $_SESSION['import_error_message'] = "Error al subir archivo.";
}

header("Location: importar.php");
exit;
?>