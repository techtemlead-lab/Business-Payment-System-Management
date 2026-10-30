import React, { useEffect, useState, useCallback, useRef } from 'react';
import { useT } from '../shared/i18n/t';
import type { Row } from './UI';

type PasswordStatus = {
    managed_by: string;
    can_reveal: boolean;
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
                    throw new Error(data.message || 'Failed to load password status');
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
            setError('Could not copy to clipboard.');
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

    if (!canManage) {
        return null;
    }

    const currentPlainPassword = status?.can_reveal && status.password ? status.password : null;
    const isReady = !loading && status !== null;

    return (
        <section
            className="panel"
            style={{
                marginTop: 20,
                border: '1px solid #e2e8f0',
                borderRadius: 12,
                overflow: 'hidden',
                boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05)',
                background: '#ffffff',
            }}
        >
            <div
                style={{
                    padding: '16px 20px',
                    borderBottom: '1px solid #f1f5f9',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    flexWrap: 'wrap',
                    gap: 12,
                    background: '#f8fafc',
                }}
            >
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <div
                        style={{
                            width: 38,
                            height: 38,
                            borderRadius: 10,
                            background: '#eff6ff',
                            color: '#2563eb',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontSize: 18,
                        }}
                    >
                        🔑
                    </div>
                    <div>
                        <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>
                            {translate('portal', 'acct_password_title')}
                        </h3>
                        <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>
                            {translate('portal', 'acct_password_hint')}
                        </p>
                    </div>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    {currentPlainPassword ? (
                        <span
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                padding: '4px 10px',
                                borderRadius: 9999,
                                fontSize: 12,
                                fontWeight: 600,
                                background: '#ecfdf5',
                                color: '#065f46',
                                border: '1px solid #a7f3d0',
                            }}
                        >
                            <span
                                style={{
                                    width: 7,
                                    height: 7,
                                    borderRadius: '50%',
                                    background: '#10b981',
                                }}
                            />
                            {translate('portal', 'acct_password_status_synced')}
                        </span>
                    ) : (
                        <span
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                padding: '4px 10px',
                                borderRadius: 9999,
                                fontSize: 12,
                                fontWeight: 600,
                                background: '#fffbeb',
                                color: '#92400e',
                                border: '1px solid #fde68a',
                            }}
                        >
                            <span
                                style={{
                                    width: 7,
                                    height: 7,
                                    borderRadius: '50%',
                                    background: '#f59e0b',
                                }}
                            />
                            {translate('portal', 'acct_password_status_unset')}
                        </span>
                    )}
                </div>
            </div>

            <div style={{ padding: '20px' }}>
                {error && (
                    <div
                        role="alert"
                        style={{
                            marginBottom: 16,
                            padding: '10px 14px',
                            background: '#fef2f2',
                            color: '#991b1b',
                            borderRadius: 8,
                            border: '1px solid #fecaca',
                            fontSize: 13,
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                        }}
                    >
                        <span>{error}</span>
                        <button
                            type="button"
                            onClick={() => setError('')}
                            style={{
                                background: 'transparent',
                                border: 'none',
                                color: '#991b1b',
                                cursor: 'pointer',
                                fontWeight: 700,
                            }}
                        >
                            ✕
                        </button>
                    </div>
                )}

                {successMessage && (
                    <div
                        role="status"
                        style={{
                            marginBottom: 16,
                            padding: '10px 14px',
                            background: '#ecfdf5',
                            color: '#065f46',
                            borderRadius: 8,
                            border: '1px solid #a7f3d0',
                            fontSize: 13,
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                        }}
                    >
                        <span>✓ {successMessage}</span>
                        <button
                            type="button"
                            onClick={() => setSuccessMessage('')}
                            style={{
                                background: 'transparent',
                                border: 'none',
                                color: '#065f46',
                                cursor: 'pointer',
                                fontWeight: 700,
                            }}
                        >
                            ✕
                        </button>
                    </div>
                )}

                {loading ? (
                    <div style={{ padding: '20px 0', textAlign: 'center', color: '#64748b' }}>
                        <span style={{ fontSize: 13 }}>⏳ {translate('portal', 'loading') || 'Loading password status...'}</span>
                    </div>
                ) : currentPlainPassword ? (
                    <div
                        style={{
                            background: '#f8fafc',
                            border: '1px solid #e2e8f0',
                            borderRadius: 10,
                            padding: '16px 18px',
                            marginBottom: 18,
                        }}
                    >
                        <div
                            style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                                flexWrap: 'wrap',
                                gap: 12,
                            }}
                        >
                            <div>
                                <span
                                    style={{
                                        fontSize: 11,
                                        fontWeight: 700,
                                        textTransform: 'uppercase',
                                        letterSpacing: '0.05em',
                                        color: '#64748b',
                                        display: 'block',
                                        marginBottom: 6,
                                    }}
                                >
                                    {translate('portal', 'acct_password_current')}
                                </span>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                    <strong
                                        style={{
                                            fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                                            fontSize: 18,
                                            letterSpacing: visible ? '0.05em' : '0.2em',
                                            color: '#0f172a',
                                            userSelect: 'all',
                                            background: '#ffffff',
                                            padding: '6px 12px',
                                            borderRadius: 6,
                                            border: '1px solid #cbd5e1',
                                            minWidth: 180,
                                            display: 'inline-block',
                                        }}
                                    >
                                        {visible ? currentPlainPassword : '••••••••••••••••'}
                                    </strong>
                                </div>
                            </div>

                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                                {/* Show/Hide Toggle */}
                                <button
                                    type="button"
                                    className="button"
                                    onClick={() => setVisible((prev) => !prev)}
                                    style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: 6,
                                        fontSize: 13,
                                        padding: '7px 14px',
                                        borderRadius: 8,
                                        cursor: 'pointer',
                                    }}
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
                                    style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: 6,
                                        fontSize: 13,
                                        fontWeight: 700,
                                        padding: '7px 16px',
                                        borderRadius: 8,
                                        cursor: 'pointer',
                                        transition: 'all 0.2s ease',
                                        background: copied ? '#059669' : '#0f172a',
                                        color: '#ffffff',
                                        border: copied ? '1px solid #047857' : '1px solid #0f172a',
                                        boxShadow: copied ? '0 0 0 3px rgba(16, 185, 129, 0.2)' : 'none',
                                    }}
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

                        <p
                            style={{
                                margin: '14px 0 0 0',
                                fontSize: 12,
                                color: '#475569',
                                borderTop: '1px dashed #e2e8f0',
                                paddingTop: 10,
                                display: 'flex',
                                alignItems: 'center',
                                gap: 6,
                            }}
                        >
                            <span style={{ fontSize: 14 }}>ℹ️</span>
                            <span>{translate('portal', 'acct_password_instructions')}</span>
                        </p>
                    </div>
                ) : (
                    <div
                        style={{
                            background: '#f8fafc',
                            border: '1px dashed #cbd5e1',
                            borderRadius: 10,
                            padding: '16px 18px',
                            marginBottom: 18,
                            color: '#64748b',
                            fontSize: 13,
                        }}
                    >
                        <p style={{ margin: 0, lineHeight: 1.5 }}>
                            ⚠️ {translate('portal', 'acct_password_self_changed')}
                        </p>
                    </div>
                )}

                {/* Reset Actions Section */}
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        flexWrap: 'wrap',
                        gap: 12,
                        borderTop: '1px solid #f1f5f9',
                        paddingTop: 16,
                    }}
                >
                    <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                        {/* Auto-Generate Button */}
                        <button
                            type="button"
                            className="button dark"
                            disabled={busy}
                            onClick={() => handleReset()}
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                padding: '8px 16px',
                                borderRadius: 8,
                                fontWeight: 700,
                                fontSize: 13,
                                cursor: busy ? 'not-allowed' : 'pointer',
                            }}
                        >
                            <span>{busy ? '⏳' : '⚡'}</span>
                            <span>
                                {busy
                                    ? translate('portal', 'acct_password_resetting')
                                    : translate('portal', 'acct_password_reset')}
                            </span>
                        </button>

                        {/* Custom Password Toggle Button */}
                        <button
                            type="button"
                            className="button"
                            disabled={busy}
                            onClick={() => setShowCustom((prev) => !prev)}
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                padding: '8px 14px',
                                borderRadius: 8,
                                fontSize: 13,
                                cursor: 'pointer',
                            }}
                        >
                            <span>✏️</span>
                            <span>{translate('portal', 'acct_password_reset_custom')}</span>
                        </button>
                    </div>

                    <button
                        type="button"
                        onClick={() => loadPasswordStatus()}
                        disabled={loading || busy}
                        style={{
                            background: 'none',
                            border: 'none',
                            color: '#64748b',
                            fontSize: 12,
                            cursor: 'pointer',
                            textDecoration: 'underline',
                        }}
                    >
                        🔄 {translate('portal', 'refresh') || 'Refresh'}
                    </button>
                </div>

                {/* Custom Password Input Area */}
                {showCustom && (
                    <div
                        style={{
                            marginTop: 14,
                            padding: '14px 16px',
                            background: '#f1f5f9',
                            borderRadius: 8,
                            border: '1px solid #e2e8f0',
                        }}
                    >
                        <label
                            style={{
                                display: 'block',
                                fontSize: 12,
                                fontWeight: 700,
                                color: '#334155',
                                marginBottom: 6,
                            }}
                        >
                            {translate('portal', 'acct_password_custom_label')}
                        </label>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                            <div style={{ position: 'relative', flex: '1 1 240px' }}>
                                <input
                                    type={customVisible ? 'text' : 'password'}
                                    value={customPassword}
                                    onChange={(e) => setCustomPassword(e.target.value)}
                                    placeholder={translate('portal', 'acct_password_custom_ph')}
                                    style={{
                                        width: '100%',
                                        padding: '8px 36px 8px 12px',
                                        borderRadius: 6,
                                        border: '1px solid #cbd5e1',
                                        fontSize: 13,
                                        fontFamily: 'ui-monospace, monospace',
                                    }}
                                />
                                <button
                                    type="button"
                                    onClick={() => setCustomVisible((prev) => !prev)}
                                    style={{
                                        position: 'absolute',
                                        right: 8,
                                        top: '50%',
                                        transform: 'translateY(-50%)',
                                        background: 'none',
                                        border: 'none',
                                        cursor: 'pointer',
                                        fontSize: 14,
                                        padding: 2,
                                    }}
                                >
                                    {customVisible ? '👁️‍🗨️' : '👁️'}
                                </button>
                            </div>

                            <button
                                type="button"
                                className="button dark"
                                disabled={busy || customPassword.trim().length < 8}
                                onClick={() => handleReset(customPassword)}
                                style={{
                                    padding: '8px 16px',
                                    borderRadius: 6,
                                    fontWeight: 700,
                                    fontSize: 13,
                                }}
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
                                style={{
                                    padding: '8px 12px',
                                    borderRadius: 6,
                                    fontSize: 13,
                                }}
                            >
                                {translate('portal', 'cancel') || 'Cancel'}
                            </button>
                        </div>
                        <small style={{ display: 'block', marginTop: 6, color: '#64748b', fontSize: 11 }}>
                            {translate('portal', 'acct_password_custom_ph')}
                        </small>
                    </div>
                )}
            </div>
        </section>
    );
}
