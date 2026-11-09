import { useEffect, useState } from 'react';
import { Empty, Modal, label } from './UI';
import PartnerPrescriberControls from './PartnerPrescriberControls';
import { useT } from '../shared/i18n/t';

type OfficeAccount = { id: number; name: string; role: string; status: string; enabled: boolean };

/**
 * On the employees page: decide per office account (MD, Chairman, any staff) who may prescribe
 * and dispense from company stock. Only the owners see this and can change it.
 */
export default function OfficePrescriberGrants() {
    const translate = useT();
    const [query, setQuery] = useState('');
    const [accounts, setAccounts] = useState<OfficeAccount[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [opened, setOpened] = useState<OfficeAccount | null>(null);
    const [reloads, setReloads] = useState(0);

    useEffect(() => {
        let active = true;
        const term = query.trim();
        const timer = window.setTimeout(() => {
            fetch(`/commands/prescribers/office-accounts?${new URLSearchParams(term ? { q: term } : {})}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
                .then((json: { data: OfficeAccount[] }) => {
                    if (active) {
                        setAccounts(json.data);
                        setFailed(false);
                    }
                })
                .catch(() => {
                    if (active) {
                        setFailed(true);
                    }
                });
        }, 250);
        return () => {
            active = false;
            window.clearTimeout(timer);
        };
    }, [query, reloads]);

    const close = () => {
        setOpened(null);
        setReloads((count) => count + 1);
    };

    return (
        <>
            <div className="rx-search">
                <input type="search" value={query} placeholder={translate('office_staff', 'prescribers_search')} aria-label={translate('office_staff', 'prescribers_search')} onChange={(e) => setQuery(e.target.value)} />
            </div>
            {failed && <p className="error" role="alert">{translate('prescription', 'verify_failed')}</p>}
            {!accounts && !failed && <p className="muted">{translate('prescription', 'verify_loading')}</p>}
            {accounts && accounts.length === 0 && <Empty line title={translate('prescription', 'office_empty')} />}
            {accounts && accounts.length > 0 && (
                <ul className="rx-list">
                    {accounts.map((account) => (
                        <li key={account.id} className="rx-card">
                            <div className="rx-card-head">
                                <div>
                                    <b>{account.name}</b>
                                    <small className="cell-sub">{label(account.role, translate)}</small>
                                </div>
                                <span className="rx-actions">
                                    <span className={`badge ${account.enabled ? 'active' : 'suspended'}`}>{translate('prescription', account.enabled ? 'verify_status_on' : 'verify_status_off')}</span>
                                    <button type="button" className="button small" onClick={() => setOpened(account)}>{translate('office_staff', 'prescriber_manage')}</button>
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {opened && (
                <Modal title={opened.name} variant="drawer" onClose={close}>
                    <PartnerPrescriberControls account={opened} />
                </Modal>
            )}
        </>
    );
}
