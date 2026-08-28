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

// Traer la información del evento, el activo y el usuario responsable
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

class PDF_Acta_Baja extends FPDF {
    function to_iso($string) { return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8'); }
    
    function Header() {
        // Logo de la Empresa (Izquierda)
        $this->Image('imagenes/logo.png', 10, 10, 45);
        
        // Títulos Centrales
        $this->SetFont('Arial', 'B', 10);
        $this->SetXY(55, 12);
        $this->Cell(95, 5, $this->to_iso('PROCESO EVALUACIÓN Y CONTROL'), 0, 1, 'C');
        $this->SetX(55);
        $this->Cell(95, 5, $this->to_iso('PROCEDIMIENTO DE AUDITORIA INTERNA'), 0, 1, 'C');
        
        // Títulos Derecha (Arpesod)
        $this->SetXY(150, 12);
        $this->Cell(55, 5, $this->to_iso('ARPESOD ASOCIADOS SAS'), 0, 1, 'C');
        $this->SetX(150);
        $this->Cell(55, 5, 'NIT. 900.333.755-6', 0, 1, 'C');
        
        $this->Ln(8);
        
        // Título Principal del Documento
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(220, 220, 220);
        $this->Cell(0, 8, $this->to_iso('SOLICITUD DE INGRESO, TRASLADO Y/O DAR DE BAJA ACTIVOS FIJOS'), 1, 1, 'C', true);
        $this->Ln(5);
    }
    
    function Footer() {}
}

$pdf = new PDF_Acta_Baja('P', 'mm', 'Letter');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);

