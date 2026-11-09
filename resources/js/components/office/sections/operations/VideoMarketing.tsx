import {Link} from '@inertiajs/react';
import {Row} from '../../../UI';
import {OperationsFrame,useOperationsPage,field,root,type Action} from '../../operationsShared';
/** The product videos shown on the home page. */
export default function VideoMarketing(props:Row){
    const page=useOperationsPage(props);
    const {section,videoManagedOnWebsite,videoSetting,videoProducts,translate,choice,reason,choose}=page;
    const forms:Action[]=[];
    if(section==='video-marketing'&&!videoManagedOnWebsite)forms.push({title:translate('portal','ops_save_homepage_video'),action:root+'/marketing/homepage-video',fields:[choice('enabled',translate('portal','ops_fld_show_video'),['1','0'],true),choose(videoProducts,'product_id',translate('portal','ops_fld_product_video'),false),field('title',translate('portal','ops_fld_section_title')),field('description',translate('portal','ops_fld_introduction'),'textarea',false),reason],defaults:{...videoSetting,enabled:videoSetting.enabled?'1':'0',title:videoSetting.title??translate('portal','ops_video_title_default')}});
    return <OperationsFrame page={page} forms={forms} top={<>
        {section==='video-marketing'&&videoManagedOnWebsite&&<section className="panel"><h2>{translate('website_admin','video_moved_title')}</h2><p>{translate('website_admin','video_moved_body')}</p><Link className="button dark" href="/website#website-video">{translate('website_admin','video_moved_link')}</Link></section>}
        {section==='video-marketing'&&!videoManagedOnWebsite&&<section className="panel"><h2>{translate('portal','ops_promote_video')}</h2><p>{translate('portal','ops_promote_video_body')} <Link className="text-link" href="/catalog">{translate('portal','product_management')}</Link>. {translate('portal','ops_promote_video_hide')}</p>{videoProducts.length===0&&<p className="muted">{translate('portal','ops_no_product_videos')}</p>}</section>}
    </>}/>;
}
