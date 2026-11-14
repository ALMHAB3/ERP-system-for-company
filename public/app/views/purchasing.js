import { h, get, post, form, table, tabs, panel, act, can, modal, money, qty, badge, toast, state, opts, refItems, today, currencyFields, rateHint, wireBank, PAY_METHODS } from '../ui.js';

const KINDS = [['goods', 'بضاعة'], ['freight', 'أجور الشحن'], ['insurance', 'تأمين'], ['customs', 'جمارك'], ['clearance', 'تخليص ومصاريف ميناء'], ['expense', 'مصروف عام']].map(([value, label]) => ({ value, label }));

function orders() {
  return panel(async (refresh) => {
    const r = await get('purchase-orders');
    return table([
      { label: 'الرقم', key: 'number' }, { label: 'المورد', key: 'supplier' }, { label: 'الحالة', render: (o) => badge(o.status) },
      { label: 'الإجمالي', num: true, render: (o) => money(o.total) }, { label: 'مستوى الاعتماد', num: true, render: (o) => o.required_approval_level },
      { label: 'البنود', render: (o) => o.lines.map((l) => l.sku + ' × ' + qty(l.quantity)).join('، ') },
      { label: '', render: (o) => h('div', { class: 'row-actions' },
        o.status === 'draft' && can('purchase_orders.create') ? act('إرسال للاعتماد', () => post(`purchase-orders/${o.id}/submit`), refresh) : null,
        o.status === 'pending_approval' && can('purchase_orders.approve') && o.created_by !== state.me.id ? act('اعتماد', () => post(`purchase-orders/${o.id}/approve`), refresh, { confirm: 'اعتماد أمر الشراء ' + o.number + '؟', ok: 'تم الاعتماد' }) : null,
        o.status === 'approved' && can('purchase_orders.receive') ? h('button', { class: 'btn small', onclick: () => {
          const m = modal('استلام أمر الشراء', form([{ name: 'warehouse_id', label: 'المستودع', type: 'select', options: opts.warehouses(), required: true }], 'تأكيد', async (v) => { await post(`purchase-orders/${o.id}/receive`, { warehouse_id: Number(v.warehouse_id) }); toast('تم الاستلام', 'ok'); m.close(); refresh(); }));
        } }, 'استلام') : null) },
    ], r.data, 'لا توجد أوامر شراء');
  });
}

function newOrder() {
  return form([
    { name: 'number', label: 'رقم الأمر', type: 'text', required: true }, { name: 'supplier', label: 'اسم المورد', type: 'text', required: true },
    ...currencyFields(state.lookups.currency),
    { name: 'lines', label: 'البنود', type: 'rows', fields: [
      { name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true },
      { name: 'quantity', label: 'الكمية', type: 'number', required: true }, { name: 'unit_price', label: 'سعر الوحدة', type: 'number', required: true }] },
  ], 'إنشاء أمر الشراء', async (v, f) => {
    const r = await post('purchase-orders', { ...v, exchange_rate: v.exchange_rate || undefined, lines: v.lines.map((l) => ({ ...l, product_id: Number(l.product_id) })) });
    toast(`تم إنشاء الأمر (إجمالي ${money(r.total)}، يتطلب اعتماد مستوى ${r.required_approval_level})`, 'ok'); f.reset();
  });
}

export function suppliers() {
  return panel(async (refresh) => h('div', null,
    can('suppliers.manage') ? h('div', { class: 'card' }, h('h3', null, 'إضافة مورد'), form([
      { name: 'name', label: 'الاسم', type: 'text', required: true }, { name: 'country', label: 'الدولة (رمز من حرفين)', type: 'text' },
      { name: 'currency', label: 'عملة التعامل', type: 'select', options: opts.currencies(), value: 'USD' }, { name: 'terms_days', label: 'مدة السداد (يوم)', type: 'number', value: '30' }, { name: 'tax_no', label: 'الرقم الضريبي', type: 'text' },
    ], 'إضافة', async (v, f) => { await post('payables/suppliers', { ...v, terms_days: +v.terms_days }); state.lookups = await get('lookups'); toast('تمت إضافة المورد', 'ok'); refresh(); })) : null,
    table([{ label: 'المورد', key: 'name' }, { label: 'الدولة', key: 'country' }, { label: 'العملة', key: 'currency' }, { label: 'مدة السداد', num: true, key: 'terms_days' }], (await get('payables/suppliers')).data)));
}

