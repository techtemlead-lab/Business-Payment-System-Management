import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { PackageCheck, Plus, Trash2 } from 'lucide-react';
import { ActionForm, Badge, DateInput, Empty, FormErrors, Modal, Pagination, Table, date, money, number, type Row } from './UI';
import type { OfficeHub } from './OfficeHubTabs';
import OfficePage from './office/OfficePage';
import { currentView, DrawerLines, StaffActionDialog, ViewSwitch, type StaffAction } from './office/sections/staffSectionParts';
import { useT } from '../shared/i18n/t';
import { randomUuid } from '../shared/randomUuid';
import SearchSelect from './SearchSelect';

type PurchasingView = 'orders' | 'suppliers' | 'archived';

interface PurchasingData {
    branch_scope: { view: string; code?: string | null; name?: string | null };
    show_cost: boolean;
    can: { create: boolean; edit: boolean };
    orders: Row;
    suppliers: Row[];
    archived_suppliers: Row[];
    products: Row[];
    branches: Row[];
}

type Translate = (key: string, replace?: Record<string, string>) => string;

function useText(): Translate {
    const translate = useT();
    return (key, replace) => translate('purchasing', key, replace);
}

/** Line rows for a new order: pick a product and how many. */
function NewOrderForm({ data, onDone }: { data: PurchasingData; onDone: () => void }) {
    const text = useText();
    const translate = useT();
    const form = useForm<{ supplier_id: string; branch_id: string; notes: string; lines: { product_id: string; quantity: string }[] }>({
        supplier_id: '',
        branch_id: data.branches.length === 1 ? String(data.branches[0].id) : '',
        notes: '',
        lines: [{ product_id: '', quantity: '' }],
    });
    const setLine = (index: number, field: 'product_id' | 'quantity', value: string) => {
        form.setData('lines', form.data.lines.map((line, position) => (position === index ? { ...line, [field]: value } : line)));
    };

    return (
        <form
            className="action-form"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/commands/purchasing/orders', { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <FormErrors errors={form.errors} />
            <label>
                {text('supplier')}
                <SearchSelect
                    required
                    value={form.data.supplier_id}
                    onChange={(value) => form.setData('supplier_id', value)}
                    emptyLabel={translate('portal', 'select_placeholder')}
                    options={data.suppliers.map((supplier) => ({ value: String(supplier.id), label: String(supplier.name) }))}
                />
            </label>
            <label>
                {text('receiving_branch')}
                <SearchSelect
                    required
                    value={form.data.branch_id}
                    onChange={(value) => form.setData('branch_id', value)}
                    emptyLabel={translate('portal', 'select_placeholder')}
                    options={data.branches.map((branch) => ({ value: String(branch.id), label: `${branch.code} · ${branch.name}` }))}
                />
            </label>
            {form.data.lines.map((line, index) => (
                <div className="ops-row is-wrap" key={index}>
                    <label style={{ flex: '1 1 16rem' }}>
                        {text('product')}
                        <SearchSelect
                            required
                            value={line.product_id}
                            onChange={(value) => setLine(index, 'product_id', value)}
                            emptyLabel={translate('portal', 'select_placeholder')}
                            options={data.products.map((product) => ({ value: String(product.id), label: `${product.sku} · ${product.name}` }))}
                        />
                    </label>
                    <label style={{ flex: '0 1 8rem' }}>
                        {text('quantity')}
                        <input type="number" min={1} max={100000} required value={line.quantity} onChange={(event) => setLine(index, 'quantity', event.target.value)} />
                    </label>
                    {form.data.lines.length > 1 && (
                        <button
                            type="button"
                            className="icon-button"
                            aria-label={text('remove_line')}
                            title={text('remove_line')}
                            onClick={() => form.setData('lines', form.data.lines.filter((_, position) => position !== index))}
                        >
                            <Trash2 size={16} />
                        </button>
                    )}
                </div>
            ))}
            <button type="button" className="button" onClick={() => form.setData('lines', [...form.data.lines, { product_id: '', quantity: '' }])}>
                <Plus size={16} /> {text('add_line')}
            </button>
            <label>
                {text('notes')}
                <textarea rows={2} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} />
            </label>
            <button className="button dark" disabled={form.processing}>{text('save_draft')}</button>
        </form>
    );
}

