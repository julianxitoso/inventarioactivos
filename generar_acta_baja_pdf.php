<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/backend/auth_check.php'; 
require_once __DIR__ . '/backend/db.php'; 
require_once __DIR__ . '/lib/fpdf/fpdf.php';

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error crítico de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4");

if (!isset($_GET['id_historial']) || empty($_GET['id_historial'])) {
    die("Error: No se proporcionó el ID del historial.");
}
$id_historial = (int)$_GET['id_historial'];

$sql = "SELECT 
            h.fecha_evento, h.descripcion_evento, h.usuario_responsable as auditor,
            a.Codigo_Inv, a.serie, a.marca, a.estado as estado_fisico, a.detalles,
            ta.nombre_tipo_activo,
            u.nombre_completo as responsable_nombre, u.usuario as responsable_cedula, 
            u.regional, u.empresa,
            c.nombre_cargo
        FROM historial_activos h
        INNER JOIN activos_tecnologicos a ON h.id_activo = a.id
        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
        WHERE h.id_historial = ?";

$stmt = $conexion->prepare($sql);
if(!$stmt) die("Error al preparar la consulta: ".$conexion->error);
$stmt->bind_param("i", $id_historial);
$stmt->execute();
$result = $stmt->get_result();
$hist = $result->fetch_assoc();
$stmt->close();

if (!$hist) {
    die("No se encontró el registro de baja para el ID proporcionado.");
}

