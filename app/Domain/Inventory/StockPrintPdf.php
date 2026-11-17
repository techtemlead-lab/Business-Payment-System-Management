<?php

namespace App\Domain\Inventory;

use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\PdfFonts;
use App\Support\SiteCustomization;
use App\Support\SiteFacts;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The current stock list as an invoice-style PDF for staff who may see the Stock page,
 * in the language they are reading the portal in.
 */
final class StockPrintPdf
{
    public function __construct(private StockPrintList $list) {}

    public function download(User $actor, BranchScope $scope): BinaryFileResponse
    {
        $stock = $this->list->build($scope);
        $now = now('Asia/Dhaka');
        $html = view('invoices.stock-list', [
            'company' => $this->company(),
            'branch' => $scope->allBranches ? __('stock_print.all_branches') : (string) ($scope->branch->name ?? ''),
            'printed_at' => $now,
            'rows' => $stock['rows'],
        ])->render();

        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'Madina-stock-list-'.$now->format('Y-m-d').'.pdf';
        $path = $dir.'/stock-list-'.$now->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';

        try {
            $pdf = new Mpdf([
                'tempDir' => $dir,
                'format' => 'A4',
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 10,
                'margin_bottom' => 16,
                'margin_footer' => 6,
                ...PdfFonts::options(),
            ]);
            $pdf->SetTitle(__('stock_print.title'));
            $pdf->SetHTMLFooter(
                '<table width="100%" style="font-size:8pt;color:#64748b"><tr><td>'.e(__('stock_print.footer', ['date' => LocalDigits::text($now->format('d/m/Y'))])).'</td>'
                .'<td align="right">'.e(__('stock_print.page')).' {PAGENO} / {nbpg}</td></tr></table>',
            );
            if (app()->getLocale() === 'bn') {
                $pdf->AddPageByArray(['pagenumstyle' => 'bengali']);
            }
            $pdf->WriteHTML($html);
            $pdf->Output($path, Destination::FILE);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        Audit::record($actor->id, 'report.exported', 'stock-print', [
            'branch' => $scope->allBranches ? 'all' : ($scope->branch->code ?? ''),
            'rows' => count($stock['rows']),
        ]);

        return response()->download($path, $filename, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    /** A phone number the way the website shows it (+8801…); empty when none is set. */
    private function phoneForLetterhead(?string $number): string
    {
        $number = trim((string) $number);

        return $number === '' ? '' : SiteCustomization::internationalDisplay($number);
    }

    /**
     * The letterhead as the owner set it in the website editor.
     *
     * @return array{name: string, address: string, whatsapp: string, mobile: string, email: string, licence: string, trademark: string, logo_path: ?string}
     */
    public function company(): array
    {
        $custom = SiteCustomization::all();
        $defaults = SiteCustomization::defaults();
        $letterhead = static fn (string $key): string => filled($custom[$key] ?? null) ? (string) $custom[$key] : (string) ($defaults[$key] ?? '');
        $logoPath = SiteCustomization::logoFilePath();
        if ($logoPath === null || ! is_file($logoPath)) {
            $logoPath = public_path('madina-header-logo.png');
        }
        if (! is_file($logoPath)) {
            $logoPath = public_path('branding/madina-logo-latest.png');
        }
        $phones = $this->letterheadPhones($letterhead('contact_phone'), SiteCustomization::secondPhone(), $letterhead('social_whatsapp'));

        return [
            'name' => $letterhead('contact_company_title'),
            'address' => $letterhead('office_address'),
            'whatsapp' => $phones['whatsapp'],
            'mobile' => $phones['mobile'],
            'email' => trim($letterhead('contact_email')),
            'licence' => SiteFacts::tradeLicence(),
            'trademark' => SiteFacts::trademarkNumber(),
            'logo_path' => is_file($logoPath) ? $logoPath : null,
        ];
    }

    /**
     * The WhatsApp line is the website number that matches the owner's WhatsApp link, or the first
     * number when none matches. The other number is the mobile line.
     *
     * @return array{whatsapp: string, mobile: string}
     */
    private function letterheadPhones(string $primary, ?string $second, string $whatsappLink): array
    {
        $tail = static fn (string $number): string => substr((string) preg_replace('/\D/', '', $number), -10);
        $numbers = array_values(array_filter([trim($primary), trim((string) $second)], static fn (string $number): bool => $number !== ''));
        $linkTail = $tail((string) strtok($whatsappLink, '?'));

        $whatsapp = $numbers[0] ?? '';
        foreach ($numbers as $number) {
            if ($linkTail !== '' && $tail($number) === $linkTail) {
                $whatsapp = $number;
                break;
            }
        }

        $mobile = '';
        foreach ($numbers as $number) {
            if ($tail($number) !== $tail($whatsapp)) {
                $mobile = $number;
                break;
            }
        }

        return [
            'whatsapp' => $this->phoneForLetterhead($whatsapp),
            'mobile' => $this->phoneForLetterhead($mobile),
        ];
    }
}
