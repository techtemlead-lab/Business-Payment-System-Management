import {Row} from '../../../UI';
import {OperationsFrame,useOperationsPage,field,root,type Action} from '../../operationsShared';
/** Commission rules proposed for a future date. */
export default function PolicySchedules(props:Row){
    const page=useOperationsPage(props);
    const {section,policy,translate,choice,reason,partnerField}=page;
    const forms:Action[]=[];
    if(section==='policy-schedules')forms.push({title:translate('portal','ops_propose_commission_schedule'),action:root+'/policy-schedules',policy:true,policyRules:policy?.rules??{},fundingSources:policy?.funding_sources??[],fields:[choice('scope',translate('portal','ops_fld_schedule_scope'),['global','buyer','recipient']),{...partnerField,required:false,label:translate('portal','ops_fld_partner_custom')},{name:'rule_key',label:translate('portal','ops_fld_commission_rule'),required:true,options:Object.entries(policy?.rules??{}).map(([key,value]:[string,any])=>{const localized=translate('portal',`rule_name_${key}`);return{value:key,label:localized!==`rule_name_${key}`?localized:(value.name??key)};})},field('percentage',translate('portal','ops_fld_rate_pct'),'number'),field('effective_at',translate('portal','ops_fld_starts_dhaka'),'datetime-local'),field('expires_at',translate('portal','ops_fld_expires_dhaka'),'datetime-local',false),reason]});
    return <OperationsFrame page={page} forms={forms} top={<>
        {section==='policy-schedules'&&<p>{translate('portal','ops_policy_schedules_hint')}</p>}
    </>}/>;
}