// Función auxiliar para dibujar los bloques de firma verticales
function renderFirmaVertical($pdf, $titulo, $nombre, $cc) {
    $pdf->Ln(4);
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(170, 6, $pdf->to_iso($titulo), 0, 1, 'L');

    $pdf->Ln(10); // Espacio para que la persona firme a mano

    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(15, 5, 'Firma:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(85, 5, '________________________________________________', 0, 0, 'L');

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(15, 5, 'Fecha:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(55, 5, '_________________________', 0, 1, 'L');

    $pdf->Ln(2);

    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(15, 5, 'Nombre:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(85, 5, $pdf->to_iso($nombre), 0, 0, 'L');

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(15, 5, 'CC:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(55, 5, $pdf->to_iso($cc), 0, 1, 'L');

    $pdf->Ln(4);
}

class PDF_Acta_Baja extends FPDF {
    function to_iso($string) { return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8'); }
    function Header() {}
    function Footer() {}
}

$pdf = new PDF_Acta_Baja('P', 'mm', 'Letter');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();

$w_total = 195; // Ancho total útil de la página
$x_start = 10;
$y_start = 10;

// ==========================================
// BLOQUE 1: CABECERA (ENMARCADA)
// ==========================================
$pdf->SetLineWidth(0.5);
$pdf->Rect($x_start, $y_start, $w_total, 30);
$pdf->SetLineWidth(0.2); 

$pdf->Image('imagenes/logo.png', $x_start + 2, $y_start + 4, 38);
$pdf->Line($x_start + 42, $y_start, $x_start + 42, $y_start + 30); // Divisor Logo
$pdf->Line($x_start + 160, $y_start, $x_start + 160, $y_start + 30); // Divisor Derecho

$pdf->SetFont('Arial', 'B', 8); 
$pdf->SetXY($x_start + 42, $y_start + 2);
$pdf->Cell(118, 4, $pdf->to_iso('PROCESO EVALUACIÓN Y CONTROL'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 4, $pdf->to_iso('PROCEDIMIENTO DE AUDITORIA INTERNA'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 4, $pdf->to_iso('ARPESOD ASOCIADOS SAS'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 4, 'NIT. 900.333.755-6', 0, 1, 'C');

$pdf->Ln(1); 
$pdf->SetFont('Arial', 'B', 9); 
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 5, $pdf->to_iso('SOLICITUD DE INGRESO, TRASLADO Y/O'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 5, $pdf->to_iso('DAR DE BAJA ACTIVOS FIJOS'), 0, 1, 'C');

$pdf->SetY($y_start + 30);

// ==========================================
// BLOQUE 2: DATOS DEL DOCUMENTO
// ==========================================
$pdf->SetFont('Arial', 'B', 8);

$y_current = $pdf->GetY();
$pdf->Cell($w_total, 7, '', 1, 0); 
$pdf->SetXY($x_start, $y_current);
$pdf->Cell(85, 7, 'Fecha: ' . date('d/m/Y', strtotime($hist['fecha_evento'])), 0, 0, 'L');
$pdf->Line($x_start + 85, $y_current, $x_start + 85, $y_current + 7); 
$pdf->SetX($x_start + 86);
$pdf->Cell(109, 7, 'Regional: ' . $pdf->to_iso($hist['regional'] ?? 'N/A'), 0, 1, 'L');

$y_current = $pdf->GetY();
$pdf->Cell($w_total, 7, '', 1, 0); 
$pdf->SetXY($x_start, $y_current);
$pdf->Cell(85, 7, $pdf->to_iso('Área: ' . ($hist['nombre_cargo'] ?? 'N/A')), 0, 0, 'L');
$pdf->Line($x_start + 85, $y_current, $x_start + 85, $y_current + 7); 
$pdf->SetX($x_start + 86);
$pdf->Cell(109, 7, 'Punto de Venta: ' . $pdf->to_iso($hist['empresa'] ?? 'N/A'), 0, 1, 'L');

// ==========================================
// BLOQUE 3: TEXTO LEGAL
// ==========================================
$texto_legal = "Para formalizar la solicitud, en la presente acta quedaran consignados los equipos y muebles que están bajo su responsabilidad, buen uso y cuidado. Los daños que se generen le serán descontados automáticamente.\n\nCuando haya terminación del contrato laboral o retiro voluntario, usted debe hacer entrega de los activos fijos aquí estipulados al líder de zona o en su defecto al nuevo encargado del puesto, ya que este será un requisito indispensable para la firma de paz y salvo por parte de la empresa.";

$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell($w_total, 5, $pdf->to_iso($texto_legal), 1, 'J');

// ==========================================
// BLOQUE 4: TABLA DEL ACTIVO
// ==========================================
$pdf->SetFont('Arial','B',7);
$y_table = $pdf->GetY();

$w_cod = 25;
$w_ser = 35;
$w_mar = 30;
$w_des = 50;
$w_est_title = 15;
$w_est_col = 5;
$w_obs = $w_total - ($w_cod + $w_ser + $w_mar + $w_des + $w_est_title); 

$pdf->Cell($w_cod, 10, $pdf->to_iso('Código'), 1, 0, 'C');
$pdf->Cell($w_ser, 10, 'Serie', 1, 0, 'C');
$pdf->Cell($w_mar, 10, 'Marca', 1, 0, 'C');
$pdf->Cell($w_des, 10, $pdf->to_iso('Descripción del Activo'), 1, 0, 'C');

$x_estado = $pdf->GetX();
$pdf->Cell($w_est_title, 5, 'Estado', 1, 2, 'C');
$pdf->SetFont('Arial','B',6);
$pdf->Cell($w_est_col, 5, 'B', 1, 0, 'C');
$pdf->Cell($w_est_col, 5, 'R', 1, 0, 'C');
$pdf->Cell($w_est_col, 5, 'M', 1, 0, 'C');

$pdf->SetXY($x_estado + $w_est_title, $y_table);
$pdf->SetFont('Arial','B',7);
$pdf->Cell($w_obs, 10, 'Observaciones', 1, 1, 'C');

$pdf->SetFont('Arial','',7);
$estado = strtoupper($hist['estado_fisico']);
$b = ($estado == 'BUENO') ? 'X' : '';
$r = ($estado == 'REGULAR') ? 'X' : '';
$m = ($estado == 'MALO') ? 'X' : '';

$pdf->Cell($w_cod, 8, $pdf->to_iso($hist['Codigo_Inv'] ?? ''), 1, 0, 'C');
$pdf->Cell($w_ser, 8, $pdf->to_iso($hist['serie'] ?? ''), 1, 0, 'C');
$pdf->Cell($w_mar, 8, $pdf->to_iso($hist['marca'] ?? ''), 1, 0, 'C');
$pdf->Cell($w_des, 8, $pdf->to_iso(substr($hist['nombre_tipo_activo'] ?? '', 0, 35)), 1, 0, 'C');
$pdf->SetFont('Arial','B',7);
$pdf->Cell($w_est_col, 8, $b, 1, 0, 'C');
$pdf->Cell($w_est_col, 8, $r, 1, 0, 'C');
$pdf->Cell($w_est_col, 8, $m, 1, 0, 'C');
$pdf->SetFont('Arial','',7);
$pdf->Cell($w_obs, 8, $pdf->to_iso(substr($hist['detalles'] ?? '', 0, 30)), 1, 1, 'C');

// ==========================================
// BLOQUE 5: OBSERVACIONES GENERALES
// ==========================================
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell($w_total, 6, 'OBSERVACIONES GENERALES:', 1, 1, 'L', true);

$y_obs_text = $pdf->GetY();
$pdf->Rect($x_start, $y_obs_text, $w_total, 20); 
$pdf->SetFont('Arial', '', 9);
$pdf->SetXY($x_start + 2, $y_obs_text + 2);
$pdf->MultiCell($w_total - 4, 5, $pdf->to_iso($hist['descripcion_evento']), 0, 'L');
$pdf->SetY($y_obs_text + 20); 

// =========================================================
// INICIO DEL MARCO INFERIOR CONTINUO (CHECKBOX + FIRMAS)
// =========================================================
$y_start_footer = $pdf->GetY(); // Marcamos el punto de inicio del cuadro gigante

$pdf->Ln(8);
$pdf->SetX(15);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(70, 6, 'Certifico que el equipo detallado fue por:', 0, 0, 'L');

$pdf->SetFont('Arial', '', 9);

$pdf->Rect($pdf->GetX() + 10, $pdf->GetY() + 1, 4, 4);
$pdf->Cell(30, 6, 'Ingreso', 0, 0, 'R');

$pdf->Rect($pdf->GetX() + 10, $pdf->GetY() + 1, 4, 4);
$pdf->Cell(30, 6, 'Traslado', 0, 0, 'R');

$pdf->SetFont('Arial', 'B', 10);
$pdf->Rect($pdf->GetX() + 10, $pdf->GetY() + 1, 4, 4);
$pdf->Text($pdf->GetX() + 10.8, $pdf->GetY() + 4.5, 'X'); 
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(25, 6, 'Baja', 0, 1, 'R');

$pdf->Ln(8);

// ==========================================
// FIRMAS VERTICALES ESTILIZADAS
// ==========================================

// Línea divisoria tenue
$pdf->SetDrawColor(200, 200, 200);
$pdf->Line($x_start, $pdf->GetY(), $x_start + $w_total, $pdf->GetY());
$pdf->SetDrawColor(0, 0, 0);

renderFirmaVertical($pdf, 'Autorizado por:', '', '');

$pdf->SetDrawColor(200, 200, 200);
$pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY());
$pdf->SetDrawColor(0, 0, 0);

renderFirmaVertical($pdf, 'Nombre de quien entrega (Responsable Actual):', $hist['responsable_nombre'], $hist['responsable_cedula']);

$pdf->SetDrawColor(200, 200, 200);
$pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY());
$pdf->SetDrawColor(0, 0, 0);

renderFirmaVertical($pdf, 'Nombre de quien recibe (Auditor / Almacén):', '', '');

$pdf->Ln(2); // Padding final

// CERRAR EL CUADRO GIGANTE
$y_end_footer = $pdf->GetY();
$pdf->Rect($x_start, $y_start_footer, $w_total, $y_end_footer - $y_start_footer);


$pdf->Output('I', 'Acta_Baja_S' . ($hist['serie'] ?? 'N-A') . '.pdf');
exit;
?>