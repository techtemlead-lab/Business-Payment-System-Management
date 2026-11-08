import type React from 'react';
import {
    BadgeCheck,
    BookOpen,
    Building2,
    FileBadge,
    FileCheck2,
    FileText,
    Footprints,
    Globe,
    HandCoins,
    Handshake,
    HeartPulse,
    House,
    Info,
    Landmark,
    LayoutTemplate,
    MapPin,
    Megaphone,
    MessageCircleQuestion,
    MessageSquareHeart,
    Navigation,
    Package,
    PackageSearch,
    Phone,
    Scale,
    ShieldCheck,
    Stethoscope,
    Store,
    Truck,
    UserCog,
    Users,
    type LucideIcon,
} from 'lucide-react';
import type { WebsiteSectionProps } from '../WebsiteLayout';
import CompanyModule from './CompanyModule';
import HomeModule from './HomeModule';
import { AboutModule, ContactModule, DealersModule, FaqModule, FeedbackModule, NoticeModule, PartnershipModule, PrescribersModule, PrivacyModule, ProductDetailsModule, ProductsModule, QualityModule, ServicesModule, TermsModule } from './InnerPageModules';
import { AdminPortalModule, DealerPortalModule, StaffPortalModule, SubdealerPortalModule } from './PortalModules';
import { FooterModule, HeaderModule, NavbarModule } from './PublicPartsModules';
import { ArticlesModule, ConductModule, DeliveryModule, GrievanceModule, PartnerExitModule, QualityDocumentsModule, RefundModule, VerifyModule } from './TrustPageModules';

export type AreaId = 'public' | 'admin' | 'staff' | 'dealer' | 'subdealer';
export type RailGroupId = 'shared' | 'parts' | 'pages' | 'policies';

export interface WebsiteModuleDefinition {
    id: string;
    area: AreaId;
    railGroup?: RailGroupId;
    icon: LucideIcon;
    /** The public address, for the preview link. */
    slug?: string;
    /** The stored on/off switch of the page, when it has one. */
    flag?: string;
    component: React.ComponentType<WebsiteSectionProps>;
}

export const WEBSITE_AREAS: Array<{ id: AreaId; icon: LucideIcon }> = [
    { id: 'public', icon: Globe },
    { id: 'admin', icon: ShieldCheck },
    { id: 'staff', icon: UserCog },
    { id: 'dealer', icon: Store },
    { id: 'subdealer', icon: Users },
];

export const RAIL_GROUPS: RailGroupId[] = ['shared', 'parts', 'pages', 'policies'];

export const WEBSITE_MODULES: WebsiteModuleDefinition[] = [
    { id: 'company', area: 'public', railGroup: 'shared', icon: Building2, component: CompanyModule },

    { id: 'header', area: 'public', railGroup: 'parts', icon: LayoutTemplate, component: HeaderModule },
    { id: 'navbar', area: 'public', railGroup: 'parts', icon: Navigation, component: NavbarModule },
    { id: 'footer', area: 'public', railGroup: 'parts', icon: Footprints, component: FooterModule },

    { id: 'home', area: 'public', railGroup: 'pages', icon: House, slug: '/', component: HomeModule },
    { id: 'products', area: 'public', railGroup: 'pages', icon: Package, slug: '/products', flag: 'page_enabled_products', component: ProductsModule },
    { id: 'product_details', area: 'public', railGroup: 'pages', icon: PackageSearch, component: ProductDetailsModule },
    { id: 'about', area: 'public', railGroup: 'pages', icon: Info, slug: '/about', flag: 'page_enabled_about', component: AboutModule },
    { id: 'contact', area: 'public', railGroup: 'pages', icon: Phone, slug: '/contact', flag: 'page_enabled_contact', component: ContactModule },
    { id: 'dealers', area: 'public', railGroup: 'pages', icon: MapPin, slug: '/dealers', flag: 'page_enabled_dealers', component: DealersModule },
    { id: 'services', area: 'public', railGroup: 'pages', icon: HeartPulse, slug: '/services', flag: 'page_enabled_services', component: ServicesModule },
    { id: 'partnership', area: 'public', railGroup: 'pages', icon: Handshake, slug: '/partnership', flag: 'page_enabled_partnership', component: PartnershipModule },
    { id: 'quality', area: 'public', railGroup: 'pages', icon: BadgeCheck, slug: '/quality', flag: 'page_enabled_quality', component: QualityModule },
    { id: 'faq', area: 'public', railGroup: 'pages', icon: MessageCircleQuestion, slug: '/faq', flag: 'page_enabled_faq', component: FaqModule },
    { id: 'prescribers', area: 'public', railGroup: 'pages', icon: Stethoscope, slug: '/prescribers', flag: 'page_enabled_prescribers', component: PrescribersModule },
    { id: 'feedback', area: 'public', railGroup: 'pages', icon: MessageSquareHeart, slug: '/feedback', component: FeedbackModule },
    { id: 'notice', area: 'public', railGroup: 'pages', icon: Megaphone, slug: '/notice', component: NoticeModule },
    { id: 'verify', area: 'public', railGroup: 'pages', icon: ShieldCheck, slug: '/verify-dealer', flag: 'page_enabled_verify-dealer', component: VerifyModule },
    { id: 'grievance', area: 'public', railGroup: 'pages', icon: Landmark, slug: '/grievance', flag: 'page_enabled_grievance', component: GrievanceModule },
    { id: 'quality_documents', area: 'public', railGroup: 'pages', icon: FileCheck2, slug: '/quality-documents', flag: 'page_enabled_quality-documents', component: QualityDocumentsModule },
    { id: 'articles', area: 'public', railGroup: 'pages', icon: BookOpen, slug: '/articles', flag: 'page_enabled_articles', component: ArticlesModule },

    { id: 'privacy', area: 'public', railGroup: 'policies', icon: FileBadge, slug: '/privacy', flag: 'page_enabled_privacy', component: PrivacyModule },
    { id: 'terms', area: 'public', railGroup: 'policies', icon: Scale, slug: '/terms', flag: 'page_enabled_terms', component: TermsModule },
    { id: 'refund', area: 'public', railGroup: 'policies', icon: HandCoins, slug: '/refund-policy', flag: 'page_enabled_refund-policy', component: RefundModule },
    { id: 'delivery', area: 'public', railGroup: 'policies', icon: Truck, slug: '/delivery-policy', flag: 'page_enabled_delivery-policy', component: DeliveryModule },
    { id: 'conduct', area: 'public', railGroup: 'policies', icon: FileText, slug: '/code-of-conduct', flag: 'page_enabled_code-of-conduct', component: ConductModule },
    { id: 'exit', area: 'public', railGroup: 'policies', icon: FileText, slug: '/partner-exit-policy', flag: 'page_enabled_partner-exit-policy', component: PartnerExitModule },

    { id: 'admin_portal', area: 'admin', icon: ShieldCheck, component: AdminPortalModule },
    { id: 'staff_portal', area: 'staff', icon: UserCog, component: StaffPortalModule },
    { id: 'dealer_portal', area: 'dealer', icon: Store, component: DealerPortalModule },
    { id: 'subdealer_portal', area: 'subdealer', icon: Users, component: SubdealerPortalModule },
];

/** Addresses from the old layout of this page keep landing somewhere sensible. */
export const OLD_ANCHORS: Record<string, string> = {
    'website-home': 'home',
    'website-pages': 'about',
    'website-text': 'faq',
    'website-video': 'home',
    'website-footer': 'footer',
};
