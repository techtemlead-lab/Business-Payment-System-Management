import { useEffect, useState } from 'react';
import { Stethoscope } from 'lucide-react';
import { Empty, label } from './UI';
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
    const [openId, setOpenId] = useState<number | null>(null);
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

    const toggle = (id: number) => {
        if (openId === id) {
            setOpenId(null);
            setReloads((count) => count + 1);
        } else {
            setOpenId(id);
        }
    };

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2 className="ops-row is-inline"><Stethoscope size={18} /> {translate('prescription', 'office_panel_title')}</h2>
                    <p className="muted">{translate('prescription', 'office_panel_hint')}</p>
                </div>
                <div className="rx-search">
                    <input type="search" value={query} placeholder={translate('prescription', 'office_search')} aria-label={translate('prescription', 'office_search')} onChange={(e) => setQuery(e.target.value)} />
                </div>
            </div>
            {failed && <p className="error" role="alert">{translate('prescription', 'verify_failed')}</p>}
            {!accounts && !failed && <p className="muted">{translate('prescription', 'verify_loading')}</p>}
            {accounts && accounts.length === 0 && <Empty title={translate('prescription', 'office_empty')} />}
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
                                    <button type="button" className="button small" onClick={() => toggle(account.id)}>{translate('prescription', openId === account.id ? 'office_hide' : 'office_manage')}</button>
                                </span>
                            </div>
                            {openId === account.id && <PartnerPrescriberControls account={account} />}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
