<?php
// Habilitar visualización de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php'; // Ajusta la ruta si es necesario
// Define qué roles pueden generar actas de devolución
restringir_acceso_pagina(['admin', 'tecnico', 'registrador', 'auditor']); 

require_once 'backend/db.php'; // Ajusta la ruta
require_once 'lib/fpdf/fpdf.php'; // << IMPORTANTE: Ruta a tu archivo fpdf.php

// Función para convertir a ISO-8859-1 si es necesario para FPDF con fuentes estándar
function to_iso_devolucion($string) {
    return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8');
}

$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $db_conn_error_detail = method_exists($conexion, 'connect_error') ? $conexion->connect_error : 'Desconocido';
    error_log("Error de conexión BD en generar_acta_devolucion_pdf.php: " . $db_conn_error_detail);
    die("Error crítico de conexión a la base de datos. No se puede generar el acta. Detalle: " . htmlspecialchars($db_conn_error_detail));
}
$conexion->set_charset("utf8mb4");

if (!isset($_GET['id_prestamo']) || !filter_var($_GET['id_prestamo'], FILTER_VALIDATE_INT) || $_GET['id_prestamo'] <= 0) {
    die("ID de préstamo no válido o no proporcionado.");
}
$id_prestamo_acta = (int)$_GET['id_prestamo'];

// Consulta para obtener todos los datos necesarios para el acta de devolución
$sql_acta_devolucion = "SELECT 
            p.id_prestamo, 
            p.fecha_prestamo AS fecha_prestamo_original, 
            p.fecha_devolucion_esperada AS fecha_devolucion_esperada_original, 
            p.estado_activo_prestamo AS estado_activo_al_prestar_original, 
            p.observaciones_prestamo AS observaciones_prestamo_original,
            p.fecha_devolucion_real,
            p.estado_activo_devolucion,
            p.observaciones_devolucion,
            p.estado_prestamo,
            a.id AS activo_id,
            a.serie AS activo_serie, 
            a.marca AS activo_marca, 
            a.Codigo_Inv AS activo_codigo_inv,
            a.detalles AS activo_detalles_generales, 
            ta.nombre_tipo_activo,
            up.nombre_completo AS nombre_usuario_presta_original, /* Usuario que originalmente prestó y ahora recibe de vuelta */
            up.usuario AS cedula_usuario_presta_original,
            cargos_up.nombre_cargo AS cargo_usuario_presta_original, 
            up.empresa AS empresa_usuario_presta_original, 
            ur.nombre_completo AS nombre_usuario_devuelve, /* Usuario que tenía el activo y ahora lo devuelve */
            ur.usuario AS cedula_usuario_devuelve,
            cargos_ur.nombre_cargo AS cargo_usuario_devuelve,
            ur.empresa AS empresa_usuario_devuelve
        FROM prestamos_activos p
        JOIN activos_tecnologicos a ON p.id_activo = a.id
        JOIN usuarios up ON p.id_usuario_presta = up.id
        JOIN usuarios ur ON p.id_usuario_recibe = ur.id
        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
        LEFT JOIN cargos cargos_up ON up.id_cargo = cargos_up.id_cargo 
        LEFT JOIN cargos cargos_ur ON ur.id_cargo = cargos_ur.id_cargo 
        WHERE p.id_prestamo = ? 
        AND p.estado_prestamo = 'Devuelto'"; // Asegurarse que el préstamo esté efectivamente devuelto

$stmt = $conexion->prepare($sql_acta_devolucion);
if (!$stmt) {
    error_log("Error al preparar consulta para acta de devolución: " . $conexion->error);
    die("Error al preparar datos para el acta de devolución (SQL). Revise los logs del servidor.");
}
$stmt->bind_param("i", $id_prestamo_acta);
if(!$stmt->execute()){
    error_log("Error al ejecutar consulta para acta de devolución: " . $stmt->error);
    die("Error al ejecutar la obtención de datos para el acta de devolución (SQL). Revise los logs del servidor.");
}
$result = $stmt->get_result();
$datos_devolucion = $result->fetch_assoc();
$stmt->close();

