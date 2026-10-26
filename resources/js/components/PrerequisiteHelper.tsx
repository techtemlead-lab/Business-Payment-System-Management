import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ArrowRight } from 'lucide-react';
import { useT } from '../shared/i18n/t';

type Missing = { key: string; title: string; reason: string; href: string };
type ChecklistItem = { key: string; title: string; done: boolean; href: string };

export default function PrerequisiteHelper({
    guide,
    tourBlocked = [],
}: {
    guide?: {
        ready?: boolean;
        message?: string | null;
        missing?: Missing[];
        checklist?: ChecklistItem[];
    } | null;
    tourBlocked?: Array<{ key: string; title: string; href: string }>;
}) {
    const translate = useT();
    const missing = guide?.missing || [];
    const checklist = guide?.checklist || [];
    const showGuide = !guide?.ready && missing.length > 0;
    const showTourBlock = (tourBlocked?.length || 0) > 0;

    if (!showGuide && !showTourBlock && checklist.length === 0) {
        return null;
    }

    return (
        <div className="prereq-helper" data-tour="help-prereq">
            {showTourBlock && (
                <div className="prereq-banner tour-block" role="status">
                    <AlertTriangle size={18} />
                    <div>
                        <strong>{translate('portal', 'prereq_tour_blocked_title')}</strong>
                        <p>{translate('portal', 'prereq_tour_blocked_body', { step: tourBlocked[0].title })}</p>
                        <div className="prereq-actions">
                            {tourBlocked.map((item) => (
                                <Link key={item.key} className="button small dark" href={item.href}>
                                    {translate('portal', 'prereq_go_do', { step: item.title })} <ArrowRight size={14} />
                                </Link>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            {showGuide && (
                <div className="prereq-banner" role="status">
                    <AlertTriangle size={18} />
                    <div>
                        <strong>{translate('portal', 'prereq_title')}</strong>
                        <p>{guide?.message}</p>
                        <ol className="prereq-missing-list">
                            {missing.map((item) => (
                                <li key={item.key}>
                                    <span>{item.title} — {item.reason}</span>
                                    <Link className="button small" href={item.href}>
                                        {translate('portal', 'prereq_do_then_return')} <ArrowRight size={14} />
                                    </Link>
                                </li>
                            ))}
                        </ol>
                    </div>
                </div>
            )}

            {checklist.length > 0 && (
                <details className="prereq-checklist">
                    <summary>{translate('portal', 'prereq_checklist_title')}</summary>
                    <ul>
                        {checklist.map((item) => (
                            <li key={item.key} className={item.done ? 'done' : ''}>
                                {item.done ? <CheckCircle2 size={14} /> : <span className="prereq-dot" />}
                                <Link href={item.href}>{item.title}</Link>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}
