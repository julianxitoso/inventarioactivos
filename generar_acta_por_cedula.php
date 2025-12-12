<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php'; 
require_once 'backend/db.php'; 
require_once 'lib/fpdf/fpdf.php'; 

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error crítico de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4");

if (!isset($_GET['cedula']) || empty($_GET['cedula'])) {
    die("Error: No se proporcionó una cédula válida.");
}
$cedula_responsable = $_GET['cedula'];

$sql = "SELECT 
            a.*, 
            ta.nombre_tipo_activo,
            u.nombre_completo as nombre_receptor,
            u.empresa as empresa_receptor,
            c.nombre_cargo as nombre_cargo
            
        FROM activos_tecnologicos a
        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
        WHERE u.usuario = ? AND (a.estado IS NULL OR a.estado != 'Dado de Baja')
        ORDER BY ta.nombre_tipo_activo, a.marca";

$stmt = $conexion->prepare($sql);
if(!$stmt) die("Error al preparar la consulta: ".$conexion->error);
$stmt->bind_param("s", $cedula_responsable);
$stmt->execute();
$result = $stmt->get_result();
$activos = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($activos)) {
    die("No se encontraron activos asignados para la cédula: " . htmlspecialchars($cedula_responsable));
}

$datos_receptor = $activos[0];
$nombre_recibe = $datos_receptor['nombre_receptor'] ?? 'N/A';
$cedula_recibe = $_GET['cedula'];
$cargo = $datos_receptor['nombre_cargo'] ?? 'Sin Cargo Asignado';
$empresa = $datos_receptor['empresa_receptor']?? 'Sin Empresa Asignada';

