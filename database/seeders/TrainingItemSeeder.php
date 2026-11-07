<?php

namespace Database\Seeders;

use App\Models\TrainingItem;
use Illuminate\Database\Seeder;

/**
 * Three starter lessons for the partner training library. They are saved as
 * drafts: partners see nothing until an office staff member publishes them.
 * Safe to run again; existing lessons with the same English title are left alone.
 */
class TrainingItemSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->starterItems() as $position => $item) {
            TrainingItem::query()->firstOrCreate(
                ['title_en' => $item['title_en']],
                [...$item, 'type' => 'article', 'sort_order' => $position + 1, 'published' => false, 'published_at' => null],
            );
        }
    }

    /**
     * @return list<array{title_bn: string, title_en: string, summary: string, topic: string, audience: string, body: string}>
     */
    private function starterItems(): array
    {
        return [
            [
                'title_bn' => 'কীভাবে অর্ডার দেবেন ও পেমেন্টের স্ক্রিনশট পাঠাবেন',
                'title_en' => 'How to place an order and send the payment screenshot',
                'summary' => 'চার ধাপে অর্ডার, পেমেন্ট ও প্রমাণ পাঠান। / Order, pay and send proof in four easy steps.',
                'topic' => 'portal',
                'audience' => 'all',
                'body' => implode("\n", [
                    '১. মেনু থেকে "পণ্য কিনুন" খুলুন। যে পণ্য লাগবে তা বেছে নিয়ে সংখ্যা লিখুন।',
                    '২. পাতায় দেখানো কোম্পানির বিকাশ, নগদ বা ব্যাংক অ্যাকাউন্টে পুরো টাকা পাঠান। শুধু ওই অ্যাকাউন্টেই টাকা পাঠান।',
                    '৩. ট্রানজেকশন আইডি (TrxID) লিখুন, পেমেন্টের স্ক্রিনশট যোগ করুন, তারপর অর্ডার পাঠান। পরে টাকা দিলে অর্ডারটি খুলে আবার পেমেন্টের তথ্য দিন।',
                    '৪. অফিস টাকা মিলিয়ে দেখে অর্ডার অনুমোদন করবে এবং পণ্য পাঠাবে। অবস্থা "অর্ডার" পাতায় দেখুন।',
                    'মনে রাখবেন: কাউকে হাতে নগদ টাকা দেবেন না, আর অন্য কোনো নম্বরে টাকা পাঠাবেন না।',
                    '',
                    '1. Open "Buy products" from the menu. Pick the products you need and type how many.',
                    '2. Pay the full amount to the company bKash, Nagad or bank account shown on the page. Use only that account.',
                    '3. Type the transaction ID (TrxID), add a screenshot of the payment, then send the order. If you pay later, open the order and add the payment there.',
                    '4. The office checks the payment, approves the order and sends the goods. Follow it on the "Orders" page.',
                    'Remember: never hand cash to anyone, and never send money to any other number.',
                ]),
            ],
            [
                'title_bn' => 'এমআরপি, আইডি কার্ড এবং আয়ের মিথ্যা প্রতিশ্রুতি নয়',
                'title_en' => 'MRP, your ID card and no false income promises',
                'summary' => 'গ্রাহক ও আপনার অ্যাকাউন্ট নিরাপদ রাখার সহজ নিয়ম। / Simple rules that keep customers and your account safe.',
                'topic' => 'rules',
                'audience' => 'all',
                'body' => implode("\n", [
                    'এমআরপি: প্যাকেটে লেখা এমআরপি-র বেশি দামে কখনো বিক্রি করবেন না।',
                    'আইডি কার্ড: গ্রাহক বা নতুন সদস্যের সাথে কথা বলার সময় প্রোফাইল থেকে আপনার আইডি কার্ড দেখান, যাতে তারা জানেন আপনি কোম্পানির অনুমোদিত পার্টনার।',
                    'আয়ের প্রতিশ্রুতি নয়: কাউকে নির্দিষ্ট আয়, দ্রুত বড়লোক হওয়া বা "নিশ্চিত লাভ"-এর কথা বলবেন না। আয় নির্ভর করে সত্যিকারের পণ্য বিক্রির উপর।',
                    'রোগ সারানোর দাবি নয়: পণ্য কোনো রোগ সারায়—এমন কথা বলবেন না। গ্রাহককে প্রয়োজনে ডাক্তারের পরামর্শ নিতে বলুন।',
                    'এই নিয়ম না মানলে অ্যাকাউন্ট বন্ধ হতে পারে।',
                    '',
                    'MRP: never sell above the MRP printed on the pack.',
                    'ID card: show your ID card from your profile when you talk to customers or new members, so they know you are an approved company partner.',
                    'No income promises: do not promise anyone a fixed income, quick riches or "sure profit". Earnings come only from real product sales.',
                    'No cure claims: do not say a product cures a disease. Ask customers to see a doctor when they need one.',
                    'Breaking these rules can get an account closed.',
                ]),
            ],
            [
                'title_bn' => 'টিম পেজ কীভাবে ব্যবহার করবেন',
                'title_en' => 'Using the team page',
                'summary' => 'আপনার অধীনে কারা যোগ দিয়েছেন ও টিম কেমন চলছে দেখুন। / See who joined under you and how your team is doing.',
                'topic' => 'portal',
                'audience' => 'dealers',
                'body' => implode("\n", [
                    '১. মেনু থেকে "আমার নেটওয়ার্ক" খুলুন। উপরে আপনার টিমের সারসংক্ষেপ দেখা যায়।',
                    '২. নিচে আপনার রেফারে যোগ দেওয়া সদস্যদের দেখবেন। কারও নামে চাপলে তার কেনাকাটার সময়রেখা দেখা যাবে।',
                    '৩. নতুন সদস্য আনতে প্রোফাইল থেকে আপনার জয়েন পোস্টার বা QR কোড শেয়ার করুন।',
                    '৪. কোনো তথ্য ভুল মনে হলে "নোটিশ ও সাপোর্ট" থেকে অফিসকে জানান।',
                    '',
                    '1. Open "My network" from the menu. The top shows a summary of your team.',
                    '2. Below are the members who joined with your referral. Tap a name to see their purchase timeline.',
                    '3. To bring in new members, share your join poster or QR code from your profile.',
                    '4. If anything looks wrong, tell the office through "Notices & support".',
                ]),
            ],
        ];
    }
}
