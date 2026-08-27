<?php
// =================================================================================
// ARCHIVO: procesar_importacion_completo.php
// =================================================================================

use PhpOffice\PhpSpreadsheet\IOFactory;

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

session_start();
require_once __DIR__ . '/backend/auth_check.php';
restringir_acceso_pagina(['admin']);
require_once __DIR__ . '/backend/db.php';

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    $_SESSION['import_error_message'] = "Error crítico: Falla en la librería de Excel.";
    header("Location: importar.php");
    exit;
}

if (!isset($conexion) || $conexion->connect_error) {
    $_SESSION['import_error_message'] = "Error de conexión a la BD.";
    header("Location: importar.php");
    exit;
}
$conexion->set_charset("utf8mb4");

$errores = [];
$importados = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_excel'])) {
    $file = $_FILES['archivo_excel'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $tempPath = $file['tmp_name'];
        
        try {
            $spreadsheet = IOFactory::load($tempPath);
            
            // Forzar la lectura de la pestaña correcta
            if ($spreadsheet->sheetNameExists('ANALISIS_ACTIVOS')) {
                $sheet = $spreadsheet->getSheetByName('ANALISIS_ACTIVOS');
            } else {
                $sheet = $spreadsheet->getActiveSheet(); 
            }
            
            $highestRow = $sheet->getHighestRow();
            $conexion->begin_transaction();

            $stmt_buscar_usuario = $conexion->prepare("SELECT id, id_centro_costo FROM usuarios WHERE usuario = ? LIMIT 1");
            
            for ($row = 2; $row <= $highestRow; $row++) {
                
                // Limpieza de Puntos (.) y Espacios
                $raw_cat = trim($sheet->getCell('A' . $row)->getValue() ?? '');
                $categoria = ($raw_cat === '.') ? '' : mb_strtoupper($raw_cat, 'UTF-8');
                
                $raw_tipo = trim($sheet->getCell('B' . $row)->getValue() ?? '');
                $nombre_tipo = ($raw_tipo === '.') ? '' : mb_strtoupper($raw_tipo, 'UTF-8');
                
                $vida_util = intval($sheet->getCell('C' . $row)->getValue() ?? 0);
                
                $raw_inv = trim($sheet->getCell('D' . $row)->getValue() ?? '');
                $codigo_inventario = ($raw_inv === '.') ? '' : mb_strtoupper($raw_inv, 'UTF-8');

                $raw_serie = trim($sheet->getCell('E' . $row)->getValue() ?? '');
                $serie = ($raw_serie === '.') ? '' : mb_strtoupper($raw_serie, 'UTF-8');

                $raw_marca = trim($sheet->getCell('F' . $row)->getValue() ?? '');
                $marca = ($raw_marca === '.') ? '' : mb_strtoupper($raw_marca, 'UTF-8');

                $raw_modelo = trim($sheet->getCell('G' . $row)->getValue() ?? '');
                $modelo = ($raw_modelo === '.') ? '' : mb_strtoupper($raw_modelo, 'UTF-8');

                $cedula_responsable = trim($sheet->getCell('H' . $row)->getValue() ?? '');
                
                $raw_estado = trim($sheet->getCell('I' . $row)->getValue() ?? '');
                $estado = ($raw_estado === '.') ? '' : mb_strtoupper($raw_estado, 'UTF-8');

                $valor_compra = floatval($sheet->getCell('J' . $row)->getValue() ?? 0);
                
                // Fecha
                $cellDate = $sheet->getCell('K' . $row);
                $fecha_compra = null;
                if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cellDate)) {
                    $fecha_compra = date('Y-m-d', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($cellDate->getValue()));
                } else {
                    $val_date = trim($cellDate->getValue() ?? '');
                    if(!empty($val_date)) $fecha_compra = date('Y-m-d', strtotime(str_replace('/', '-', $val_date)));
                }

                $raw_detalles = trim($sheet->getCell('L' . $row)->getValue() ?? '');
                $detalles = ($raw_detalles === '.') ? '' : mb_strtoupper($raw_detalles, 'UTF-8');

                // --- NUEVOS CAMPOS ---
                $raw_tenencia = trim($sheet->getCell('M' . $row)->getValue() ?? '');
                $tenencia = empty($raw_tenencia) ? 'PROPIO' : mb_strtoupper($raw_tenencia, 'UTF-8');

                $codigo_cuenta = trim($sheet->getCell('N' . $row)->getValue() ?? '');
                $nombre_cuenta = mb_strtoupper(trim($sheet->getCell('O' . $row)->getValue() ?? ''), 'UTF-8');
                $codigo_depreciacion = trim($sheet->getCell('P' . $row)->getValue() ?? '');
                $nombre_depreciacion = mb_strtoupper(trim($sheet->getCell('Q' . $row)->getValue() ?? ''), 'UTF-8');

                // Validaciones de salto de fila
                if (empty($categoria) && empty($nombre_tipo) && empty($cedula_responsable)) { continue; }

                // ========================================================
                // 1. AUTO-ASIGNAR CENTRO DE COSTO SEGÚN CÉDULA
                // ========================================================
                $id_usuario_responsable = null;
                $id_centro_costo = null;

                $stmt_buscar_usuario->bind_param("s", $cedula_responsable);
                $stmt_buscar_usuario->execute();
                $resultado_usu = $stmt_buscar_usuario->get_result();

                if ($row_usu = $resultado_usu->fetch_assoc()) {
                    $id_usuario_responsable = $row_usu['id'];
                    $id_centro_costo = $row_usu['id_centro_costo'];
                } else {
                    $errores[] = "Fila {$row}: La cédula {$cedula_responsable} no existe en el sistema. Omitiendo activo.";
                    continue; 
                }

                // ========================================================
                // 2. GESTIÓN DE CATEGORÍAS
                // ========================================================
                $id_categoria = null;
                $res_cat = $conexion->query("SELECT id_categoria FROM categorias WHERE nombre_categoria = '" . $conexion->real_escape_string($categoria) . "' LIMIT 1");
                if ($res_cat && $res_cat->num_rows > 0) {
                    $id_categoria = $res_cat->fetch_object()->id_categoria;
                } else {
                    $conexion->query("INSERT INTO categorias (nombre_categoria) VALUES ('" . $conexion->real_escape_string($categoria) . "')");
                    $id_categoria = $conexion->insert_id;
                }

                // ========================================================
                // 3. GESTIÓN DE TIPOS DE ACTIVO Y CUENTAS CONTABLES
                // ========================================================
                $id_tipo_activo = null;
                $res_tipo = $conexion->query("SELECT id_tipo_activo FROM tipos_activo WHERE nombre_tipo_activo = '" . $conexion->real_escape_string($nombre_tipo) . "' LIMIT 1");
                
                if ($res_tipo && $res_tipo->num_rows > 0) {
                    $id_tipo_activo = $res_tipo->fetch_object()->id_tipo_activo;
                    // Actualizamos el tipo de activo con las cuentas contables del Excel
                    $sql_upd = "UPDATE tipos_activo SET codigo_cuenta = ?, nombre_cuenta = ?, codigo_cuenta_depreciacion = ?, nombre_cuenta_depreciacion = ? WHERE id_tipo_activo = ?";
                    $stmt_upd = $conexion->prepare($sql_upd);
                    $stmt_upd->bind_param("ssssi", $codigo_cuenta, $nombre_cuenta, $codigo_depreciacion, $nombre_depreciacion, $id_tipo_activo);
                    $stmt_upd->execute();
                    $stmt_upd->close();
                } else {
                    // Creamos el tipo de activo nuevo con toda su base contable
                    $sql_ins = "INSERT INTO tipos_activo (nombre_tipo_activo, id_categoria, codigo_cuenta, nombre_cuenta, codigo_cuenta_depreciacion, nombre_cuenta_depreciacion) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt_ins = $conexion->prepare($sql_ins);
                    $stmt_ins->bind_param("sissss", $nombre_tipo, $id_categoria, $codigo_cuenta, $nombre_cuenta, $codigo_depreciacion, $nombre_depreciacion);
                    $stmt_ins->execute();
                    $id_tipo_activo = $stmt_ins->insert_id;
                    $stmt_ins->close();
                }

                // ========================================================
                // 4. INSERCIÓN DEL ACTIVO FINAL (En activos_tecnologicos)
                // ========================================================
                if (!empty($serie)) {
                    $res_serie = $conexion->query("SELECT id FROM activos_tecnologicos WHERE serie = '" . $conexion->real_escape_string($serie) . "' LIMIT 1");
                    if ($res_serie && $res_serie->num_rows > 0) {
                        $errores[] = "Fila {$row}: El serial {$serie} ya está registrado. Omitiendo activo.";
                        continue;
                    }
                }

                // Usando tabla activos_tecnologicos
                $sql_insert = "INSERT INTO activos_tecnologicos 
                (id_tipo_activo, marca, modelo, serie, estado, Codigo_Inv, tenencia, id_usuario_responsable, id_centro_costo, fecha_compra, valor_aproximado, vida_util, detalles) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt_activo = $conexion->prepare($sql_insert);
                // issssssiisdis (13 parámetros)
                $stmt_activo->bind_param("issssssiisdis", 
                    $id_tipo_activo, $marca, $modelo, $serie, $estado, $codigo_inventario, $tenencia, 
                    $id_usuario_responsable, $id_centro_costo, $fecha_compra, $valor_compra, $vida_util, $detalles
                );
                
                if ($stmt_activo->execute()) {
                    $importados++;
                } else {
                    $errores[] = "Fila {$row}: Error al guardar activo -> " . $stmt_activo->error;
                }
                $stmt_activo->close();
            }

            $stmt_buscar_usuario->close();

            if ($importados > 0) {
                $conexion->commit();
                $_SESSION['import_success_message'] = "¡Éxito! Se importaron $importados activos con Cuentas Contables y Tenencias correctamente.";
            } else {
                $conexion->rollback();
                $_SESSION['import_error_message'] = "No se importó ningún activo. Revise los errores.";
            }
            
            if (!empty($errores)) {
                $_SESSION['import_errors'] = $errores;
            }

        } catch (Exception $e) {
            $conexion->rollback();
            $_SESSION['import_error_message'] = "Error procesando el archivo: " . $e->getMessage();
        }
    } else {
        $_SESSION['import_error_message'] = "Error en la subida del archivo.";
    }
    header("Location: importar.php");
    exit;
}
?>