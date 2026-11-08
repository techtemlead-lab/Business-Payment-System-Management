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
            // Spelled-out English line from before the owner chose Arabic, and the Bangla default: neither may reach the other language.
            'home_bismillah_text' => ['Bismillahir Rahmanir Rahim', 'বিসমিল্লাহির রাহমানির রাহিম'],
            'company_intro_body' => ['Explore our company, our product range and the people connecting our products with communities across Bangladesh.'],
            'announcement_text' => ['For product availability and dealership enquiries, please contact the company.'],
            'side_banner_1_alt' => ['Madina Premium Natural Care - 100% Pure Botanical Ingredients'],
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
            'about_hero_lead' => ['Madina Natural Health Care is a licensed single-owner distributor (Trade License 2026-00563, Pirganj Municipality) headquartered on Thana Road, Pirganj 5470, Rangpur. We supply chemical-free herbal and Holistic Nutracare nutrition products through an authorized dealer and sub-dealer network serving communities across Bangladesh.', 'Madina Natural Health Care is a licensed, single-owner distributor (Trade License 2026-00563, Pirganj Municipality) with its head office on Thana Road, Pirganj 5470, Rangpur. We supply chemical-free herbal and Holistic Nutracare nutrition products through authorized dealers and sub-dealers across Bangladesh.', 'মদিনা ন্যাচারাল হেলথ কেয়ার একটি লাইসেন্সধারী, একক মালিকানার ডিস্ট্রিবিউটর (ট্রেড লাইসেন্স ২০২৬-০০৫৬৩, পীরগঞ্জ পৌরসভা)। প্রধান কার্যালয় থানা রোড, পীরগঞ্জ ৫৪৭০, রংপুর। আমরা অনুমোদিত ডিলার ও সাব-ডিলারদের মাধ্যমে সারা বাংলাদেশে রাসায়নিকমুক্ত ভেষজ ও হোলিস্টিক নিউট্রাকেয়ার পুষ্টি পণ্য সরবরাহ করি।'],
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