if (!$datos_devolucion) {
    if (isset($conexion) && $conexion) { $conexion->close(); } // Cerrar conexión si no se encontraron datos
    die("No se encontraron datos para el préstamo devuelto ID: " . htmlspecialchars($id_prestamo_acta) . ". Verifique que el préstamo exista y esté marcado como 'Devuelto'.");
}
$conexion->close(); // Cerrar la conexión después de obtener los datos

// Array para meses en español
$meses_espanol = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

class PDF_Acta_Devolucion extends FPDF {
    private $empresaNombre = 'ARPESOD ASOCIADOS SAS'; 
    private $logoPath = 'imagenes/logo.png'; // Ruta relativa desde la raíz del proyecto

    function Header() {
        if (file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 15, 10, 35); 
        } else {
            error_log("FPDF Header (Devolución): No se encontró el logo en " . realpath($this->logoPath));
        }
        $this->Ln(15); 
        $this->SetFont('Arial', 'B', 14); 
        $this->Cell(0, 10, to_iso_devolucion('ACTA DE DEVOLUCIÓN DE ELEMENTO TECNOLÓGICO'), 0, 1, 'C');
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 6, to_iso_devolucion($this->empresaNombre), 0, 1, 'C');
        $this->Ln(6); 
    }

    function Footer() {
        $this->SetY(-15); 
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 5, to_iso_devolucion('Generado por Sistema de Inventario TI - ARPESOD ASOCIADOS SAS'), 0, 1, 'C');
        $this->Cell(0, 5, to_iso_devolucion('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }

    function InfoCell($label, $value, $labelWidth = 55, $valueWidth = 0, $lineHeight = 5, $border = 0) {
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($labelWidth, $lineHeight, to_iso_devolucion($label . ":"), $border, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $current_x = $this->GetX();
        $current_y = $this->GetY();
        if ($valueWidth == 0) { 
            $this->MultiCell(0, $lineHeight, to_iso_devolucion(trim($value ?: 'N/A')), $border, 'L');
        } else {
            $this->MultiCell($valueWidth, $lineHeight, to_iso_devolucion(trim($value ?: 'N/A')), $border, 'L');
        }
        if ($this->GetY() <= $current_y + $lineHeight) { // Evitar doble salto si MultiCell no saltó
             $this->Ln(0.5); 
        }
    }

    function SectionTitle($title, $lineHeight = 6) {
        $this->Ln(3); 
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(220, 220, 220); 
        $this->SetTextColor(0,0,0);
        $this->Cell(0, $lineHeight, to_iso_devolucion($title), 1, 1, 'L', true); 
        $this->Ln(1.5);
        $this->SetTextColor(0,0,0); 
    }
     function Paragraph($text, $lineHeight = 4.5) { 
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(0, $lineHeight, to_iso_devolucion($text), 0, 'J'); 
        $this->Ln(1.5); 
    }
}

// Creación del objeto PDF
$pdf = new PDF_Acta_Devolucion('P', 'mm', 'Letter');
$pdf->AliasNbPages(); 
$pdf->SetMargins(20, 15, 20); 
$pdf->SetAutoPageBreak(true, 20); 
$pdf->AddPage();

// Formatear fecha de devolución
$fecha_devolucion_obj = strtotime($datos_devolucion['fecha_devolucion_real']);
$dia_devolucion = date("d", $fecha_devolucion_obj);
$mes_devolucion_num = (int)date("m", $fecha_devolucion_obj);
$mes_devolucion_texto = $meses_espanol[$mes_devolucion_num] ?? date("F", $fecha_devolucion_obj);
$anio_devolucion = date("Y", $fecha_devolucion_obj);
$fecha_devolucion_formateada = "Popayán, " . $dia_devolucion . " de " . $mes_devolucion_texto . " de " . $anio_devolucion;

// --- CONTENIDO DEL ACTA ---
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, to_iso_devolucion($fecha_devolucion_formateada), 0, 1, 'R');
$pdf->Cell(0, 6, to_iso_devolucion("Acta de Devolución (Préstamo ID: " . $datos_devolucion['id_prestamo'] . ")"), 0, 1, 'R');
$pdf->Ln(3);

$pdf->SectionTitle('1. INFORMACIÓN DEL ELEMENTO TECNOLÓGICO DEVUELTO');
$pdf->InfoCell('Tipo de Activo', $datos_devolucion['nombre_tipo_activo']);
$pdf->InfoCell('Marca', $datos_devolucion['activo_marca']);
$pdf->InfoCell('Serie / Serial', $datos_devolucion['activo_serie']);
$pdf->InfoCell('Cód. Inventario', $datos_devolucion['activo_codigo_inv']);
$pdf->InfoCell('Detalles Generales Activo', $datos_devolucion['activo_detalles_generales']);

$pdf->SectionTitle('2. DETALLES DE LA DEVOLUCIÓN');
$pdf->InfoCell('Fecha Préstamo Original', date("d/m/Y", strtotime($datos_devolucion['fecha_prestamo_original'])));
$pdf->InfoCell('Fecha Devolución Esperada', date("d/m/Y", strtotime($datos_devolucion['fecha_devolucion_esperada_original'])));
$pdf->InfoCell('Fecha Devolución Real', date("d/m/Y", strtotime($datos_devolucion['fecha_devolucion_real'])));
$pdf->InfoCell('Estado del Activo al Devolver', $datos_devolucion['estado_activo_devolucion']);
$pdf->InfoCell('Observaciones Devolución', $datos_devolucion['observaciones_devolucion']);
$pdf->InfoCell('Estado del Activo al Prestar (Original)', $datos_devolucion['estado_activo_al_prestar_original']);


$pdf->SectionTitle('3. DEVUELTO POR (Usuario que tenía el activo)');
$pdf->InfoCell('Nombre Completo', $datos_devolucion['nombre_usuario_devuelve']);
$pdf->InfoCell('Cédula', $datos_devolucion['cedula_usuario_devuelve']);
$pdf->InfoCell('Cargo', $datos_devolucion['cargo_usuario_devuelve']);
$pdf->InfoCell('Empresa', $datos_devolucion['empresa_usuario_devuelve']);

$pdf->SectionTitle('4. RECIBIDO POR (Funcionario que recibe de vuelta)');
$pdf->InfoCell('Nombre Completo', $datos_devolucion['nombre_usuario_presta_original']);
$pdf->InfoCell('Cédula', $datos_devolucion['cedula_usuario_presta_original']);
$pdf->InfoCell('Cargo', $datos_devolucion['cargo_usuario_presta_original']);
$pdf->InfoCell('Empresa', $datos_devolucion['empresa_usuario_presta_original']);

$pdf->Ln(10); 

// Espacios para Firmas
$signature_y_start = $pdf->GetY();
if ($signature_y_start > $pdf->GetPageHeight() - 50) { 
    $pdf->AddPage();
    $signature_y_start = $pdf->GetY() + 5; 
}

$pdf->SetFont('Arial','',10);
$pdf->Cell(85, 6, to_iso_devolucion('_________________________'), 0, 0, 'C');
$pdf->Cell(10, 6, '', 0, 0, 'C'); 
$pdf->Cell(85, 6, to_iso_devolucion('_________________________'), 0, 1, 'C');

$pdf->Cell(85, 6, to_iso_devolucion('Firma Quien Devuelve'), 0, 0, 'C');
$pdf->Cell(10, 6, '', 0, 0, 'C');
$pdf->Cell(85, 6, to_iso_devolucion('Firma Quien Recibe'), 0, 1, 'C');

$pdf->Cell(85, 6, to_iso_devolucion('C.C: ' . $datos_devolucion['cedula_usuario_devuelve']), 0, 0, 'C');
$pdf->Cell(10, 6, '', 0, 0, 'C');
$pdf->Cell(85, 6, to_iso_devolucion('C.C: ' . $datos_devolucion['cedula_usuario_presta_original']), 0, 1, 'C');

// Salida del PDF
$nombre_archivo_saneado_dev = preg_replace('/[^A-Za-z0-9\-]/', '_', $datos_devolucion['activo_serie'] ?: ('DevolucionID' . $datos_devolucion['id_prestamo']));
$nombre_pdf_dev = "Acta_Devolucion_" . $nombre_archivo_saneado_dev . ".pdf";

if (ob_get_length()) ob_end_clean(); 

$pdf->Output('I', $nombre_pdf_dev); 
exit;
?>
