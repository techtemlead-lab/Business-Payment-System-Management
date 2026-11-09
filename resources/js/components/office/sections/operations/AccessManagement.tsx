import StaffAccessEditor from '../../../StaffAccessEditor';
import {Row} from '../../../UI';
import {OperationsFrame,useOperationsPage} from '../../operationsShared';
/** What each staff member may open and do. */
export default function AccessManagement(props:Row){
    const page=useOperationsPage(props);
    const {section,staff,permissionModules,staffAccess,customRoles,rolePresets}=page;
    return <OperationsFrame page={page} top={<>
        {section==='access-management'&&<StaffAccessEditor modules={permissionModules} staff={staffAccess} roles={customRoles} presets={rolePresets}/>}
    </>}/>;
}
