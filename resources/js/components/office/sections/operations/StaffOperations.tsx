import {Row} from '../../../UI';
import {OperationsFrame,useOperationsPage,field,root,type Action} from '../../operationsShared';
/** Staff areas and monthly targets. */
export default function StaffOperations(props:Row){
    const page=useOperationsPage(props);
    const {section,staff,translate,choose}=page;
    const forms:Action[]=[];
    if(section==='staff-operations') {
        forms.push({title:translate('portal','ops_assign_territory'),action:root+'/staff/territories',fields:[choose(staff.filter((s:Row)=>s.field_sales),'staff_id',translate('portal','ops_fld_sales_rep')),field('place_code',translate('portal','ops_fld_place_code'))]});
        forms.push({title:translate('portal','ops_set_monthly_target'),action:root+'/staff/targets',fields:[choose(staff.filter((s:Row)=>s.field_sales),'staff_id',translate('portal','ops_fld_sales_rep')),field('period_month',translate('portal','ops_fld_target_month'),'month'),field('target',translate('portal','ops_fld_sales_target'),'number'),field('bonus',translate('portal','ops_fld_bonus'),'number'),field('version',translate('portal','ops_fld_version_revise'),'number',false)]});
    }
    return <OperationsFrame page={page} forms={forms}/>;
}
