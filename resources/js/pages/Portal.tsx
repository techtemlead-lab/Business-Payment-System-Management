import React,{useState} from 'react';
import {Head,Link,usePage,usePoll} from '@inertiajs/react';
import {ArrowUpRight,Plus} from 'lucide-react';
import Shell from '../components/Shell';
import DashboardOverview from '../components/DashboardOverview';
import ProductPurchaseMatrix from '../components/ProductPurchaseMatrix';
import PartnerCatalogDirectory from '../components/PartnerCatalogDirectory';
import NetworkTreeExplorer from '../components/NetworkTreeExplorer';
import HelpSupportHub from '../components/HelpSupportHub';
import PrerequisiteHelper from '../components/PrerequisiteHelper';
import PartnerHubNav from '../components/PartnerHubNav';
import OfficeHubTabs from '../components/OfficeHubTabs';
import {Row,Table,Pagination,Badge,money,dateTime,label} from '../components/UI';
import {useT} from '../shared/i18n/t';
import {SECTION_LABEL_KEYS} from '../shared/portalNav';
import Orders,{OrderTable} from '../components/office/sections/Orders';
import Security from '../components/office/sections/Security';
import Profile from '../components/office/sections/Profile';
import Network,{TreeShowMore} from '../components/office/sections/Network';
import Notifications,{PartnerCirculars} from '../components/office/sections/Notifications';
import ShareFeedback from '../components/office/sections/ShareFeedback';
import {SectionLoading,options,pageTotalLabel,Filter,type FilterSelect} from '../components/office/portalShared';
/**
 * Office-only screens load on demand, so a dealer's phone never downloads the website editor,
 * treasury or payroll code it will never open.
 */
const SiteCustomizationEditor=React.lazy(()=>import('../components/SiteCustomizationEditor'));
const PublicTrustHub=React.lazy(()=>import('../components/PublicTrustHub'));
const ProductManagementHub=React.lazy(()=>import('../components/ProductManagementHub'));
const ProductCreateModal=React.lazy(()=>import('../components/ProductCreateModal'));
const CommissionStudio=React.lazy(()=>import('../components/CommissionStudio'));
const AreaDealersHub=React.lazy(()=>import('../components/AreaDealersHub'));
const PlaceCodesHub=React.lazy(()=>import('../components/PlaceCodesHub'));
const BranchesHub=React.lazy(()=>import('../components/BranchesHub'));
const CourierBoard=React.lazy(()=>import('../components/CourierBoard'));
const BranchPerformanceHub=React.lazy(()=>import('../components/BranchPerformanceHub'));
const StockLotsHub=React.lazy(()=>import('../components/StockLotsHub'));
const RankRoyaltyHub=React.lazy(()=>import('../components/RankRoyaltyHub'));
const TopSellersBoard=React.lazy(()=>import('../components/TopSellersBoard'));
const SettlementsHub=React.lazy(()=>import('../components/SettlementsHub'));
const CompensationSimulator=React.lazy(()=>import('../components/CompensationSimulator'));
const PayoutDisbursementHub=React.lazy(()=>import('../components/PayoutDisbursementHub'));
const WorkLogTable=React.lazy(()=>import('../components/WorkLogTable'));
const EmployeesHub=React.lazy(()=>import('../components/EmployeesHub'));
const DashboardManagementHub=React.lazy(()=>import('../components/DashboardManagementHub'));
const OfficeChat=React.lazy(()=>import('../components/PartnerChat'));
const PartnerMessages=React.lazy(()=>import('../components/PartnerChat').then(m=>({default:m.PartnerMessages})));
const SupportAdminHub=React.lazy(()=>import('../components/HelpSupportHub').then(m=>({default:m.SupportAdminHub})));
const AgreementHub=React.lazy(()=>import('../components/AgreementHub'));
const TrainingHub=React.lazy(()=>import('../components/TrainingHub'));
const EventsCalendarHub=React.lazy(()=>import('../components/EventsCalendarHub'));
const DataCleanupHub=React.lazy(()=>import('../components/DataCleanupHub'));
const PrescriptionsHub=React.lazy(()=>import('../components/PrescriptionsHub'));
const SalesKitHub=React.lazy(()=>import('../components/dealertools/SalesKitHub'));
const CustomerBookHub=React.lazy(()=>import('../components/dealertools/CustomerBookHub'));
const SchemesHub=React.lazy(()=>import('../components/dealertools/SchemesHub'));
const ContestsHub=React.lazy(()=>import('../components/dealertools/ContestsHub'));
const PartnerScorecardHub=React.lazy(()=>import('../components/dealertools/PartnerScorecardHub'));
const PrivacyRequestsHub=React.lazy(()=>import('../components/PrivacyRequestsHub'));
const PartnerPrivacyRequests=React.lazy(()=>import('../components/PrivacyRequestsHub').then(m=>({default:m.PartnerPrivacyRequests})));
const PurchasingHub=React.lazy(()=>import('../components/PurchasingHub'));
const StockCountHub=React.lazy(()=>import('../components/StockCountHub'));
const IdentityReviewHub=React.lazy(()=>import('../components/IdentityReviewHub'));
const Policies=React.lazy(()=>import('../components/office/sections/Policies'));
const Content=React.lazy(()=>import('../components/office/sections/Content'));
const Enquiries=React.lazy(()=>import('../components/office/sections/Enquiries'));
const Feedback=React.lazy(()=>import('../components/office/sections/Feedback'));
const Applications=React.lazy(()=>import('../components/office/sections/Applications'));
const LoginRules=React.lazy(()=>import('../components/office/sections/LoginRules'));
const Payments=React.lazy(()=>import('../components/office/sections/Payments'));
const Inventory=React.lazy(()=>import('../components/office/sections/Inventory'));
const Accounts=React.lazy(()=>import('../components/office/sections/Accounts'));
const PartnerFrame=React.lazy(()=>import('../components/office/sections/PartnerFrame'));
const SettingsHome=React.lazy(()=>import('../components/office/SettingsHome'));
/** These pages draw their own tab strip through OfficePage, so the page must not print a second one. */
const DRAWS_OWN_TABS=['accounts','sub-dealers','partnerships','sub-dealer-applications','network-tree','area-dealers','ranks','employees','content','website','customization','public-trust','enquiries','feedback','agreement','training','events','sales-kit','schemes','contests','dashboards','data-cleanup','identity-review','privacy-requests','login-rules','overview','support-admin','partner-messages','top-sellers','branch-performance','partner-scorecard'];
/**
 * Every 30 seconds work queues refresh their list, so new orders and payments show up
 * without a reload. Every other page only refreshes the menu badges: re-sending a whole
 * report or the network tree every few seconds wastes the user's mobile data.
 */
