import { usePage } from '@inertiajs/react';
import { HelpCircle } from 'lucide-react';
import { useT } from '../shared/i18n/t';

export type JargonTerm = 'mo' | 'am' | 'sm' | 'me' | 'tds';

/**
 * A short plain-words explanation of letters a dealer may not know. It opens on tap,
 * so it works on phones, where a hover tooltip never appears. Office screens don't show it.
 */
export default function JargonHint({ terms }: { terms: JargonTerm[] }) {
    const translate = useT();
    const { portal } = usePage<any>().props;
    if (portal === 'manage' || portal === 'sadmin') {
        return null;
    }

    return (
        <details className="jargon-hint">
            <summary>
                <HelpCircle size={14} aria-hidden="true" />
                <span>{translate('partner_hub', 'jargon_question', { terms: terms.map((term) => term.toUpperCase()).join(', ') })}</span>
            </summary>
            <dl>
                {terms.map((term) => (
                    <div key={term}>
                        <dt>{term.toUpperCase()}</dt>
                        <dd>{translate('partner_hub', `jargon_${term}`)}</dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}
