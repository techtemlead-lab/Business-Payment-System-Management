import {Link} from '@inertiajs/react';
import {Row} from '../../../UI';
import {randomUuid} from '../../../../shared/randomUuid';
import {OperationsFrame,useOperationsPage,field,root,reserveProgramLabel,type Action} from '../../operationsShared';
/** Requests to pay money out of the reserve funds. */
export default function ReservePayouts(props:Row){
    const page=useOperationsPage(props);
    const {section,translate,portalBase,reason,partnerField}=page;
    const forms:Action[]=[];
    if(section==='reserve-payouts')forms.push({title:translate('portal','ops_request_reserve'),action:root+'/reserves',fields:[{name:'program',label:translate('portal','ops_fld_reserve_program'),required:true,options:['additional_rank','bike','car'].map(value=>({value,label:reserveProgramLabel(value,translate)}))},{...partnerField,required:false},field('amount',translate('portal','ops_fld_approved_amount'),'number'),reason],defaults:{request_key:randomUuid()}});
    return <OperationsFrame page={page} forms={forms} top={<>
        {section==='reserve-payouts'&&<p>{translate('wave3_ops','reserve_hint')} <Link className="text-link" href={`${portalBase??''}/treasury/funds/marketing`}>{translate('wave3_ops','marketing_moved_link')}</Link></p>}
    </>}/>;
}
