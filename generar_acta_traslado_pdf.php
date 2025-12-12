<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php';
require_once 'backend/db.php';
require_once 'lib/fpdf/fpdf.php';

// --- Bloque de conexión robusto ---
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error crítico de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4");

if (!isset($_GET['id_historial']) || !filter_var($_GET['id_historial'], FILTER_VALIDATE_INT)) { die("Error: ID de historial no válido."); }
$id_historial = (int)$_GET['id_historial'];

// 1. Obtener el evento de historial para saber IDs de activo y cédulas
$sql_evento = "SELECT id_activo, fecha_evento, datos_nuevos, datos_anteriores FROM historial_activos WHERE id_historial = ? AND tipo_evento = 'TRASLADO'";
$stmt_evento = $conexion->prepare($sql_evento);
if(!$stmt_evento) die("Error al preparar la consulta del evento: ".$conexion->error);
$stmt_evento->bind_param("i", $id_historial);
$stmt_evento->execute();
$evento_data = $stmt_evento->get_result()->fetch_assoc();
$stmt_evento->close();

if (!$evento_data) { die("No se encontraron datos para el evento de traslado con ID: " . htmlspecialchars($id_historial)); }

$id_activo = $evento_data['id_activo'];
$datos_nuevos = !empty($evento_data['datos_nuevos']) ? json_decode($evento_data['datos_nuevos'], true) : [];
$datos_anteriores = !empty($evento_data['datos_anteriores']) ? json_decode($evento_data['datos_anteriores'], true) : [];

$cedula_entrega = $datos_anteriores['cedula_responsable_anterior'] ?? null;
$cedula_recibe = $datos_nuevos['destino_cedula'] ?? null;

// 2. Obtener la información completa del activo
$sql_activo = "SELECT a.*, ta.nombre_tipo_activo FROM activos_tecnologicos a LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo WHERE a.id = ?";
$stmt_activo = $conexion->prepare($sql_activo);
$stmt_activo->bind_param("i", $id_activo);
$stmt_activo->execute();
$activo_data = $stmt_activo->get_result()->fetch_assoc();
$stmt_activo->close();

// 3. Obtener los datos completos del usuario que ENTREGA (origen)
$usuario_entrega = null;
if ($cedula_entrega) {
    $stmt_entrega = $conexion->prepare("SELECT u.*, c.nombre_cargo FROM usuarios u LEFT JOIN cargos c ON u.id_cargo = c.id_cargo WHERE u.usuario = ?");
    $stmt_entrega->bind_param("s", $cedula_entrega);
    $stmt_entrega->execute();
    $usuario_entrega = $stmt_entrega->get_result()->fetch_assoc();
    $stmt_entrega->close();
}

// 4. Obtener los datos completos del usuario que RECIBE (destino)
$usuario_recibe = null;
if ($cedula_recibe) {
    $stmt_recibe = $conexion->prepare("SELECT u.*, c.nombre_cargo FROM usuarios u LEFT JOIN cargos c ON u.id_cargo = c.id_cargo WHERE u.usuario = ?");
    $stmt_recibe->bind_param("s", $cedula_recibe);
    $stmt_recibe->execute();
    $usuario_recibe = $stmt_recibe->get_result()->fetch_assoc();
    $stmt_recibe->close();
}

// Asignar variables para el PDF con datos frescos de la base de datos
$nombre_recibe = $usuario_recibe['nombre_completo'] ?? 'N/A';
$cc_recibe = $usuario_recibe['usuario'] ?? 'N/A';
$cargo_recibe = $usuario_recibe['nombre_cargo'] ?? 'N/A';
$empresa = $usuario_recibe['empresa'] ?? 'N/A';
$regional = $usuario_recibe['regional'] ?? 'N/A';

$cc_entrega = $usuario_entrega['usuario'] ?? 'N/A';

$autorizado_por = "MARY LUZ TRUJILLO";
$autorizado_cc = "25286841";

