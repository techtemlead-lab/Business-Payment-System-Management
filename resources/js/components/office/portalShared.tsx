import React,{useEffect,useState} from 'react';
import {Link,router,usePage} from '@inertiajs/react';
import {Search,ShieldCheck} from 'lucide-react';
import {Row,Field,Badge,money,number,label,DateInput} from '../UI';
import {useT} from '../../shared/i18n/t';
/** Placeholder shown for the moment an office screen is being fetched. */
export function SectionLoading(){const translate=useT();return <div className="section-loading" role="status" aria-live="polite"><span className="section-loading-bar"/><span className="section-loading-bar short"/><span className="section-loading-block"/><span className="sr-only">{translate('portal','loading')}</span></div>;}
export type TranslateFn=(group:string,key:string,replace?:Record<string,string>)=>string;
export const options=(items:string[],translate?:TranslateFn)=>items.map(value=>({value,label:label(value,translate)}));
export const contentPageLabel=(slug:string,translate:TranslateFn)=>translate('portal',`content_page_${slug.replace('-','_')}`);
/** A payment method in the reader's language ("Bank" shows as "ব্যাংক" on Bangla pages); unknown methods show as stored. */
export const channelName=(channel:unknown,translate:TranslateFn):string=>{const raw=String(channel??"");const key=`pay_channel_${raw.toLowerCase()}`;const text=translate("portal",key);return text!==key?text:raw;};
const supportTopicKey=(topic:string)=>{
 const map:Record<string,string>={
  Support:'support_topic_support',support:'support_topic_support',
  Complaint:'support_topic_complaint',complaint:'support_topic_complaint',
  'Order dispute':'support_topic_order',order_dispute:'support_topic_order',
  'KYC correction':'support_topic_kyc',kyc_correction:'support_topic_kyc',
  'Payout question':'support_topic_payout',payout_question:'support_topic_payout',
  'Dealer enquiry':'enquiry_topic_dealer',dealer_enquiry:'enquiry_topic_dealer',
  'Partnership enquiry':'enquiry_topic_partnership',partnership_enquiry:'enquiry_topic_partnership',
  'Product enquiry':'enquiry_topic_product',product_enquiry:'enquiry_topic_product',
  product_problem:'enquiry_topic_product_problem',
 };
 return map[topic]??null;
};
export const supportTopicLabel=(topic:string,translate:TranslateFn)=>{
 const key=supportTopicKey(topic);
 return key?translate('portal',key):topic;
};
export const accountFields=(translate:TranslateFn):Field[]=>[{name:'name',label:translate('portal','ops_fld_full_name'),required:true},{name:'email',label:translate('portal','ops_fld_email'),type:'email',required:true},{name:'phone',label:translate('portal','ops_fld_phone')},{name:'role',label:translate('portal','ops_fld_account_type'),options:options(['dealer','sub_dealer'],translate),required:true},{name:'division',label:translate('portal','ops_fld_division')},{name:'district',label:translate('portal','ops_fld_district')}];
export function parseJson<T>(value:unknown,fallback:T):T{if(typeof value!=='string'){return(value as T)??fallback;}try{return JSON.parse(value) as T;}catch{return fallback;}}
export function orderDue(row:Row):number{if(['rejected','cancelled'].includes(String(row.status))){return 0;}const due=Number(row.total||0)-Number(row.paid||0)-Number(row.due_credit||0);return due>0?due:0;}
export function orderItemCount(row:Row):number{return (row.lines??[]).reduce((n:number,l:Row)=>n+Number(l.quantity||0),0);}
export function orderSentCount(row:Row):number{return (row.lines??[]).reduce((n:number,l:Row)=>n+Number(l.dispatched||0),0);}
/** Statuses where nothing can have been sent yet, so the delivered column shows a dash. */
export const notYetSendable=['submitted','held','cancelled','rejected'];
export function AgingPill({days}:{days:number}){const translate=useT();const band=days>90?'is-90':days>60?'is-60':days>30?'is-30':'';return <span className={`aging-pill ${band}`.trim()}>{translate('portal','days_count',{n:number(days)})}</span>;}
/** "Page total" when the list has more pages than the one on screen, so the footer sum is not read as a grand total. */
/** Stock movement types (receipt, dispatch, ...) in plain words. */
export const stockKindLabel=(kind:string,translate:TranslateFn)=>{const key=`stock_kind_${kind}`;const localized=translate('portal',key);return localized!==key?localized:label(kind,translate);};
export const pageTotalLabel=(page:Row|undefined,translate:TranslateFn)=>Number(page?.last_page??1)>1?translate('portal','table_page_total'):undefined;
/** Totals count only money actually paid or received; the other rows stay listed and the label says so. */
export const countedTotalLabel=(page:Row|undefined,translate:TranslateFn,key:string)=>[pageTotalLabel(page,translate),translate('portal',key)].filter(Boolean).join(' · ');
/** Footer sum of a server-worked figure, for columns where what counts is not what the row shows. */
export const deliveredFooter=(key:string)=>(rows:Row[])=>money(rows.reduce((cents,r)=>cents+Math.round(Number(r[key]||0)*100),0)/100);
export const countedTotal=(rows:Row[],key:string,status:string)=>rows.filter(r=>r.status===status).reduce((cents,r)=>cents+Math.round(Number(r[key]||0)*100),0)/100;
/** A branch asked for in the address, so a download matches the page it was started from. */
export const branchFilterKeys=['branch_id','all_branches'];
/** /exports/orders with the filters that are on screen, so the file matches the list. */
export function ordersExportHref(section:string,filters:Row|undefined):string{
 const params=new URLSearchParams();
 for(const key of ['q','status','period','from','to','buyer_role',...branchFilterKeys]){
  const value=String(filters?.[key]??'').trim();
  if(value){
   params.set(key,value);
  }
 }
 params.set('section',section);
 return `/exports/orders?${params.toString()}`;
}
export const orderStatuses=['submitted','held','approved','partially_dispatched','dispatched','delivered','partly_delivered','returned','rejected','cancelled'];
/** The Deliveries list only holds orders with goods still to send (OrderListFilter::AwaitingDispatch), plus the overdue view. */
export const deliveryStatuses=['overdue_delivery','approved','partially_dispatched','dispatched'];
export const claimStatuses=['pending','verified','rejected'];
export const accountStatuses=['active','pending','suspended','closed'];
/**
 * Status tabs with counts above a list (orders, payments, withdrawals — as in Shopify
 * or Daraz Seller Center): one click switches status and keeps the other filters.
 */
