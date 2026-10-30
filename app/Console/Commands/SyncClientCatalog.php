<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('madina:sync-client-catalog {--deactivate-others : Deactivate catalog SKUs not in the client list} {--dry-run : Show planned changes without writing}')]
#[Description('Upsert Madina client catalog products, packshot images, and company contact defaults from resources/client-catalog.')]
class SyncClientCatalog extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deactivateOthers = (bool) $this->option('deactivate-others');
        $catalog = $this->catalog();
        $skus = collect($catalog)->pluck('sku')->all();

        foreach ($catalog as $row) {
            $imageSource = base_path('resources/client-catalog/products/'.$row['image']);
            if (! is_file($imageSource)) {
                $this->error('Missing packshot: '.$row['image']);

                return self::FAILURE;
            }

            /** @var Product $product */
            $product = Product::query()->firstOrNew(['sku' => $row['sku']]);
            $wasNew = ! $product->exists;
            $product->fill([
                'name' => $row['name'],
                'category' => $row['category'],
                'description' => $row['description'],
                'unit' => $row['unit'],
                'mrp' => $row['mrp'],
                'dealer_price' => $row['dealer_price'],
                'sub_dealer_price' => $row['sub_dealer_price'],
                'active' => true,
                'featured' => $row['featured'],
                'tone' => $row['tone'],
                'low_stock_at' => $row['low_stock_at'],
            ]);
            if ($wasNew || (int) $product->stock <= 0) {
                $product->stock = $row['stock'];
            }

            if ($dryRun) {
                $this->line(($wasNew ? 'CREATE' : 'UPDATE').' '.$row['sku'].' · '.$row['name']);

                continue;
            }

            DB::transaction(function () use ($product, $imageSource, $wasNew): void {
                $oldImage = $product->image_path;
                $extension = strtolower(pathinfo($imageSource, PATHINFO_EXTENSION) ?: 'jpg');
                $stored = 'product-media/images/'.Str::uuid().'.'.$extension;
                Storage::disk('local')->put($stored, File::get($imageSource));
                $product->image_path = $stored;
                $product->registry_version = (int) $product->registry_version + ($wasNew ? 1 : 1);
                if ($product->registry_version < 1) {
                    $product->registry_version = 1;
                }
                $product->save();
                if ($oldImage && $oldImage !== $stored && Storage::disk('local')->exists($oldImage)) {
                    Storage::disk('local')->delete($oldImage);
                }
            });

            $this->info(($wasNew ? 'Created' : 'Updated').' '.$row['sku']);
        }

        if ($deactivateOthers && ! $dryRun) {
            $count = Product::query()->whereNotIn('sku', $skus)->where('active', true)->update(['active' => false]);
            $this->warn("Deactivated {$count} non-client product(s).");
        }

        if (! $dryRun) {
            SiteCustomization::save([
                'office_address' => 'Thana Road, Pirganj 5470, Rangpur, Bangladesh',
                'footer_address' => 'Thana Road, Pirganj 5470, Rangpur',
                'footer_phone' => '+8801730532966',
                'footer_support_title' => '+8801730532966',
                'footer_support_link' => 'tel:+8801730532966',
                'contact_phone' => '+8801730532966',
                'contact_email' => 'holisticnutracare@gmail.com',
                'footer_email' => 'holisticnutracare@gmail.com',
                'footer_about_title' => 'Madina Natural Health Care',
                'footer_about_desc' => 'Licensed distributor of natural herbal and nutrition products. Trade License 2026-00563, Pirganj Municipality, Rangpur. Marketed nutrition line: Holistic Nutracare.',
                'contact_company_desc' => 'Madina Natural Health Care (Owner: Md Anowar Hossain). Head office: Thana Road, Pirganj 5470, Rangpur. Factory/warehouse: Chaira, Moshorikhula, Hemayetpur, Savar, Dhaka. Hotline: 01730532966 · 01723731877 · 01727612379.',
                'about_hero_lead' => 'Madina Natural Health Care is a licensed single-owner distributor (Trade License 2026-00563) supplying chemical-free herbal and nutrition products through an authorized dealer network across Bangladesh.',
                'map_facility' => 'Madina Natural Health Care — Thana Road, Pirganj',
                'footer_logo_url' => '/madina-header-logo-white.png?v=9',
                'social_whatsapp' => 'https://wa.me/8801730532966',
                'whatsapp_link' => 'https://wa.me/8801730532966',
            ]);
            SiteCustomization::forgetCache();
            $this->info('Company contact defaults updated from trade license / catalog.');
        }

        $this->info('Client catalog sync '.($dryRun ? 'dry-run complete.' : 'complete.'));

        return self::SUCCESS;
    }

    /**
     * @return list<array{
     *   sku: string,
     *   name: string,
     *   category: string,
     *   description: string,
     *   unit: string,
     *   mrp: string,
     *   dealer_price: string,
     *   sub_dealer_price: string,
     *   stock: int,
     *   low_stock_at: int,
     *   featured: bool,
     *   tone: string,
     *   image: string
     * }>
     */
    private function catalog(): array
    {
        return [
            [
                'sku' => 'MNHC-NAT-500',
                'name' => 'Naturalife Fruit Syrup 500ml',
                'category' => 'Fruit Syrup',
                'description' => 'Naturalife (ন্যাচারালাইফ) fruit syrup for immunity, hair and skin support, healthy weight gain and gentle detoxification. Marketed by Madina Natural Health Care / Holistic Nutracare.',
                'unit' => 'bottle',
                'mrp' => '400.00',
                'dealer_price' => '200.00',
                'sub_dealer_price' => '260.00',
                'stock' => 100,
                'low_stock_at' => 10,
                'featured' => true,
                'tone' => 'sage',
                'image' => 'naturalife.jpg',
            ],
            [
                'sku' => 'MNHC-NRG-500',
                'name' => 'Modhu N.R.G Fruit Syrup 500ml',
                'category' => 'Fruit Syrup',
                'description' => 'Modhu N.R.G (মধু এন.আর.জি) fruit syrup formulated for strength, vitality, restful sleep and nursing-mother nutritional support.',
                'unit' => 'bottle',
                'mrp' => '400.00',
                'dealer_price' => '200.00',
                'sub_dealer_price' => '260.00',
                'stock' => 100,
                'low_stock_at' => 10,
                'featured' => true,
                'tone' => 'rose',
                'image' => 'modhu-nrg.jpg',
            ],
            [
                'sku' => 'MNHC-HKID-250',
                'name' => 'Herbs-Kid Superfood 250g',
                'category' => 'Superfood',
                'description' => 'Herbs-Kid / Herba-Cure khurma premix (250g) supporting physical, mental and nutritional strength for growing children and family wellness.',
                'unit' => 'jar',
                'mrp' => '750.00',
                'dealer_price' => '375.00',
                'sub_dealer_price' => '488.00',
                'stock' => 80,
                'low_stock_at' => 8,
                'featured' => true,
                'tone' => 'sand',
                'image' => 'herbs-kid-250.jpg',
            ],
            [
                'sku' => 'MNHC-HKID-400',
                'name' => 'Herba-Cure Khurma Premix 400g',
                'category' => 'Superfood',
                'description' => 'Herba-Cure khurma premix 400g jar — nutrient-dense herbal superfood for daily vitality and recovery.',
                'unit' => 'jar',
                'mrp' => '1200.00',
                'dealer_price' => '600.00',
                'sub_dealer_price' => '780.00',
                'stock' => 60,
                'low_stock_at' => 6,
                'featured' => false,
                'tone' => 'sand',
                'image' => 'herba-cure-400.jpg',
            ],
            [
                'sku' => 'MNHC-SIL-500',
                'name' => 'Silaf Superfood 500g',
                'category' => 'Superfood',
                'description' => 'Silaf (সিলাফ) nutrition superfood 500g for immunity and physical/mental strength.',
                'unit' => 'jar',
                'mrp' => '1200.00',
                'dealer_price' => '600.00',
                'sub_dealer_price' => '780.00',
                'stock' => 60,
                'low_stock_at' => 6,
                'featured' => true,
                'tone' => 'sky',
                'image' => 'silaf-500.jpg',
            ],
            [
                'sku' => 'MNHC-SAJ-250',
                'name' => 'Sajeeban Superfood 250g',
                'category' => 'Superfood',
                'description' => 'Sajeeban (সজীবন) walnut-based herbal halwa 250g for weakness recovery, immunity, hair/skin vitality and healthy weight gain.',
                'unit' => 'jar',
                'mrp' => '750.00',
                'dealer_price' => '375.00',
                'sub_dealer_price' => '488.00',
                'stock' => 80,
                'low_stock_at' => 8,
                'featured' => true,
                'tone' => 'sage',
                'image' => 'sajeeban-250.jpg',
            ],
            [
                'sku' => 'MNHC-HPW-100',
                'name' => 'Herb-Powder Ashwagandha Halwa 100g',
                'category' => 'Superfood',
                'description' => 'Herb-Powder / Herba-Power ashwagandha herbal halwa 100g for physical, mental and nutritional support.',
                'unit' => 'pack',
                'mrp' => '350.00',
                'dealer_price' => '175.00',
                'sub_dealer_price' => '228.00',
                'stock' => 120,
                'low_stock_at' => 12,
                'featured' => false,
                'tone' => 'sand',
                'image' => 'herb-powder-100.jpg',
            ],
            [
                'sku' => 'MNHC-TSK-60',
                'name' => 'Taskin Pain Oil 60ml',
                'category' => 'Oil',
                'description' => 'Taskin / Takleef (তাসকিন) topical oil 60ml for relief from various types of body pain.',
                'unit' => 'bottle',
                'mrp' => '450.00',
                'dealer_price' => '270.00',
                'sub_dealer_price' => '338.00',
                'stock' => 100,
                'low_stock_at' => 10,
                'featured' => true,
                'tone' => 'rose',
                'image' => 'taskin-60.jpg',
            ],
            [
                'sku' => 'MNHC-AJM-30',
                'name' => 'Ajmer Lobon 30 Tablets',
                'category' => 'Ayurvedic',
                'description' => 'Ajmer Lobon / Bhaskar Lobon (আজমীর লবণ) 30 tablets for indigestion, mouth odor, stomach ache and bloating.',
                'unit' => 'box',
                'mrp' => '350.00',
                'dealer_price' => '175.00',
                'sub_dealer_price' => '228.00',
                'stock' => 150,
                'low_stock_at' => 15,
                'featured' => false,
                'tone' => 'sky',
                'image' => 'ajmer-lobon.jpg',
            ],
            [
                'sku' => 'MNHC-SKS-30',
                'name' => 'Shas-Kash Seemoni 30 Tablets',
                'category' => 'Ayurvedic',
                'description' => 'Shas-Kash Seemoni / শ্বাস-কাশ সীমনি 30 tablets for asthma, bronchitis, eczema and chronic cold/cough support.',
                'unit' => 'box',
                'mrp' => '350.00',
                'dealer_price' => '175.00',
                'sub_dealer_price' => '228.00',
                'stock' => 150,
                'low_stock_at' => 15,
                'featured' => false,
                'tone' => 'sage',
                'image' => 'shas-kash-seemoni.jpg',
            ],
        ];
    }
}
