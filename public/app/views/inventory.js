import { h, get, post, put, form, table, tabs, panel, can, money, qty, toast, state, opts, refItems, reloadLookups } from '../ui.js';

const whOptions = (all) => [...(all ? [{ value: '', label: 'كل المستودعات' }] : []), ...opts.warehouses()];

async function balances() {
  const wrap = h('div');
  const load = async (warehouse_id) => {
    const r = await get('inventory/balances', { warehouse_id });
    const showCost = r.data.length && 'value' in r.data[0];
    wrap.replaceChildren(table([
      { label: 'الصنف', render: (x) => x.sku }, { label: 'المستودع', render: (x) => state.lookups.warehouses.find((w) => w.id === x.warehouse_id)?.code ?? x.warehouse_id },
      { label: 'الفعلي', num: true, render: (x) => qty(x.on_hand) }, { label: 'المحجوز', num: true, render: (x) => qty(x.reserved) },
      { label: 'المتاح للبيع', num: true, render: (x) => qty(x.available) },
      ...(showCost ? [{ label: 'متوسط التكلفة', num: true, render: (x) => money(x.average_cost) }, { label: 'القيمة', num: true, render: (x) => money(x.value) }] : []),
    ], r.data));
  };
  const sel = form([{ name: 'warehouse_id', label: 'المستودع', type: 'select', options: whOptions(true) }], 'عرض', (v) => load(v.warehouse_id ?? ''));
  await load('');
  return h('div', null, sel, wrap);
}

async function lots() {
  const r = await get('inventory/lots');
  const showCost = r.data.length && 'unit_cost' in r.data[0];
  return table([
    { label: 'الصنف', render: (x) => x.sku + ' — ' + x.name }, { label: 'المستودع', key: 'warehouse' }, { label: 'الدفعة', key: 'lot_no' },
    { label: 'الانتهاء', render: (x) => x.expiry_date || '—' }, { label: 'الكمية', num: true, render: (x) => qty(x.on_hand) },
    ...(showCost ? [{ label: 'تكلفة الوحدة', num: true, render: (x) => money(x.unit_cost) }] : []),
  ], r.data);
}

function adjust() {
  return h('div', null, h('p', { class: 'hint' }, 'التسوية تسجَّل كحركة في الدفتر (لا تعديل مباشر للرصيد). الإخراج يسحب من الدفعة المحددة أو الأقدم صلاحية أولاً.'),
    form([
      { name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true },
      { name: 'warehouse_id', label: 'المستودع', type: 'select', options: opts.warehouses(), required: true },
      { name: 'direction', label: 'نوع الحركة', type: 'select', options: [{ value: 'in', label: 'إدخال (زيادة)' }, { value: 'out', label: 'إخراج (نقص / إتلاف)' }] },
      { name: 'quantity', label: 'الكمية', type: 'number', required: true },
      { name: 'unit_cost', label: 'تكلفة الوحدة (للإدخال)', type: 'number' },
      { name: 'lot_no', label: 'رقم الدفعة', type: 'text' }, { name: 'expiry_date', label: 'تاريخ الانتهاء', type: 'date' },
      { name: 'reason', label: 'السبب', type: 'text', required: true, wide: true },
    ], 'تسجيل التسوية', async (v, f) => { await post('inventory/adjustments', { ...v, product_id: Number(v.product_id) }); toast('تم تسجيل التسوية', 'ok'); f.reset(); }));
}

function transfer() {
  return form([
    { name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true },
    { name: 'from_warehouse_id', label: 'من مستودع', type: 'select', options: opts.warehouses(), required: true },
    { name: 'to_warehouse_id', label: 'إلى مستودع', type: 'select', options: opts.warehouses(), required: true },
    { name: 'quantity', label: 'الكمية', type: 'number', required: true },
    { name: 'lot_no', label: 'دفعة محددة (اختياري)', type: 'text' },
    { name: 'reference', label: 'مرجع التحويل', type: 'text', required: true },
  ], 'تنفيذ التحويل', async (v, f) => { await post('inventory/transfers', { ...v, product_id: Number(v.product_id) }); toast('تم التحويل بتكلفة الدفعات', 'ok'); f.reset(); });
}

function master() {
  return h('div', null,
    h('h3', null, 'إضافة صنف'),
    form([
      { name: 'sku', label: 'رمز الصنف (SKU)', type: 'text', required: true }, { name: 'name', label: 'الاسم', type: 'text', required: true },
      { name: 'base_uom', label: 'وحدة القياس', type: 'text', value: 'PCS' },
      { name: 'lead_time_days', label: 'مدة التوريد (يوم)', type: 'number', value: '45' }, { name: 'cover_days', label: 'تغطية الطلب الواحد (يوم)', type: 'number', value: '30' },
      { name: 'has_expiry', label: 'له تاريخ انتهاء صلاحية (يُطلب تاريخه عند الاستلام)', type: 'checkbox' },
    ], 'إضافة', async (v, f) => { await post('products', { ...v, lead_time_days: +v.lead_time_days, cover_days: +v.cover_days }); await reloadLookups(); toast('تمت إضافة الصنف', 'ok'); f.reset(); }),
    h('h3', null, 'تعديل خصائص صنف (التخطيط والصلاحية)'),
    form([
      { name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true, onChange: (label, bag) => {
        const p = state.lookups.products.find((x) => x.sku + ' — ' + x.name === label.trim());   // show the product's current settings
        if (p) { bag.lead_time_days.input.value = p.lead_time_days; bag.cover_days.input.value = p.cover_days; bag.has_expiry.input.checked = !!p.has_expiry; }
      } },
      { name: 'lead_time_days', label: 'مدة التوريد (يوم)', type: 'number', required: true }, { name: 'cover_days', label: 'تغطية الطلب (يوم)', type: 'number', required: true },
      { name: 'has_expiry', label: 'له تاريخ انتهاء صلاحية', type: 'checkbox' },
    ], 'حفظ', async (v) => { await put('products/' + v.product_id, { lead_time_days: +v.lead_time_days, cover_days: +v.cover_days, has_expiry: !!v.has_expiry }); await reloadLookups(); toast('تم الحفظ', 'ok'); }),
    h('h3', null, 'إضافة مستودع'),
    form([
      { name: 'code', label: 'الرمز', type: 'text', required: true }, { name: 'name', label: 'الاسم', type: 'text', required: true },
      { name: 'type', label: 'النوع', type: 'select', options: [['main', 'رئيسي'], ['branch', 'فرعي'], ['transit', 'ترانزيت'], ['returns', 'مرتجعات'], ['damaged', 'تالف']].map(([value, label]) => ({ value, label })) },
    ], 'إضافة', async (v, f) => { await post('warehouses', v); await reloadLookups(); toast('تمت إضافة المستودع (امنح المستخدمين صلاحية الوصول إليه)', 'ok'); f.reset(); }));
}

export default async function () {
  return tabs([
    { label: 'الأرصدة', render: balances }, { label: 'الدفعات والصلاحية', render: lots },
    ...(can('inventory.adjust') ? [{ label: 'تسوية مخزون', render: adjust }] : []),
    ...(can('inventory.transfer') ? [{ label: 'تحويل بين المستودعات', render: transfer }] : []),
    ...(can('master_data.manage') ? [{ label: 'الأصناف والمستودعات', render: master }] : []),
  ]);
}