export function StatusTabs({counts,filters,section,statuses}:{counts:Record<string,number>,filters:Row,section:string,statuses:string[]}){
 const translate=useT();
 const {portalBase:base}=usePage<any>().props;
 const active=String(filters.status||'');
 // Closed partners have their own tab and are left out of "All".
 const total=Object.entries(counts).reduce((sum,[status,count])=>status==='closed'&&['accounts','sub-dealers'].includes(section)?sum:sum+Number(count||0),0);
 const tabs=[{value:'',label:translate('portal','orders_tab_all'),count:total},...[...statuses,...Object.keys(counts).filter(status=>!statuses.includes(status))].map(status=>({value:status,label:label(status,translate),count:Number(counts[status]||0)}))];
 const go=(status:string)=>{
  const query=Object.fromEntries(Object.entries({...filters,status}).filter(([key,value])=>value!==''&&value!=null&&key!=='page'));
  router.get(`${base}/${section}`,query,{preserveState:true,preserveScroll:true});
 };
 const listRef=React.useRef<HTMLDivElement>(null);
 // Phones scroll the tabs sideways: keep the selected one in view after a reload.
 useEffect(()=>{listRef.current?.querySelector<HTMLElement>('.is-active')?.scrollIntoView({block:'nearest',inline:'nearest'});},[active]);
 return <div ref={listRef} className="order-status-tabs" role="tablist" aria-label={translate('portal','orders_filter_status')}>
  {tabs.map(tab=><button key={tab.value||'all'} type="button" role="tab" aria-selected={active===tab.value} className={`order-status-tab${active===tab.value?' is-active':''}${tab.count===0&&tab.value!==''?' is-empty':''}`} onClick={()=>go(tab.value)}>
   <span>{tab.label}</span><b>{number(tab.count)}</b>
  </button>)}
 </div>;
}
/** Totals above a list (orders, deliveries): read-only figures in the dashboard's figure style. */
export function SummaryFigures({items}:{items:{key:string,label:string,value:string,tone?:string}[]}){
 return <div className="admin-kpis list-summary">{items.map(item=><div key={item.key} className={`admin-kpi is-static${item.tone?` tone-${item.tone}`:''}`}>
  <span className="admin-kpi-label">{item.label}</span>
  <strong className="admin-kpi-value">{item.value}</strong>
 </div>)}</div>;
}
export const accountFilterKeys=['q','status','division','district','rank','no_order_days',...branchFilterKeys];
/** Excel download of the dealer or sub-dealer list with exactly the filters on screen. */
export function accountsExportHref(section:string,filters:Row|undefined):string{
 const query:Record<string,string>={section};
 accountFilterKeys.forEach(key=>{const value=filters?.[key];if(value!=null&&value!=='')query[key]=String(value);});
 return `/exports/accounts?${new URLSearchParams(query).toString()}`;
}
/** Division, district, rank and "no order lately" drop-downs for the accounts list. */
/** Values are place code ids (never names); labels are the names of those place codes. */
export function accountFilterSelects(section:string,filters:Row,areas:{division_id:number,division:string,districts:{id:number,name:string}[]}[],translate:(group:string,key:string,replace?:Record<string,string>)=>string):FilterSelect[]{
 const division=String(filters.division||'');
 const chosen=areas.find(area=>String(area.division_id)===division);
 const districts=chosen?chosen.districts.map(district=>({value:String(district.id),label:district.name})):areas.flatMap(area=>area.districts.map(district=>({value:String(district.id),label:`${district.name} (${area.division})`})));
 return [
  {name:'division',label:translate('portal','acct_filter_division'),options:areas.map(area=>({value:String(area.division_id),label:area.division})),resets:['district']},
  {name:'district',label:translate('portal','acct_filter_district'),options:districts},
  ...(section==='accounts'?[{name:'rank',label:translate('portal','acct_filter_rank'),options:['dealer','mo','am','sm','me'].map(rank=>({value:rank,label:translate('portal',`net_rank_${rank}`)}))}]:[]),
  {name:'no_order_days',label:translate('portal','acct_filter_any_order'),options:['30','60','90'].map(days=>({value:days,label:translate('portal','acct_filter_no_order_days',{days})}))},
 ];
}
export const stockMovementFilterKeys=['q','period','from','to','branch_id','product_id','movement'];
/** Excel download of the stock movement log with exactly the filters on screen. */
export function stockMovementsExportHref(filters:Row|undefined):string{
 const params=new URLSearchParams();
 stockMovementFilterKeys.forEach(key=>{const value=String(filters?.[key]??'').trim();if(value){params.set(key,value);}});
 return `/exports/stock-movements?${params.toString()}`;
}
/** Product and movement type drop-downs for the stock movement log, plus branch on "All branches" (otherwise the top-bar branch decides). */
export function stockMovementSelects(d:Row,translate:TranslateFn):FilterSelect[]{
 const products=[...new Map((d.products??[]).map((product:Row)=>[String(product.id),product.sku?`${product.name} (${product.sku})`:String(product.name)])).entries()];
 return [
  ...(d.branch_scope?.view==='all'&&(d.branches??[]).length>1?[{name:'branch_id',label:translate('portal','inv_filter_branch'),options:(d.branches??[]).map((branch:Row)=>({value:String(branch.id),label:branch.name||branch.code}))}]:[]),
  {name:'product_id',label:translate('portal','inv_filter_product'),options:products.map(([value,text])=>({value,label:String(text)}))},
  {name:'movement',label:translate('portal','inv_filter_movement'),options:(d.movement_kinds??[]).map((kind:string)=>({value:kind,label:stockKindLabel(kind,translate)}))},
 ];
}
/** An extra server filter drop-down in the filter bar; its value lives in the URL like the search. */
export type FilterSelect={name:string,label:string,options:{value:string,label:string}[],resets?:string[]};
export function Filter({section,filters,actions,selects=[]}:{section:string,filters:Row,actions?:React.ReactNode,selects?:FilterSelect[]}){
 const translate=useT();
 const {portalBase:base,portal}=usePage<any>().props;
 const staff=portal==='manage'||portal==='sadmin';
 const listsOrders=['orders','deliveries','reports'].includes(section);
 const isAccountSection=['accounts','sub-dealers'].includes(section);
 const showListFilters=listsOrders||section==='inventory'||isAccountSection;
 const [q,setQ]=useState(filters.q??'');
 const [from,setFrom]=useState(String(filters.from||''));
 const [to,setTo]=useState(String(filters.to||''));
 const buyerRole=String(filters.buyer_role||'');
 const period=String(filters.period||'');
 const status=String(filters.status||'');
 const channel=String(filters.channel||'');
 const [periodChoice,setPeriodChoice]=useState(period);
 useEffect(()=>{setQ(filters.q??'');setFrom(String(filters.from||''));setTo(String(filters.to||''));setPeriodChoice(period);},[filters.q,filters.from,filters.to,period]);
 const apply=(next:Row)=>{
  const selectedPeriod=String(next.period??period);
  const selectedFrom=String(next.from??from);
  const selectedTo=selectedPeriod==='day'?selectedFrom:String(next.to??to);
  const query={
   q:next.q??q,
   buyer_role:next.buyer_role??buyerRole,
   status:next.status??status,
   channel:next.channel??channel,
   period:selectedPeriod,
   from:selectedPeriod==='day'||selectedPeriod==='custom'?selectedFrom:'',
   to:selectedPeriod==='custom'?selectedTo:'',
   ...Object.fromEntries(selects.map(select=>[select.name,String(next[select.name]??filters[select.name]??'')])),
   ...(section==='inventory'?{tab:'movements'}:{}),
  };
  router.get(`${base}/${section}`,Object.fromEntries(Object.entries(query).filter(([,value])=>value)),{preserveState:true,preserveScroll:true});
 };
 const periods=[
  {value:'',label:translate('portal','orders_period_all')},
  {value:'today',label:translate('portal','orders_period_today')},
  {value:'week',label:translate('portal','orders_period_week')},
  {value:'month',label:translate('portal','orders_period_month')},
  ...(listsOrders?[{value:'last_month',label:translate('portal','orders_period_last_month')}]:[]),
  {value:'year',label:translate('portal','orders_period_year')},
  {value:'day',label:translate('portal','orders_period_day')},
  {value:'custom',label:translate('portal','orders_period_custom')},
 ];
 const filtered=Boolean(filters.q||filters.buyer_role||filters.status||filters.channel||filters.period||filters.from||filters.to||selects.some(select=>filters[select.name]));
 const statusChoices=isAccountSection?accountStatuses:section==='deliveries'?deliveryStatuses:orderStatuses;
 return <form className="filter-bar order-filters" onSubmit={e=>{e.preventDefault();apply({q,buyer_role:buyerRole})}}>
  <div className="order-search">
   <div className="search-input"><Search size={16}/><input aria-label={translate('portal','search_records')} placeholder={translate('portal',section==='inventory'?'inv_search_hint':'search_by_name_or_reference')} value={q} onChange={e=>setQ(e.target.value)}/></div>
   <button className="button small dark" type="submit">{translate('portal','search')}</button>
  </div>
  {staff&&['orders','payments','withdrawals'].includes(section)&&<select className="order-partner-select" data-tour="orders-buyer-role" aria-label={translate('portal','orders_filter_all')} value={buyerRole} onChange={e=>apply({buyer_role:e.target.value})}><option value="">{translate('portal','orders_filter_all')}</option><option value="dealer">{section==='withdrawals'?translate('portal','wd_filter_dealers'):translate('portal','orders_filter_dealers')}</option><option value="sub_dealer">{section==='withdrawals'?translate('portal','wd_filter_sub_dealers'):translate('portal','orders_filter_sub_dealers')}</option></select>}
  {showListFilters&&!isAccountSection&&<select className="order-period-select" data-tour="orders-period" aria-label={translate('portal','orders_period_all')} value={periodChoice} onChange={e=>{const next=e.target.value;setPeriodChoice(next);apply({period:next});}}>{periods.map(option=><option key={option.value||'all-dates'} value={option.value}>{option.label}</option>)}</select>}
  {showListFilters&&!['orders','payments','withdrawals','accounts','sub-dealers','ledger','audit','inventory'].includes(section)&&<select className="order-status-select" aria-label={translate('portal','orders_filter_status')} value={status} onChange={e=>apply({status:e.target.value})}><option value="">{translate('portal','orders_filter_status')}</option>{statusChoices.map(item=><option key={item} value={item}>{label(item,translate)}</option>)}</select>}
  {selects.map(select=><select key={select.name} className="order-extra-select" aria-label={select.label} value={String(filters[select.name]??'')} onChange={e=>apply({[select.name]:e.target.value,...Object.fromEntries((select.resets??[]).map(name=>[name,'']))})}><option value="">{select.label}</option>{select.options.map(option=><option key={option.value} value={option.value}>{option.label}</option>)}</select>)}
  {showListFilters&&periodChoice==='day'&&<div className="order-dates"><DateInput aria-label={translate('portal','orders_period_day')} value={from} onChange={e=>{setFrom(e.target.value);if(/^\d{4}-\d{2}-\d{2}$/.test(e.target.value))apply({period:'day',from:e.target.value});}}/></div>}
  {showListFilters&&periodChoice==='custom'&&<div className="order-dates"><DateInput aria-label={translate('portal','orders_period_custom')} value={from} onChange={e=>{setFrom(e.target.value);if(/^\d{4}-\d{2}-\d{2}$/.test(e.target.value)&&/^\d{4}-\d{2}-\d{2}$/.test(to))apply({period:'custom',from:e.target.value,to});}}/><DateInput aria-label={translate('portal','orders_period_custom')} value={to} onChange={e=>{setTo(e.target.value);if(/^\d{4}-\d{2}-\d{2}$/.test(from)&&/^\d{4}-\d{2}-\d{2}$/.test(e.target.value))apply({period:'custom',from,to:e.target.value});}}/></div>}
  {(filtered||actions)&&<div className="order-filter-actions">{filtered&&<Link href={`${base}/${section}`} className="text-link" preserveState={false}>{translate('portal','clear')}</Link>}{actions}</div>}
 </form>;
}
export function FinanceGate({title,children}:{title?:string,children?:React.ReactNode}){const translate=useT();const {financeActive}=usePage<any>().props;if(financeActive)return null;return <div className="policy-gate"><div className="gate-icon"><ShieldCheck size={25}/></div><div><span className="eyebrow">{translate('portal','finance_status')}</span><h2>{title??translate('portal','finance_posting_disabled')}</h2><p>{translate('portal','finance_posting_body')}</p>{children}</div><Badge status="draft"/></div>}
/** The "Showing: <branch> / All branches" chip on staff pages that follow the top-bar branch switcher. */
export function BranchScopeChip({scope}:{scope:{view:string,name?:string|null}}){const translate=useT();const name=scope.view==='all'?translate('portal','branch_view_all'):(scope.name||translate('portal','branch_scope_none'));return <p className="branch-scope-chip" title={translate('portal','branch_scope_hint')}><span>{translate('portal','branch_scope_showing',{name})}</span></p>}
/** Stock reasons are saved as a code and a note ("write_off: carton damaged"). Show the code as words. */
export function stockReasonText(reason:string,translate:(group:string,key:string)=>string){const colon=reason.indexOf(':');const code=(colon===-1?reason:reason.slice(0,colon)).trim();const key=`stock_reason_code_${code}`;const words=translate('portal',key);if(words===key){return reason;}return colon===-1?words:`${words}:${reason.slice(colon+1)}`;}
/** What every section of the portal page receives from it. */
export type PortalSectionProps={section:string,data:Row,filters:Row};
/**
 * The names a section of the portal page reads, the same ones `Portal.tsx` uses, so markup moves over unchanged.
 * `open`, `close`, `modal` and `selected` belong to the calling section: its dialog is its own.
 */
export function usePortalSection({section,data:d,filters}:PortalSectionProps){
 const translate=useT();const {auth,financeActive,staffActions,withdrawalMinimum,staffRoles,portalBase:base,portal,securityControls,flash,collectionAccounts:sharedCollectionAccounts=[]}=usePage<any>().props;const u=auth.user;const caps:string[]=auth.capabilities;const staff=portal==='manage'||portal==='sadmin';const[modal,setModal]=useState<string|null>(null);const[selected,setSelected]=useState<Row>({});const security=securityControls||{};const otp=security.partner_login_otp||{};const totp=security.totp||{};
 /** A status drop-down for the server filter bar; it filters every page, not just the rows on screen. */
 const statusSelect=(statuses:string[]):FilterSelect=>({name:'status',label:translate('portal','orders_filter_status'),options:statuses.map(item=>({value:item,label:label(item,translate)}))});
 const open=(name:string,row:Row={})=>{setSelected(row);setModal(name)};const close=()=>{setModal(null)};
 return {section,d,filters,translate,auth,u,caps,staff,base,portal,financeActive,staffActions,withdrawalMinimum,staffRoles,security,otp,totp,flash,sharedCollectionAccounts,statusSelect,open,close,modal,selected};
}
