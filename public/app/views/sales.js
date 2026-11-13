import { h, get, post, put, form, table, tabs, panel, act, can, modal, money, qty, pct, badge, toast, state, opts, refItems, reloadLookups, today } from '../ui.js';

const CATS = [['wholesale', 'جملة'], ['retail', 'تجزئة'], ['direct', 'مبيعات مباشرة']].map(([value, label]) => ({ value, label }));

function orders() {
  return panel(async (refresh) => {
    const r = await get('sales-orders');
    const creditNote = (o) => {
      const m = modal('إشعار دائن / مرتجع على ' + o.number, h('div', null, h('p', { class: 'hint' }, 'تعود البضاعة إلى نفس الدفعة بتكلفتها الأصلية، ويُخفَّض رصيد الفاتورة.'), form([
        { name: 'reason', label: 'سبب الإرجاع', type: 'text', required: true, wide: true },
        ...o.lines.map((l) => ({ name: 'q' + l.id, label: `${l.sku} (المباع ${qty(l.quantity)}) — كمية المرتجع`, type: 'number' })),
      ], 'إصدار الإشعار', async (v) => {
        const lines = o.lines.filter((l) => v['q' + l.id] && Number(v['q' + l.id]) > 0).map((l) => ({ line_id: l.id, quantity: v['q' + l.id] }));
        if (!lines.length) throw new Error('أدخل كمية مرتجعة لبند واحد على الأقل');
        await post(`sales-orders/${o.id}/credit-notes`, { reason: v.reason, lines }); toast('تم إصدار الإشعار الدائن وإرجاع المخزون', 'ok'); m.close(); refresh();
      })));
    };
    return table([
      { label: 'الطلب', key: 'number' }, { label: 'العميل', key: 'customer' }, { label: 'الحالة', render: (o) => badge(o.status) },
      { label: 'الصافي', num: true, render: (o) => money(o.net) },
      { label: 'البنود', render: (o) => o.lines.map((l) => `${l.sku} × ${qty(l.quantity)} @ ${money(l.unit_price)}${Number(l.discount_rate) ? ' (خصم ' + pct(l.discount_rate) + ')' : ''}`).join('، ') },
      { label: '', render: (o) => h('div', { class: 'row-actions' },
        o.status === 'reserved' && can('sales.ship') ? act('شحن', () => post(`sales-orders/${o.id}/ship`), refresh, { confirm: 'شحن الطلب ' + o.number + ' (سحب المخزون)؟', ok: 'تم الشحن' }) : null,
        o.status === 'shipped' && can('sales.invoice') ? act('إصدار فاتورة', () => post(`sales-orders/${o.id}/invoice`), refresh, { ok: 'تم إصدار الفاتورة' }) : null,
        o.status === 'reserved' && can('sales.cancel') ? act('إلغاء', () => post(`sales-orders/${o.id}/cancel`), refresh, { cls: 'danger', confirm: 'إلغاء الطلب وتحرير الحجز؟', ok: 'تم الإلغاء' }) : null,
        o.status === 'invoiced' && can('sales.return') ? h('button', { class: 'btn secondary small', onclick: () => creditNote(o) }, 'مرتجع / إشعار دائن') : null) },
    ], r.data, 'لا توجد طلبات');
  });
}

