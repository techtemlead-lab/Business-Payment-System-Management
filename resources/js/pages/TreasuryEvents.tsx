import { Head } from '@inertiajs/react';
import Shell from '../components/Shell';
import { Row } from '../components/UI';

/** Placeholder until this page is built. The props are the real ones. */
export default function TreasuryEvents(props: Row) {
    return <Shell section={props.section}><Head title="TreasuryEvents" /><pre>{JSON.stringify(props, null, 2)}</pre></Shell>;
}
