import { Head } from '@inertiajs/react';
import Shell from '../components/Shell';
import { Row } from '../components/UI';

/** Placeholder until this page is built. The props are the real ones. */
export default function TreasuryOrder(props: Row) {
    return <Shell section={props.section}><Head title="TreasuryOrder" /><pre>{JSON.stringify(props, null, 2)}</pre></Shell>;
}