class PDF_Acta_Compromiso extends FPDF {
    protected $widths;
    public $empresa = '';
    function to_iso($string) { return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8'); }
    
    // --- INICIO DE LA SECCIÓN MODIFICADA: NUEVA CABECERA ---
    function Header() {
        // --- Posición y dimensiones ---
        $x_start = 10;
        $y_start = 10;
        $logo_width = 45;
        $main_col_width = 100;
        $side_col_width = 50;
        $row_height = 6;
        $header_height = $row_height * 3;
        
        // --- PASO 3: Usar la propiedad de la clase para decidir qué texto mostrar ---
        $arpesod_text = "ARPESOD ASOCIADOS S.A.S.\nNIT: 900333755-6";
        $finansuenos_text = "FINANSUEÑOS\nNIT: 901723445";
        $display_text = '';

        if (!empty($this->empresa)) {
            if (stripos($this->empresa, 'arpesod') !== false) {
                $display_text = $arpesod_text;
            } elseif (stripos($this->empresa, 'finansueños') !== false) {
                $display_text = $finansuenos_text;
            } else {
                $display_text = $this->empresa; // Si no es ninguna, muestra el nombre tal cual
            }
        }
        

        // --- Celda del Logo (ocupa 3 filas de alto) ---
        $this->Image('imagenes/logo.png', $x_start + 1, $y_start + 1, $logo_width - 2, $header_height - 2);
        $this->Rect($x_start, $y_start, $logo_width, $header_height);

        // --- Columna Principal y Lateral ---
        // Fila 1
        $this->SetXY($x_start + $logo_width, $y_start);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($main_col_width, $row_height, $this->to_iso('ACTA DE ENTREGA DE ACTIVOS FIJOS Y DEVOLUTIVOS'), 1, 0, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell($side_col_width, $row_height, $this->to_iso('Código: GTI-FT-001'), 1, 1, 'C');

        // Fila 2 y 3 (Título principal ocupa 2 filas de alto)
        $this->SetXY($x_start + $logo_width, $y_start + $row_height);
        $this->SetFont('Arial', 'B', 10);
        // Usamos MultiCell para centrar verticalmente el texto largo
        $title_x = $this->GetX();
        $title_y = $this->GetY();
        
            $this->MultiCell($main_col_width, $row_height, $this->to_iso($display_text), 1, 'C');
       
        
        
        $this->SetXY($title_x, $title_y); // Restaurar XY para dibujar la celda que lo contiene
        $this->Cell($main_col_width, $row_height * 2, '', 0, 0); // Celda invisible para el posicionamiento
        
        // Celdas laterales para Fila 2 y 3
        $this->SetFont('Arial', '', 8);
        $this->SetXY($x_start + $logo_width + $main_col_width, $y_start + $row_height);
        $this->Cell($side_col_width, $row_height, $this->to_iso('Versión: 01'), 1, 1, 'C');
        $this->SetXY($x_start + $logo_width + $main_col_width, $y_start + ($row_height * 2));
        $this->Cell($side_col_width, $row_height, $this->to_iso('Fecha de Vigencia:                             '), 1, 1, 'C');
        
        // Espacio después de la cabecera
        $this->Ln(8);
    }
    
    function Footer() {}
    function DrawSectionHeader($title) {
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(210, 210, 210);
        $this->SetTextColor(0);
        $this->Cell(0, 7, $this->to_iso($title), 1, 1, 'C', true);
    }
    function DrawDataRow($label, $value, $is_blank = false) {
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(50, 7, $this->to_iso($label), 'L', 0, 'L');
        $this->SetFont('Arial', '', 9);
        if ($is_blank) {
            $this->Cell(0, 7, '_________________________________________', 'R', 1, 'L');
        } else {
            $this->Cell(0, 7, $this->to_iso($value), 'R', 1, 'L');
        }
    }
    function SetWidths($w) { $this->widths = $w; }
    function Row($data) {
        $line_height = 8; // Altura de fila fija para las casillas
        $this->CheckPageBreak($line_height);
        for($i=0;$i<count($data);$i++){
            $w=$this->widths[$i]; $a='C'; $x=$this->GetX(); $y=$this->GetY();
            $this->Rect($x, $y, $w, $line_height);
            $this->SetXY($x+2, $y + 1);
            $this->MultiCell($w-4, 5, $this->to_iso($data[$i]), 0, $a);
            $this->SetXY($x+$w,$y);
        }
        $this->Ln($line_height);
    }
    function CheckPageBreak($h){ if($this->GetY()+$h>$this->PageBreakTrigger) $this->AddPage($this->CurOrientation); }
}

$pdf = new PDF_Acta_Compromiso('P', 'mm', 'Letter');
$pdf->empresa = $empresa; 
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);

$pdf->DrawSectionHeader('DATOS DEL EQUIPO O ACTIVO ENTREGADO');

// --- INICIO DE LA SECCIÓN MODIFICADA: CABECERA DE TABLA ---
$pdf->SetFont('Arial','B',8);
$pdf->SetFillColor(50, 50, 50);
$pdf->SetTextColor(255);

// Guardar posición inicial Y para la cabecera
$y1 = $pdf->GetY();
$x1 = $pdf->GetX();

// Cabeceras de altura completa
$pdf->Cell(15, 12, 'ITEM', 1, 0, 'C', true);
$pdf->Cell(50, 12, 'TIPO DE ACTIVO', 1, 0, 'C', true);
$pdf->Cell(30, 12, $pdf->to_iso('CÓDIGO INTERNO'), 1, 0, 'C', true);
$pdf->Cell(35, 12, 'MARCA', 1, 0, 'C', true);
$pdf->Cell(35, 12, 'SERIE', 1, 0, 'C', true);

// Guardar posición para la cabecera de ESTADO
$x2 = $pdf->GetX();

// Cabecera principal de ESTADO
$pdf->Cell(30, 6, 'ESTADO', 'LTR', 1, 'C', true);

// Sub-cabeceras de ESTADO
$pdf->SetXY($x2, $y1 + 6);
$pdf->SetFont('Arial','B',7);
$pdf->Cell(10, 6, 'B', 1, 0, 'C', true);
$pdf->Cell(10, 6, 'R', 1, 0, 'C', true);
$pdf->Cell(10, 6, 'M', 1, 1, 'C', true);

// Restaurar fuente y color para el contenido
$pdf->SetFont('Arial','',9);
$pdf->SetTextColor(0);
// --- FIN DE LA SECCIÓN MODIFICADA ---


// --- INICIO DE LA SECCIÓN MODIFICADA: CONTENIDO DE TABLA ---
$pdf->SetWidths([15, 50, 30, 35, 35, 10, 10, 10]);
$item_count = 1;

foreach($activos as $activo) {
    $pdf->Row([
        $item_count++,
        $activo['nombre_tipo_activo'] ?? 'N/A',
        $activo['Codigo_Inv'] ?? 'S/C',
        $activo['marca'] ?? 'S/M',
        $activo['serie'] ?? 'S/S',
        '', // Casilla vacía para Bueno (B)
        '', // Casilla vacía para Regular (R)
        ''  // Casilla vacía para Malo (M)
    ]);
}
// --- FIN DE LA SECCIÓN MODIFICADA ---

$pdf->Ln(5);

$pdf->DrawSectionHeader('COMPROMISO');
$pdf->SetFont('Arial', '', 9);
$commitment_text = "Con la firma de la presente acta, hago constar que he recibido a entera satisfacción los activos relacionados anteriormente, los cuales se encuentran en buen estado y funcionamiento. Me comprometo a darles el uso adecuado para el cual fueron diseñados y asignados, a velar por su integridad, cuidado y mantenimiento. Así mismo, me comprometo a reportar de manera inmediata cualquier daño, pérdida o falla que presenten. Entiendo que estos activos son propiedad de la empresa y debo restituirlos en buen estado, salvo el deterioro normal por su uso, al momento de mi traslado, retiro o cuando la empresa así lo requiera. El incumplimiento de este compromiso podrá acarrear las sanciones disciplinarias y/o legales a que haya lugar.";
$pdf->MultiCell(0, 5, $pdf->to_iso($commitment_text), 1, 'J');
$pdf->Ln(5);


// --- INICIO DE LA SECCIÓN DE FIRMAS CON RECUADRO ---

$y_pos_start_box = $pdf->GetY();
if ($y_pos_start_box > 180) { $pdf->AddPage(); $y_pos_start_box = $pdf->GetY(); }

$pdf->DrawSectionHeader('FIRMAS');

$y_content_start = $pdf->GetY();
$block_width = 65;
$signature_area_height = 32; // Altura total del área de contenido de firmas

// Dibujar las divisiones y el recuadro exterior del contenido
$pdf->Cell($block_width, $signature_area_height, '', 'L', 0);
$pdf->Cell($block_width, $signature_area_height, '', 'L', 0);
$pdf->Cell($block_width, $signature_area_height, '', 'LR', 1);
$pdf->Cell(195, 0, '', 'T', 1); // Línea inferior del recuadro

// Posicionar el cursor para escribir el contenido de las firmas
$pdf->SetY($y_content_start);

// --- Títulos de los bloques ---
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell($block_width, 7, $pdf->to_iso('COLABORADOR'), 0, 0, 'C');
$pdf->Cell($block_width, 7, $pdf->to_iso('LIDER DE AREA'), 0, 0, 'C');
$pdf->Cell($block_width, 7, $pdf->to_iso('AUDITOR'), 0, 1, 'C');
$pdf->Ln(1);

// --- Datos de los bloques ---
$pdf->SetFont('Arial', '', 9);
$x1 = 12; // Margen izquierdo + padding
$x2 = $x1 + $block_width;
$x3 = $x2 + $block_width;

// Fila de Nombres
$pdf->SetX($x1);
$pdf->Cell($block_width - 4, 6, 'Nombre: ' . $pdf->to_iso(substr($nombre_recibe, 0, 25)), 0, 0, 'L');
$pdf->SetX($x2);
$pdf->Cell($block_width - 4, 6, 'Nombre: ', 0, 0, 'L');
$pdf->SetX($x3);
$pdf->Cell($block_width - 4, 6, 'Nombre: ', 0, 1, 'L');

// Fila de Cargos
$pdf->SetX($x1);
$pdf->Cell($block_width - 4, 6, 'Cargo: ' . $pdf->to_iso(substr($cargo, 0, 28)), 0, 0, 'L');
$pdf->SetX($x2);
$pdf->Cell($block_width - 4, 6, 'Cargo: ', 0, 0, 'L');
$pdf->SetX($x3);
$pdf->Cell($block_width - 4, 6, 'Cargo: ', 0, 1, 'L');

// Fila de Cédulas
$pdf->SetX($x1);
$pdf->Cell($block_width - 4, 6, 'C.C.: ' . $pdf->to_iso($cedula_recibe), 0, 0, 'L');
$pdf->SetX($x2);
$pdf->Cell($block_width - 4, 6, 'C.C.: ', 0, 0, 'L');
$pdf->SetX($x3);
$pdf->Cell($block_width - 4, 6, 'C.C.: ', 0, 1, 'L');

// Fila de Firmas
$pdf->SetX($x1);
$pdf->Cell($block_width - 4, 6, 'Firma: ', 0, 0, 'L');
$pdf->SetX($x2);
$pdf->Cell($block_width - 4, 6, 'Firma: ', 0, 0, 'L');
$pdf->SetX($x3);
$pdf->Cell($block_width - 4, 6, 'Firma: ', 0, 1, 'L');

// --- FIN DE LA SECCIÓN DE FIRMAS ---


$pdf->Output('I', 'Acta_Activos_' . $cedula_responsable . '.pdf');
exit;
?>
