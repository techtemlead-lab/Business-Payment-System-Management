import {Link} from '@inertiajs/react';
import {Plus} from 'lucide-react';
import {ActionForm,Table,Modal,Badge} from '../../UI';
import {contentPageLabel,usePortalSection,type PortalSectionProps} from '../portalShared';
/** The public text pages (about, terms...): office only. */
export default function Content(props:PortalSectionProps){
 const {section,d,base,translate,open,close,modal,selected}=usePortalSection(props);
 return <>
 {section==='content'&&<><div className="toolbar-end"><button className="button dark" onClick={()=>open('content')}><Plus size={17}/>{translate('portal','content_edit')}</button></div><section className="panel table-panel"><div className="panel-heading"><div><h2>{translate('portal','content_pages_title')}</h2><p className="muted">{translate('portal','content_pages_hint')}</p></div><Link className="text-link" href={`${base}/website`}>{translate('portal','content_open_website')}</Link></div><Table rows={d.pages??[]} columns={[{key:'slug',title:translate('portal','content_col_page'),render:r=>contentPageLabel(String(r.slug),translate)},{key:'title',title:translate('portal','content_col_title')},{key:'published',title:translate('portal','enquiry_status_label'),render:r=><Badge status={r.published?'published':'draft'}/>},{key:'action',title:'',render:r=><button className="text-link" onClick={()=>open('content',r)}>{translate('portal','content_edit_row')}</button>}]} empty={translate('portal','content_empty_title')} emptyBody={translate('portal','content_empty_body')}/></section></>}
 {modal&&<Modal title={translate('portal','content_modal_title')} onClose={close}>
 {modal==='content'&&<ActionForm action="/commands/content" defaults={{...selected,published:selected.published?'1':'0'}} fields={[{name:'slug',label:translate('portal','content_field_page'),options:(d.page_slugs??[]).map((slug:string)=>({value:slug,label:contentPageLabel(slug,translate)})),required:true},{name:'title',label:translate('portal','content_field_title'),required:true},{name:'body',label:translate('portal','content_field_body'),type:'textarea',required:true},{name:'published',label:translate('portal','content_field_publication'),options:[{value:'1',label:translate('portal','content_published')},{value:'0',label:translate('portal','content_draft')}],required:true}]} onDone={close}/>}
 </Modal>}
 </>;
}