/** One row per line still due; rows left empty are not sent. */
function ReceiveForm({ order, onDone }: { order: Row; onDone: () => void }) {
    const text = useText();
    const dueLines: Row[] = order.lines.filter((line: Row) => Number(line.due) > 0);
    const [reference] = useState(() => randomUuid());
    const form = useForm<{ reference: string; lines: Row[] }>({
        reference,
        lines: dueLines.map((line) => ({ purchase_order_line_id: line.id, quantity: '', batch_number: '', expires_on: '', unit_cost: '' })),
    });
    form.transform((values) => ({ ...values, lines: values.lines.filter((line) => String(line.quantity) !== '') }));
    const setLine = (index: number, field: string, value: string) => {
        form.setData('lines', form.data.lines.map((line, position) => (position === index ? { ...line, [field]: value } : line)));
    };

    return (
        <form
            className="action-form"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/commands/purchasing/orders/${order.id}/receipts`, { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <p className="muted">{text('receive_hint')}</p>
            <p className="muted">{text('batch_blank_hint')}</p>
            <FormErrors errors={form.errors} />
            {dueLines.map((line, index) => {
                const row = form.data.lines[index];
                const required = String(row.quantity) !== '';
                return (
                    <fieldset key={line.id}>
                        <legend>{line.sku} · {line.name} ({text('due')}: {number(line.due)})</legend>
                        <div className="ops-row is-wrap">
                            <label>{text('quantity_now')}<input type="number" min={1} max={line.due} value={row.quantity} onChange={(event) => setLine(index, 'quantity', event.target.value)} /></label>
                            <label>{text('batch_optional')}<input maxLength={60} value={row.batch_number} onChange={(event) => setLine(index, 'batch_number', event.target.value)} /></label>
                            <label>{text('expiry')}<DateInput required={required} value={row.expires_on} onChange={(event) => setLine(index, 'expires_on', event.target.value)} /></label>
                            <label>{text('unit_cost')}<input type="number" min={0} step="0.01" required={required} value={row.unit_cost} onChange={(event) => setLine(index, 'unit_cost', event.target.value)} /></label>
                        </div>
                    </fieldset>
                );
            })}
            <button className="button dark" disabled={form.processing}><PackageCheck size={16} /> {text('book_in')}</button>
        </form>
    );
}

function OrderDetail({ order, data, onClose }: { order: Row; data: PurchasingData; onClose: () => void }) {
    const text = useText();
    const translate = useT();
    const [receiving, setReceiving] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const canCancel = data.can.edit && ['draft', 'sent', 'partly_received'].includes(order.status);

    return (
        <Modal title={`${order.number} · ${order.supplier_name ?? ''}`} onClose={onClose}>
            <p><Badge status={order.status} /> {order.branch_code} · {order.branch_name}</p>
            {order.notes && <p className="muted">{order.notes}</p>}
            {order.cancel_reason && <p className="muted">{text('cancel_reason_saved', { reason: order.cancel_reason })}</p>}
            <Table
                tools={false}
                rows={order.lines}
                columns={[
                    { key: 'product', title: text('product'), render: (line: Row) => <>{line.name}<small className="cell-sub">{line.sku}</small></> },
                    { key: 'ordered', title: text('ordered'), align: 'right', render: (line: Row) => number(line.ordered) },
                    { key: 'received', title: text('received'), align: 'right', render: (line: Row) => number(line.received) },
                    { key: 'due', title: text('due'), align: 'right', render: (line: Row) => <b>{number(line.due)}</b> },
                ]}
            />
            <div className="ops-row is-wrap">
                {data.can.edit && order.status === 'draft' && (
                    <button type="button" className="button dark" onClick={() => router.post(`/commands/purchasing/orders/${order.id}/send`, {}, { preserveScroll: true })}>{text('send_order')}</button>
                )}
                {data.can.create && order.awaiting_goods && (
                    <button type="button" className="button dark" onClick={() => setReceiving(true)}><PackageCheck size={16} /> {text('receive_goods')}</button>
                )}
                {canCancel && <button type="button" className="button" onClick={() => setCancelling(true)}>{text('cancel_order')}</button>}
            </div>
            {receiving && <ReceiveForm key={order.received} order={order} onDone={() => setReceiving(false)} />}
            {cancelling && (
                <>
                    {Number(order.received) > 0 && <p className="muted">{text('cancel_keeps_stock')}</p>}
                    <ActionForm
                        action={`/commands/purchasing/orders/${order.id}/cancel`}
                        fields={[{ name: 'reason', label: text('cancel_reason'), type: 'textarea', required: true }]}
                        submit={text('cancel_order')}
                        onDone={() => setCancelling(false)}
                    />
                </>
            )}
            <h3>{text('receipts')}</h3>
            {order.receipts.length === 0 && <p className="muted">{text('no_receipts')}</p>}
            {order.receipts.map((receipt: Row) => (
                <div key={receipt.id}>
                    <p className="muted">{text('received_by_on', { name: String(receipt.received_by_name ?? ''), date: date(receipt.created_at) })}</p>
                    <Table
                        tools={false}
                        rows={receipt.lines}
                        columns={[
                            { key: 'product', title: text('product'), render: (line: Row) => line.name },
                            { key: 'quantity', title: text('quantity'), align: 'right', render: (line: Row) => number(line.quantity) },
                            { key: 'batch', title: text('batch'), render: (line: Row) => line.batch_number || '—' },
                            { key: 'expiry', title: text('expiry'), render: (line: Row) => date(line.expires_on) },
                            ...(data.show_cost ? [{ key: 'cost', title: text('unit_cost'), money: true, render: (line: Row) => money(line.unit_cost) }] : []),
                        ]}
                    />
                </div>
            ))}
            <p className="muted">{translate('portal', 'col_date')}: {date(order.created_at)}</p>
        </Modal>
    );
}

export default function PurchasingHub({ data, hub }: { data: PurchasingData; hub?: OfficeHub | null }) {
    const text = useText();
    const { url } = usePage();
    const views: { key: PurchasingView; label: string }[] = [
        { key: 'orders', label: text('tab_orders') },
        { key: 'suppliers', label: text('tab_suppliers') },
        { key: 'archived', label: text('tab_archived_suppliers') },
    ];
    const view = currentView(url, views.map((item) => item.key), 'orders');
    const [creating, setCreating] = useState(false);
    const [selectedOrderId, setSelectedOrderId] = useState<number | null>(null);
    const [openedSupplier, setOpenedSupplier] = useState<Row | null>(null);
    const [editingSupplier, setEditingSupplier] = useState<Row | null>(null);
    const [addingSupplier, setAddingSupplier] = useState(false);
    const [confirming, setConfirming] = useState<(StaffAction & { note: string }) | null>(null);
    const orders: Row[] = data.orders?.data ?? [];
    const selectedOrder = orders.find((order) => order.id === selectedOrderId) ?? null;

    const openConfirm = (supplier: Row, kind: 'archive' | 'restore') => {
        setOpenedSupplier(null);
        setConfirming({
            title: text(kind === 'archive' ? 'archive_supplier' : 'restore_supplier'),
            action: `/commands/purchasing/suppliers/${supplier.id}/${kind}`,
            fields: [],
            note: text(kind === 'archive' ? 'archive_supplier_note' : 'restore_supplier_note', { name: String(supplier.name) }),
        });
    };

    const action = view === 'orders' && data.can.create ? (
        <button type="button" className="button dark" disabled={data.suppliers.length === 0} onClick={() => setCreating(true)}>
            <Plus size={16} /> {text('new_order')}
        </button>
    ) : view === 'suppliers' && data.can.create ? (
        <button type="button" className="button dark" onClick={() => setAddingSupplier(true)}><Plus size={16} /> {text('new_supplier')}</button>
    ) : undefined;

    const supplierColumns = [
        { key: 'name', title: text('supplier_name'), render: (supplier: Row) => <button type="button" className="record-link" onClick={() => setOpenedSupplier(supplier)}>{supplier.name}</button> },
        { key: 'contact', title: text('contact'), render: (supplier: Row) => supplier.contact || '—' },
    ];

    return (
        <OfficePage
            title={text('page_title')}
            subtitle={data.branch_scope?.name ? text('showing_branch', { name: String(data.branch_scope.name) }) : undefined}
            hub={hub}
            action={action}
        >
            <ViewSwitch views={views} active={view} fallback="orders" label={text('views_label')} />

            {view === 'orders' && (orders.length === 0 ? <Empty title={text('no_orders')} body={text('no_orders_body')} /> : (
                <>
                    <Table
                        rows={orders}
                        columns={[
                            { key: 'number', title: text('order_number'), render: (order: Row) => <button type="button" className="record-link" onClick={() => setSelectedOrderId(order.id)}>{order.number}</button> },
                            { key: 'date', title: text('date'), render: (order: Row) => date(order.created_at) },
                            { key: 'supplier', title: text('supplier'), render: (order: Row) => order.supplier_name ?? '—' },
                            { key: 'branch', title: text('branch'), render: (order: Row) => order.branch_code ?? '—' },
                            { key: 'ordered', title: text('ordered'), align: 'right', render: (order: Row) => number(order.ordered) },
                            { key: 'received', title: text('received'), align: 'right', render: (order: Row) => number(order.received) },
                            { key: 'due', title: text('due'), align: 'right', render: (order: Row) => <b>{number(order.due)}</b> },
                            { key: 'status', title: text('status'), render: (order: Row) => <Badge status={order.status} /> },
                        ]}
                    />
                    <Pagination page={data.orders} />
                </>
            ))}

            {view === 'suppliers' && (data.suppliers.length === 0
                ? <Empty title={text('no_suppliers')} body={text('no_suppliers_body')} />
                : <Table rows={data.suppliers} columns={[...supplierColumns, { key: 'notes', title: text('notes'), render: (supplier: Row) => supplier.notes || '—' }]} />)}

            {view === 'archived' && (data.archived_suppliers.length === 0
                ? <Empty line title={text('no_archived_suppliers')} />
                : <Table rows={data.archived_suppliers} columns={[...supplierColumns, { key: 'archived_at', title: text('archived_on'), render: (supplier: Row) => date(supplier.archived_at) }]} />)}

            {creating && <Modal title={text('new_order')} onClose={() => setCreating(false)}><NewOrderForm data={data} onDone={() => setCreating(false)} /></Modal>}
            {selectedOrder && <OrderDetail order={selectedOrder} data={data} onClose={() => setSelectedOrderId(null)} />}
            {openedSupplier && (
                <Modal
                    title={String(openedSupplier.name)}
                    variant="drawer"
                    onClose={() => setOpenedSupplier(null)}
                    footer={data.can.edit ? (
                        <div className="ops-row is-wrap">
                            {openedSupplier.archived_at ? (
                                <button type="button" className="button dark" onClick={() => openConfirm(openedSupplier, 'restore')}>{text('restore_supplier')}</button>
                            ) : (
                                <>
                                    <button type="button" className="button dark" onClick={() => { setEditingSupplier(openedSupplier); setOpenedSupplier(null); }}>{text('edit_supplier')}</button>
                                    <button type="button" className="button" onClick={() => openConfirm(openedSupplier, 'archive')}>{text('archive_supplier')}</button>
                                </>
                            )}
                        </div>
                    ) : undefined}
                >
                    <DrawerLines
                        lines={[
                            { label: text('contact'), value: openedSupplier.contact },
                            { label: text('notes'), value: openedSupplier.notes },
                            { label: text('archived_on'), value: openedSupplier.archived_at ? date(openedSupplier.archived_at) : null },
                        ]}
                    />
                </Modal>
            )}
            {confirming && <StaffActionDialog action={confirming} before={<p className="muted">{confirming.note}</p>} onClose={() => setConfirming(null)} />}
            {(addingSupplier || editingSupplier) && (
                <Modal title={text(editingSupplier ? 'edit_supplier' : 'new_supplier')} onClose={() => { setAddingSupplier(false); setEditingSupplier(null); }}>
                    <ActionForm
                        action={editingSupplier ? `/commands/purchasing/suppliers/${editingSupplier.id}` : '/commands/purchasing/suppliers'}
                        method={editingSupplier ? 'patch' : 'post'}
                        defaults={editingSupplier ? { name: editingSupplier.name, contact: editingSupplier.contact ?? '', notes: editingSupplier.notes ?? '' } : {}}
                        fields={[
                            { name: 'name', label: text('supplier_name'), required: true },
                            { name: 'contact', label: text('contact') },
                            { name: 'notes', label: text('notes'), type: 'textarea' },
                        ]}
                        onDone={() => { setAddingSupplier(false); setEditingSupplier(null); }}
                    />
                </Modal>
            )}
        </OfficePage>
    );
}
