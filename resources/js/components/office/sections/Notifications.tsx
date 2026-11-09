import {Link,router,usePage} from '@inertiajs/react';
import {CheckCircle2,ShieldCheck} from 'lucide-react';
import PushOptIn from '../../PushOptIn';
import {NoticeBulkPicker} from '../../OfficeListTools';
import {Row,Pagination,Empty,date} from '../../UI';
import {useT} from '../../../shared/i18n/t';
import {usePortalSection,type PortalSectionProps} from '../portalShared';
export function PartnerCirculars({circulars}:{circulars:Row[]}){
 const translate=useT();
 const {portalBase:base}=usePage<any>().props;
 return <><section className="panel"><div className="panel-heading"><div><h2>{translate('portal','company_notices')}</h2><p className="muted">{translate('portal','company_notices_hint')}</p></div></div>{circulars.length?circulars.map((item:Row)=><article key={item.id} className="notification ptl-notice-item"><div className="step-icon"><ShieldCheck size={20}/></div><div><b>{item.title}</b><p className="ptl-prewrap">{item.body}</p><small>{date(item.updated_at)}</small></div></article>):<Empty title={translate('portal','no_company_notices')} body={translate('portal','no_company_notices_body')}/>}</section><section className="panel narrow-panel"><h2>{translate('portal','ask_company')}</h2><p className="muted">{translate('partner_hub','ask_company_moved')}</p><Link className="button dark" href={`${base}/help#support-request`}>{translate('partner_hub','help_way_form')}</Link></section></>;
}
/** Everyone's own updates, partner and office alike. */
export default function Notifications(props:PortalSectionProps){
 const {section,d,translate}=usePortalSection(props);
 return <>
 {section==='notifications'&&<section className="panel"><div className="panel-heading"><div><h2>{translate('portal','your_updates')}</h2><p className="muted">{translate('portal','your_updates_hint')}</p></div>{d.notices.data.some((n:Row)=>!n.read_at)&&<button type="button" className="button small" onClick={()=>router.post('/commands/notices/read-all')}>{translate('portal','mark_all_read')}</button>}</div><NoticeBulkPicker notices={d.notices.data}/><PushOptIn/>{d.notices.data.length?d.notices.data.map((n:Row)=><div className={`notification ${n.read_at?'':'is-unread'}`} key={n.id}><div className="step-icon"><CheckCircle2 size={20}/></div><div><b>{n.title}</b><p>{n.body}</p><small>{date(n.created_at)}</small></div>{!n.read_at&&<button className="text-link" onClick={()=>router.post(`/commands/notices/${n.id}/read`)}>{translate('portal','mark_read')}</button>}</div>):<Empty title={translate('portal','all_caught_up')}/>}<Pagination page={d.notices}/></section>}
 </>;
}