const LIVE_SECTIONS=['overview','orders','payments','deliveries','notifications','sub-dealer-orders','payouts-hub'];


export default function Portal({section,data:d,filters}:{section:string,data:Row,filters:Row}){
 const translate=useT();const {auth,staffActions,portalBase:base,portal,securityControls,collectionAccounts:sharedCollectionAccounts=[]}=usePage<any>().props;const u=auth.user;const caps:string[]=auth.capabilities;const staff=portal==='manage'||portal==='sadmin';const[modal,setModal]=useState<string|null>(null);
 /** A status drop-down for the server filter bar; it filters every page, not just the rows on screen. */
 const statusSelect=(statuses:string[]):FilterSelect=>({name:'status',label:translate('portal','orders_filter_status'),options:statuses.map(item=>({value:item,label:label(item,translate)}))});
 usePoll(30000,()=>({only:LIVE_SECTIONS.includes(section)?['data','navAttention','unreadNotices']:['navAttention','unreadNotices']}));
 const open=(name:string)=>{setModal(name)};const close=()=>{setModal(null)};
 const sectionProps={section,data:d,filters};
 const stats=d.stats??{}; const action=section==='overview'?<Link className="button dark" href={staff?(base+(caps.includes('orders')||caps.includes('finance')?'/orders':caps.includes('content')?'/content':'/profile')):(base+'/purchase')}>{staff?(caps.includes('orders')||caps.includes('finance')?translate('portal','manage_orders'):caps.includes('content')?translate('portal','manage_website'):translate('portal','view_profile')):translate('portal','new_purchase_request')}<ArrowUpRight size={17}/></Link>:(!staff&&(section==='orders'||section==='deliveries'||section==='catalog'))?<Link className="button dark" href={`${base}/purchase`}><Plus size={17}/>{translate('portal','new_purchase_request')}</Link>:section==='accounts'&&(staffActions?.accounts?.create??true)?<Link className="button dark" href="/accounts/create?intent=dealer"><Plus size={17}/>{translate('portal','acct_heading')}</Link>:section==='sub-dealers'&&(staffActions?.accounts?.create??true)?<Link className="button dark" href="/accounts/create?intent=sub_dealer"><Plus size={17}/>{translate('portal','sd_add')}</Link>:undefined;
 return <Shell section={d.hub?.home??section} action={action}><Head title={SECTION_LABEL_KEYS[section]?translate('portal',SECTION_LABEL_KEYS[section]):label(section,translate)}/><React.Suspense fallback={<SectionLoading/>}>
 {d.hub&&!DRAWS_OWN_TABS.includes(section)&&<OfficeHubTabs hub={d.hub}/>}
 {d.partner_hub&&<PartnerHubNav hub={d.partner_hub}/>}
 {section==='settings'&&<SettingsHome groups={d.settings_groups??[]}/>}
 {section==='overview'&&<DashboardOverview data={d} orders={<OrderTable page={d.orders}/>}/>}
 {staff&&['orders','deliveries'].includes(section)&&d.no_branch_assigned&&<p className="notice" role="status">{translate('portal','orders_no_branch')}</p>}
 {['orders','deliveries','reports'].includes(section)&&<Orders {...sectionProps}/>}
 {section==='deliveries'&&staff&&d.courier_board&&<CourierBoard board={d.courier_board}/>}
 {section==='catalog'&&(staff?<div data-tour="catalog-grid"><ProductManagementHub products={d.products} allProducts={d.all_catalog_products||d.products} featuredOrderProducts={d.featured_order_products||[]} categories={d.categories||[]} catalogCategories={d.catalog_categories||[]} usedCategories={d.used_categories||[]} catalogUnits={d.catalog_units||[]} customization={d.customization||{}} defaults={d.defaults||{}} filters={filters} catalogFilters={d.catalog_filters} productsPage={d.products_page} canCreate={Boolean(d.can_create_product)} canOpenProduct={Boolean(d.can_open_product)} onCreateProduct={()=>open('product')}/></div>:<div data-tour="catalog-grid"><PartnerCatalogDirectory products={d.products} categories={d.categories} filters={filters}/></div>)}
 {section==='purchase'&&!staff&&<div data-tour="purchase-matrix"><ProductPurchaseMatrix repeatOrders={d.repeat_orders||[]} runningSchemes={d.running_schemes||[]} products={d.products} categories={d.categories||[]} subCategories={d.sub_categories||[]} collectionAccounts={sharedCollectionAccounts || []} fullPaymentRequired={Boolean(d.full_payment_required)} courierChoices={d.courier_choices||[]} initialProductId={typeof window!=='undefined'?new URLSearchParams(window.location.search).get('product_id'):null}/></div>}
 {['accounts','sub-dealers'].includes(section)&&<Accounts {...sectionProps}/>}
 {section==='inventory'&&<Inventory {...sectionProps}/>}
 {section==='payments'&&<Payments {...sectionProps}/>}
 {section==='policies'&&<Policies {...sectionProps}/>}
 {section==='commissions'&&<CommissionStudio rules={d.rules||[]}/>}
 {section==='settlements'&&<SettlementsHub state={d.settlement_state}/>}
 {section==='simulator'&&<CompensationSimulator initialSimulation={d.simulation} activeRules={d.active_rules||[]}/>}
 {section==='payouts-hub'&&<div data-tour="payouts-hub"><PayoutDisbursementHub data={d.payouts_table} tdsRate={d.tds_rate??0.00}/></div>}
 {section==='network-tree'&&staff&&<PartnerFrame data={d}><NetworkTreeExplorer dealers={d.dealers||[]} treeStats={d.tree_stats??null}/><TreeShowMore data={d} base={base}/></PartnerFrame>}
 {section==='area-dealers'&&<PartnerFrame data={d}><AreaDealersHub assignments={d.assignments} summary={d.area_summary} areaFilters={d.area_filters||{}} areaPoolRate={d.area_pool_rate} divisions={d.divisions||[]} dealers_list={d.dealers_list||[]} place_codes={d.place_codes||[]}/></PartnerFrame>}
 {section==='stock-lots'&&<StockLotsHub data={d.stock_lots||{}}/>}
 {section==='branch-performance'&&<BranchPerformanceHub performance={d.performance||{period:{period:'month',from:'',to:'',label:'month',timezone:''},totals:{order_count:0,order_value:'0.00',income:'0.00',outstanding:'0.00',estimated_profit:'0.00'},branches:[],unassigned:{order_count:0,order_value:'0.00',income:'0.00',outstanding:'0.00',estimated_profit:'0.00'},orders:{data:[],current_page:1,last_page:1,total:0,links:[]}}} branch_options={d.branch_options||[]}/>}
 {section==='branches'&&<BranchesHub branches={d.branches||{data:[],current_page:1,last_page:1,total:0,links:[]}} active_branch_id={d.active_branch_id} head_office_without_place_code={d.head_office_without_place_code} can_edit_branches={!!d.can_edit_branches} assignable_staff={d.assignable_staff||[]} canManage={caps.includes('inventory')||caps.includes('accounts')||['super_admin','md','chairman'].includes(u.role)}/>}
 {section==='place-codes'&&<PlaceCodesHub level={d.level||'division'} level_label={d.level_label||'Division'} searchable={!!d.searchable} filters={d.filters||filters} submodules={d.submodules||[]} parent_options={d.parent_options||[]} management_referral={d.management_referral||null} places={d.places||{data:[],current_page:1,last_page:1,total:0,links:[]}}/>}
 {section==='ranks'&&<PartnerFrame data={d}><RankRoyaltyHub policy={d.rank_policy} filters={filters} ranks={d.ranks||[]} stats={d.stats||{mo_count:0,am_count:0,sm_count:0,me_count:0,dealer_count:0}}/></PartnerFrame>}
 {section==='employees'&&<div data-tour="employees-hub"><EmployeesHub employees={d.employees} availablePermissions={d.available_permissions} stats={d.employee_stats} filters={filters} ownerAccounts={d.owner_accounts} hub={d.hub}/></div>}
 {section==='dashboards'&&<DashboardManagementHub settings={d.dashboard_settings||{}} defaults={d.dashboard_defaults||{}} stats={d.stats||{dealers_count:0,active_dealers:0,sub_dealers_count:0,active_sub_dealers:0,employees_count:0,active_employees:0,super_admins_count:0}} recent_employees={d.recent_employees||[]}/>}
 {section==='identity-review'&&<IdentityReviewHub/>}{section==='messages'&&<PartnerMessages chat={d.chat}/>}{section==='partner-messages'&&<OfficeChat inbox={d.chat_inbox} thread={d.chat_thread} routing={d.chat_routing} noBranch={Boolean(d.chat_no_branch)}/>}{section==='help'&&<HelpSupportHub data={d}/>}{section==='support-admin'&&<div data-tour="support-admin"><SupportAdminHub data={d}/></div>}{!staff&&<PrerequisiteHelper guide={d.guide_prerequisites} tourBlocked={[]} />}{section==='benefits-fund'&&<section className="panel" data-tour="benefits-fund"><div className="panel-heading"><div><h2>{translate('portal','benefits_fund_title')}</h2><p className="muted">{d.benefits_fund?.description||translate('portal','benefits_fund_desc_full')}</p><p className="muted"><Link className="record-link" href={`${base}/company-funds?fund=benefits`}>{translate('funds','link_manage')}</Link></p></div><span className="badge">{d.benefits_fund?.entry_count?translate('portal','benefits_fund_entries',{count:String(d.benefits_fund.entry_count)}):translate('portal','benefits_fund_empty')}</span></div><div className="metric-grid three"><div className="metric"><span>{translate('portal','benefits_fund_balance')}</span><strong>{money(d.benefits_fund?.balance)}</strong></div><div className="metric"><span>{translate('portal','benefits_fund_funded_entries')}</span><strong>{d.benefits_fund?.entry_count??0}</strong></div><div className="metric"><span>{translate('portal','benefits_fund_visibility')}</span><strong>{translate('portal','benefits_fund_owner_only')}</strong><small>{translate('portal','benefits_fund_hidden_staff')}</small></div></div><Filter section={section} filters={filters} selects={[statusSelect(['reserved','reversed'])]}/><Table searchable={false} printable totalCount={d.benefits_entries?.total} totalLabel={pageTotalLabel(d.benefits_entries,translate)} rows={d.benefits_entries?.data??[]} columns={[{key:'created_at',title:translate('portal','benefits_col_posted'),render:r=>dateTime(r.created_at)},{key:'order_number',title:translate('portal','benefits_col_source_order'),render:r=>r.order_number??'—'},{key:'buyer_name',title:translate('portal','benefits_col_buyer'),render:r=><>{r.buyer_name??'—'}<small className="cell-sub">{[r.buyer_code,r.buyer_role?label(r.buyer_role,translate):null,r.division,r.district].filter(Boolean).join(' · ')}</small></>},{key:'purchase_value',title:translate('portal','benefits_col_purchase_value'),money:true,total:true,render:r=>money(r.purchase_value)},{key:'company_share',title:translate('portal','benefits_col_company_share'),money:true,total:true,render:r=>money(r.company_share)},{key:'total_allocated',title:translate('portal','benefits_col_commissions'),money:true,total:true,render:r=>money(r.total_allocated)},{key:'amount',title:translate('portal','benefits_col_amount'),money:true,total:true,render:r=><b>{money(r.amount)}</b>},{key:'reason',title:translate('portal','benefits_col_detail'),render:r=><span className="muted ops-fs-xs">{r.reason||'—'}</span>},{key:'status',title:translate('portal','enquiry_status_label'),render:r=><Badge status={r.status}/>}]} empty={translate('portal','benefits_fund_empty')} emptyBody={translate('portal','benefits_empty_body')}/><Pagination page={d.benefits_entries}/></section>}
 {section==='circulars'&&<PartnerCirculars circulars={d.circulars??[]}/>}
 {section==='top-sellers'&&d.top_sellers&&<TopSellersBoard board={d.top_sellers} staff={Array.isArray(d.top_sellers.divisions)}/>}
 {section==='agreement'&&<AgreementHub agreement={d.agreement}/>}
 {section==='training'&&<TrainingHub data={d.training}/>}{section==='data-cleanup'&&<DataCleanupHub data={d.data_cleanup}/>}
 {section==='events'&&<EventsCalendarHub data={d.events_calendar}/>}
 {section==='prescriptions'&&d.prescriptions&&<PrescriptionsHub data={d.prescriptions} tools={d.prescription_tools} publicTools={d.prescriber_public}/>}
 {section==='privacy-requests'&&<PrivacyRequestsHub data={d.privacy_queue}/>}
 {section==='security'&&d.privacy_requests&&<PartnerPrivacyRequests data={d.privacy_requests}/>}
 {section==='purchasing'&&d.purchasing&&<PurchasingHub data={d.purchasing}/>}
 {section==='stock-counts'&&d.stock_counts&&<StockCountHub data={d.stock_counts}/>}
 {section==='sales-kit'&&d.sales_kit&&<SalesKitHub data={d.sales_kit}/>}
 {section==='customers'&&d.customer_book&&<CustomerBookHub data={d.customer_book} filters={filters}/>}
 {section==='schemes'&&d.schemes_admin&&<SchemesHub data={d.schemes_admin}/>}
 {section==='contests'&&d.contests&&<ContestsHub data={d.contests}/>}
 {section==='partner-scorecard'&&d.partner_scorecard&&<PartnerScorecardHub data={d.partner_scorecard} filters={filters}/>}
 {section==='network'&&<Network {...sectionProps}/>}
 {section==='profile'&&<Profile {...sectionProps}/>}
 {section==='security'&&<Security {...sectionProps}/>}
 {section==='login-rules'&&['super_admin','md','chairman'].includes(u.role)&&<LoginRules otp={securityControls?.partner_login_otp||{}} passwordResetNotice={securityControls?.password_reset_notice}/>}
 {section==='content'&&<Content {...sectionProps}/>}
 {(section==='website'||section==='customization')&&<SiteCustomizationEditor trustEditor={d.trust_editor} customization={d.customization} defaults={d.defaults} products={d.products} websitePages={d.websitePages} bnCopy={d.bangla_defaults} videoSetting={d.videoSetting} videoProducts={d.videoProducts}/>}
 {section==='public-trust'&&<PublicTrustHub data={d.public_trust}/>}
 {section==='enquiries'&&<Enquiries {...sectionProps}/>}
 {section==='feedback'&&<Feedback {...sectionProps}/>}
 {section==='share-feedback'&&<ShareFeedback {...sectionProps}/>}
 {['partnerships','sub-dealer-applications'].includes(section)&&<Applications {...sectionProps}/>}
 {section==='audit'&&<section className="panel table-panel"><div className="panel-heading"><h2>{translate('portal','audit_recorded_activity')}</h2><span>{translate('portal','audit_recorded_activity_hint')}</span></div><WorkLogTable data={d.work_log_table}/></section>}
 {section==='notifications'&&<Notifications {...sectionProps}/>}
 {modal==='product'&&<ProductCreateModal isOpen onClose={close} categories={d.catalog_categories||[]} units={d.catalog_units||[]} maxDealerPct={['super_admin','md','chairman'].includes(u.role)?70:50} maxSubDealerPct={['super_admin','md','chairman'].includes(u.role)?70:40}/>}
 </React.Suspense></Shell>
}
