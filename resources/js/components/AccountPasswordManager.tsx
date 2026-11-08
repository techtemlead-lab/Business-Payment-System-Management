import React, { useEffect, useState, useCallback, useRef } from 'react';
import { useT } from '../shared/i18n/t';
import type { Row } from './UI';

type PasswordStatus = {
    managed_by: string;
    can_reveal: boolean;
    owner_only?: boolean;
    password: string | null;
};

type AccountPasswordManagerProps = {
    // Portal tables/editors pass loose Row maps; runtime always includes id.
    account: Row;
    canManage: boolean;
};

function getCsrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (meta) {
        return meta;
    }

    const xsrf = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
    if (!xsrf) {
        return '';
    }

    return decodeURIComponent(xsrf.split('=').slice(1).join('=') || '');
}

export default function AccountPasswordManager({ account, canManage }: AccountPasswordManagerProps) {
    const translate = useT();
    const translateRef = useRef(translate);
    translateRef.current = translate;
    const [status, setStatus] = useState<PasswordStatus | null>(null);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [successMessage, setSuccessMessage] = useState('');
    const [visible, setVisible] = useState(false);
    const [copied, setCopied] = useState(false);
    const [showCustom, setShowCustom] = useState(false);
    const [customPassword, setCustomPassword] = useState('');
    const [customVisible, setCustomVisible] = useState(false);

    const loadPasswordStatus = useCallback((signal?: AbortSignal) => {
        if (!canManage) {
            return;
        }

        setLoading(true);
        setError('');

        fetch(`/commands/accounts/${account.id}/password-status`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal,
        })
            .then(async (res) => {
                if (!res.ok) {
                    const data = await res.json().catch(() => ({}));
                    throw new Error(data.message || translateRef.current('portal', 'acct_password_load_failed'));
                }

                return res.json();
            })
            .then((json) => {
                if (signal?.aborted) {
                    return;
                }
                setStatus(json.data || null);
            })
            .catch((err) => {
                if (err?.name === 'AbortError' || signal?.aborted) {
                    return;
                }
                setError(err.message || translateRef.current('portal', 'acct_password_load_failed'));
            })
            .finally(() => {
                if (!signal?.aborted) {
                    setLoading(false);
                }
            });
    }, [account.id, canManage]);

    useEffect(() => {
        if (!canManage) {
            return;
        }

        const controller = new AbortController();
        loadPasswordStatus(controller.signal);

        return () => {
            controller.abort();
        };
    }, [canManage, loadPasswordStatus]);

    const copyToClipboard = async (text: string) => {
        if (!text) return;
        try {
            if (navigator?.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                const el = document.createElement('textarea');
                el.value = text;
                el.setAttribute('readonly', '');
                el.style.position = 'absolute';
                el.style.left = '-9999px';
                document.body.appendChild(el);
                el.select();
                document.execCommand('copy');
                document.body.removeChild(el);
            }
            setCopied(true);
            setTimeout(() => setCopied(false), 2500);
        } catch {
            setError(translate('portal', 'acct_password_copy_failed'));
        }
    };

    const handleReset = (plainOverride?: string) => {
        if (busy) return;
        setBusy(true);
        setError('');
        setSuccessMessage('');

        const payload: Record<string, string> = {};
        if (plainOverride && plainOverride.trim().length > 0) {
            payload.password = plainOverride.trim();
        }

        const csrf = getCsrfToken();
        fetch(`/commands/accounts/${account.id}/reset-password`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
                'X-XSRF-TOKEN': csrf,
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        })
            .then(async (res) => {
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    throw new Error(json.message || translate('portal', 'acct_password_reset_failed'));
                }
                setStatus(json.data || null);
                setVisible(true);
                setShowCustom(false);
                setCustomPassword('');
                setSuccessMessage(json.message || translate('portal', 'acct_password_reset_flash'));

                if (json.data?.password) {
                    copyToClipboard(json.data.password);
                }
            })
            .catch((err) => {
                setError(err.message || translate('portal', 'acct_password_reset_failed'));
            })
            .finally(() => {
                setBusy(false);
            });
    };

    // Owners only. The password is fetched when asked for, and each fetch is written to the audit log.
    const handleReveal = () => {
        if (busy) return;
        setBusy(true);
        setError('');

        const csrf = getCsrfToken();
        fetch(`/commands/accounts/${account.id}/reveal-password`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
                'X-XSRF-TOKEN': csrf,
            },
            credentials: 'same-origin',
        })
            .then(async (res) => {
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    throw new Error(json.message || translate('portal', 'acct_password_load_failed'));
                }
                setStatus(json.data || null);
                setVisible(true);
            })
            .catch((err) => {
                setError(err.message || translate('portal', 'acct_password_load_failed'));
            })
            .finally(() => {
                setBusy(false);
            });
    };

    if (!canManage) {
        return null;
    }

    const currentPlainPassword = status?.can_reveal && status.password ? status.password : null;
    const canRevealOnRequest = Boolean(status?.can_reveal && !status.password);
    const isReady = !loading && status !== null;

    return (
        <section className="panel pw-panel">
            <div className="pw-head hub-row is-between">
                <div className="hub-media">
                    <div className="hub-icon tone-info">
                        🔑
                    </div>
                    <div>
                        <h3 className="hub-title">
                            {translate('portal', 'acct_password_title')}
                        </h3>
                        <p className="hub-sub">
                            {translate('portal', 'acct_password_hint')}
                        </p>
                    </div>
                </div>

                <div className="hub-row">
                    {status?.owner_only ? null : currentPlainPassword || canRevealOnRequest ? (
                        <span className="badge tone-ok">
                            {translate('portal', 'acct_password_status_synced')}
                        </span>
                    ) : (
                        <span className="badge tone-warn">
                            {translate('portal', 'acct_password_status_unset')}
                        </span>
                    )}
                </div>
            </div>

            <div className="pw-body">
                {error && (
                    <div role="alert" className="error hub-alert">
                        <span>{error}</span>
                        <button
                            type="button"
                            onClick={() => setError('')}
                            className="hub-dismiss"
                        >
                            ✕
                        </button>
                    </div>
                )}

                {successMessage && (
                    <div role="status" className="success hub-alert">
                        <span>✓ {successMessage}</span>
                        <button
                            type="button"
                            onClick={() => setSuccessMessage('')}
                            className="hub-dismiss"
                        >
                            ✕
                        </button>
                    </div>
                )}

                {loading ? (
                    <div className="hub-loading">
                        <span>⏳ {translate('portal', 'loading') || 'Loading password status...'}</span>
                    </div>
                ) : currentPlainPassword ? (
                    <div className="hub-tile is-soft pw-box">
                        <div className="hub-row is-between">
                            <div>
                                <span className="hub-label pw-secret-label">
                                    {translate('portal', 'acct_password_current')}
                                </span>
                                <div className="hub-row">
                                    <strong className={`pw-secret${visible ? '' : ' is-masked'}`}>
                                        {visible ? currentPlainPassword : '••••••••••••••••'}
                                    </strong>
                                </div>
                            </div>

                            <div className="hub-row">
                                {/* Show/Hide Toggle */}
                                <button
                                    type="button"
                                    className="button"
                                    onClick={() => setVisible((prev) => !prev)}
                                >
                                    <span>{visible ? '👁️‍🗨️' : '👁️'}</span>
                                    <span>
                                        {visible
                                            ? translate('portal', 'acct_password_hide')
                                            : translate('portal', 'acct_password_show')}
                                    </span>
                                </button>

                                {/* Dedicated One-Click Copy Button */}
                                <button
                                    type="button"
                                    onClick={() => copyToClipboard(currentPlainPassword)}
                                    className={`button dark${copied ? ' pw-copied' : ''}`}
                                >
                                    <span>{copied ? '✓' : '📋'}</span>
                                    <span>
                                        {copied
                                            ? translate('portal', 'acct_password_copied')
                                            : translate('portal', 'acct_password_copy')}
                                    </span>
                                </button>
                            </div>
                        </div>

                        <p className="pw-note">
                            <span>ℹ️</span>
                            <span>{translate('portal', 'acct_password_instructions')}</span>
                        </p>
                    </div>
                ) : canRevealOnRequest ? (
                    <div className="hub-tile is-soft pw-box">
                        <div className="hub-row is-between">
                            <div>
                                <span className="hub-label pw-secret-label">
                                    {translate('portal', 'acct_password_current')}
                                </span>
                                <div className="hub-row">
                                    <strong className="pw-secret is-masked">••••••••••••••••</strong>
                                </div>
                            </div>
                            <button type="button" className="button" disabled={busy} onClick={handleReveal}>
                                <span>👁️</span>
                                <span>{translate('portal', 'acct_password_reveal')}</span>
                            </button>
                        </div>
                        <p className="pw-note">
                            <span>ℹ️</span>
                            <span>{translate('portal', 'acct_password_reveal_note')}</span>
                        </p>
                    </div>
                ) : (
                    <div className="hub-empty pw-box">
                        <p>
                            {status?.owner_only
                                ? <>🔒 {translate('portal', 'acct_password_owner_only')}</>
                                : <>⚠️ {translate('portal', 'acct_password_self_changed')}</>}
                        </p>
                    </div>
                )}

                {/* Reset Actions Section */}
                <div className="pw-actions hub-row is-between">
                    <div className="hub-row">
                        {/* Auto-Generate Button */}
                        <button
                            type="button"
                            className="button dark"
                            disabled={busy}
                            onClick={() => handleReset()}
                        >
                            <span>{busy ? '⏳' : '⚡'}</span>
                            <span>
                                {busy
                                    ? translate('portal', 'acct_password_resetting')
                                    : translate('portal', 'acct_password_reset')}
                            </span>
                        </button>

                        {/* Only owners may choose a password; for other staff it is sent to the partner by SMS. */}
                        {!status?.owner_only && <button
                            type="button"
                            className="button"
                            disabled={busy}
                            onClick={() => setShowCustom((prev) => !prev)}
                        >
                            <span>✏️</span>
                            <span>{translate('portal', 'acct_password_reset_custom')}</span>
                        </button>}
                    </div>

                    <button
                        type="button"
                        onClick={() => loadPasswordStatus()}
                        disabled={loading || busy}
                        className="hub-quiet"
                    >
                        🔄 {translate('portal', 'refresh') || 'Refresh'}
                    </button>
                </div>

                {/* Custom Password Input Area */}
                {showCustom && !status?.owner_only && (
                    <div className="pw-custom">
                        <label>
                            {translate('portal', 'acct_password_custom_label')}
                        </label>
                        <div className="hub-row">
                            <div className="pw-input-wrap">
                                <input
                                    type={customVisible ? 'text' : 'password'}
                                    value={customPassword}
                                    onChange={(e) => setCustomPassword(e.target.value)}
                                    placeholder={translate('portal', 'acct_password_custom_ph')}
                                />
                                <button
                                    type="button"
                                    onClick={() => setCustomVisible((prev) => !prev)}
                                    className="pw-eye"
                                >
                                    {customVisible ? '👁️‍🗨️' : '👁️'}
                                </button>
                            </div>

                            <button
                                type="button"
                                className="button dark"
                                disabled={busy || customPassword.trim().length < 8}
                                onClick={() => handleReset(customPassword)}
                            >
                                {busy
                                    ? translate('portal', 'acct_password_resetting')
                                    : translate('portal', 'acct_password_custom_submit')}
                            </button>

                            <button
                                type="button"
                                className="button"
                                onClick={() => {
                                    setShowCustom(false);
                                    setCustomPassword('');
                                }}
                            >
                                {translate('portal', 'cancel') || 'Cancel'}
                            </button>
                        </div>
                        <small className="hub-hint">
                            {translate('portal', 'acct_password_custom_ph')}
                        </small>
                    </div>
                )}
            </div>
        </section>
    );
}
