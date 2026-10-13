import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowUpRight, LockKeyhole, Leaf } from 'lucide-react';
import { Flash } from '../components/UI';

export default function Auth({ mode, token = '', email = '' }: { mode: string; token?: string; email?: string }) {
    const form = useForm({ email, password: '', password_confirmation: '', token });
    const title = mode === 'login' ? 'Welcome back.' : mode === 'reset' ? 'A fresh start.' : 'Let’s get you back in.';

    return (
        <div className="auth-page">
            <Head title={title} />
            <section className="auth-story">
                <a className="brand" href="/">madina<small>NATURAL HEALTH CARE</small></a>
                <div>
                    <div className="eyebrow">YOUR PARTNER WORKSPACE</div>
                    <h1>Good things<br />grow <em>together.</em></h1>
                    <p>A more connected way to manage orders,<br />follow deliveries and grow your partnership.</p>
                    <div className="auth-orbit"><Leaf size={110} /></div>
                </div>
                <span>ROOTED IN NATURE. CONNECTED BY CARE.</span>
            </section>
            <main className="auth-main">
                <a href="/" className="text-link back-link">← Back to website</a>
                <div className="auth-form">
                    <div className="auth-icon"><LockKeyhole size={22} /></div>
                    <h1>{title}</h1>
                    <p>{mode === 'login' ? 'Sign in to your Madina account.' : mode === 'reset' ? 'Choose a strong password for your account.' : 'Enter your account email to request a reset link.'}</p>
                    <Flash />
                    <form className="action-form" onSubmit={event => {
                        event.preventDefault();
                        form.post(mode === 'login' ? '/login' : mode === 'forgot' ? '/forgot-password' : '/reset-password', {
                            onFinish: () => form.reset('password', 'password_confirmation'),
                        });
                    }}>
                        <label>Email address<input type="email" required autoComplete="username" value={form.data.email} onChange={event => form.setData('email', event.target.value)} /></label>
                        {mode !== 'forgot' && <label>Password<input type="password" required autoComplete={mode === 'login' ? 'current-password' : 'new-password'} value={form.data.password} onChange={event => form.setData('password', event.target.value)} /></label>}
                        {mode === 'reset' && <>
                            <p className="muted">At least 12 characters, with uppercase, lowercase and a number.</p>
                            <label>Confirm password<input type="password" required autoComplete="new-password" value={form.data.password_confirmation} onChange={event => form.setData('password_confirmation', event.target.value)} /></label>
                        </>}
                        {mode === 'login' && <Link className="text-link" href="/forgot-password">Forgot your password?</Link>}
                        <button className="button dark" disabled={form.processing}>
                            {form.processing ? 'Please wait…' : mode === 'login' ? 'Sign in' : mode === 'forgot' ? 'Send reset link' : 'Set password'}<ArrowUpRight size={18} />
                        </button>
                    </form>
                    <div className="auth-help">Not a partner yet? <a href="/contact">Contact the company →</a><p>Accounts are created and maintained by authorized company staff.</p></div>
                </div>
            </main>
        </div>
    );
}
