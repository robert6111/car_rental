<?php
require_once __DIR__ . '/app.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use setasign\Fpdi\Fpdi;

/**
 * PDF reports. The page header is the company's own letterhead PDF when one is uploaded
 * under Settings; otherwise a header is drawn from the business details (logo, name, contacts).
 */
class CarRentalReportPdf extends Fpdi
{
    private ?string $letterheadPath = null;
    private $letterheadTemplate = null;
    private float $contentTop;

    public function __construct(string $orientation = 'P')
    {
        parent::__construct($orientation, 'mm', 'A4');
        $letterhead = cr_setting('letterhead_pdf');
        if ($letterhead !== '' && is_file(cr_storage_path($letterhead))) {
            $this->letterheadPath = cr_storage_path($letterhead);
        }
        $this->contentTop = $this->letterheadPath ? 68 : 42;
        $this->SetMargins(10, $this->contentTop + 2, 10);
        $this->SetAutoPageBreak(true, 22);
        $this->SetTitle($this->safeText(cr_company_name(), 100));
        $this->SetCreator($this->safeText(cr_company_name(), 100));
    }

    public function Header(): void
    {
        if ($this->letterheadPath) {
            try {
                if ($this->letterheadTemplate === null) {
                    $this->setSourceFile($this->letterheadPath);
                    $this->letterheadTemplate = $this->importPage(1);
                }
                $this->useTemplate($this->letterheadTemplate, 0, 0, $this->GetPageWidth(), $this->GetPageHeight());
                $this->SetY($this->contentTop);
                return;
            } catch (Throwable $e) {
                error_log('Letterhead PDF could not be used: ' . $e->getMessage());
                $this->letterheadPath = null;
            }
        }
        $this->drawCompanyHeader();
        $this->SetY($this->contentTop);
    }

    private function drawCompanyHeader(): void
    {
        $x = 10;
        $logo = cr_setting('company_logo');
        if ($logo !== '' && is_file(cr_storage_path($logo))) {
            try {
                $this->Image(cr_storage_path($logo), 10, 8, 0, 20);
                $x = 42;
            } catch (Throwable $e) {
                error_log('Company logo could not be added to PDF: ' . $e->getMessage());
            }
        }
        $this->SetXY($x, 9);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(20, 30, 45);
        $this->Cell(0, 8, $this->safeText(cr_company_name(), 70), 0, 2);

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(90, 90, 90);
        $address = trim(cr_setting('company_address'));
        if ($address !== '') $this->Cell(0, 5, $this->safeText($address, 110), 0, 2);
        $contacts = array_filter([
            cr_setting('company_phone') !== '' ? 'Tel: ' . cr_setting('company_phone') : '',
            cr_setting('company_email'),
            cr_setting('company_website'),
            cr_setting('company_tax_id') !== '' ? 'Tax ID: ' . cr_setting('company_tax_id') : '',
        ]);
        if ($contacts) $this->Cell(0, 5, $this->safeText(implode('   |   ', $contacts), 130), 0, 2);

        $this->SetDrawColor(37, 99, 235);
        $this->SetLineWidth(0.6);
        $this->Line(10, 34, $this->GetPageWidth() - 10, 34);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(0, 0, 0);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 10, $this->safeText(cr_company_name(), 60) . '  -  Page ' . $this->PageNo(), 0, 0, 'C');
    }

    public function reportTitle(string $title, array $filters = []): void
    {
        $this->SetFont('Arial', 'B', 15);
        $this->SetTextColor(20, 30, 45);
        $this->Cell(0, 8, $this->safeText($title, 80), 0, 1, 'C');

        $filterText = [];
        foreach ($filters as $label => $value) {
            if ($value !== '' && $value !== null) {
                $filterText[] = $label . ': ' . $value;
            }
        }

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(90, 90, 90);
        $this->Cell(0, 6, 'Generated: ' . cr_date(date('Y-m-d H:i:s'), true), 0, 1, 'C');
        if ($filterText) {
            $this->Cell(0, 6, $this->safeText(implode('   |   ', $filterText), 150), 0, 1, 'C');
        }
        $this->Ln(4);
    }

    /** FPDF core fonts use the Windows-1252 character set: convert text and shorten it to fit a cell. */
    public function safeText($value, int $max = 40): string
    {
        $text = trim((string)$value);
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        // Currency symbols the PDF fonts cannot draw are replaced with readable text.
        $text = preg_replace('/\s{2,}/', ' ', strtr($text, ['₺' => ' TL ', '₹' => ' Rs ', '₦' => ' NGN ', '₱' => ' PHP ', '₩' => ' KRW ', '₫' => ' VND ', '₴' => ' UAH ', '₽' => ' RUB ', '₪' => ' ILS ', '₵' => ' C ']));
        $text = trim($text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;
    }
}
