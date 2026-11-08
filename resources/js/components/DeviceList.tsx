import { useEffect, useState } from 'react';
import { dateTime } from './UI';
import { useT } from '../shared/i18n/t';
import { jsonRequest, JsonRequestError } from '../shared/jsonRequest';

type Device = { handle: string; label: string; ip_address: string | null; last_active_at: string; current: boolean };

/** Where this account is signed in, with a log out button for each other device. */
export default function DeviceList() {
    const translate = useT();
    const [devices, setDevices] = useState<Device[] | null>(null);
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let current = true;
        jsonRequest<{ data: Device[] }>('/commands/devices')
            .then(({ data }) => {
                if (current) {
                    setDevices(data);
                }
            })
            .catch(() => {
                if (current) {
                    setError(translate('productivity', 'device_failed'));
                }
            });
        return () => {
            current = false;
        };
    }, [translate]);

    const logOut = (device: Device) => {
        setBusy(true);
        setError(null);
        jsonRequest<{ data: Device[] }>(`/commands/devices/${device.handle}/logout`, { method: 'POST', body: { current_password: password } })
            .then(({ data }) => {
                setDevices(data);
                setPassword('');
            })
            .catch((failure: unknown) => setError(translate('productivity', failure instanceof JsonRequestError && failure.status === 422 ? 'device_wrong_password' : 'device_failed')))
            .finally(() => setBusy(false));
    };

    const others = (devices ?? []).some((device) => !device.current);

    return (
        <section className="panel narrow-panel" aria-labelledby="device-list-title">
            <h2 id="device-list-title">{translate('productivity', 'device_title')}</h2>
            <p className="muted">{translate('productivity', 'device_hint')}</p>
            {devices !== null && devices.length === 0 && <p className="muted">{translate('productivity', 'device_empty')}</p>}
            <ul className="device-list">
                {(devices ?? []).map((device) => (
                    <li key={device.handle}>
                        <span>
                            <b>{device.label}</b>
                            {device.current && <small className="device-current">{translate('productivity', 'device_this_one')}</small>}
                            <small className="muted">{[device.ip_address, dateTime(device.last_active_at)].filter(Boolean).join(' · ')}</small>
                        </span>
                        {!device.current && <button type="button" className="button small" disabled={busy || password === ''} onClick={() => logOut(device)}>{translate('productivity', 'device_log_out')}</button>}
                    </li>
                ))}
            </ul>
            {others && (
                <label>
                    {translate('productivity', 'device_password')}
                    <input type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} />
                </label>
            )}
            {error && <p className="field-error" role="alert">{error}</p>}
        </section>
    );
}
