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
    $filas = $worksheet->getRowIterator(2); // Empezar desde la segunda fila para omitir encabezados

    $filas_importadas = 0;
    $filas_omitidas = 0;
    $errores = [];

    // Pre-cargar datos para validación
    $tipos_activos_db = [];
    $result = $conexion->query("SELECT id_tipo_activo, LOWER(nombre_tipo_activo) as nombre FROM tipos_activo");
    while ($row = $result->fetch_assoc()) {
        $tipos_activos_db[$row['nombre']] = $row['id_tipo_activo'];
    }

    $usuarios_db = [];
    $result = $conexion->query("SELECT id, usuario FROM usuarios");
    while ($row = $result->fetch_assoc()) {
        $usuarios_db[$row['usuario']] = $row['id'];
    }

    foreach ($filas as $fila) {
        $datosFila = [];
        $cellIterator = $fila->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);
        
        foreach ($cellIterator as $celda) {
            $datosFila[] = $celda->getValue();
        }

        // Mapeo de columnas por índice
        $serie = trim($datosFila[0] ?? '');
        $marca = trim($datosFila[1] ?? '');
        $tipo_activo_nombre = strtolower(trim($datosFila[2] ?? ''));
        $estado = trim($datosFila[3] ?? 'Bueno');
        $valor_aproximado = is_numeric($datosFila[4]) ? floatval($datosFila[4]) : 0;
        $fecha_compra = trim($datosFila[5] ?? date('Y-m-d'));
        $cedula_responsable = trim($datosFila[6] ?? '');
        $codigo_inventario = trim($datosFila[7] ?? '');
        $detalles = trim($datosFila[8] ?? '');
        $procesador = trim($datosFila[9] ?? '');
        $ram = trim($datosFila[10] ?? '');
        $disco_duro = trim($datosFila[11] ?? '');
        $sistema_operativo = trim($datosFila[12] ?? '');
        $offimatica = trim($datosFila[13] ?? '');
        $antivirus = trim($datosFila[14] ?? '');
        $tipo_equipo = trim($datosFila[15] ?? '');

        // --- VALIDACIONES ---
        if (empty($serie)) {
            $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. La columna 'serie' es obligatoria.";
            $filas_omitidas++;
            continue;
        }

        // Verificar si la serie ya existe
        $stmt_check = $conexion->prepare("SELECT id FROM activos_tecnologicos WHERE serie = ?");
        $stmt_check->bind_param("s", $serie);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. La serie '{$serie}' ya existe.";
            $filas_omitidas++;
            $stmt_check->close();
            continue;
        }
        $stmt_check->close();

        // Validar y obtener ID de tipo de activo
        if (empty($tipo_activo_nombre) || !isset($tipos_activos_db[$tipo_activo_nombre])) {
            $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. El tipo de activo '{$datosFila[2]}' no es válido.";
            $filas_omitidas++;
            continue;
        }
        $id_tipo_activo = $tipos_activos_db[$tipo_activo_nombre];

        // Validar y obtener ID del responsable
        if (empty($cedula_responsable) || !isset($usuarios_db[$cedula_responsable])) {
            $errores[] = "Fila " . $fila->getRowIndex() . ": Omitida. La cédula de responsable '{$cedula_responsable}' no fue encontrada.";
            $filas_omitidas++;
            continue;
        }
        $id_usuario_responsable = $usuarios_db[$cedula_responsable];

        // --- INSERCIÓN ---
        $sql_insert = "INSERT INTO activos_tecnologicos (serie, marca, id_tipo_activo, estado, valor_aproximado, fecha_compra, id_usuario_responsable, Codigo_Inv, detalles, procesador, ram, disco_duro, sistema_operativo, offimatica, antivirus, Tipo_equipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_insert = $conexion->prepare($sql_insert);
        $stmt_insert->bind_param("ssisdsisssssssss", $serie, $marca, $id_tipo_activo, $estado, $valor_aproximado, $fecha_compra, $id_usuario_responsable, $codigo_inventario, $detalles, $procesador, $ram, $disco_duro, $sistema_operativo, $offimatica, $antivirus, $tipo_equipo);
        
        if ($stmt_insert->execute()) {
            $filas_importadas++;
        } else {
            $errores[] = "Fila " . $fila->getRowIndex() . ": Error al insertar en la base de datos - " . $stmt_insert->error;
            $filas_omitidas++;
        }
        $stmt_insert->close();
    }

    $_SESSION['import_success_message'] = "Importación completada. Se registraron exitosamente **{$filas_importadas}** activos.";
    if ($filas_omitidas > 0) {
        $_SESSION['import_error_message'] = "Se omitieron **{$filas_omitidas}** filas por errores de validación o duplicados.";
        $_SESSION['import_errors'] = $errores;
    }

} else {
    $_SESSION['import_error_message'] = "Error al subir el archivo. Por favor, inténtelo de nuevo.";
}

header("Location: importar.php");
exit;
?>