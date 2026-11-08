import { Empty, dateTime } from './UI';
import { useT } from '../shared/i18n/t';

type Translate = ReturnType<typeof useT>;

export type AccessLogRow = {
    id: number;
    patient: string | null;
    patient_id: number;
    viewer: string | null;
    is_self: boolean;
    action: string;
    at: string | null;
};

/** Who opened a patient record, newest first. Only the patient's own prescriber gets these rows. */
export function PatientAccessLogList({ rows, showPatient, translate }: { rows: AccessLogRow[]; showPatient: boolean; translate: Translate }) {
    if (rows.length === 0) {
        return <Empty title={translate('clinic_records', 'access_log_empty')} />;
    }

    return (
        <div className="table-wrap">
            <table className="rx-table">
                <thead>
                    <tr>
                        <th>{translate('clinic_records', 'access_when')}</th>
                        {showPatient && <th>{translate('prescription', 'rx_patient')}</th>}
                        <th>{translate('clinic_records', 'access_who')}</th>
                        <th>{translate('clinic_records', 'access_what')}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id}>
                            <td>{row.at ? dateTime(row.at) : ''}</td>
                            {showPatient && <td>{row.patient}</td>}
                            <td>{row.is_self ? translate('clinic_records', 'access_you') : row.viewer}</td>
                            <td className={row.action === 'blocked' ? 'is-bad' : undefined}>{translate('clinic_records', `access_action_${row.action}`)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
