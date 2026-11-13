import { h, get, post, form, table, tabs, panel, act, can, modal, money, qty, pct, rateFromPercent, badge, toast, opts, refItems, currencyFields } from '../ui.js';

const BASIS = [['volume', 'حسب الحجم (CBM)'], ['weight', 'حسب الوزن (كجم)'], ['value', 'حسب القيمة'], ['quantity', 'حسب الكمية']].map(([value, label]) => ({ value, label }));

function details(s) {
  return h('div', null,
    h('p', null, 'العملة: ', s.currency, ' · سعر الصرف: ', money(s.exchange_rate), ' · الحالة: ', badge(s.status)),
    h('h4', null, 'البنود'),
    table([
      { label: 'الصنف', render: (l) => l.sku + ' — ' + l.name }, { label: 'الدفعة', render: (l) => l.lot_no || '(تلقائي)' }, { label: 'الانتهاء', render: (l) => l.expiry_date || '—' },
      { label: 'الكمية', num: true, render: (l) => qty(l.quantity) }, { label: 'سعر الوحدة', num: true, render: (l) => money(l.unit_price) }, { label: 'الجمارك', num: true, render: (l) => pct(l.duty_rate) },
      { label: 'التكلفة الفعلية', num: true, render: (l) => (l.landed_total ? money(l.landed_total) : '—') }, { label: 'تكلفة الوحدة', num: true, render: (l) => (l.landed_unit ? money(l.landed_unit) : '—') },
    ], s.lines),
    h('h4', null, 'مصاريف الشحنة (بالعملة الأساسية)'),
    table([{ label: 'البند', key: 'code' }, { label: 'المبلغ', num: true, render: (c) => money(c.amount) }, { label: 'التوزيع', render: (c) => BASIS.find((b) => b.value === c.basis)?.label }, { label: 'ضمن القيمة الجمركية', render: (c) => (c.in_customs_value ? 'نعم' : 'لا') }], s.costs, 'لا توجد مصاريف'));
}

function list() {
  return panel(async (refresh) => {
    const r = await get('shipments');
    return table([
      { label: 'المرجع', key: 'reference' }, { label: 'الحالة', render: (s) => badge(s.status) },
      { label: 'العملة', render: (s) => s.currency + ' × ' + money(s.exchange_rate) }, { label: 'عدد البنود', num: true, render: (s) => s.lines.length },
      { label: '', render: (s) => h('div', { class: 'row-actions' },
        h('button', { class: 'btn secondary small', onclick: () => modal('شحنة ' + s.reference, details(s)) }, 'عرض'),
        s.status === 'open' && can('shipments.cost') ? act('احتساب التكلفة الفعلية', () => post(`shipments/${s.id}/finalize`), refresh, { confirm: 'سيتم تجميد التكلفة الفعلية لهذه الشحنة. متابعة؟', ok: 'تم احتساب التكلفة' }) : null,
        s.status === 'costed' && can('shipments.receive') ? h('button', { class: 'btn small', onclick: () => {
          const m = modal('استلام الشحنة في مستودع', form([{ name: 'warehouse_id', label: 'المستودع', type: 'select', options: opts.warehouses(), required: true }], 'تأكيد الاستلام', async (v) => {
            await post(`shipments/${s.id}/receive`, { warehouse_id: Number(v.warehouse_id) }); toast('تم الاستلام وتحديث المخزون', 'ok'); m.close(); refresh();
          }));
        } }, 'استلام في المستودع') : null) },
    ], r.data, 'لا توجد شحنات');
  });
}

function create() {
  return h('div', null, h('p', { class: 'hint' }, 'أدخل بنود الفاتورة التجارية ثم المصاريف. الشحن والتأمين يُحسبان ضمن القيمة الجمركية (CIF). الرسم الجمركي يُدخل كنسبة مئوية لكل بند حسب بند التعرفة.'),
    form([
      { name: 'reference', label: 'مرجع الشحنة / رقم الحاوية', type: 'text', required: true },
      ...currencyFields('USD', 'سعر الصرف إلى الدينار الليبي'),
      { name: 'lines', label: 'بنود الفاتورة التجارية', type: 'rows', fields: [
        { name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true },
        { name: 'quantity', label: 'الكمية', type: 'number', required: true }, { name: 'unit_price', label: 'سعر الوحدة', type: 'number', required: true },
        { name: 'volume_cbm', label: 'الحجم CBM', type: 'number' }, { name: 'weight_kg', label: 'الوزن كجم', type: 'number' },
        { name: 'duty_pct', label: 'الرسم الجمركي %', type: 'number', value: '0', required: true },
        { name: 'lot_no', label: 'رقم دفعة المورد', type: 'text' }, { name: 'expiry_date', label: 'الانتهاء', type: 'date' },
      ] },
      { name: 'costs', label: 'المصاريف (بالعملة الأساسية)', type: 'rows', min: 0, fields: [
        { name: 'code', label: 'البند (FREIGHT, INSURANCE, PORT…)', type: 'text', required: true }, { name: 'amount', label: 'المبلغ', type: 'number', required: true },
        { name: 'basis', label: 'التوزيع', type: 'select', options: BASIS }, { name: 'in_customs_value', label: 'ضمن القيمة الجمركية', type: 'checkbox' },
      ] },
    ], 'إنشاء الشحنة', async (v, f) => {
      await post('shipments', {
        reference: v.reference, currency: v.currency, exchange_rate: v.exchange_rate || undefined,
        lines: v.lines.map((l) => ({ product_id: Number(l.product_id), quantity: l.quantity, unit_price: l.unit_price, volume_cbm: l.volume_cbm, weight_kg: l.weight_kg, duty_rate: rateFromPercent(l.duty_pct), lot_no: l.lot_no, expiry_date: l.expiry_date })),
        costs: v.costs.map((c) => ({ code: c.code.toUpperCase(), amount: c.amount, basis: c.basis, in_customs_value: !!c.in_customs_value })),
      });
      toast('تم إنشاء الشحنة. الخطوة التالية: احتساب التكلفة الفعلية من قائمة الشحنات.', 'ok'); f.reset();
    }));
}

export default async function () {
  return tabs([{ label: 'الشحنات', render: async () => list() }, ...(can('shipments.manage') ? [{ label: 'شحنة جديدة', render: async () => create() }] : [])]);
}
