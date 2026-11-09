import {lazy} from 'react';
import {Link,usePage} from '@inertiajs/react';
import {ArrowUpRight,Download} from 'lucide-react';
import {OfficeListTable,SavedFilterMenu} from '../../OfficeListTools';
import {Row,Pagination,Badge,money,number,date,label} from '../../UI';
import {useT} from '../../../shared/i18n/t';
import {AgingPill,Filter,StatusTabs,SummaryFigures,countedTotalLabel,deliveredFooter,notYetSendable,orderDue,orderItemCount,orderSentCount,orderStatuses,ordersExportHref,pageTotalLabel,usePortalSection,type PortalSectionProps} from '../portalShared';
export function OrderTable({page,empty,emptyBody,tools=true,searchable=true,report=false,overdue=false,listKey}:{page:Row,empty?:string,emptyBody?:string,tools?:boolean,searchable?:boolean,report?:boolean,overdue?:boolean,listKey?:string}){const translate=useT();const {portalBase:base,portal,auth}=usePage<any>().props;const staff=portal==='manage'||portal==='sadmin'||auth?.user?.workspace_mode==='staff';return <><OfficeListTable listKey={listKey} bulk="orders" phoneColumns={staff?['number','user','total','status','action']:['number','total','status']} tools={tools} searchable={searchable} printable={report} totalCount={page?.total} totalLabel={report?countedTotalLabel(page,translate,'order_footer_counted'):pageTotalLabel(page,translate)} rows={page?.data??[]} columns={[{key:'number',title:translate('portal','col_order'),render:r=><Link className="record-link" href={`${base}/orders/${r.id}`}>{r.number}</Link>},{key:'created_at',title:translate('portal','col_date'),render:r=>date(r.created_at)},...(staff?[{key:'user',title:translate('portal','col_partner'),render:(r:Row)=><>{r.user?.name??'—'}<small className="cell-sub">{[r.user?.dealer_code,r.user?.role?label(r.user.role,translate):null].filter(Boolean).join(' · ')}</small></>},{key:'phone',title:translate('portal','ops_fld_phone'),render:(r:Row)=>r.user?.phone||'—'},{key:'area',title:translate('portal','col_area'),render:(r:Row)=>[r.user?.district,r.user?.division].filter(Boolean).join(', ')||'—'}]:[]),{key:'branch',title:translate('portal','col_branch'),render:r=>r.fulfillment_branch?.name||'—'},{key:'lines',title:translate('portal','col_items'),align:'right',total:report,sortValue:orderItemCount,render:r=>number(orderItemCount(r))},{key:'delivered',title:translate('portal','col_delivered'),align:'right',sortValue:(r:Row)=>orderItemCount(r)?orderSentCount(r)/orderItemCount(r):0,render:(r:Row)=>notYetSendable.includes(r.status)?'—':<span title={translate('portal','col_delivered_hint')}>{number(orderSentCount(r))} / {number(orderItemCount(r))}</span>},{key:'total',title:translate('portal','col_order_value'),money:true,footer:report?deliveredFooter('delivered_value'):undefined,render:r=><b>{money(r.total)}</b>},{key:'paid',title:translate('portal','col_paid'),money:true,footer:report?deliveredFooter('delivered_paid'):undefined,render:r=>money(r.paid)},{key:'due',title:translate('portal','col_due'),money:true,total:report,sortValue:orderDue,render:r=>money(orderDue(r))},...(overdue?[{key:'days_since_sent',title:translate('portal','col_days_since_sent'),align:'right' as const,sortValue:(r:Row)=>Number(r.days_since_sent??0),render:(r:Row)=>r.days_since_sent!=null?<AgingPill days={Number(r.days_since_sent)}/>:'—'},{key:'courier_tracking',title:translate('portal','col_courier_tracking'),render:(r:Row)=>r.last_courier||r.last_tracking?<>{r.last_courier||'—'}<small className="cell-sub">{r.last_tracking||''}</small></>:'—'}]:[]),{key:'status',title:translate('portal','col_status'),render:r=><><Badge status={r.status}/>{['rejected','cancelled'].includes(r.status)&&r.decision_reason&&<small className="cell-sub" data-testid="order-closed-reason">{r.decision_reason}</small>}</>},{key:'action',title:'',render:r=><Link aria-label={`${translate('portal','view')} ${r.number}`} href={`${base}/orders/${r.id}`} className="icon-button"><ArrowUpRight size={17}/></Link>}]} empty={empty} emptyBody={emptyBody}/><Pagination page={page}/></>}
function DeliverySummary({summary}:{summary:Row}){
 const translate=useT();
 return <SummaryFigures items={[
  {key:'orders',label:translate('portal','deliv_sum_orders'),value:number(summary.orders),tone:Number(summary.orders||0)>0?'warn':''},
  {key:'units',label:translate('portal','deliv_sum_units'),value:number(summary.units)},
  {key:'oldest',label:translate('portal','deliv_sum_oldest'),value:translate('portal','days_count',{n:number(summary.oldest_days)})},
 ]}/>;
}
/** Grand totals for every order the filters match, shown above the list (the footer only adds up one page). */
function OrderTotals({totals}:{totals:Row}){
 const translate=useT();
 return <><div className="metric-grid four order-totals" aria-label={translate('portal','order_totals_title')}>
  <div className="metric"><span>{translate('portal','order_totals_orders')}</span><strong>{number(totals.orders)}</strong></div>
  <div className="metric"><span>{translate('portal','order_totals_value')}</span><strong>{money(totals.value)}</strong></div>
  <div className="metric"><span>{translate('portal','order_totals_paid')}</span><strong>{money(totals.paid)}</strong></div>
  <div className="metric"><span>{translate('portal','order_totals_to_pay')}</span><strong>{money(totals.to_pay)}</strong></div>
 </div><p className="muted" data-testid="order-totals-note">{translate('portal','order_totals_note')}</p></>;
}
/** Only the office downloads this, so a partner's phone does not. */
const OfficeOrders=lazy(()=>import('./OfficeOrders'));
/** Orders and deliveries: the office gets its own pages, partners keep this list. */
export default function Orders(props:PortalSectionProps){
 const {section,d,filters,caps,staff,translate}=usePortalSection(props);
 if(section==='orders'&&d.orders_table){return <OfficeOrders data={d}/>;}
 // The courier board mounted beside this section draws the office's whole Deliveries page.
 if(section==='deliveries'&&d.courier_board){return null;}
 const ordersEmpty=section==='deliveries'?{title:translate('portal','empty_deliveries_title'),body:translate('portal','empty_deliveries_body')}:{title:translate('portal','empty_orders_title'),body:translate('portal','empty_orders_body')};
 return <>
 {['orders','deliveries','reports'].includes(section)&&<section className="panel table-panel" data-tour="orders-table">{section==='orders'&&d.order_status_counts&&<StatusTabs counts={d.order_status_counts} filters={filters} section="orders" statuses={orderStatuses}/>}{section==='deliveries'&&d.delivery_summary&&<DeliverySummary summary={d.delivery_summary}/>}{section==='orders'&&d.order_totals&&<OrderTotals totals={d.order_totals}/>}<Filter section={section} filters={filters} actions={<>{staff&&section!=='reports'&&<SavedFilterMenu listKey={section} section={section} filters={filters}/>}{staff&&(caps.includes('reports')||caps.includes('finance'))&&<Link className="button small" href="/operations/financial-reports">{translate('portal','excel_pdf_reports')}</Link>}{(!staff||caps.includes('reports')||caps.includes('orders')||caps.includes('finance'))&&<a className="button small" data-tour="invoice-actions" href={ordersExportHref(section,filters)}><Download size={15}/>{staff?translate('portal','export_csv'):translate('partner_hub','export_excel')}</a>}</>}/><OrderTable page={d.orders} empty={ordersEmpty.title} emptyBody={ordersEmpty.body} searchable={false} report overdue={section==='deliveries'&&filters.status==='overdue_delivery'} listKey={staff&&section!=='reports'?section:undefined}/></section>}
 </>;
}
