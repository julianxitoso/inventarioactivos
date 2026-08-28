<?php
ob_start();
// =================================================================================
// ARCHIVO: procesar_importacion_completo.php
// VERSIÓN: DEFINITIVA (Limpiador Visual de Moneda + Fechas + Excel de Omitidos)
// =================================================================================

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M'); 
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_FILES['archivo_excel']) && $_FILES['archivo_excel']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['archivo_excel'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $tempPath = $file['tmp_name'];
            
            try {
                $spreadsheet = IOFactory::load($tempPath);
                
                if ($spreadsheet->sheetNameExists('ANALISIS_ACTIVOS')) {
                    $sheet = $spreadsheet->getSheetByName('ANALISIS_ACTIVOS');
                } else {
                    $sheet = $spreadsheet->getActiveSheet(); 
                }
                
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                $conexion->begin_transaction();

                $stmt_buscar_usuario = $conexion->prepare("SELECT id, id_centro_costo FROM usuarios WHERE usuario = ? LIMIT 1");
                $valores_nulos_comunes = ['', '.', 'S/N', 'S/N.', 'SN', 'N/A', 'NA', 'SIN SERIE', 'NO TIENE', 'NAN'];
                
                // ==================================================================
                // INICIAR GENERADOR DE EXCEL DE OMITIDOS (DINÁMICO)
                // ==================================================================
                $errorSpreadsheet = new Spreadsheet();
                $errorSheet = $errorSpreadsheet->getActiveSheet();
                $errorSheet->setTitle('Activos Omitidos');
                
                $headers = $sheet->rangeToArray('A1:' . $highestColumn . '1', NULL, TRUE, FALSE)[0];
                $headers[] = 'MOTIVO DE OMISIÓN';
                
                $errorSheet->fromArray($headers, NULL, 'A1');
                
                $lastColIndex = count($headers);
                $lastColLetter = Coordinate::stringFromColumnIndex($lastColIndex);
                $errorSheet->getStyle('A1:' . $lastColLetter . '1')->getFont()->setBold(true);
                $errorSheet->getStyle('A1:' . $lastColLetter . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF0000');
                $errorSheet->getStyle('A1:' . $lastColLetter . '1')->getFont()->getColor()->setARGB('FFFFFFFF');
                
                $errorRow = 2; 
                
                for ($row = 2; $row <= $highestRow; $row++) {
                    
                    // 1. LECTURA BÁSICA
                    $raw_cat = trim((string)$sheet->getCell('A' . $row)->getValue());
                    $categoria = in_array(strtoupper($raw_cat), $valores_nulos_comunes) ? '' : mb_strtoupper($raw_cat, 'UTF-8');
                    
                    $raw_tipo = trim((string)$sheet->getCell('B' . $row)->getValue());
                    $nombre_tipo = in_array(strtoupper($raw_tipo), $valores_nulos_comunes) ? '' : mb_strtoupper($raw_tipo, 'UTF-8');
                    
                    $vida_util = floatval($sheet->getCell('C' . $row)->getCalculatedValue() ?? 0);
                    
                    $raw_inv = trim((string)$sheet->getCell('D' . $row)->getValue());
                    $codigo_inventario = in_array(strtoupper($raw_inv), $valores_nulos_comunes) ? null : mb_strtoupper($raw_inv, 'UTF-8');

                    $raw_serie = trim((string)$sheet->getCell('E' . $row)->getValue());
                    $serie = in_array(strtoupper($raw_serie), $valores_nulos_comunes) ? null : mb_strtoupper($raw_serie, 'UTF-8');

                    $raw_marca = trim((string)$sheet->getCell('F' . $row)->getValue());
                    $marca = in_array(strtoupper($raw_marca), $valores_nulos_comunes) ? '' : mb_strtoupper($raw_marca, 'UTF-8');

                    $raw_modelo = trim((string)$sheet->getCell('G' . $row)->getValue());
                    $modelo = in_array(strtoupper($raw_modelo), $valores_nulos_comunes) ? '' : mb_strtoupper($raw_modelo, 'UTF-8');

                    $cedula_responsable = trim((string)$sheet->getCell('H' . $row)->getValue());
                    
                    $raw_estado = trim((string)$sheet->getCell('I' . $row)->getValue());
                    $estado = in_array(strtoupper($raw_estado), $valores_nulos_comunes) ? '' : mb_strtoupper($raw_estado, 'UTF-8');

                    // ========================================================
                    // LIMPIEZA VISUAL DE MONEDA (VACUNA ANTI-RECORTES)
                    // ========================================================
                    // getFormattedValue toma foto a lo que se ve en Excel, no a su formato interno
                    $raw_val = (string)$sheet->getCell('J' . $row)->getFormattedValue();
                    
                    // Quitamos símbolos de moneda y espacios invisibles
                    $raw_val = trim(str_ireplace(['$', 'COP', ' ', ' '], '', $raw_val));
                    
                    // Si termina en decimales (,00 o .00), los eliminamos
                    if (preg_match('/[.,]\d{1,2}$/', $raw_val)) {
                        $raw_val = preg_replace('/[.,]\d{1,2}$/', '', $raw_val);
                    }
                    
                    // Quitamos todos los puntos y comas de separadores de miles
                    $raw_val = preg_replace('/[.,]/', '', $raw_val);
                    
                    // Aseguramos que solo queden números puros
                    $raw_val = preg_replace('/[^0-9]/', '', $raw_val);
                    $valor_compra = empty($raw_val) ? 0 : floatval($raw_val);

                    // ========================================================
                    // FECHAS (Corrección Zona Horaria Colombia -5)
                    // ========================================================
                    $cellDate = $sheet->getCell('K' . $row);
                    $fecha_compra = null;
                    $valDateRaw = $cellDate->getValue();
                    
                    if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cellDate) || (is_numeric($valDateRaw) && !empty($valDateRaw))) {
                        try {
                            $dateTime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($valDateRaw);
                            $fecha_compra = $dateTime->format('Y-m-d');
                        } catch (Exception $e) {
                            $fecha_compra = null;
                        }
                    } else {
                        $val_date_str = trim((string)$valDateRaw);
                        if (!empty($val_date_str)) {
                            $timestamp = strtotime(str_replace('/', '-', $val_date_str));
                            if ($timestamp !== false) {
                                $fecha_compra = date('Y-m-d', $timestamp);
                            }
                        }
                    }
                    if ($fecha_compra === '1970-01-01') {
                        $fecha_compra = null;
                    }

                    $raw_detalles = trim((string)$sheet->getCell('L' . $row)->getValue());
                    $detalles = in_array(strtoupper($raw_detalles), $valores_nulos_comunes) ? '' : mb_strtoupper($raw_detalles, 'UTF-8');

                    $raw_tenencia = trim((string)$sheet->getCell('M' . $row)->getValue());
                    $tenencia = empty($raw_tenencia) ? 'PROPIO' : mb_strtoupper($raw_tenencia, 'UTF-8');

                    $codigo_cuenta = trim((string)$sheet->getCell('N' . $row)->getValue());
                    $nombre_cuenta = mb_strtoupper(trim((string)$sheet->getCell('O' . $row)->getValue()), 'UTF-8');
                    $codigo_depreciacion = trim((string)$sheet->getCell('P' . $row)->getValue());
                    $nombre_depreciacion = mb_strtoupper(trim((string)$sheet->getCell('Q' . $row)->getValue()), 'UTF-8');

                    if (empty($categoria) && empty($nombre_tipo) && empty($cedula_responsable)) { continue; }

                    // ========================================================
                    // FUNCIÓN AUXILIAR: REGISTRAR ERROR Y COPIAR FILA COMPLETA
                    // ========================================================
                    $registrarError = function($motivoError) use (&$errores, &$errorSheet, &$errorRow, $sheet, $row, $highestColumn) {
                        $errores[] = "Fila {$row}: " . $motivoError;
                        $rowData = $sheet->rangeToArray('A' . $row . ':' . $highestColumn . $row, NULL, TRUE, FALSE)[0];
                        $rowData[] = $motivoError;
                        $errorSheet->fromArray($rowData, NULL, 'A'.$errorRow);
                        $errorRow++;
                    };

                    // ========================================================
                    // VALIDACIONES DE USUARIO Y DUPLICADOS
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
                        $registrarError("La cédula {$cedula_responsable} no existe en el sistema.");
                        continue; 
                    }

                    // Categoría
                    $id_categoria = null;
                    $res_cat = $conexion->query("SELECT id_categoria FROM categorias_activo WHERE nombre_categoria = '" . $conexion->real_escape_string($categoria) . "' LIMIT 1");
                    
                    if ($res_cat && $res_cat->num_rows > 0) {
                        $id_categoria = $res_cat->fetch_object()->id_categoria;
                        $sql_upd_cat = "UPDATE categorias_activo SET cuenta_contable = ?, nombre_cuenta = ?, cuenta_depreciacion = ?, nombre_cuenta_depreciacion = ? WHERE id_categoria = ?";
                        $stmt_upd_cat = $conexion->prepare($sql_upd_cat);
                        $stmt_upd_cat->bind_param("ssssi", $codigo_cuenta, $nombre_cuenta, $codigo_depreciacion, $nombre_depreciacion, $id_categoria);
                        $stmt_upd_cat->execute();
                        $stmt_upd_cat->close();
                    } else {
                        $sql_ins_cat = "INSERT INTO categorias_activo (nombre_categoria, cuenta_contable, nombre_cuenta, cuenta_depreciacion, nombre_cuenta_depreciacion) VALUES (?, ?, ?, ?, ?)";
                        $stmt_ins_cat = $conexion->prepare($sql_ins_cat);
                        $stmt_ins_cat->bind_param("sssss", $categoria, $codigo_cuenta, $nombre_cuenta, $codigo_depreciacion, $nombre_depreciacion);
                        $stmt_ins_cat->execute();
                        $id_categoria = $stmt_ins_cat->insert_id;
                        $stmt_ins_cat->close();
                    }

                    // Tipo
                    $id_tipo_activo = null;
                    $res_tipo = $conexion->query("SELECT id_tipo_activo FROM tipos_activo WHERE nombre_tipo_activo = '" . $conexion->real_escape_string($nombre_tipo) . "' LIMIT 1");
                    
                    if ($res_tipo && $res_tipo->num_rows > 0) {
                        $id_tipo_activo = $res_tipo->fetch_object()->id_tipo_activo;
                    } else {
                        $sql_ins = "INSERT INTO tipos_activo (nombre_tipo_activo, id_categoria) VALUES (?, ?)";
                        $stmt_ins = $conexion->prepare($sql_ins);
                        $stmt_ins->bind_param("si", $nombre_tipo, $id_categoria);
                        $stmt_ins->execute();
                        $id_tipo_activo = $stmt_ins->insert_id;
                        $stmt_ins->close();
                    }

                    // Duplicados
                    if ($codigo_inventario !== null) {
                        $res_inv = $conexion->query("SELECT id FROM activos_tecnologicos WHERE Codigo_Inv = '" . $conexion->real_escape_string($codigo_inventario) . "' LIMIT 1");
                        if ($res_inv && $res_inv->num_rows > 0) {
                            $registrarError("El Código de Inventario {$codigo_inventario} ya está registrado.");
                            continue;
                        }
                    }

                    if ($serie !== null) {
                        $res_serie = $conexion->query("SELECT id FROM activos_tecnologicos WHERE serie = '" . $conexion->real_escape_string($serie) . "' LIMIT 1");
                        if ($res_serie && $res_serie->num_rows > 0) {
                            $registrarError("El serial {$serie} ya está registrado.");
                            continue;
                        }
                    }

                    // ========================================================
                    // INSERCIÓN DEL ACTIVO FINAL
                    // ========================================================
                    $sql_insert = "INSERT INTO activos_tecnologicos 
                    (id_tipo_activo, marca, modelo, serie, estado, Codigo_Inv, tenencia, id_usuario_responsable, id_centro_costo, fecha_compra, valor_aproximado, vida_util, detalles) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt_activo = $conexion->prepare($sql_insert);
                    $stmt_activo->bind_param("issssssiisdis", 
                        $id_tipo_activo, $marca, $modelo, $serie, $estado, $codigo_inventario, $tenencia, 
                        $id_usuario_responsable, $id_centro_costo, $fecha_compra, $valor_compra, $vida_util, $detalles
                    );
                    
                    if ($stmt_activo->execute()) {
                        $importados++;
                    } else {
                        $registrarError("Error interno en BD al guardar activo: " . $stmt_activo->error);
                    }
                    $stmt_activo->close();
                }

                $stmt_buscar_usuario->close();

                // ========================================================
                // CERRAR Y GENERAR ARCHIVO SI HUBO ERRORES
                // ========================================================
                $btnDescarga = "";
                if ($errorRow > 2) {
                    $fileName = 'activos_omitidos_' . date('Ymd_His') . '.xlsx';
                    $filePath = __DIR__ . '/' . $fileName;
                    $writer = new Xlsx($errorSpreadsheet);
                    $writer->save($filePath);
                    
                    $btnDescarga = "<br><br><a href='{$fileName}' class='btn btn-warning btn-sm border border-dark text-dark fw-bold shadow-sm' download><i class='bi bi-file-earmark-excel'></i> Descargar Excel de Errores/Omitidos</a>";
                }

                if ($importados > 0) {
                    $conexion->commit();
                    $_SESSION['import_success_message'] = "¡Éxito! Se importaron $importados activos correctamente." . $btnDescarga;
                } else {
                    $conexion->rollback();
                    $_SESSION['import_error_message'] = "No se importó ningún activo. Revisa el archivo de errores." . $btnDescarga;
                }
                
                if (!empty($errores)) {
                    $_SESSION['import_errors'] = $errores;
                }

            } catch (Exception $e) {
                $conexion->rollback();
                $_SESSION['import_error_message'] = "Error procesando el archivo: " . $e->getMessage();
            }
        } else {
            $_SESSION['import_error_message'] = "Error en la subida. Código interno: " . $file['error'];
        }
    } else {
        $_SESSION['import_error_message'] = "El servidor bloqueó el archivo por seguridad o tamaño.";
    }
} else {
    $_SESSION['import_error_message'] = "Acceso denegado.";
}

ob_end_clean();
header("Location: importar.php");
exit;
?>