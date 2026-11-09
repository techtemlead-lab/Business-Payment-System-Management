import {Row} from '../../../UI';
import {OperationsFrame,useOperationsPage,field,root,type Action} from '../../operationsShared';
/** Staff salary contracts and the monthly salary run. */
export default function Payroll(props:Row){
    const page=useOperationsPage(props);
    const {section,translate,capabilities,reason,staffField}=page;
    const forms:Action[]=[];
    if(section==='payroll') {
        if(capabilities.includes('finance'))forms.push({title:translate('portal','ops_create_salary_contract'),action:root+'/payroll/contracts',fields:[staffField,field('effective_month',translate('portal','ops_fld_effective_month'),'month'),field('base_salary',translate('portal','ops_fld_base_salary'),'number'),field('allowance',translate('portal','ops_fld_allowance'),'number'),field('deduction',translate('portal','ops_fld_deduction'),'number'),reason]});
        forms.push({title:translate('portal','ops_prepare_payroll'),action:root+'/payroll/prepare',fields:[field('period_month',translate('portal','ops_fld_closed_salary_month'),'month')]});
    }
    return <OperationsFrame page={page} forms={forms} top={<>
        {section==='payroll'&&<p>{translate('portal','ops_payroll_hint')}</p>}
    </>}/>;
}
