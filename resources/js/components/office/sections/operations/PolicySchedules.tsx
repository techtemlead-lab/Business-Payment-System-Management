import {Row} from '../../../UI';
import {OperationsFrame,useOperationsPage,field,root,type Action} from '../../operationsShared';
import SpecialRatesPage from '../../../specialrates/SpecialRatesPage';
/** One person's special rate, and shared rates proposed for a future date (the older form, kept for that). */
export default function PolicySchedules(props:Row){
    const page=useOperationsPage(props);
    const {section,policy,translate,choice,reason,partnerField,hub}=page;
    const forms:Action[]=[];
    if(section==='policy-schedules')forms.push({title:translate('special_rates','legacy_form'),action:root+'/policy-schedules',policy:true,policyRules:policy?.rules??{},fundingSources:policy?.funding_sources??[],fields:[choice('scope',translate('portal','ops_fld_schedule_scope'),['global','buyer','recipient']),{...partnerField,required:false,label:translate('portal','ops_fld_partner_custom')},{name:'rule_key',label:translate('portal','ops_fld_commission_rule'),required:true,options:Object.entries(policy?.rules??{}).map(([key,value]:[string,any])=>{const localized=translate('portal',`rule_name_${key}`);return{value:key,label:localized!==`rule_name_${key}`?localized:(value.name??key)};})},field('percentage',translate('portal','ops_fld_rate_pct'),'number'),field('effective_at',translate('portal','ops_fld_starts_dhaka'),'datetime-local'),field('expires_at',translate('portal','ops_fld_expires_dhaka'),'datetime-local',false),reason]});
    // The requests are listed by the new page itself, so the generic table is not drawn a second time.
    return <OperationsFrame page={{...page,tables:[]}} forms={forms} top={<SpecialRatesPage special={props.special} partners={props.partners??[]} partnerSearch={props.partnerSearch??''} tables={props.tables??[]} hub={hub}/>}/>;
}