export async function bills() {
  const [shipments, orders] = await Promise.all([can('shipments.manage', 'shipments.cost', 'shipments.receive') ? get('shipments') : { data: [] }, can('purchase_orders.create', 'purchase_orders.approve', 'purchase_orders.receive') ? get('purchase-orders') : { data: [] }]);
  const refs = [{ value: '', label: '— (للمصروفات العامة فقط) —' }, ...shipments.data.map((s) => ({ value: 'shipment:' + s.reference, label: 'شحنة ' + s.reference })), ...orders.data.map((o) => ({ value: 'po:' + o.number, label: 'أمر شراء ' + o.number }))];
  return panel(async (refresh) => {
    const list = await get('payables/invoices');
    return h('div', null,
      can('supplier_invoices.record') ? h('div', { class: 'card' }, h('h3', null, 'تسجيل فاتورة مورد'), form([
        { name: 'supplier_id', label: 'المورد', type: 'select', options: opts.suppliers(), required: true }, { name: 'number', label: 'رقم فاتورة المورد', type: 'text', required: true },
        { name: 'invoice_date', label: 'تاريخ الفاتورة', type: 'date', value: today(), required: true }, { name: 'due_date', label: 'تاريخ الاستحقاق (اختياري)', type: 'date' },
        ...currencyFields('USD', 'سعر الصرف يوم الفاتورة'),
        { name: 'subtotal', label: 'المبلغ قبل الضريبة', type: 'number', required: true }, { name: 'tax', label: 'الضريبة', type: 'number', value: '0' },
        { name: 'kind', label: 'نوع الفاتورة', type: 'select', options: KINDS },
        { name: 'ref', label: 'الشحنة / أمر الشراء المرتبط', type: 'select', options: refs },
        { name: 'expense_account', label: 'حساب المصروف (للمصروفات العامة)', type: 'select', options: [{ value: '', label: '—' }, ...opts.accounts()] },
      ], 'تسجيل الفاتورة', async (v) => {
        const [type, ref] = (v.ref || '').split(/:(.*)/s);
        await post('payables/invoices', { supplier_id: Number(v.supplier_id), number: v.number, invoice_date: v.invoice_date, due_date: v.due_date, currency: v.currency, exchange_rate: v.exchange_rate || undefined, subtotal: v.subtotal, tax: v.tax || '0', kind: v.kind, expense_account: v.expense_account, ...(type === 'shipment' ? { shipment_reference: ref } : type === 'po' ? { po_number: ref } : {}) });
        toast('تم تسجيل الفاتورة وترحيل قيدها', 'ok'); refresh();
      })) : null,
      h('h3', null, 'فواتير الموردين'),
      table([{ label: 'المورد', key: 'supplier' }, { label: 'الرقم', key: 'number' }, { label: 'النوع', render: (i) => KINDS.find((k) => k.value === i.kind)?.label }, { label: 'المرجع', render: (i) => i.receipt_reference || '' },
        { label: 'الاستحقاق', key: 'due_date' }, { label: 'العملة', key: 'currency' }, { label: 'الإجمالي', num: true, render: (i) => money(i.total) }, { label: 'المتبقي', num: true, render: (i) => money(i.balance) }], list.data, 'لا توجد فواتير'));
  });
}

