<?php
require_once __DIR__ . '/app.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use setasign\Fpdi\Fpdi;

class CarRentalReportPdf extends Fpdi
{
    private string $letterheadPath;
    private $letterheadTemplate = null;

    public function __construct(string $orientation = 'P')
    {
        parent::__construct($orientation, 'mm', 'A4');
        $this->letterheadPath = __DIR__ . '/letterhead/letterhead.pdf';
        $this->SetMargins(10, 70, 10);
        $this->SetAutoPageBreak(true, 22);
    }

    public function Header(): void
    {
        if (is_file($this->letterheadPath)) {
            try {
                if ($this->letterheadTemplate === null) {
                    $this->setSourceFile($this->letterheadPath);
                    $this->letterheadTemplate = $this->importPage(1);
                }
                $this->useTemplate($this->letterheadTemplate, 0, 0, $this->GetPageWidth(), $this->GetPageHeight());
            } catch (Throwable $e) {
                // If the template cannot load, continue with a clean PDF instead of breaking export.
            }
        }

        // Keep report data below the letterhead area on every page.
        $this->SetY(68);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
    }

    public function reportTitle(string $title, array $filters = []): void
    {
        $this->SetFont('Arial', 'B', 15);
        $this->SetTextColor(20, 30, 45);
        $this->Cell(0, 8, $title, 0, 1, 'C');

        $filterText = [];
        foreach ($filters as $label => $value) {
            if ($value !== '' && $value !== null) {
                $filterText[] = $label . ': ' . $value;
            }
        }

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(90, 90, 90);
        $this->Cell(0, 6, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
        if ($filterText) {
            $this->Cell(0, 6, implode('   |   ', $filterText), 0, 1, 'C');
        }
        $this->Ln(4);
    }

    public function safeText($value, int $max = 40): string
    {
        $text = trim((string)$value);
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;
    }
}
?>