// --- Clase FPDF Personalizada (incluye todas las mejoras) ---
class PDF_Acta extends FPDF {
    protected $widths;
    function to_iso($string) { return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8'); }
    function Header() {
        $this->Image('imagenes/logo.png', 10, 8, 45);
        $this->SetXY(90, 10);
        $this->SetFont('Arial', 'B', 9);
        $this->MultiCell(110, 5, $this->to_iso("PROCESO EVALUACIÓN Y CONTROL\nPROCEDIMIENTO DE AUDITORIA INTERNA\nFINANSUEÑOS SAS\nNIT. 901723445"), 1, 'C');
        $this->Ln(4);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 7, $this->to_iso('SOLICITUD DE INGRESO, TRASLADO Y/O DAR DE BAJA ACTIVOS FIJOS'), 1, 1, 'C');
    }
    function Footer() { $this->SetY(-15); $this->SetFont('Arial','I',8); $this->Cell(0,10, 'Pagina '.$this->PageNo().'/{nb}',0,0,'C'); }
    function Draw_Checkbox($label, $is_checked) {
        $this->SetFont('Arial','',9);
        $this->Cell(5, 5, '', 1, 0); 
        if ($is_checked) {
            $this->SetFont('Arial','B',9);
            $x = $this->GetX(); $y = $this->GetY();
            $this->Text($x - 4, $y + 3.5, 'X');
            $this->SetFont('Arial','',9);
        }
        $this->Cell(25, 5, $this->to_iso($label), 0, 0);
    }
    function Draw_Signature_Block($label, $name, $cc = '') {
        $this->SetFont('Arial','',9);
        $this->Cell(90, 7, $this->to_iso($label . ': ' . $name), 'B', 0, 'L');
        $this->Cell(5, 7, '', 0, 0);
        $this->Cell(45, 7, 'Firma:', 'B', 0, 'L');
        $this->Cell(5, 7, '', 0, 0);
        $this->Cell(50, 7, 'Fecha:', 'B', 1, 'L');
        if($cc) { $this->Cell(90, 7, 'CC: ' . $cc, 0, 1, 'L'); }
    }
    function SetWidths($w) { $this->widths = $w; }
    function Row($data, $line_height = 5) {
        $nb = 0;
        for($i=0; $i<count($data); $i++) $nb = max($nb, $this->NbLines($this->widths[$i], $data[$i]));
        $h = $line_height * $nb;
        $this->CheckPageBreak($h);
        for($i=0; $i<count($data); $i++) {
            $w = $this->widths[$i]; $a = 'C';
            $x = $this->GetX(); $y = $this->GetY();
            $this->Rect($x, $y, $w, $h);
            $this->SetXY($x, $y + 1);
            $this->MultiCell($w, $line_height, $this->to_iso($data[$i]), 0, $a);
            $this->SetXY($x + $w, $y);
        }
        $this->Ln($h);
    }
    function CheckPageBreak($h) { if($this->GetY()+$h>$this->PageBreakTrigger) $this->AddPage($this->CurOrientation); }
    function NbLines($w, $txt) { $cw = &$this->CurrentFont['cw']; if($w==0) $w = $this->w-$this->rMargin-$this->x; $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize; $s = str_replace("\r",'',$txt); $nb = strlen($s); if($nb>0 and $s[$nb-1]=="\n") $nb--; $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1; while($i<$nb) { $c = $s[$i]; if($c=="\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; } if($c==' ') $sep = $i; $l += $cw[$c]; if($l>$wmax) { if($sep==-1) { if($i==$j) $i++; } else $i = $sep+1; $sep = -1; $j = $i; $l = 0; $nl++; } else $i++; } return $nl; }
}

$pdf = new PDF_Acta('P', 'mm', 'Letter');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->SetFont('Arial', '', 9);

$pdf->Ln(5);
$pdf->Cell(97.5, 7, $pdf->to_iso('Fecha: ' . date("d/m/Y", strtotime($evento_data['fecha_evento']))), 1, 0, 'C');
$pdf->Cell(97.5, 7, $pdf->to_iso('Regional: ' . $regional), 1, 1, 'C');
$pdf->Cell(195, 7, $pdf->to_iso('Empresa: ' . $empresa), 1, 1, 'C');
$pdf->Ln(5);

$pdf->SetFont('Arial', '', 8);
$pdf->MultiCell(0, 4, $pdf->to_iso("Para formalizar la solicitud, en la presente acta quedaran consignados los equipos y muebles que están bajo su responsabilidad, buen uso y cuidado. Los daños que se generen, le serán descontados automáticamente."), 0, 'J');
$pdf->Ln(2);
$pdf->MultiCell(0, 4, $pdf->to_iso("Cuando haya terminación del contrato laboral o retiro voluntario, usted debe hacer entrega de los activos fijos aqui estipulados al lider de zona o en su defecto al nuevo encargado del puesto, ya que este será un requisito indispensable para la firma de paz y salvo por parte de la empresa."), 0, 'J');
$pdf->Ln(5);

$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(25, 10, $pdf->to_iso('Código'), 1, 0, 'C');
$pdf->Cell(30, 10, 'Serie', 1, 0, 'C');
$pdf->Cell(35, 10, 'Marca', 1, 0, 'C');
$pdf->Cell(60, 10, $pdf->to_iso('Descripción del Activo'), 1, 0, 'C');
$pdf->Cell(45, 5, 'Estado', 1, 1, 'C');
$pdf->SetXY(160, $pdf->GetY() - 5);
$pdf->Cell(15, 5, 'B', 1, 0, 'C');
$pdf->Cell(15, 5, 'R', 1, 0, 'C');
$pdf->Cell(15, 5, 'M', 1, 1, 'C');

$pdf->SetFont('Arial', '', 8);
$pdf->SetWidths([25, 30, 35, 60, 15, 15, 15]);
$pdf->SetFont('Arial', '', 8);
$pdf->SetWidths([25, 30, 35, 60, 15, 15, 15]);

// CÓDIGO CORREGIDO
$pdf->Row([
    $activo_data['Codigo_Inv'] ?? 'N/A',
    $activo_data['serie'] ?? 'N/A',
    $activo_data['marca'] ?? 'N/A',
    $activo_data['nombre_tipo_activo'] ?? 'N/A',
    (isset($activo_data['estado']) && $activo_data['estado'] == 'Bueno' ? 'X' : ''),
    (isset($activo_data['estado']) && $activo_data['estado'] == 'Regular' ? 'X' : ''),
    (isset($activo_data['estado']) && $activo_data['estado'] == 'Malo' ? 'X' : '')
]);

// También protegemos y corregimos el campo de detalles
$observaciones = $activo_data['detalles'] ?? 'Ninguna.';
$pdf->MultiCell(195, 20, $pdf->to_iso('OBSERVACIONES GENERALES: ' . $observaciones), 1, 'L');
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(50, 5, $pdf->to_iso('Certifico que el equipo detallado fue por:'), 0, 1, 'L');
$pdf->Draw_Checkbox('Ingreso', false);
$pdf->Draw_Checkbox('Traslado', true);
$pdf->Draw_Checkbox('Baja', false);
$pdf->Ln(15);

$pdf->Draw_Signature_Block('Autorizado por', $autorizado_por, $autorizado_cc);
$pdf->Ln(10);
// --- CORRECCIÓN: Dejar en blanco el nombre y mostrar solo CC de quien entrega ---
$pdf->Draw_Signature_Block('Nombre de quien entrega', '', $cc_entrega);
$pdf->Ln(10);
$pdf->Draw_Signature_Block('Nombre de quien recibe', $nombre_recibe, $cc_recibe);

$pdf->Output('I', 'Acta_Traslado_' . ($activo_data['serie'] ?? $id_historial) . '.pdf');
exit;
?>