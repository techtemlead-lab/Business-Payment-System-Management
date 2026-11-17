<?php

namespace App\Domain\Clinic;

use App\Models\Prescription;
use App\Support\LocalDigits;
use App\Support\PdfFonts;
use Illuminate\Support\Facades\Storage;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * The prescription as an A4 PDF on the prescriber's letterhead. The header and footer repeat on
 * every page, so a long medicine list simply carries on to the next sheet.
 */
final class PrescriptionPdf
{
    public function __construct(private PrescriptionLetterhead $letterheads) {}

    public function render(Prescription $prescription, string $lang): string
    {
        $prescriber = $prescription->prescriber;
        abort_if($prescriber === null, 404);
        $letterhead = $this->letterheads->forPrescriber($prescriber);
        $logo = $this->letterheads->logoFile($prescriber);

        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $css = view('prescriptions.paper-css')->render();
        $header = '<div class="rxp">'.view('prescriptions.paper-header', ['letterhead' => $letterhead, 'logo' => $logo])->render().'</div>';
        $footer = view('prescriptions.paper-footer', ['letterhead' => $letterhead])->render();
        $body = view('prescriptions.pdf', ['prescription' => $prescription, 'lang' => $lang])->render();

        $pdf = new Mpdf([
            'tempDir' => $dir,
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 42,
            'margin_bottom' => 34,
            'margin_header' => 8,
            'margin_footer' => 6,
            ...PdfFonts::options(),
        ]);
        // A long medicine list continues on the next page at full size instead of being shrunk to fit one.
        $pdf->shrink_tables_to_fit = 0;
        $pdf->SetTitle((string) __('prescription.print_title', [], $lang).' '.$prescription->number);
        $pdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
        $pdf->SetHTMLHeader($header);
        $pdf->SetHTMLFooter($footer.'<div style="text-align:right;font-size:7pt;color:#64748b">'
            .e((string) __('prescription_letterhead.page_of', ['page' => '{PAGENO}', 'pages' => '{nbpg}'], $lang)).'</div>');
        if ($lang === 'bn') {
            $pdf->AddPageByArray(['pagenumstyle' => 'bengali']);
        }
        $pdf->WriteHTML($body, HTMLParserMode::HTML_BODY);

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    /** The file name offered on download: plain letters only. */
    public static function fileName(Prescription $prescription): string
    {
        return 'prescription-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', LocalDigits::latin((string) $prescription->number)).'.pdf';
    }
}