function newOrder() {
  return form([
    { name: 'customer_id', label: 'العميل', type: 'select', options: opts.customers(), required: true },
    { name: 'warehouse_id', label: 'المستودع', type: 'select', options: opts.warehouses(), required: true },
    { name: 'lines', label: 'البنود (السعر والخصم يحسبهما النظام حسب فئة العميل)', type: 'rows', fields: [
      { name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true }, { name: 'quantity', label: 'الكمية', type: 'number', required: true }] },
    ...(can('sales.approve_below_margin') ? [{ name: 'allow_below_margin', label: 'السماح بالبيع تحت الحد الأدنى للهامش', type: 'checkbox' }] : []),
    ...(can('sales.override_credit') ? [{ name: 'override_credit', label: 'تجاوز حد الائتمان', type: 'checkbox' }] : []),
  ], 'إنشاء الطلب وحجز المخزون', async (v, f) => {
    const o = await post('sales-orders', { customer_id: Number(v.customer_id), warehouse_id: Number(v.warehouse_id), allow_below_margin: !!v.allow_below_margin, override_credit: !!v.override_credit, lines: v.lines.map((l) => ({ product_id: Number(l.product_id), quantity: l.quantity })) });
    toast(`تم إنشاء الطلب ${o.number} (صافي ${money(o.net)}) وحُجز المخزون`, 'ok'); f.reset();
  });
}

function customers() {
  return panel(async (refresh) => h('div', null,
    can('master_data.manage') ? h('div', { class: 'card' }, h('h3', null, 'إضافة عميل'), form([
      { name: 'name', label: 'الاسم', type: 'text', required: true }, { name: 'category', label: 'الفئة', type: 'select', options: CATS },
      { name: 'credit_limit', label: 'حد الائتمان (0 = بلا حد)', type: 'number', value: '0' }, { name: 'terms_days', label: 'مدة السداد (يوم)', type: 'number', value: '0' },
    ], 'إضافة', async (v) => { await post('customers', { ...v, terms_days: +v.terms_days }); await reloadLookups(); toast('تمت إضافة العميل', 'ok'); refresh(); })) : null,
    table([{ label: 'العميل', key: 'name' }, { label: 'الفئة', render: (c) => CATS.find((x) => x.value === c.category)?.label }, { label: 'حد الائتمان', num: true, render: (c) => (Number(c.credit_limit) ? money(c.credit_limit) : 'بلا حد') }, { label: 'مدة السداد', num: true, key: 'terms_days' },
      { label: '', render: (c) => can('master_data.manage') ? h('button', { class: 'btn secondary small', onclick: () => {
        const m = modal('تعديل ' + c.name, form([
          { name: 'category', label: 'الفئة', type: 'select', options: CATS, value: c.category }, { name: 'credit_limit', label: 'حد الائتمان', type: 'number', value: c.credit_limit }, { name: 'terms_days', label: 'مدة السداد', type: 'number', value: String(c.terms_days) },
        ], 'حفظ', async (v) => { await put('customers/' + c.id, { category: v.category, credit_limit: v.credit_limit, terms_days: +v.terms_days }); await reloadLookups(); toast('تم الحفظ', 'ok'); m.close(); refresh(); }));
      } }, 'تعديل') : null }], state.lookups.customers)));
}

function prices() {
  return h('div', null, h('p', { class: 'hint' }, 'ترتيب التسعير: سعر العميل التعاقدي ← قائمة أسعار الفئة ← القائمة الافتراضية. خصم الكمية يُطبَّق على عملاء الجملة تلقائياً.'),
    form([
      { name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true }, { name: 'price', label: 'السعر', type: 'number', required: true },
      { name: 'category', label: 'قائمة الفئة', type: 'select', options: [{ value: '', label: 'القائمة الافتراضية' }, ...CATS] },
      { name: 'customer_id', label: 'أو سعر خاص لعميل (يلغي الفئة)', type: 'select', options: [{ value: '', label: '—' }, ...opts.customers()] },
    ], 'حفظ السعر', async (v) => {
      const body = { product_id: Number(v.product_id), price: v.price };
      if (v.customer_id) body.customer_id = Number(v.customer_id); else if (v.category) body.category = v.category;
      await put('prices', body); toast('تم حفظ السعر', 'ok');
    }));
}

