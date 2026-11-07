import { useCallback, useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Bell, BellOff } from 'lucide-react';
import { useT } from '../shared/i18n/t';

/** The push worker gets its own scope so it never replaces the site's offline worker (/sw.js). */
const WORKER_URL = '/push-sw.js';
const WORKER_SCOPE = '/push-notifications/';

type PushState = 'checking' | 'off' | 'on' | 'blocked' | 'busy' | 'failed';

function supported(): boolean {
    return typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
}

function csrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (meta) {
        return meta;
    }
    const xsrf = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    return xsrf ? decodeURIComponent(xsrf.split('=').slice(1).join('=')) : '';
}

function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
    const padded = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

/** Waits until the push worker is active; pushManager.subscribe needs an active worker. */
function activeWorker(registration: ServiceWorkerRegistration): Promise<ServiceWorkerRegistration> {
    if (registration.active) {
        return Promise.resolve(registration);
    }
    const worker = registration.installing ?? registration.waiting;

    return new Promise((resolve, reject) => {
        if (!worker) {
            reject(new Error('No push worker'));

            return;
        }
        worker.addEventListener('statechange', () => {
            if (worker.state === 'activated') {
                resolve(registration);
            } else if (worker.state === 'redundant') {
                reject(new Error('Push worker failed'));
            }
        });
    });
}

async function send(method: 'POST' | 'DELETE', body: Record<string, unknown>): Promise<void> {
    const token = csrfToken();
    const response = await fetch('/commands/push-subscriptions', {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token,
            'X-XSRF-TOKEN': token,
        },
        body: JSON.stringify(body),
    });
    if (!response.ok) {
        throw new Error(`Push subscription request failed (${response.status})`);
    }
}

/**
 * "Turn on notifications" for the notifications page: asks the browser for permission,
 * subscribes this phone/browser and saves it for the signed-in user. Hidden when the
 * server has no VAPID keys or the browser cannot do push.
 */
export default function PushOptIn() {
    const translate = useT();
    const publicKey = (usePage<{ webPushKey?: string | null }>().props.webPushKey ?? null) as string | null;
    const [state, setState] = useState<PushState>('checking');
    const available = Boolean(publicKey) && supported();

    useEffect(() => {
        if (!available) {
            return;
        }
        if (Notification.permission === 'denied') {
            setState('blocked');

            return;
        }
        navigator.serviceWorker
            .getRegistration(WORKER_SCOPE)
            .then((registration) => registration?.pushManager.getSubscription() ?? null)
            .then((subscription) => setState(subscription ? 'on' : 'off'))
            .catch(() => setState('off'));
    }, [available]);

    const turnOn = useCallback(async () => {
        if (!publicKey) {
            return;
        }
        setState('busy');
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                setState(permission === 'denied' ? 'blocked' : 'off');

                return;
            }
            const registration = await activeWorker(await navigator.serviceWorker.register(WORKER_URL, { scope: WORKER_SCOPE }));
            const subscription =
                (await registration.pushManager.getSubscription()) ??
                (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) }));
            const json = subscription.toJSON();
            const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings ?? ['aesgcm'];
            await send('POST', {
                endpoint: json.endpoint,
                keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
                content_encoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
            });
            setState('on');
        } catch {
            setState('failed');
        }
    }, [publicKey]);

    const turnOff = useCallback(async () => {
        setState('busy');
        try {
            const registration = await navigator.serviceWorker.getRegistration(WORKER_SCOPE);
            const subscription = await registration?.pushManager.getSubscription();
            if (subscription) {
                await send('DELETE', { endpoint: subscription.endpoint });
                await subscription.unsubscribe();
            }
            setState('off');
        } catch {
            setState('failed');
        }
    }, []);

    if (!available || state === 'checking') {
        return null;
    }

    return (
        <div className="push-opt-in">
            {state === 'on' ? (
                <button type="button" className="button small light" onClick={turnOff}>
                    <BellOff size={16} aria-hidden="true" /> {translate('portal', 'push_turn_off')}
                </button>
            ) : (
                <button type="button" className="button small" onClick={turnOn} disabled={state === 'busy' || state === 'blocked'}>
                    <Bell size={16} aria-hidden="true" /> {translate('portal', 'push_turn_on')}
                </button>
            )}
            <small className="muted" role="status">
                {state === 'on' && translate('portal', 'push_on_hint')}
                {state === 'off' && translate('portal', 'push_off_hint')}
                {state === 'busy' && translate('portal', 'push_working')}
                {state === 'blocked' && translate('portal', 'push_blocked')}
                {state === 'failed' && translate('portal', 'push_failed')}
            </small>
        </div>
    );
}
