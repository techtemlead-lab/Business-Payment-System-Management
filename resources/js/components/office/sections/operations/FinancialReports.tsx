import {useState} from 'react';
import {Link, usePage} from '@inertiajs/react';
import {ReportGroupTabs,type ReportGroup} from '../../../OfficeHubTabs';
import {Row, label, DateInput} from '../../../UI';
import {useT} from '../../../../shared/i18n/t';
import {OperationsFrame,useOperationsPage,root} from '../../operationsShared';
function Reports({salesOnly=false,types=[],groups=[]}:{salesOnly?:boolean;types?:string[];groups?:ReportGroup[]}) {
    const translate=useT();
    const {url}=usePage();
    const [groupKey,setGroupKey]=useState(()=>new URLSearchParams(url.split('?')[1]??'').get('group')??'');
    const group=groups.find(item=>item.key===groupKey)??groups[0];
    const shownTypes=group?.reports??types;
    const [period,setPeriod]=useState('month');
    const dhaka=new Date(new Date().toLocaleString('en-US',{timeZone:'Asia/Dhaka'}));
    const iso=(date:Date)=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
    const [from,setFrom]=useState(iso(new Date(dhaka.getFullYear(),dhaka.getMonth(),1)));
    const [to,setTo]=useState(iso(dhaka));
    const reportName=(type:string)=>{const key=type.replaceAll('-','_');const fundsName=translate('funds',`ops_export_name_${key}`);if(fundsName!==`ops_export_name_${key}`){return fundsName;}const localized=translate('portal',`ops_report_${key}`);return localized!==`ops_report_${key}`?localized:label(key,translate);};
    const choosePeriod=(value:string)=>{
        setPeriod(value);
        if(value==='custom'){
            return;
        }
        if(value==='last_month'){
            setFrom(iso(new Date(dhaka.getFullYear(),dhaka.getMonth()-1,1)));
            setTo(iso(new Date(dhaka.getFullYear(),dhaka.getMonth(),0)));
            return;
        }
        const start=new Date(dhaka);
        if(value==='week')start.setDate(start.getDate()-((start.getDay()+6)%7));
        if(value==='month')start.setDate(1);
        setFrom(iso(start));
        setTo(iso(dhaka));
    };
    return <section className="panel"><h2>{translate('portal','ops_download_reports')}</h2><p>{translate('portal','ops_download_reports_hint')}</p>{group&&<ReportGroupTabs groups={groups} active={group.key} onSelect={setGroupKey}/>}{group?.links.map(link=><p key={link.href}><Link className="text-link" href={link.href}>{link.label}</Link></p>)}<form className="action-form" action={root+'/reports'} method="get">
        <label>{translate('portal','ops_report')}<select key={group?.key} required name="type">{shownTypes.map(t=><option key={t} value={t}>{reportName(t)}</option>)}</select></label>
        <label>{translate('portal','ops_period')}<select value={period} onChange={e=>choosePeriod(e.target.value)}><option value="day">{translate('portal','ops_period_day')}</option><option value="week">{translate('portal','ops_period_week')}</option><option value="month">{translate('portal','ops_period_month')}</option><option value="last_month">{translate('portal','ops_period_last_month')}</option><option value="custom">{translate('portal','ops_period_custom')}</option></select></label>
        <label>{translate('portal','date_from')}<DateInput name="from" required value={from} onChange={e=>{setFrom(e.target.value);setPeriod('custom');}}/></label><label>{translate('portal','ops_through')}<DateInput name="to" required min={from} value={to} onChange={e=>{setTo(e.target.value);setPeriod('custom');}}/></label>
        {!salesOnly&&<label>{translate('portal','ops_report_account_type')}<select name="account_type" defaultValue=""><option value="">{translate('portal','ops_report_account_all')}</option><option value="dealer">{translate('portal','ops_report_account_dealer')}</option><option value="sub_dealer">{translate('portal','ops_report_account_sub_dealer')}</option></select><small className="muted">{translate('portal','ops_report_account_hint')}</small></label>}
        <label>{translate('portal','ops_format')}<select name="format"><option value="xlsx">{translate('portal','ops_format_xlsx')}</option><option value="pdf">{translate('portal','ops_format_pdf')}</option></select></label><button className="button dark">{translate('portal','ops_download_report')}</button>
    </form></section>;
}
/** The downloadable report files. */
export default function FinancialReports(props:Row){
    const page=useOperationsPage(props);
    const {section,reportTypes,reportGroups,capabilities}=page;
    return <OperationsFrame page={page} below={<>
        {section==='financial-reports'&&<Reports types={reportTypes} groups={reportGroups} salesOnly={!capabilities.includes('finance')&&!capabilities.includes('reports')}/>}
    </>}/>;
}