export function pay() {
  return panel(async (refresh) => {
    const supplierBox = h('div');
    const picker = form([{ name: 'supplier_id', label: 'المورد', type: 'select', options: [{ value: '', label: '— اختر —' }, ...opts.suppliers()] }], 'عرض فواتيره المفتوحة', async (v) => {
      if (!v.supplier_id) return;
      const inv = (await get('payables/invoices', { supplier_id: v.supplier_id, open: 1 })).data;
      if (!inv.length) return supplierBox.replaceChildren(h('div', { class: 'card empty' }, 'لا توجد فواتير مفتوحة لهذا المورد'));
      const amounts = {};
      const rows = inv.map((i) => { const input = h('input', { dir: 'ltr', placeholder: '0' }); amounts[i.id] = input; return h('tr', null, h('td', null, i.number), h('td', null, i.currency), h('td', { class: 'num' }, money(i.balance)), h('td', null, input), h('td', null, h('button', { class: 'btn secondary small', type: 'button', onclick: () => { input.value = i.balance; } }, 'كامل المتبقي'))); });
      const meta = wireBank(form([
        { name: 'paid_on', label: 'تاريخ الدفع', type: 'date', value: today(), required: true }, { name: 'exchange_rate', label: 'سعر الصرف يوم الدفع (' + inv[0].currency + ')', type: 'number', value: rateHint(inv[0].currency).value, hint: rateHint(inv[0].currency).hint },
        { name: 'method', label: 'طريقة الدفع', type: 'select', options: PAY_METHODS }, { name: 'bank_account_id', label: 'المصرف المدفوع منه', type: 'select', options: opts.banks() }, { name: 'reference', label: 'مرجع الدفع', type: 'text', required: true },
      ], 'تنفيذ الدفع', async (m) => {
        const allocations = inv.filter((i) => amounts[i.id].value.trim()).map((i) => ({ invoice_id: i.id, amount: amounts[i.id].value.trim(), currency: i.currency }));
        if (!allocations.length) throw new Error('حدد مبلغاً لفاتورة واحدة على الأقل');
        const currencies = [...new Set(allocations.map((a) => a.currency))];
        if (currencies.length > 1) throw new Error('ادفع كل عملة في عملية منفصلة');
        await post('payables/payments', { supplier_id: Number(v.supplier_id), paid_on: m.paid_on, currency: currencies[0], exchange_rate: m.exchange_rate || undefined, method: m.method, bank_account_id: m.method === 'cash' ? undefined : Number(m.bank_account_id), reference: m.reference, allocations: allocations.map(({ invoice_id, amount }) => ({ invoice_id, amount })) });
        toast('تم الدفع وترحيل القيد (مع فرق العملة إن وجد)', 'ok'); supplierBox.replaceChildren();
      }));
      supplierBox.replaceChildren(h('div', { class: 'tablewrap' }, h('table', null, h('thead', null, h('tr', null, ['الفاتورة', 'العملة', 'المتبقي', 'المبلغ المدفوع', ''].map((t) => h('th', null, t)))), h('tbody', null, rows))), meta);
    });
    return h('div', null, h('p', { class: 'hint' }, 'كل دفعة تُخصَّص لفواتير محددة. سعر صرف الدفع يُقارَن بسعر صرف الفاتورة وتُسجَّل الفروق تلقائياً.'), picker, supplierBox);
  });
}

function grir() {
  const out = h('div');
  const run = async (ref, clear, reason) => {
    const s = clear ? await post('payables/goods-received/clear', { reference: ref, reason }) : await get('payables/goods-received', { reference: ref });
    if (clear) { toast('تمت تسوية الفرق إلى فروق الأسعار: ' + money(s.cleared), 'ok'); return run(ref, false); }
    out.replaceChildren(h('div', { class: 'card' }, h('h3', null, ref),
      h('div', null, 'قيمة المستلم بالمخزون: ', h('b', null, money(s.receipts))), h('div', null, 'فواتير الموردين المسجلة: ', h('b', null, money(s.invoiced))),
      h('div', null, 'تسويات سابقة: ', h('b', null, money(s.cleared))), h('div', null, 'المتبقي بدون فاتورة: ', h('b', { class: s.open === '0.0000' ? 'pos' : 'neg' }, money(s.open))),
      s.open !== '0.0000' && can('grir.clear') ? h('div', { class: 'toolbar' }, form([{ name: 'reason', label: 'سبب التسوية إلى فروق الأسعار', type: 'text', required: true, wide: true }], 'تسوية الفرق (لا رجعة فيه)', (v) => run(ref, true, v.reason))) : null));
  };
  return h('div', null, h('p', { class: 'hint' }, 'قارن ما استُلم بالمخزون بما فوتره الموردون لشحنة أو أمر شراء. افتح فرق الحساب فقط بعد وصول كل الفواتير.'),
    form([{ name: 'reference', label: 'المرجع، مثال: shipment:CONT-001 أو po:PO-123', type: 'text', required: true, wide: true }], 'عرض الحالة', (v) => run(v.reference, false)), out);
}