function suggestions() {
  const out = h('div');
  const picker = form([
    { name: 'category', label: 'فئة العملاء', type: 'select', options: CATS },
    { name: 'margin_pct', label: 'نسبة الربح % (فارغ = الافتراضي للفئة)', type: 'number' },
    { name: 'basis', label: 'أساس التكلفة', type: 'select', options: [{ value: 'last', label: 'آخر تكلفة شراء (بالدينار)' }, { value: 'average', label: 'متوسط تكلفة المخزون الحالي' }] },
  ], 'احسب الأسعار المقترحة', async (v) => {
    const r = await get('pricing/suggestions', v);
    const picks = {};
    const rows = r.data.map((x) => {
      const check = h('input', { type: 'checkbox', checked: true }), price = h('input', { dir: 'ltr', value: x.suggested_price.replace(/\.?0+$/, '') });
      picks[x.product_id] = { check, price };
      const buy = x.last_purchase ? `${money(x.last_purchase.price)} ${x.last_purchase.currency} × ${x.last_purchase.rate}` : '—';
      return h('tr', null, h('td', null, check), h('td', null, x.sku + ' — ' + x.name), h('td', { class: 'num' }, buy), h('td', { class: 'num' }, money(x.unit_cost)), h('td', { class: 'num' }, money(x.raw_price)),
        h('td', null, price), h('td', { class: 'num' }, x.current_price ? money(x.current_price) : '—'), h('td', { class: 'num' }, x.margin_after_rounding_pct ?? ''));
    });
    out.replaceChildren(
      h('p', { class: 'hint' }, `السعر = التكلفة بالدينار × (1 + ${r.margin_pct}%) ثم يُقرَّب للأعلى إلى مضاعف ${qty(r.step)} ${r.currency} (مثال: 353 ← 355، 356 ← 360). يمكنك تعديل أي سعر؛ يُقرَّب مجدداً عند الحفظ.`),
      r.data.length ? h('div', { class: 'tablewrap' }, h('table', null, h('thead', null, h('tr', null, ['', 'الصنف', 'آخر شراء', 'التكلفة (' + r.currency + ')', 'قبل التقريب', 'السعر المعتمد', 'السعر الحالي', 'الهامش % بعد التقريب'].map((t) => h('th', null, t)))), h('tbody', null, rows)))
        : h('div', { class: 'card empty' }, 'لا توجد أصناف مشتراة لتسعيرها'),
      r.data.length ? h('button', { class: 'btn', type: 'button', onclick: async (e) => {
        const prices = Object.entries(picks).filter(([, p]) => p.check.checked && p.price.value.trim()).map(([id, p]) => ({ product_id: Number(id), price: p.price.value.trim() }));
        if (!prices.length) return toast('حدد صنفاً واحداً على الأقل', 'error');
        e.target.disabled = true;
        try { const res = await post('pricing/apply', { category: v.category, prices }); toast(`تم اعتماد ${res.applied} سعراً في قائمة أسعار الفئة`, 'ok'); } catch (err) { toast(err.message, 'error'); } finally { e.target.disabled = false; }
      } }, 'اعتماد الأسعار المحددة') : null);
  });
  return h('div', null, picker, out);
}

