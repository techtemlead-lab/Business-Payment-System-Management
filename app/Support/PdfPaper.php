<?php

namespace App\Support;

/**
 * The paper every PDF the app makes is printed on. A4 is the default so every document opens ready
 * to print on a normal office printer; a document that is wide on purpose says so by asking for
 * landscape, and a few small formats (a card, a half page poster) are kept as they are and listed
 * in Special.
 */
final class PdfPaper
{
    public const Portrait = 'A4';

    public const Landscape = 'A4-L';

    /**
     * The documents that are deliberately not portrait A4, and why. PdfPaperTest checks every PDF
     * maker in the app against this list, so a new one cannot slip in with another paper size.
     *
     * @var array<string, string>
     */
    public const Special = [
        'app/Domain/Accounts/PartnerIdCard.php' => 'card size',
        'app/Domain/Accounts/PartnerJoinPoster.php' => 'A5 poster',
        'app/Domain/Accounts/PartnerCertificates.php' => 'A4 portrait for the income certificate, A4 landscape for rank and training certificates',
        'app/Domain/Inventory/ProductListPdf.php' => 'A4 landscape: ten columns with a photo',
        'app/Domain/Inventory/StockPrintPdf.php' => 'A4 landscape: many stock columns',
        'app/Http/Controllers/FinanceOperationsController.php' => 'A4 landscape: wide report tables',
    ];
}