function settlement() {
  const out = h('div');
  return panel(async () => {
    const received = (await get('shipments')).data.filter((s) => s.status === 'received');
    const show = (r) => {
      const card = (l, v, cls) => h('div', { class: 'kpi ' + (cls || '') }, h('div', { class: 'l' }, l), h('div', { class: 'v' }, money(v)));
      return [
        h('div', { class: 'cards' }, card('التكلفة المسجلة عند الاستلام', r.booked_total), card('فرق الصرف المدفوع (بدون الضريبة)', r.fx_difference, Number(r.fx_difference) > 0 ? 'bad' : 'good'),
          card('فرق مطابقة مُسوّى سابقاً', r.cleared_variance), card('صافي تعديل التكلفة', r.delta, Number(r.delta) > 0 ? 'bad' : 'good'), card('التكلفة النهائية الفعلية', r.final_total, 'good')),
        h('p', { class: 'hint' }, `الجزء المتبقي في المخزون: ${money(r.inventory_part)} يُضاف لقيمة الدفعة · الجزء المباع: ${money(r.cogs_part)} يذهب إلى تكلفة المبيعات.`),
        table([{ label: 'الصنف', key: 'sku' }, { label: 'الدفعة', key: 'lot_no' }, { label: 'المستلم', num: true, render: (l) => qty(l.received_quantity) }, { label: 'الباقي بالمخزون', num: true, render: (l) => qty(l.quantity_in_stock) },
          { label: 'التكلفة المسجلة', num: true, render: (l) => money(l.booked_cost) }, { label: 'التعديل', num: true, render: (l) => money(l.delta) }, { label: 'للمخزون', num: true, render: (l) => money(l.inventory_part) }, { label: 'لتكلفة المبيعات', num: true, render: (l) => money(l.cogs_part) },
          { label: 'تكلفة الوحدة النهائية', num: true, render: (l) => h('b', null, money(l.final_unit_cost)) }], r.lines),
      ];
    };
    const run = async (ref, apply) => {
      try {
        const r = apply ? await post('payables/settlement', { shipment_reference: ref }) : await get('payables/settlement', { shipment_reference: ref });
        if (apply) { toast('تم اعتماد التكلفة النهائية وترحيل القيد', 'ok'); out.replaceChildren(h('div', { class: 'card pos' }, 'تمت التسوية: التكلفة النهائية ' + money(r.final_total)), ...show(r)); return; }
        out.replaceChildren(...show(r), h('div', { class: 'card' }, h('p', null, 'راجع الأرقام مع المحاسب القانوني. التسوية تُنفَّذ مرة واحدة لكل شحنة ولا تُعدَّل (التصحيح بقيد عكسي).'),
          act('اعتماد التكلفة النهائية', () => run(ref, true), null, { confirm: 'اعتماد التكلفة النهائية للشحنة ' + ref + '؟ لا يمكن تكرارها.', ok: 'تم' })));
      } catch (e) { out.replaceChildren(h('div', { class: 'card neg' }, e.message)); }
    };
    return h('div', null, h('p', { class: 'hint' }, 'بعد سداد كل فواتير الشحنة بالكامل ومطابقة المستلم بالمفوتر، يحسب النظام التكلفة الفعلية حسب أسعار الصرف التي دفعت بها فعلاً: يعدّل تكلفة الدفعة في المخزون، وما بيع منها يُسوّى في تكلفة المبيعات.'),
      form([{ name: 'ref', label: 'الشحنة المستلمة', type: 'select', options: received.map((s) => ({ value: s.reference, label: s.reference })) }], 'معاينة التسوية', (v) => v.ref ? run(v.ref, false) : toast('لا توجد شحنات مستلمة', 'error')), out);
  });
}

export function ageing() {
  return panel(async () => {
    const r = await get('payables/ageing');
    return h('div', null, h('div', { class: 'cards' }, Object.entries(r.buckets).map(([k, v]) => h('div', { class: 'kpi' }, h('div', { class: 'l' }, k === 'current' ? 'غير مستحق' : k + ' يوم تأخير'), h('div', { class: 'v' }, money(v))))),
      table([{ label: 'المورد', key: 'supplier' }, { label: 'الإجمالي', num: true, render: (s) => money(s.total) }, { label: 'الفواتير', render: (s) => s.invoices.map((i) => `${i.number} (${money(i.balance)}${i.days_overdue ? '، متأخرة ' + i.days_overdue + ' يوم' : ''})`).join(' · ') }], r.suppliers, 'لا توجد مستحقات'));
  });
}

export default async function () {
  return tabs([
    ...(can('purchase_orders.create', 'purchase_orders.approve', 'purchase_orders.receive') ? [{ label: 'أوامر الشراء', render: async () => orders() }] : []),
    ...(can('purchase_orders.create') ? [{ label: 'أمر شراء جديد', render: async () => newOrder() }] : []),
    ...(can('finance.view') ? [{ label: 'مطابقة المستلم والمفوتر (GR/IR)', render: async () => grir() }] : []),
    ...(can('shipments.settle') ? [{ label: 'التكلفة النهائية للشحنة', render: async () => settlement() }] : []),
  ]);
}