function receivables() {
  return panel(async (refresh) => {
    const inv = (await get('receivables/invoices')).data;
    const box = h('div');
    const picker = can('receipts.record') ? form([{ name: 'customer_id', label: 'تحصيل من عميل', type: 'select', options: [{ value: '', label: '— اختر —' }, ...opts.customers()] }], 'عرض فواتيره المفتوحة', (v) => {
      const open = inv.filter((i) => String(i.customer_id) === String(v.customer_id) && Number(i.balance) > 0);
      if (!open.length) return box.replaceChildren(h('div', { class: 'card empty' }, 'لا توجد فواتير مفتوحة'));
      const amounts = {};
      const rows = open.map((i) => { const input = h('input', { dir: 'ltr' }); amounts[i.id] = input; return h('tr', null, h('td', null, i.number), h('td', null, i.due_date || ''), h('td', { class: 'num' }, money(i.balance)), h('td', null, input), h('td', null, h('button', { class: 'btn secondary small', type: 'button', onclick: () => { input.value = i.balance; } }, 'كامل المتبقي'))); });
      box.replaceChildren(h('div', { class: 'tablewrap' }, h('table', null, h('thead', null, h('tr', null, ['الفاتورة', 'الاستحقاق', 'المتبقي', 'المحصَّل', ''].map((t) => h('th', null, t)))), h('tbody', null, rows))), form([
        { name: 'received_on', label: 'تاريخ التحصيل', type: 'date', value: today(), required: true }, { name: 'method', label: 'الطريقة', type: 'select', options: [['bank', 'تحويل'], ['cash', 'نقد'], ['cheque', 'شيك']].map(([value, label]) => ({ value, label })) }, { name: 'reference', label: 'المرجع', type: 'text', required: true },
      ], 'تسجيل التحصيل', async (m) => {
        const allocations = open.filter((i) => amounts[i.id].value.trim()).map((i) => ({ invoice_id: i.id, amount: amounts[i.id].value.trim() }));
        if (!allocations.length) throw new Error('حدد مبلغاً لفاتورة واحدة على الأقل');
        await post('receivables/receipts', { customer_id: Number(v.customer_id), received_on: m.received_on, method: m.method, reference: m.reference, allocations }); toast('تم تسجيل التحصيل', 'ok'); refresh();
      }));
    }) : null;
    return h('div', null, picker, box, h('h3', null, 'الفواتير'),
      table([{ label: 'الفاتورة', key: 'number' }, { label: 'العميل', key: 'customer' }, { label: 'الطلب', key: 'order' }, { label: 'الاستحقاق', render: (i) => i.due_date || '' }, { label: 'الإجمالي', num: true, render: (i) => money(i.gross) },
        { label: 'محصَّل', num: true, render: (i) => money(i.paid) }, { label: 'إشعارات دائنة', num: true, render: (i) => money(i.credited) }, { label: 'المتبقي', num: true, render: (i) => h('b', { class: Number(i.balance) > 0 ? 'neg' : 'pos' }, money(i.balance)) }], inv, 'لا توجد فواتير'));
  });
}

function ageing() {
  return panel(async () => {
    const r = await get('receivables/ageing');
    return h('div', null, h('div', { class: 'cards' }, Object.entries(r.buckets).map(([k, v]) => h('div', { class: 'kpi' }, h('div', { class: 'l' }, k === 'current' ? 'غير مستحق' : k + ' يوم تأخير'), h('div', { class: 'v' }, money(v))))),
      table([{ label: 'العميل', key: 'customer' }, { label: 'الإجمالي', num: true, render: (c) => money(c.total) }, { label: 'الفواتير', render: (c) => c.invoices.map((i) => `${i.number} (${money(i.balance)}${i.days_overdue ? '، متأخرة ' + i.days_overdue + ' يوم' : ''})`).join(' · ') }], r.customers, 'لا توجد ذمم مفتوحة'));
  });
}

export default async function () {
  return tabs([
    ...(can('sales.create', 'sales.ship', 'sales.invoice') ? [{ label: 'طلبات البيع', render: async () => orders() }] : []),
    ...(can('sales.create') ? [{ label: 'طلب بيع جديد', render: async () => newOrder() }] : []),
    { label: 'العملاء', render: async () => customers() },
    ...(can('master_data.manage') ? [{ label: 'الأسعار', render: async () => prices() }] : []),
    ...(can('master_data.manage') && can('inventory.view_cost') ? [{ label: 'اقتراح الأسعار (مقرَّبة)', render: async () => suggestions() }] : []),
    ...(can('finance.view') ? [{ label: 'الفواتير والتحصيل', render: async () => receivables() }] : []),
    ...(can('reports.view') ? [{ label: 'أعمار ذمم العملاء', render: async () => ageing() }] : []),
  ]);
}
