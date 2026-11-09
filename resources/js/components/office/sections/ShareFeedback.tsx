import {ActionForm,Table,Badge,date} from '../../UI';
import {usePortalSection,type PortalSectionProps} from '../portalShared';
/** A partner writing feedback and reading their own. Stays out of the office-only bundle. */
export default function ShareFeedback(props:PortalSectionProps){
 const {section,d,translate}=usePortalSection(props);
 return <>
 {section==='share-feedback'&&<><section className="panel narrow-panel"><h2>{translate('portal','feedback_share_title')}</h2><p className="muted">{translate('portal','feedback_share_hint')}</p><ActionForm action="/commands/feedback" fields={[{name:'location',label:translate('portal','label_location')},{name:'rating',label:translate('portal','feedback_form_rating'),options:[{value:'5',label:'5 ★'},{value:'4',label:'4 ★'},{value:'3',label:'3 ★'},{value:'2',label:'2 ★'},{value:'1',label:'1 ★'}],required:true,value:'5'},{name:'message',label:translate('portal','feedback_form_message'),type:'textarea',required:true}]} submit={translate('portal','feedback_form_submit')}/></section><section className="panel table-panel"><div className="panel-heading"><h2>{translate('portal','feedback_my_submissions')}</h2></div><Table rows={d.my_feedback??[]} columns={[{key:'created_at',title:translate('portal','enquiry_received'),render:r=>date(r.created_at)},{key:'rating',title:'★',render:r=>String(r.rating)},{key:'message',title:translate('portal','enquiry_message')},{key:'status',title:translate('portal','enquiry_status'),render:r=><Badge status={r.status||'pending'}/>}]} empty={translate('portal','feedback_empty')} emptyBody={translate('portal','feedback_empty_body')}/></section></>}
 </>;
}
