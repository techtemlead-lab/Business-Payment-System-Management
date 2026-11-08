import React from 'react';
import { ListChecks } from 'lucide-react';
import { money, number } from '../UI';
import { useT } from '../../shared/i18n/t';

export interface NextStep {
    kind: string;
    values: Record<string, string | number>;
}

/** What to do next toward the salary target and the next rank, in plain words. */
export default function NextStepsCard({ steps }: { steps: NextStep[] }) {
    const translate = useT();
    if (steps.length === 0) {
        return null;
    }
    const line = (step: NextStep): string => {
        const values: Record<string, string> = {};
        Object.entries(step.values).forEach(([key, value]) => {
            if (key === 'rank' || key === 'required') {
                values[key] = translate('portal', `net_rank_${value}`);
            } else if (key === 'left' && step.kind === 'rank_team') {
                values[key] = number(value);
            } else if (key === 'left' || key === 'target') {
                values[key] = money(value);
            } else {
                values[key] = number(value);
            }
        });
        return translate('dealer_tools', `step_${step.kind}`, values);
    };
    return (
        <section className="ph-card" data-testid="next-steps" aria-label={translate('dealer_tools', 'steps_title')}>
            <div className="ph-card-head">
                <div>
                    <h3>{translate('dealer_tools', 'steps_title')}</h3>
                    <p>{translate('dealer_tools', 'steps_hint')}</p>
                </div>
                <ListChecks size={18} />
            </div>
            <ol className="ptl-list-plain">
                {steps.map((step) => <li key={step.kind}>{line(step)}</li>)}
            </ol>
        </section>
    );
}
