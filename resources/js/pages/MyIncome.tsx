import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Shell from '../components/Shell';
import IncomeByPart, { type IncomeData } from '../components/income/IncomeByPart';
import { useT } from '../shared/i18n/t';

/** Every income of a partner as its own part: how much, on which day, and a statement for each. */
export default function MyIncome({ income }: { income: IncomeData }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;

    return (
        <Shell section="my-income">
            <Head title={translate('income', 'title')} />
            <div className="mm-page">
                <header className="mm-partner-head">
                    <Link className="text-link" href={`${base}/my-money`}><ArrowLeft size={16} aria-hidden="true" /> {translate('income', 'back')}</Link>
                    <h2>{translate('income', 'title')}</h2>
                    <p>{translate('income', 'intro')}</p>
                </header>
                <IncomeByPart income={income} statementBase={`${base}/my-income`} orderHref={(orderId) => `${base}/orders/${orderId}`} />
            </div>
        </Shell>
    );
}
