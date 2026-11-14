import { prices, suggestions } from './sales.js';
import { h, get, post, form, table, tabs, panel, act, can, modal, money, qty, pct, rateFromPercent, badge, toast, opts, refItems, currencyFields, state } from '../ui.js';

const BASIS = [['volume', 'حسب الحجم (CBM)'], ['weight', 'حسب الوزن (كجم)'], ['value', 'حسب القيمة'], ['quantity', 'حسب الكمية']].map(([value, label]) => ({ value, label }));

function details(s) {
  return h('div', null,
    h('p', null, 'العملة: ', s.currency, ' · سعر الصرف: ', money(s.exchange_rate), ' · الحالة: ', badge(s.status)),
    h('h4', null, 'البنود'),
    table([
      { label: 'الصنف', render: (l) => l.sku + ' — ' + l.name }, { label: 'الدفعة', render: (l) => l.lot_no || '(تلقائي)' }, { label: 'الانتهاء', render: (l) => l.expiry_date || '—' },
      { label: 'الكمية', num: true, render: (l) => qty(l.quantity) }, { label: 'سعر الشراء', num: true, render: (l) => money(l.unit_price) }, { label: 'سعر البيع', num: true, render: (l) => (l.sale_price ? money(l.sale_price) : '—') }, { label: 'الجمارك', num: true, render: (l) => pct(l.duty_rate) },
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
  const wrapper = h('div');
  const f = createForm();
  wrapper.append(f, preview(f));
  return wrapper;
}

// Live estimate: landed cost in dinar and suggested selling prices (rounded up) while the shipment is being typed. Nothing is saved.
function preview(f) {
  const margin = h('input', { dir: 'ltr', placeholder: 'اختياري', size: 6 });
  const out = h('div', { class: 'muted' }, 'أدخل صنفاً بكميته وسعره لتظهر التكلفة بالدينار وسعر البيع المقترح فوراً.');
  let timer;
  const run = async () => {
    const v = f.querySelector('form')._peek();
    const rows = (v.lines || []).map((l, i) => ({ l, i })).filter(({ l }) => l.quantity && l.unit_price);
    if (!rows.length) return out.replaceChildren('أدخل صنفاً بكميته وسعره لتظهر التكلفة بالدينار وسعر البيع المقترح فوراً.');
    try {
      const r = await post('shipments/preview', {
        currency: v.currency, exchange_rate: v.exchange_rate || undefined, margin_pct: margin.value.trim() || undefined,
        lines: rows.map(({ l }) => ({ quantity: l.quantity, unit_price: l.unit_price, volume_cbm: l.volume_cbm || undefined, weight_kg: l.weight_kg || undefined, duty_rate: rateFromPercent(l.duty_pct || '0') })),
        costs: (v.costs || []).filter((c) => c.code && c.amount).map((c) => ({ code: c.code.toUpperCase(), amount: c.amount, basis: c.basis, in_customs_value: !!c.in_customs_value })),
      });
      out.replaceChildren(
        table([
          { label: 'الصنف', render: (x) => x.label }, { label: 'سعر الوحدة (' + v.currency + ')', num: true, render: (x) => money(x.price) },
          { label: 'تكلفة الشراء بالدينار (وحدة)', num: true, render: (x) => money(x.r.purchase_unit_base) },
          { label: 'التكلفة الفعلية للوحدة (مع المصاريف والجمارك)', num: true, render: (x) => h('b', null, money(x.r.landed_unit_cost)) },
          { label: 'سعر بيع جملة (' + pct(Number(r.margins.wholesale) / 100) + ')', num: true, render: (x) => h('b', null, money(x.r.suggested.wholesale)) },
          { label: 'تجزئة (' + pct(Number(r.margins.retail) / 100) + ')', num: true, render: (x) => h('b', null, money(x.r.suggested.retail)) },
          { label: 'مباشر (' + pct(Number(r.margins.direct) / 100) + ')', num: true, render: (x) => h('b', null, money(x.r.suggested.direct)) },
        ], rows.map(({ l, i }, k) => ({ label: refItems.products().find((p) => p.id === Number(l.product_id))?.label || 'سطر ' + (i + 1), price: l.unit_price, r: r.lines[k] }))),
        h('div', null, 'إجمالي تكلفة الشحنة بالدينار: ', h('b', null, money(r.total)), ' ' + r.base + ' · التقريب للأعلى إلى مضاعف ' + qty(r.step) + ' ' + r.base));
    } catch (e) { out.replaceChildren(h('span', { class: 'neg' }, e.message)); }
  };
  const schedule = () => { clearTimeout(timer); timer = setTimeout(run, 500); };
  f.addEventListener('input', schedule); f.addEventListener('change', schedule); margin.addEventListener('input', schedule);
  return h('div', { class: 'card' }, h('h3', null, 'تقدير فوري بالدينار وسعر البيع المقترح'),
    h('div', { class: 'toolbar' }, h('div', { class: 'field' }, h('label', null, 'نسبة ربح موحّدة % (فارغ = الافتراضي لكل فئة)'), margin)), out);
}

function createForm() {
  return h('div', null, h('p', { class: 'hint' }, 'أدخل بنود الفاتورة التجارية ثم المصاريف. الشحن والتأمين يُحسبان ضمن القيمة الجمركية (CIF). الرسم الجمركي يُدخل كنسبة مئوية لكل بند حسب بند التعرفة.'),
    form([
      { name: 'reference', label: 'مرجع الشحنة / رقم الحاوية', type: 'text', required: true },
      ...currencyFields('USD', 'سعر الصرف إلى الدينار الليبي'),
      { name: 'lines', label: 'بنود الفاتورة التجارية', type: 'rows', fields: [
        { name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true, onChange: (label, bag) => {
          const p = state.lookups.products.find((x) => x.sku + ' — ' + x.name === label.trim());   // only products that expire ask for an expiry date
          bag.expiry_date.input.closest('.field').hidden = !p?.has_expiry; bag.expiry_date.f.required = !!p?.has_expiry;
        } },
        { name: 'quantity', label: 'الكمية', type: 'number', required: true }, { name: 'unit_price', label: 'سعر الشراء للوحدة (بعملة الشحنة)', type: 'number', required: true },
        { name: 'volume_cbm', label: 'الحجم CBM', type: 'number' }, { name: 'weight_kg', label: 'الوزن كجم', type: 'number' },
        { name: 'duty_pct', label: 'الرسم الجمركي %', type: 'number', value: '0', required: true },
        { name: 'sale_price', label: 'سعر البيع للوحدة بالدينار (يُعتمد عند الاستلام)', type: 'number' },
        { name: 'lot_no', label: 'رقم دفعة المورد', type: 'text' }, { name: 'expiry_date', label: 'تاريخ انتهاء الصلاحية', type: 'date', hidden: true },
      ] },
      { name: 'costs', label: 'المصاريف (بالعملة الأساسية)', type: 'rows', min: 0, fields: [
        { name: 'code', label: 'البند (FREIGHT, INSURANCE, PORT…)', type: 'text', required: true }, { name: 'amount', label: 'المبلغ', type: 'number', required: true },
        { name: 'basis', label: 'التوزيع', type: 'select', options: BASIS }, { name: 'in_customs_value', label: 'ضمن القيمة الجمركية', type: 'checkbox' },
      ] },
    ], 'إنشاء الشحنة', async (v, f) => {
      await post('shipments', {
        reference: v.reference, currency: v.currency, exchange_rate: v.exchange_rate || undefined,
        lines: v.lines.map((l) => ({ product_id: Number(l.product_id), quantity: l.quantity, unit_price: l.unit_price, volume_cbm: l.volume_cbm, weight_kg: l.weight_kg, duty_rate: rateFromPercent(l.duty_pct), sale_price: l.sale_price || undefined, lot_no: l.lot_no, expiry_date: l.expiry_date || undefined })),
        costs: v.costs.map((c) => ({ code: c.code.toUpperCase(), amount: c.amount, basis: c.basis, in_customs_value: !!c.in_customs_value })),
      });
      toast('تم إنشاء الشحنة. الخطوة التالية: احتساب التكلفة الفعلية من قائمة الشحنات.', 'ok'); f.reset();
    }));
}

export default async function () {
  return tabs([{ label: 'الشحنات', render: async () => list() }, ...(can('shipments.manage') ? [{ label: 'شحنة جديدة', render: async () => create() }] : []),
    ...(can('master_data.manage') ? [{ label: 'أسعار البيع', render: async () => prices() }] : []),
    ...(can('master_data.manage') && can('inventory.view_cost') ? [{ label: 'اقتراح الأسعار (مقرَّبة)', render: async () => suggestions() }] : [])]);
}
