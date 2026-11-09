<?php

namespace App\Support;

/**
 * Old factory text for the website editor.
 *
 * Saving the editor stores every field, so a site can hold old default text
 * that the owner never wrote. SiteCustomization treats a stored value that
 * matches one of these strings as "not changed" and shows today's default
 * (and its Bangla translation) instead. Add the old value here whenever a
 * default in SiteCustomization::defaults() or its lang/en/site.php twin is
 * reworded.
 */
final class SiteCustomizationRetiredDefaults
{
    /**
     * @return array<string, list<string|list<array{question: string, answer: string}>>>
     */
    public static function all(): array
    {
        return [
            // Text that used to have the phone, address, licence or company name typed in. Each one now reads it from the one saved fact.
            'contact_company_desc' => ['Madina Natural Health Care (Owner: Md Anowar Hossain). Head office: Thana Road, Pirganj 5470, Rangpur. Hotline: 01730532966 · 01723731877.'],
            'contact_phone_hours' => ['Hotline also: 01723731877'],
            // Never a company number. The owner's two numbers are 01730532966 and 01730532967.
            'footer_phone' => ['01723731877'],
            'map_subtitle' => ['Visit Madina Natural Health Care at our licensed Pirganj head office or navigate with map directions.'],
            'map_facility' => ['Madina Natural Health Care — Thana Road, Pirganj', 'Madina Natural Health Care & Physiotherapy Center'],
            'legal_meta_governance' => ['Madina Natural Health Care · Operational policy'],
            'md_message_company' => ['Madina Natural Health Care'],
            'invoice_marketed_by' => ['Madina Natural Health Care'],
            'invoice_marketed_phones' => ['01723-731877, 01730532966'],
            // Spelled-out English line from before the owner chose Arabic, and the Bangla default: neither may reach the other language.
            'home_bismillah_text' => ['Bismillahir Rahmanir Rahim', 'বিসমিল্লাহির রাহমানির রাহিম'],
            'company_intro_body' => ['Explore our company, our product range and the people connecting our products with communities across Bangladesh.'],
            'announcement_text' => ['For product availability and dealership enquiries, please contact the company.'],
            // Banner picture descriptions: "100% pure", "immunity" and "vitality" are claims the owner never supplied.
            'side_banner_1_alt' => ['Madina Premium Natural Care - 100% Pure Botanical Ingredients', 'Madina Premium Natural Care - 100% Pure Plant Ingredients', 'মদিনা প্রিমিয়াম ন্যাচারাল কেয়ার - ১০০% খাঁটি ভেষজ উপাদান'],
            'side_banner_1_slide1_alt' => ['Madina Premium Natural Care - 100% Pure Botanical Ingredients', 'মদিনার প্রাকৃতিক যত্ন - ১০০% খাঁটি ভেষজ উপাদান'],
            'side_banner_1_slide2_alt' => ['Madina Pure Herbal Skincare & Hair Vitality', 'মদিনার খাঁটি ভেষজ ত্বক ও চুলের যত্ন'],
            'side_banner_1_slide3_alt' => ['Madina Daily Immunity & Vitality Boosters', 'মদিনার রোজকার রোগ প্রতিরোধ ও শক্তির পণ্য'],
            'side_banner_2_alt' => ['Madina Authorized Dealer Network - Nationwide Distribution'],
            // Banner picture description; stated a 64-district reach nobody had checked.
            'side_banner_2_slide3_alt' => ['Madina Authorized Partner Pharmacy Outlets Across All 64 Districts'],
            'campaign_subtitle' => ['Explore limited-time wellness packages, bulk entrepreneur offers and seasonal healthcare discounts.'],
            'dealer_banner_subtitle' => ['Connect with an authorized local dealer for product information and purchase enquiries.'],
            'featured_products_badge' => ['POPULAR SELECTION'],
            'featured_products_subtitle' => ['Check & Get Your Desired Product!'],
            'video_gallery_title' => ['Brand & Product Stories'],
            'video_gallery_subtitle' => ['Company stories, herbal process highlights and authentic product demonstrations.'],
            'about_lead' => ['Licensed distributor of Holistic Nutracare herbal and nutrition products. Explore the collection and contact Madina Natural Health Care for product information and partnership enquiries.'],
            'about_summary_body' => ['Our partner workspace brings product requests, deliveries, verified manual payments and company support together. Dealer accounts are created by authorized company staff.'],
            // English-only text an earlier catalog sync saved; dropped so visitors get the built-in English or Bangla.
            'footer_about_desc' => ['Licensed distributor (Trade License 2026-00563, Pirganj Municipality). Natural herbal and nutrition products across Bangladesh.', 'লাইসেন্সধারী ডিস্ট্রিবিউটর (ট্রেড লাইসেন্স ২০২৬-০০৫৬৩, পীরগঞ্জ পৌরসভা)। সারা বাংলাদেশে প্রাকৃতিক ভেষজ ও পুষ্টি পণ্য।', 'Licensed distributor of natural herbal and nutrition products. Trade License 2026-00563, Pirganj Municipality, Rangpur. Marketed nutrition line: Holistic Nutracare.', 'Products, partners and communities across Bangladesh.'],
            'about_hero_lead' => ['Madina Natural Health Care is a licensed, single-owner distributor (Trade License 2026-00563, Pirganj Municipality) with its head office on Thana Road, Pirganj 5470, Rangpur. We supply herbal and nutrition products through authorized dealers and sub-dealers across Bangladesh.', 'মদিনা ন্যাচারাল হেলথ কেয়ার একটি লাইসেন্সধারী, একক মালিকানার ডিস্ট্রিবিউটর (ট্রেড লাইসেন্স ২০২৬-০০৫৬৩, পীরগঞ্জ পৌরসভা)। প্রধান কার্যালয় থানা রোড, পীরগঞ্জ ৫৪৭০, রংপুর। আমরা অনুমোদিত ডিলার ও সাব-ডিলারদের মাধ্যমে সারা বাংলাদেশে ভেষজ ও পুষ্টি পণ্য সরবরাহ করি।', 'Madina Natural Health Care is a licensed single-owner distributor (Trade License 2026-00563, Pirganj Municipality) headquartered on Thana Road, Pirganj 5470, Rangpur. We supply herbal and Holistic Nutracare nutrition products through an authorized dealer and sub-dealer network serving communities across Bangladesh.', 'Madina Natural Health Care is a licensed single-owner distributor (Trade License 2026-00563, Pirganj Municipality) headquartered on Thana Road, Pirganj 5470, Rangpur. We supply chemical-free herbal and Holistic Nutracare nutrition products through an authorized dealer and sub-dealer network serving communities across Bangladesh.', 'Madina Natural Health Care is a licensed, single-owner distributor (Trade License 2026-00563, Pirganj Municipality) with its head office on Thana Road, Pirganj 5470, Rangpur. We supply chemical-free herbal and Holistic Nutracare nutrition products through authorized dealers and sub-dealers across Bangladesh.', 'মদিনা ন্যাচারাল হেলথ কেয়ার একটি লাইসেন্সধারী, একক মালিকানার ডিস্ট্রিবিউটর (ট্রেড লাইসেন্স ২০২৬-০০৫৬৩, পীরগঞ্জ পৌরসভা)। প্রধান কার্যালয় থানা রোড, পীরগঞ্জ ৫৪৭০, রংপুর। আমরা অনুমোদিত ডিলার ও সাব-ডিলারদের মাধ্যমে সারা বাংলাদেশে রাসায়নিকমুক্ত ভেষজ ও হোলিস্টিক নিউট্রাকেয়ার পুষ্টি পণ্য সরবরাহ করি।'],
            'about_history' => ['Madina Natural Health Care was established under the ownership of Md Anowar Hossain as a licensed natural health distributor based in Pirganj, Rangpur. From a focused local operation we built a structured channel for Holistic Nutracare nutrition and herbal products manufactured from natural raw materials under modern process standards. Today the company links head office, authorized dealers and sub-dealers so authentic wellness products reach pharmacies, partner outlets and households nationwide, with warehouse support from Chaira, Moshorikhula, Hemayetpur, Savar, Dhaka.'],
            'about_mission' => ['To protect public health by ensuring adulterant-free food and promoting the correct use of herbal products, while building a transparent, staff-verified dealer and sub-dealer network that equips local entrepreneurs with reliable inventory, fair commercial terms and accountable order and payment processes.'],
            'about_vision' => ['To place chemical-free natural herbal and food products in every home in Bangladesh, and to be recognized as a dependable mother company for dealers, sub-dealers and community health partners who grow sustainable livelihoods through authentic wellness distribution.', 'To bring chemical-free natural herbal and food products to every home in Bangladesh, and to be a dependable mother company for dealers, sub-dealers and health partners who build a steady income by selling genuine products.', 'বাংলাদেশের প্রতিটি ঘরে রাসায়নিকমুক্ত প্রাকৃতিক ভেষজ ও খাদ্য পণ্য পৌঁছে দেওয়া, এবং ডিলার, সাব-ডিলার ও স্বাস্থ্য অংশীদারদের জন্য একটি ভরসার মাদার কোম্পানি হওয়া—যারা খাঁটি পণ্য বিক্রি করে নিয়মিত আয় গড়ে তোলেন।'],
            'about_obj1_title' => ['Authentic Wellness'],
            'about_obj1_desc' => ['Supply safe, verified unani medicines and dietary supplements with clear quality standards and product documentation for every SKU in the catalog.'],
            'about_obj2_title' => ['Empowered Partners'],
            'about_obj2_desc' => ['Support authorized dealers and sub-dealers with fair territory terms, structured product training, dedicated partner portals and company-backed commercial guidance.'],
            'about_obj3_title' => ['Accessible Care'],
            'about_obj3_desc' => ['Connect rural and urban communities with nearby partner outlets and complementary physiotherapy and natural health guidance where company services operate.'],
            'about_obj4_title' => ['Transparent Operations'],
            'about_obj4_desc' => ['Maintain staff-audited product requests, manual payment verification, delivery records and distribution accountability from head office through the partner network.'],
            'about_network' => ['Madina operates as the mother company for a staff-governed distribution network. Dealer and sub-dealer accounts are created only after company review—there is no public self-registration. Authorized partners place product requests through dedicated portals, submit manual payment evidence for verification, and serve end customers under approved commercial terms. This model keeps inventory, pricing discipline and customer trust aligned from Pirganj head office to local outlets across Bangladesh.'],
            // The last line of the page used to name Dhaka; the head office is in Pirganj, Rangpur.
            'footer_location' => ['Dhaka, Bangladesh', 'ঢাকা, বাংলাদেশ'],
            'contact_topic1_desc' => ['Dealer accounts are created by company staff after review.'],
            'contact_topic2_desc' => ['Ask about availability, orders and your local dealer.'],
            'directory_subtitle' => ['Find a company-approved dealer point or search nearest dealers in your area.'],
            'directory_empty_title' => ['No published dealers in this area yet.'],
            'catalog_apply_filters_btn' => ['Apply Filters'],
            'catalog_empty_desc' => ['Try another search or clear your filters.'],
            'product_out_of_stock_label' => ['Awaiting Stock'],
            'product_payment_mode_label' => ['Cash / Manual Payment'],
            'product_purchase_note' => ['Partner product requests and manual payments are reviewed by company staff.'],
            'cart_dialog_btn' => ['Sign in to request products'],
            'cart_dialog_notice' => ['Product requests and payments are reviewed by company staff.'],
            'partnership_hero_lead' => ['Join our nationwide dealer and sub-dealer network. Grow a trusted wellness business with company-backed products, training and territory support across Bangladesh.'],
            'partnership_benefit1_title' => ['Trusted product range'],
            'partnership_benefit1_desc' => ['Offer authentic natural health, unani and wellness products with clear quality standards.'],
            'partnership_benefit2_title' => ['Structured onboarding'],
            'partnership_benefit2_desc' => ['Company staff review applications, create accounts and guide you through territory setup.'],
            'partnership_benefit3_title' => ['Partner workspace'],
            'partnership_benefit3_desc' => ['Use dedicated dealer and sub-dealer portals for orders, deliveries, dues and earnings visibility.'],
            'partnership_benefit4_desc' => ['Connect communities with nearby authorized points while representing a recognized wellness brand.'],
            'partnership_step1_desc' => ['Send a dealer enquiry with your district, experience and preferred partnership type.'],
            'partnership_step2_title' => ['Company review'],
            'partnership_step2_desc' => ['Our team reviews coverage, documents and fit for the Madina network.'],
            'partnership_step3_title' => ['Account activation'],
            'partnership_step3_desc' => ['Approved partners receive secure portal access from company staff — no self-registration passwords.'],
            'partnership_cta_body' => ['Tell us about your location and goals. Approved partners are invited by the company after review.'],
            'services_hero_lead' => ['Madina supports community wellness through authentic products and care-focused services at our Natural Health Care and Physiotherapy Center.'],
            'services_card1_desc' => ['Guidance on suitable wellness products and lifestyle support for everyday family health needs.'],
            'services_card2_desc' => ['Care-oriented physiotherapy support at our registered facility for mobility and recovery needs.'],
            'services_card3_title' => ['Partner product access'],
            'services_card3_desc' => ['Find authorized dealers for product availability, local advice and purchase enquiries nationwide.'],
            'quality_hero_title' => ['Quality & Authenticity Promise'],
            'quality_hero_lead' => ['We focus on reliable sourcing, clear product information and an authorized distribution network so customers receive genuine Madina wellness products.'],
            'quality_pillar1_title' => ['Verified product standards'],
            'quality_pillar1_desc' => ['Products are selected and presented with attention to safety, labeling clarity and consistent quality expectations.'],
            'quality_pillar2_desc' => ['Published dealers are company-approved points — helping communities avoid unofficial or counterfeit channels.'],
            'quality_pillar3_title' => ['Audited partner operations'],
            'quality_pillar3_desc' => ['Orders, deliveries and manual payment claims follow staff-reviewed workflows with activity trails.'],
            'faq_items' => [[
                ['question' => 'Where can I buy Madina products?', 'answer' => 'Purchase through an authorized dealer listed in our Dealer Directory, or contact the company for help finding a nearby partner.'],
                ['question' => 'How do I become a dealer or sub-dealer?', 'answer' => 'Submit a dealer enquiry on the Contact page. Company staff review applications and create approved partner accounts — there is no public self-registration with a shared password.'],
                ['question' => 'Do you offer home delivery from this website?', 'answer' => 'The public website is informational. Partner product requests and deliveries are handled through authorized dealer and sub-dealer workspaces after company review.'],
                ['question' => 'How can I verify an authorized dealer?', 'answer' => 'Use the Dealer Directory to find company-published dealers by division and district, or contact head office for confirmation.'],
                ['question' => 'What should I do for product support or complaints?', 'answer' => 'Use the Contact form with the Support or Complaint topic, or call the published customer care number during service hours.'],
            ]],
            'privacy_hero_lead' => ['How Madina Natural Health Care handles information from our public website, partner network accounts and payment operations — plain operational rules until counsel updates the CMS text.'],
            'privacy_body' => ['1. Who We Are
Madina Natural Health Care runs this public website and the panels used by authorized dealers and sub-dealers across Bangladesh. This Privacy Policy explains in simple words what information we collect, why we use it, and how to contact us. We may update this policy from time to time.

2. Information We Collect
We collect only the information we need to run the website, answer questions and run the dealer network:
• Contact forms: name, phone, email, district/division and your message.
• Dealer accounts: your name, shop name, ID documents where needed for checking, phone, email, shop address, area details and the payment details staff record for your account.
• Orders and payments: products, quantities, delivery details, account book notes, and the bKash, Nagad, Rocket or bank TrxID you send so we can match your payment.
• Technical records: time, IP address and basic browser details, used for login safety, stopping misuse and keeping an activity log.

3. Partner Network & Accounts
Company staff open and manage dealer and sub-dealer accounts after checking them — nobody can open an account on their own. Keep your login details private. Inside the company, each staff member sees only the records they need for their job.

4. Payments & Transaction IDs (TrxID)
When you send a payment (for example bKash, Nagad, Rocket or bank transfer), we save the amount, the company account you paid to, the TrxID and the payment screenshot, so the accounts team can check it against company records. A false, repeated or changed TrxID can lead to the payment being rejected, your account being checked, or other action under company rules.

5. How We Use Information
We use this information to:
• Answer customer care, product and partnership questions.
• Check, approve and support dealer and sub-dealer accounts.
• Handle product orders, warehouse work and delivery.
• Match payments, keep dealer account books, and pay approved withdrawals as company policy allows.
• Send notices such as circulars, product list updates or service messages.
• Keep activity records for security and for the company\'s own money checks.

6. Sharing With Others
We do not sell personal information. We do not rent or trade dealer or customer lists. We share small details only when needed to get the work done — for example name, address and phone with a courier for delivery, payment references with banks or mobile banking services for checking, or information that a lawful authority asks for. Inside the company, what each person can see depends on their role.

7. How We Keep Information Safe
We protect accounts and records with standard safety steps for this kind of website: secure (encrypted) website connections, protected passwords, optional two-step login for dealer accounts, role-based access, and an activity log for sensitive actions. No online system is 100% safe, so please protect your own phone, computer and passwords.

8. How Long We Keep Records & Your Copy
We keep account, order and payment records while you work with us, and for as long as company record rules and normal business or accounting needs require. Dealers can download a copy of their profile data (JSON file) from the security settings in their panel; ID document numbers are not included in that file. For other privacy questions, use the Contact page or the head office details below.

9. Cookies & Settings
We use necessary login and security cookies, and setting cookies for language and theme. We do not use third-party advertising trackers on the public site.

10. Updates & Contact
We may change this policy when our work or the law changes. The date on this page changes when a new version is published. For privacy questions or data requests, contact Madina Natural Health Care:
• Office: Thana Road, Pirganj 5470, Rangpur, Bangladesh
• Customer care: +8801730532966 / 01723731877
• Or use the public Contact form', '১. আমরা কারা
মদিনা ন্যাচারাল হেলথ কেয়ার এই পাবলিক ওয়েবসাইট এবং সারা বাংলাদেশের অনুমোদিত ডিলার ও সাব-ডিলারদের প্যানেল চালায়। আমরা কী তথ্য নিই, কেন ব্যবহার করি এবং কীভাবে আমাদের সাথে যোগাযোগ করবেন—এই গোপনীয়তা নীতিতে তা সহজ ভাষায় বলা হয়েছে। প্রয়োজনে আমরা এই নীতি বদলাতে পারি।

২. আমরা কী তথ্য নিই
ওয়েবসাইট চালানো, প্রশ্নের উত্তর দেওয়া এবং ডিলার নেটওয়ার্ক চালানোর জন্য যে তথ্য দরকার, শুধু সেটুকুই নিই:
• যোগাযোগ ফর্ম: নাম, ফোন, ইমেইল, জেলা/বিভাগ ও আপনার বার্তা।
• ডিলার অ্যাকাউন্ট: আপনার নাম, দোকানের নাম, যাচাইয়ের জন্য দরকার হলে পরিচয়পত্র, ফোন, ইমেইল, দোকানের ঠিকানা, এলাকার তথ্য এবং আপনার অ্যাকাউন্টে স্টাফের লেখা পেমেন্টের তথ্য।
• অর্ডার ও পেমেন্ট: পণ্য, পরিমাণ, ডেলিভারির তথ্য, হিসাব খাতার নোট এবং পেমেন্ট মেলানোর জন্য আপনার পাঠানো বিকাশ, নগদ, রকেট বা ব্যাংকের TrxID।
• কারিগরি রেকর্ড: সময়, আইপি ঠিকানা ও ব্রাউজারের সাধারণ তথ্য—লগইন নিরাপত্তা, অপব্যবহার ঠেকানো ও কাজের রেকর্ড রাখার জন্য।

৩. ডিলার অ্যাকাউন্ট
যাচাইয়ের পর কোম্পানি স্টাফ ডিলার ও সাব-ডিলার অ্যাকাউন্ট খোলেন ও দেখাশোনা করেন — কেউ নিজে নিজে নিবন্ধন করতে পারেন না। আপনার লগইন তথ্য গোপন রাখুন। কোম্পানির ভেতরে প্রত্যেক স্টাফ শুধু নিজের কাজের জন্য দরকারি তথ্যই দেখতে পান।

৪. পেমেন্ট ও ট্রানজেকশন আইডি (TrxID)
আপনি পেমেন্টের তথ্য পাঠালে (যেমন বিকাশ, নগদ, রকেট বা ব্যাংক ট্রান্সফার) আমরা টাকার পরিমাণ, কোম্পানির যে অ্যাকাউন্টে টাকা পাঠিয়েছেন সেটি, TrxID ও টাকা পাঠানোর স্ক্রিনশট রেখে দিই, যাতে হিসাব বিভাগ কোম্পানির রেকর্ডের সাথে মিলিয়ে দেখতে পারে। ভুয়া, একই বা বদলানো TrxID দিলে পেমেন্ট নামঞ্জুর হতে পারে, অ্যাকাউন্ট যাচাই হতে পারে বা কোম্পানির নিয়ম অনুযায়ী অন্য ব্যবস্থা নেওয়া হতে পারে।

৫. তথ্য কী কাজে লাগাই
এই তথ্য আমরা ব্যবহার করি:
• কাস্টমার কেয়ার, পণ্য ও পার্টনারশিপ বিষয়ে প্রশ্নের উত্তর দিতে।
• ডিলার ও সাব-ডিলার অ্যাকাউন্ট যাচাই, অনুমোদন ও সহায়তা করতে।
• পণ্যের অর্ডার, গুদামের কাজ ও ডেলিভারি সামলাতে।
• পেমেন্ট মেলাতে, ডিলারের হিসাব খাতা রাখতে এবং কোম্পানির নিয়ম অনুযায়ী অনুমোদিত টাকা তোলার পেমেন্ট দিতে।
• সার্কুলার, পণ্য তালিকার আপডেট বা সেবা বার্তার মতো নোটিশ পাঠাতে।
• নিরাপত্তা ও কোম্পানির নিজের টাকার হিসাব যাচাইয়ের জন্য কাজের রেকর্ড রাখতে।

৬. অন্যদের সাথে শেয়ার
আমরা কারো ব্যক্তিগত তথ্য বিক্রি করি না। ডিলার বা গ্রাহকের তালিকা ভাড়া দিই না বা বিনিময় করি না। কাজ শেষ করতে দরকার হলেই কেবল সামান্য তথ্য শেয়ার করা হয় — যেমন ডেলিভারির জন্য কুরিয়ারকে নাম/ঠিকানা/ফোন, যাচাইয়ের জন্য ব্যাংক বা মোবাইল ব্যাংকিংকে পেমেন্ট রেফারেন্স, অথবা আইনগত কর্তৃপক্ষ চাইলে প্রয়োজনীয় তথ্য। কোম্পানির ভেতরে কে কী দেখবেন, তা তার পদ অনুযায়ী ঠিক করা থাকে।

৭. তথ্য কীভাবে নিরাপদ রাখি
এই ধরনের ওয়েবসাইটের জন্য প্রচলিত নিরাপত্তা ব্যবস্থা দিয়ে অ্যাকাউন্ট ও রেকর্ড সুরক্ষিত রাখি: নিরাপদ (এনক্রিপ্টেড) ওয়েবসাইট সংযোগ, সুরক্ষিত পাসওয়ার্ড, ডিলার লগইনে ঐচ্ছিক দুই ধাপের লগইন, পদ অনুযায়ী অনুমতি এবং গুরুত্বপূর্ণ কাজের রেকর্ড। কোনো অনলাইন সিস্টেমই ১০০% নিরাপদ নয়, তাই নিজের ফোন, কম্পিউটার ও পাসওয়ার্ড নিজে সুরক্ষিত রাখুন।

৮. কতদিন রেকর্ড রাখি ও আপনার কপি
আপনি আমাদের সাথে কাজ করা পর্যন্ত এবং কোম্পানির রেকর্ড রাখার নিয়ম ও সাধারণ ব্যবসা বা হিসাবের প্রয়োজন অনুযায়ী অ্যাকাউন্ট, অর্ডার ও পেমেন্টের রেকর্ড রাখা হয়। ডিলাররা নিজের প্যানেলের নিরাপত্তা সেটিং থেকে প্রোফাইলের তথ্যের একটি কপি (JSON ফাইল) ডাউনলোড করতে পারেন; সেই ফাইলে পরিচয়পত্রের নম্বর থাকে না। গোপনীয়তা নিয়ে অন্য প্রশ্নের জন্য যোগাযোগ পাতা বা নিচের প্রধান কার্যালয়ের ঠিকানা ব্যবহার করুন।

৯. কুকিজ ও সেটিং
আমরা লগইন ও নিরাপত্তার জন্য দরকারি কুকিজ এবং ভাষা ও থিম মনে রাখার কুকিজ ব্যবহার করি। পাবলিক সাইটে অন্য কোম্পানির বিজ্ঞাপনের ট্র্যাকার ব্যবহার করি না।

১০. পরিবর্তন ও যোগাযোগ
আমাদের কাজ বা আইন বদলালে এই নীতি বদলাতে পারে। নতুন সংস্করণ প্রকাশ হলে এই পাতার তারিখ বদলে যায়। গোপনীয়তা বা তথ্য সংক্রান্ত অনুরোধের জন্য মদিনা ন্যাচারাল হেলথ কেয়ারে যোগাযোগ করুন:
• অফিস: থানা রোড, পীরগঞ্জ ৫৪৭০, রংপুর, বাংলাদেশ
• কাস্টমার কেয়ার: +৮৮০১৭৩০৫৩২৯৬৬ / ০১৭২৩৭৩১৮৭৭
• অথবা পাবলিক যোগাযোগ ফর্ম ব্যবহার করুন', '1. Who We Are
Madina Natural Health Care operates this public website and the related partner workspaces used by authorized dealers and sub-dealers across Bangladesh. This Privacy Policy explains, in practical terms, what information we collect, why we use it, and how you can contact us. It is an operational company policy and can be updated in the Website CMS; it is not a substitute for formal legal advice.

2. Information We Collect
We collect information needed to run the website, answer enquiries and operate the partner network:
• Public contact forms: name, phone, email, district/division and message content.
• Partner onboarding and accounts: legal name, trade/shop name, identity documents where required for verification, phone, email, shop address, territory details and payment collection details staff record for the account.
• Orders and payments: product lines, quantities, delivery details, ledger notes, and payment claim references including mobile financial service or bank TrxID values submitted for reconciliation.
• Technical logs: timestamps, IP address and basic browser details used for sign-in security, abuse prevention and audit trails.

3. Partner Network & Accounts
Dealer and sub-dealer accounts are created and maintained by company staff after review — there is no public self-registration with a shared password. Account holders are responsible for keeping login credentials private. Staff access inside company systems is limited by role so people only see records needed for their work.

4. Payments & Transaction IDs (TrxID)
When partners submit manual payment claims (for example bKash, Nagad, Rocket or bank transfer), we store the claimed amount, collection account details and the TrxID or reference number so accounts staff can verify the payment against company records. False, duplicate or altered TrxID claims may lead to claim rejection, account review or further action under company operating rules.

5. How We Use Information
We use collected information to:
• Respond to customer care, product and partnership enquiries.
• Verify, approve and support authorized dealer and sub-dealer accounts.
• Process product requests, warehouse handling and delivery coordination.
• Reconcile payment claims, maintain partner ledgers and process approved payouts where company policy allows.
• Send operational notices such as circulars, catalog updates or service messages.
• Keep audit records needed for security and internal financial control.

6. Sharing & Third Parties
We do not sell personal information. We do not rent or trade partner or customer lists. Limited details may be shared only when needed to complete operations — for example courier name/address/phone for delivery, payment references with banks or mobile financial services for verification, or information required by lawful authority requests. Internal access follows role-based permissions.

7. Security Practices
We protect accounts and records with industry-standard measures appropriate to this platform, including encrypted website connections, hashed passwords, optional two-factor authentication for partner logins, role-based access and activity logging for sensitive actions. No online system can promise perfect security; partners should protect their own devices and credentials.

8. Retention & Your Access
Account, order and payment records are kept while the relationship is active and for as long as company retention settings and ordinary business or accounting needs require. Authorized partners can download a JSON copy of their profile data from portal security settings (identity document numbers are omitted from that export). For other privacy questions, use the Contact page or the head-office details below.

9. Cookies & Preferences
We use essential session and security cookies, plus preference cookies such as language and theme. We do not run third-party advertising trackers on the public site.

10. Updates & Contact
We may revise this policy when operations or regulations change. The effective label on this page is updated when a new version is published in the CMS. For privacy questions or data requests, contact Madina Natural Health Care:
• Office: Thana Road, Pirganj 5470, Rangpur, Bangladesh
• Customer care: +8801730532966 / 01723731877
• Email: holisticnutracare@gmail.com
• Or use the public Contact form'],
            'terms_hero_lead' => ['Operating rules for using the Madina Natural Health Care website, catalog and partner services — including accounts, orders and TrxID payment claims.'],
            'terms_body' => ['1. Agreement to These Terms
By using the Madina Natural Health Care website, product list, dealer directory or dealer panels, you agree to follow these Terms of Use and the company\'s working rules. If you do not agree, please stop using the site and its services.

2. Company Website & Product List
This website shares company information, health product details, helpful articles and a list of authorized dealers. Product information and MRP are for general guidance. Retail and wholesale buying happen through authorized company channels and checked dealer or sub-dealer accounts — you cannot buy directly from this site.

3. Partner Network Accounts
Only company staff open dealer and sub-dealer accounts, after checking documents and the area:
• Your login (phone, email or dealer code) is only for you. Keep your password safe and turn on two-step login when it is offered.
• Do not give false information about your area, level or link with the company.
• The company may stop or close your panel access for breaking the rules, giving false information or bad conduct.

4. Orders, Payments & TrxID
The company checks dealer orders and payments:
• An order in the panel is a request. It depends on stock and staff approval.
• The payment details you send must match a real payment. Give the correct TrxID, a screenshot of the payment and the correct company account you paid to. A false, repeated or jointly faked TrxID is serious misconduct. It can lead to the payment being rejected, the account being closed, and further action.
• Orders must be paid in full before the company approves and sends the goods. Wallet withdrawals need company approval under the published rules.

5. Product & Health Information
The product list may include unani medicines, food supplements and herbal health products. Website content is for information and learning only. It is not personal medical advice, diagnosis or a prescription. See a qualified doctor or health professional when you need treatment advice.

6. Our Names, Logos & Content
Trademarks, product names, logos, text, photos and site design belong to Madina Natural Health Care or to the companies that allow us to use them. Do not copy, collect or reuse them for business without written permission.

7. Website Availability
We try to keep the website and dealer tools running, but we cannot promise they will always be available. Maintenance, network problems or problems at other service providers may stop access, sometimes without notice.

8. Law & Disputes
These terms follow the laws of Bangladesh. Please raise any problem about the website or dealer work with the company first, so we can solve it fairly. If it is not solved, the courts in Rangpur, Bangladesh will decide.

9. Contact
Questions about these terms, partnership rules or the website:
• Head office: Thana Road, Pirganj 5470, Rangpur, Bangladesh
• Customer care: +8801730532966 / 01723731877
• Or use the public Contact form', '১. এই শর্তাবলিতে সম্মতি
মদিনা ন্যাচারাল হেলথ কেয়ারের ওয়েবসাইট, পণ্যের তালিকা, ডিলার তালিকা বা ডিলার প্যানেল ব্যবহার করলে আপনি এই ব্যবহারের শর্তাবলি ও কোম্পানির কাজের নিয়ম মানতে রাজি হচ্ছেন। রাজি না হলে সাইট ও এর সেবা ব্যবহার বন্ধ করুন।

২. কোম্পানির ওয়েবসাইট ও পণ্যের তালিকা
এই ওয়েবসাইটে কোম্পানির তথ্য, স্বাস্থ্য পণ্যের বিবরণ, দরকারি লেখা এবং অনুমোদিত ডিলারদের তালিকা আছে। পণ্যের তথ্য ও MRP সাধারণ ধারণার জন্য। খুচরা ও পাইকারি কেনাকাটা অনুমোদিত কোম্পানি চ্যানেল এবং যাচাই করা ডিলার বা সাব-ডিলার অ্যাকাউন্টের মাধ্যমে হয় — এই সাইট থেকে সরাসরি কেনা যায় না।

৩. ডিলার অ্যাকাউন্ট
কাগজপত্র ও এলাকা যাচাইয়ের পর শুধু কোম্পানি স্টাফ ডিলার ও সাব-ডিলার অ্যাকাউন্ট খোলেন:
• আপনার লগইন (ফোন, ইমেইল বা ডিলার কোড) শুধু আপনার জন্য। পাসওয়ার্ড নিরাপদ রাখুন এবং সুযোগ থাকলে দুই ধাপের লগইন চালু করুন।
• নিজের এলাকা, স্তর বা কোম্পানির সাথে সম্পর্ক নিয়ে মিথ্যা তথ্য দেবেন না।
• নিয়ম ভাঙলে, ভুয়া তথ্য দিলে বা খারাপ আচরণ করলে কোম্পানি আপনার প্যানেল ব্যবহার বন্ধ বা অ্যাকাউন্ট বাতিল করতে পারে।

৪. অর্ডার, পেমেন্ট ও TrxID
ডিলারের অর্ডার ও পেমেন্ট কোম্পানি যাচাই করে:
• প্যানেলে দেওয়া অর্ডার একটি অনুরোধ। এটি স্টক ও স্টাফের অনুমোদনের ওপর নির্ভর করে।
• আপনার পাঠানো পেমেন্টের তথ্য আসল লেনদেনের সাথে মিলতে হবে। সঠিক TrxID, টাকা পাঠানোর স্ক্রিনশট এবং কোম্পানির যে অ্যাকাউন্টে টাকা পাঠিয়েছেন সেটি সঠিকভাবে দিন। ভুয়া, একই বা যোগসাজশে বানানো TrxID গুরুতর অপরাধ। এতে পেমেন্ট নামঞ্জুর, অ্যাকাউন্ট বন্ধ এবং আরও ব্যবস্থা নেওয়া হতে পারে।
• কোম্পানি অনুমোদন দিয়ে মাল পাঠানোর আগে অর্ডারের পুরো টাকা দিতে হবে। ওয়ালেট থেকে টাকা তোলার জন্য প্রকাশিত নিয়ম অনুযায়ী কোম্পানির অনুমোদন লাগবে।

৫. পণ্য ও স্বাস্থ্য তথ্য
পণ্যের তালিকায় ইউনানি ওষুধ, খাদ্য সম্পূরক ও ভেষজ স্বাস্থ্য পণ্য থাকতে পারে। ওয়েবসাইটের লেখা শুধু তথ্য ও জানার জন্য। এটি ব্যক্তিগত চিকিৎসা পরামর্শ, রোগ নির্ণয় বা প্রেসক্রিপশন নয়। চিকিৎসার পরামর্শ দরকার হলে যোগ্য ডাক্তার বা স্বাস্থ্যকর্মীর কাছে যান।

৬. আমাদের নাম, লোগো ও লেখা
ট্রেডমার্ক, পণ্যের নাম, লোগো, লেখা, ছবি ও সাইটের ডিজাইন মদিনা ন্যাচারাল হেলথ কেয়ার বা যারা আমাদের ব্যবহারের অনুমতি দিয়েছে তাদের। লিখিত অনুমতি ছাড়া ব্যবসার কাজে এগুলো কপি, সংগ্রহ বা আবার ব্যবহার করবেন না।

৭. ওয়েবসাইট চালু থাকা
আমরা ওয়েবসাইট ও ডিলার প্যানেল চালু রাখার চেষ্টা করি, তবে সব সময় চালু থাকবে এমন নিশ্চয়তা দিতে পারি না। রক্ষণাবেক্ষণ, নেটওয়ার্ক সমস্যা বা অন্য সেবাদাতার সমস্যায় কখনো কখনো আগে না জানিয়েই সাইট বন্ধ থাকতে পারে।

৮. আইন ও বিরোধ
এই শর্তাবলি বাংলাদেশের আইন মেনে চলে। ওয়েবসাইট বা ডিলারের কাজ নিয়ে কোনো সমস্যা হলে আগে কোম্পানিকে জানান, যাতে ন্যায্যভাবে মিটিয়ে নেওয়া যায়। না মিটলে রংপুর, বাংলাদেশের আদালত সিদ্ধান্ত দেবে।

৯. যোগাযোগ
এই শর্তাবলি, পার্টনারশিপের নিয়ম বা ওয়েবসাইট নিয়ে প্রশ্ন থাকলে:
• প্রধান কার্যালয়: থানা রোড, পীরগঞ্জ ৫৪৭০, রংপুর, বাংলাদেশ
• কাস্টমার কেয়ার: +৮৮০১৭৩০৫৩২৯৬৬ / ০১৭২৩৭৩১৮৭৭
• অথবা পাবলিক যোগাযোগ ফর্ম ব্যবহার করুন', '1. Agreement to These Terms
By using the Madina Natural Health Care public website, product catalog, dealer directory or authorized partner workspaces, you agree to follow these Terms of Use and related company operating rules. If you do not agree, please stop using the site and related services. Staff may replace this CMS text with counsel-approved wording before go-live without changing how the page is published.

2. Company Website & Catalog
This website shares company information, wellness product details, educational content and an authorized dealer directory. Catalog information and MRP figures are for public guidance. Retail purchases and wholesale supply run through authorized company channels and verified dealer or sub-dealer accounts — not through an open public checkout on this site.

3. Partner Network Accounts
Dealer and sub-dealer accounts are created only by company staff after documentation and territory review:
• Login access (phone, email or dealer code) is personal. Protect passwords and enable two-factor authentication when offered.
• Do not misrepresent your territory, tier or company affiliation.
• The company may suspend or close portal access for policy breaches, fraudulent claims or abusive conduct.

4. Orders, Payments & TrxID Claims
Partner commerce follows company review workflows:
• Portal order lines are commercial requests subject to stock and staff approval.
• Manual payment claims must match real transfers. Submit accurate TrxID / transaction references and the correct company collection account. False, duplicate or collusive TrxID claims are treated as serious misconduct and may result in claim rejection, account termination and referral for further remedies.
• Credit terms and wallet payouts require explicit company approval under published policy.

5. Product & Wellness Information
Catalog items may include unani medicines, nutrition supplements and herbal wellness products. Website content is informational and educational. It is not personal medical advice, diagnosis or a prescription. Seek qualified professional care when you need clinical guidance.

6. Intellectual Property
Trademarks, product names, logos, text, photography and site design belong to Madina Natural Health Care or its licensors (including Holistic Nutracare distribution marks where applicable). Do not copy, scrape or reuse them commercially without written permission.

7. Availability
We aim to keep the website and partner tools available, but services are provided as available. Maintenance windows, network issues or third-party outages may interrupt access without advance notice in every case.

8. Governing Law
These terms are governed by the laws of Bangladesh. Disputes about website use or partner operations should first be raised with the company for good-faith resolution; otherwise competent courts in Rangpur, Bangladesh have jurisdiction.

9. Contact
Questions about these terms, partnership rules or platform operations:
• Head office: Thana Road, Pirganj 5470, Rangpur, Bangladesh
• Customer care: +8801730532966 / 01723731877
• Email: holisticnutracare@gmail.com
• Or use the public Contact form'],
        ];
    }
}
