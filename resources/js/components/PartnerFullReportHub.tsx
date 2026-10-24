import { FileBarChart2 } from 'lucide-react';
import { Badge, money, date, Table, type Row } from './UI';

export default function PartnerFullReportHub({ report }: { report: Row }) {
    const summary = report?.summary ?? {};
    const commissions: Row[] = report?.commissions ?? [];
    const orders: Row[] = report?.orders ?? [];
    const teamActivity: Row[] = report?.team_activity ?? [];
    const teamMembers: Row[] = report?.team_members ?? [];

    return (
        <div className="partner-full-report">
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2 style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                            <FileBarChart2 size={18} /> Full report
                        </h2>
                        <p className="muted">Orders, commission by ledger head, payments, and team activity in one place.</p>
                    </div>
                </div>
                <div className="metric-grid" style={{ gridTemplateColumns: 'repeat(4, minmax(0, 1fr))' }}>
                    <div className="metric"><span>Total ordered</span><strong>{money(summary.ordered)}</strong><small>{summary.orders_count ?? 0} orders</small></div>
                    <div className="metric"><span>Payments received</span><strong>{money(summary.paid)}</strong></div>
                    <div className="metric"><span>Outstanding due</span><strong>{money(summary.due)}</strong></div>
                    <div className="metric"><span>Commission earned</span><strong>{money(summary.commission)}</strong></div>
                </div>
            </section>

            <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>Commission by head</h2>
                        <p>Own sale, referral, area dealer, salary, royalty, incentives and more.</p>
                    </div>
                </div>
                <Table
                    rows={commissions}
                    columns={[
                        { key: 'label', title: 'Head' },
                        { key: 'entries', title: 'Entries' },
                        { key: 'amount', title: 'Amount', render: (r) => money(r.amount) },
                    ]}
                    empty="No commission posted yet"
                    emptyBody="Earnings appear here after orders are delivered and commissions post."
                />
            </section>

            <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>Order & payment records</h2>
                        <p>Order value, verified payment, remaining due, and payment channel.</p>
                    </div>
                </div>
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Ordered</th>
                                <th>Paid</th>
                                <th>Due</th>
                                <th>Payments</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.length === 0 && (
                                <tr><td colSpan={6} className="muted">No orders yet.</td></tr>
                            )}
                            {orders.map((order) => (
                                <tr key={order.id}>
                                    <td>
                                        <b>{order.number}</b>
                                        <small className="cell-sub">{date(order.created_at)}</small>
                                    </td>
                                    <td><Badge status={order.status} /></td>
                                    <td>{money(order.total)}</td>
                                    <td>{money(order.paid)}</td>
                                    <td>{money(order.due)}</td>
                                    <td>
                                        {(order.payments ?? []).length === 0 && <span className="muted">—</span>}
                                        {(order.payments ?? []).map((payment: Row, index: number) => (
                                            <div key={`${order.id}-${index}`} style={{ fontSize: 11, marginBottom: 4 }}>
                                                <b>{payment.channel}</b> {money(payment.amount)} · {payment.status}
                                                {payment.reference ? <small className="cell-sub">{payment.reference}</small> : null}
                                            </div>
                                        ))}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>Team members</h2>
                        <p>{summary.team_members ?? 0} downline · team commission {money(summary.team_commission)}</p>
                    </div>
                </div>
                <Table
                    rows={teamMembers}
                    columns={[
                        { key: 'name', title: 'Member', render: (r) => <><b>{r.name}</b><small className="cell-sub">{r.dealer_code}</small></> },
                        { key: 'role', title: 'Role' },
                        { key: 'phone', title: 'Phone' },
                        { key: 'status', title: 'Status', render: (r) => <Badge status={r.status} /> },
                    ]}
                    empty="No team members yet"
                />
            </section>

            <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>Team transactions</h2>
                        <p>Recent purchases and payment progress from your network.</p>
                    </div>
                </div>
                <Table
                    rows={teamActivity}
                    columns={[
                        { key: 'created_at', title: 'When', render: (r) => date(r.created_at) },
                        { key: 'member_name', title: 'Member', render: (r) => <><b>{r.member_name}</b><small className="cell-sub">{r.member_code}</small></> },
                        { key: 'number', title: 'Order' },
                        { key: 'status', title: 'Status', render: (r) => <Badge status={r.status} /> },
                        { key: 'total', title: 'Ordered', render: (r) => money(r.total) },
                        { key: 'paid', title: 'Paid', render: (r) => money(r.paid) },
                        { key: 'due', title: 'Due', render: (r) => money(r.due) },
                    ]}
                    empty="No team transactions yet"
                />
            </section>
        </div>
    );
}