// --- BLOQUE DE INFORMACIÓN (FECHA, REGIONAL, ÁREA) ---
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(15, 6, 'Fecha:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(45, 6, date('d/m/Y', strtotime($hist['fecha_evento'])), 0, 0);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(18, 6, 'Regional:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(45, 6, $pdf->to_iso($hist['regional'] ?? 'N/A'), 0, 1);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(15, 6, 'Area:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(45, 6, $pdf->to_iso($hist['nombre_cargo'] ?? 'N/A'), 0, 0);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(28, 6, 'Punto de Venta:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(45, 6, $pdf->to_iso($hist['empresa'] ?? 'N/A'), 0, 1);
$pdf->Ln(5);

// --- BLOQUE DE TEXTO LEGAL ---
$texto_legal = "Para formalizar la solicitud, en la presente acta quedaran consignados los equipos y muebles que están bajo su responsabilidad, buen uso y cuidado. Los daños que se generen le serán descontados automáticamente.\nCuando haya terminación del contrato laboral o retiro voluntario, usted debe hacer entrega de los activos fijos aquí estipulados al líder de zona o en su defecto al nuevo encargado del puesto, ya que este será un requisito indispensable para la firma de paz y salvo por parte de la empresa.";
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 5, $pdf->to_iso($texto_legal), 0, 'J');
$pdf->Ln(5);

// --- CABECERA DE LA TABLA DE ACTIVOS ---
$pdf->SetFont('Arial','B',8);
$pdf->SetFillColor(230, 230, 230);

// Guardar X y Y para la subcabecera de Estado
$x_start = $pdf->GetX();
$y_start = $pdf->GetY();

$pdf->Cell(20, 10, $pdf->to_iso('Código'), 1, 0, 'C', true);
$pdf->Cell(35, 10, 'Serie', 1, 0, 'C', true);
$pdf->Cell(35, 10, 'Marca', 1, 0, 'C', true);
$pdf->Cell(40, 10, $pdf->to_iso('Descripción del Activo'), 1, 0, 'C', true);

// Cabecera compartida de ESTADO
$x_estado = $pdf->GetX();
$pdf->Cell(15, 5, 'Estado', 1, 2, 'C', true);
$pdf->SetFont('Arial','B',7);
$pdf->Cell(5, 5, 'B', 1, 0, 'C', true);
$pdf->Cell(5, 5, 'R', 1, 0, 'C', true);
$pdf->Cell(5, 5, 'M', 1, 0, 'C', true);

// Volver al nivel de la cabecera para "Observaciones"
$pdf->SetXY($x_estado + 15, $y_start);
$pdf->SetFont('Arial','B',8);
$pdf->Cell(50, 10, 'Observaciones', 1, 1, 'C', true);

// --- CONTENIDO DE LA TABLA (EL ACTIVO) ---
$pdf->SetFont('Arial','',8);

// Calcular la X para las casillas de estado (Bueno, Regular, Malo)
$estado = strtoupper($hist['estado_fisico']);
$b = ($estado == 'BUENO') ? 'X' : '';
$r = ($estado == 'REGULAR') ? 'X' : '';
$m = ($estado == 'MALO') ? 'X' : '';

// Fila de datos
$pdf->Cell(20, 8, $pdf->to_iso($hist['Codigo_Inv'] ?? 'S/C'), 1, 0, 'C');
$pdf->Cell(35, 8, $pdf->to_iso($hist['serie'] ?? 'S/S'), 1, 0, 'C');
$pdf->Cell(35, 8, $pdf->to_iso($hist['marca'] ?? 'S/M'), 1, 0, 'C');
$pdf->Cell(40, 8, $pdf->to_iso(substr($hist['nombre_tipo_activo'] ?? 'N/A', 0, 25)), 1, 0, 'C');
$pdf->Cell(5, 8, $b, 1, 0, 'C');
$pdf->Cell(5, 8, $r, 1, 0, 'C');
$pdf->Cell(5, 8, $m, 1, 0, 'C');
$pdf->Cell(50, 8, $pdf->to_iso(substr($hist['detalles'] ?? 'Sin detalles', 0, 30)), 1, 1, 'C');

$pdf->Ln(8);

// --- SECCIÓN: OBSERVACIONES DEL EVENTO (Por qué se dio de baja) ---
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(55, 6, 'OBSERVACIONES GENERALES:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 6, $pdf->to_iso($hist['descripcion_evento']), 0, 'L');
$pdf->Ln(6);

// --- SECCIÓN: CHECKBOX TIPO DE ACTA ---
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(70, 6, 'Certifico que el equipo detallado fue por:', 0, 0);

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(15, 6, 'Ingreso', 0, 0); 
$pdf->Cell(5, 5, '', 1, 0, 'C'); // Casilla Vacía
$pdf->Cell(5, 6, '', 0, 0);

$pdf->Cell(15, 6, 'Traslado', 0, 0); 
$pdf->Cell(5, 5, '', 1, 0, 'C'); // Casilla Vacía
$pdf->Cell(5, 6, '', 0, 0);

$pdf->Cell(10, 6, 'Baja', 0, 0); 
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(5, 5, 'X', 1, 1, 'C'); // Casilla Marcada con X
$pdf->Ln(15);

// --- SECCIÓN DE FIRMAS ---
$block_width = 65;

// Títulos de Firmas
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell($block_width, 5, 'Autorizado por', 0, 0, 'L');
$pdf->Cell($block_width, 5, 'Nombre de quien entrega', 0, 0, 'L');
$pdf->Cell($block_width, 5, 'Nombre de quien recibe', 0, 1, 'L');

$pdf->SetFont('Arial', '', 9);
$pdf->Cell($block_width, 5, 'CC: ', 0, 0, 'L');
$pdf->Cell($block_width, 5, 'CC: ' . $pdf->to_iso($hist['responsable_cedula'] ?? ''), 0, 0, 'L');
$pdf->Cell($block_width, 5, 'CC: ', 0, 1, 'L');

$pdf->Ln(8);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell($block_width, 5, 'Firma _____________________', 0, 0, 'L');
$pdf->Cell($block_width, 5, 'Firma _____________________', 0, 0, 'L');
$pdf->Cell($block_width, 5, 'Firma _____________________', 0, 1, 'L');

$pdf->Ln(2);

$pdf->Cell($block_width, 5, 'Fecha _____________________', 0, 0, 'L');
$pdf->Cell($block_width, 5, 'Fecha _____________________', 0, 0, 'L');
$pdf->Cell($block_width, 5, 'Fecha _____________________', 0, 1, 'L');

$pdf->Output('I', 'Acta_Baja_S' . ($hist['serie'] ?? 'N-A') . '.pdf');
exit;
?>