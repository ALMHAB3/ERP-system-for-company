import { einvoiceView } from './einvoice.js';
import { cityItems, h, get, post, put, download, form, table, tabs, panel, act, can, modal, money, qty, pct, badge, toast, state, opts, refItems, reloadLookups, today, wireBank, PAY_METHODS, SALE_METHODS } from '../ui.js';

const CATS = [['wholesale', 'جملة'], ['retail', 'تجزئة'], ['direct', 'مبيعات مباشرة']].map(([value, label]) => ({ value, label }));

const METHOD_LABEL = { cash: 'نقداً', transfer: 'تحويل مصرفي', credit: 'دين على العميل' };
const methodBadge = (m) => { const [t, c] = SALE_METHODS[m] || [m, '']; return h('span', { class: 'badge ' + c }, t); };

// Managers (reports.view) see what was sold today / last 7 / last 30 days; the sales representative sees their own sales.
function managerSummary() {
  const box = h('div'); let period = 'today';
  const label = { today: 'اليوم', week: 'آخر 7 أيام', month: 'آخر 30 يوماً' };
  const bar = h('div', { class: 'toolbar' }, Object.entries(label).map(([k, l]) => h('button', { class: 'btn secondary small', type: 'button', onclick: () => { period = k; load(); } }, l)));
  const load = async () => {
    box.replaceChildren(h('div', { class: 'card empty' }, '…'));
    const r = await get('reports/sales-summary', { period });
    box.replaceChildren(
      h('div', { class: 'cards' },
        h('div', { class: 'kpi' }, h('div', { class: 'l' }, 'عدد الفواتير (' + label[period] + ')'), h('div', { class: 'v' }, String(r.totals.count))),
        h('div', { class: 'kpi' }, h('div', { class: 'l' }, 'إجمالي المبيعات'), h('div', { class: 'v' }, money(r.totals.gross), h('span', { class: 'muted' }, ' ' + state.lookups.currency))),
        h('div', { class: 'kpi' }, h('div', { class: 'l' }, 'الصافي قبل الضريبة'), h('div', { class: 'v' }, money(r.totals.net)))),
      h('h3', null, 'حسب مندوب المبيعات'), table([{ label: 'المندوب', render: (x) => x.name }, { label: 'عدد الفواتير', num: true, render: (x) => x.count }, { label: 'الإجمالي', num: true, render: (x) => money(x.gross) }], r.by_rep, 'لا توجد مبيعات في هذه الفترة'),
      h('h3', null, 'حسب طريقة الدفع'), table([{ label: 'الطريقة', render: (x) => METHOD_LABEL[x.name] || x.name }, { label: 'عدد الفواتير', num: true, render: (x) => x.count }, { label: 'الإجمالي', num: true, render: (x) => money(x.gross) }], r.by_method, 'لا توجد مبيعات'),
      h('h3', null, 'الفواتير'), table([{ label: 'الفاتورة', key: 'number' }, { label: 'الوقت', render: (x) => x.issued }, { label: 'العميل', key: 'customer' }, { label: 'المندوب', render: (x) => x.rep || '—' },
        { label: 'طريقة الدفع', render: (x) => methodBadge(x.payment_method) }, { label: 'الإجمالي', num: true, render: (x) => money(x.gross) },
        { label: '', render: (x) => h('button', { class: 'btn secondary small', onclick: () => einvoiceView(x.invoice_id) }, 'عرض الفاتورة') }], r.invoices, 'لا توجد فواتير', { csv: 'sales-' + period }));
  };
  load();
  return h('div', null, bar, box);
}

function history() {
  if (can('reports.view')) return managerSummary();
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
      { label: 'طريقة الدفع', render: (o) => methodBadge(o.payment_method) }, { label: 'الصافي', num: true, render: (o) => money(o.net) },
      { label: 'البنود', render: (o) => o.lines.map((l) => `${l.sku} × ${qty(l.quantity)} @ ${money(l.unit_price)}`).join('، ') },
      { label: '', render: (o) => h('div', { class: 'row-actions' },
        o.invoice_id ? h('button', { class: 'btn secondary small', onclick: () => einvoiceView(o.invoice_id) }, 'عرض الفاتورة') : null,
        o.status === 'reserved' && can('sales.create') && o.lines.some((l) => l.tracks_serials) ? h('button', { class: 'btn secondary small', onclick: () => serialPicker(o, refresh) }, 'الأرقام التسلسلية') : null,
        o.status === 'reserved' && can('sales.ship') ? act('تسليم', () => post(`sales-orders/${o.id}/ship`), refresh, { confirm: 'تسليم الطلب ' + o.number + ' (صرف من المخزون)؟', ok: 'تم التسليم' }) : null,
        o.status === 'shipped' && can('sales.invoice') ? act('إصدار فاتورة', () => post(`sales-orders/${o.id}/invoice`), refresh, { ok: 'تم إصدار الفاتورة' }) : null,
        o.status === 'reserved' && can('sales.cancel') ? act('إلغاء', () => post(`sales-orders/${o.id}/cancel`), refresh, { cls: 'danger', confirm: 'إلغاء الطلب وتحرير الحجز؟', ok: 'تم الإلغاء' }) : null,
        o.status === 'invoiced' && can('sales.return') ? h('button', { class: 'btn secondary small', onclick: () => creditNote(o) }, 'مرتجع / إشعار دائن') : null) },
    ], r.data, 'لا توجد مبيعات بعد');
  });
}

function serialPicker(o, refresh) {
  const boxes = o.lines.filter((l) => l.tracks_serials).map((l) => ({ l, ta: h('textarea', { rows: 4, dir: 'ltr', placeholder: 'SN-001\nSN-002' }, l.serials.join('\n')) }));
  const m = modal('الأرقام التسلسلية — ' + o.number, h('div', null,
    h('p', { class: 'hint' }, 'اكتب رقماً تسلسلياً في كل سطر. يجب أن يساوي عددها كمية البند قبل التسليم، وأن تكون مسجلة في مخزون مستودع الطلب.'),
    boxes.map(({ l, ta }) => h('div', { class: 'field' }, h('label', null, `${l.sku} — ${l.name} (الكمية ${qty(l.quantity)})`), ta)),
    h('button', { class: 'btn', onclick: async (e) => {
      e.target.disabled = true;
      try {
        for (const { l, ta } of boxes) await put(`sales-orders/${o.id}/lines/${l.id}/serials`, { serials: ta.value.split('\n').map((x) => x.trim()).filter(Boolean) });
        toast('تم حفظ الأرقام التسلسلية', 'ok'); m.close(); refresh();
      } catch (err) { toast(err.message, 'error'); e.target.disabled = false; }
    } }, 'حفظ')));
}

// ONE screen: pick the goods, say how it is paid, press once. The system reserves, issues from stock, and issues the invoice.
function newOrder() {
  const registered = state.lookups.customers.filter((c) => !c.is_walk_in).map((c) => ({ id: c.id, label: c.name }));
  const onlyWarehouse = state.lookups.warehouses.length === 1 ? state.lookups.warehouses[0].id : '';
  const f = form([
    { name: 'payment_method', label: 'طريقة الدفع', type: 'select', value: 'cash', options: [{ value: 'cash', label: 'نقداً (كاش)' }, { value: 'transfer', label: 'تحويل مصرفي' }, { value: 'credit', label: 'دين على العميل (لعملاء مسجَّلين فقط)' }] },
    { name: 'bank_account_id', label: 'المصرف الذي حُوِّل عليه المبلغ', type: 'select', options: opts.banks(), hint: state.lookups.bank_accounts.length ? '' : 'لا توجد حسابات مصرفية: يضيفها المحاسب من «المحاسبة ← الحسابات المصرفية»' },
    { name: 'payment_reference', label: 'رقم التحويل / الإيصال (اختياري)', type: 'text' },
    { name: 'customer_name', label: 'اسم الزبون (اختياري — إن تُرك فارغاً يُسجَّل «غير معروف»)', type: 'text' },
    { name: 'customer_id', label: 'العميل المسجَّل (الدين يكون على عميل مسجَّل فقط)', type: 'ref', items: registered, hint: 'عميل جديد؟ سجّله أولاً من شاشة «العملاء».' },
    { name: 'warehouse_id', label: 'المستودع', type: 'select', value: onlyWarehouse, options: [{ value: '', label: '— اختر —' }, ...opts.warehouses()], required: true },
    { name: 'lines', label: 'الأصناف (السعر يحسبه النظام من قائمة الأسعار)', type: 'rows', enterNext: true, skipBlank: true, fields: [
      { name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true, onChange: (label, bag) => {
        const p = state.lookups.products.find((x) => x.sku + ' — ' + x.name === label.trim());   // serial numbers are asked only for products that track them
        bag.serials.input.closest('.field').hidden = !p?.tracks_serials;
      } }, { name: 'quantity', label: 'الكمية', type: 'number', required: true, last: true },
      { name: 'serials', label: 'الأرقام التسلسلية (رقم في كل سطر)', type: 'textarea', hidden: true }] },
  ], 'إتمام البيع وإصدار الفاتورة', async (v, el) => {
    if (!v.lines.length) throw new Error('أضف صنفاً واحداً على الأقل');
    if (v.payment_method === 'credit' && !v.customer_id) throw new Error('الدين لا يكون إلا على عميل مسجَّل: اختر العميل، أو سجّله من شاشة العملاء');
    if (v.payment_method === 'transfer' && !v.bank_account_id) throw new Error('اختر المصرف الذي حُوِّل عليه المبلغ');
    const credit = v.payment_method === 'credit';
    const r = await post('sales/quick', {
      warehouse_id: Number(v.warehouse_id), payment_method: v.payment_method,
      customer_id: v.customer_id ? Number(v.customer_id) : undefined, customer_name: credit ? undefined : v.customer_name || undefined,
      bank_account_id: v.payment_method === 'transfer' ? Number(v.bank_account_id) : undefined, payment_reference: credit ? undefined : v.payment_reference || undefined,
      lines: v.lines.map((l) => ({ product_id: Number(l.product_id), quantity: l.quantity, serials: l.serials ? l.serials.split('\n').map((x) => x.trim()).filter(Boolean) : undefined })),
    });
    toast(`تمت عملية البيع — الفاتورة ${r.invoice_number} بإجمالي ${money(r.gross)}`, 'ok'); el.reset(); I.lines.input._reset(); I.warehouse_id.input.value = onlyWarehouse; sync(); I.lines.input._rows[0].product_id.input.focus(); einvoiceView(r.invoice_id);   // reset() forgets the pre-selected branch
  });
  const I = f._inputs;
  const sync = () => {
    const m = I.payment_method.input.value;
    I.bank_account_id.input.closest('.field').hidden = m !== 'transfer';
    I.payment_reference.input.closest('.field').hidden = m === 'credit';
    I.customer_name.input.closest('.field').hidden = m === 'credit';
    I.customer_id.input.closest('.field').hidden = m !== 'credit';
  };
  f.addEventListener('change', sync); sync();
  return f;
}

export function customers() {
  const fields = (c) => [
    { name: 'name', label: 'اسم العميل', type: 'text', required: true, value: c?.name },
    { name: 'phone', label: 'رقم الهاتف', type: 'text', required: true, value: c?.phone || '' },
    { name: 'email', label: 'البريد الإلكتروني (اختياري)', type: 'text', value: c?.email || '' },
    { name: 'city', label: 'المدينة (ابحث في القائمة أو اكتبها بنفسك)', type: 'ref', free: true, items: cityItems(), required: true, value: c?.city || '' },
    { name: 'address', label: 'موقع الشركة أو المحل (اختياري)', type: 'text', value: c?.address || '' },
    { name: 'category', label: 'الفئة', type: 'select', options: CATS, value: c?.category },
    { name: 'credit_limit', label: 'المبلغ الذي يستطيع أخذه بالدين (0 = بلا حد)', type: 'number', value: c ? c.credit_limit.replace(/\.?0+$/, '') || '0' : '0' },
    { name: 'terms_days', label: 'مدة السداد بالأيام', type: 'number', value: c ? String(c.terms_days) : '0' },
  ];
  const body = (v) => ({ name: v.name, phone: v.phone, email: v.email || null, city: v.city, address: v.address || null, category: v.category, credit_limit: v.credit_limit || '0', terms_days: +v.terms_days });
  return panel(async (refresh) => {
    const canEdit = can('master_data.manage');
    return h('div', null,
      can('master_data.manage', 'sales.create') ? h('div', { class: 'card' }, h('h3', null, 'إضافة عميل'), (() => {
        const f = form(fields(), 'إضافة', async (v) => { await post('customers', body(v)); await reloadLookups(); toast('تمت إضافة العميل', 'ok'); refresh(); });
        return f;
      })()) : null,
      table([{ label: 'العميل', render: (c) => h('span', null, c.name, c.is_walk_in ? h('span', { class: 'badge walk' }, 'بدون تسجيل — نقداً/تحويل فقط') : null) },
        { label: 'الهاتف', render: (c) => (c.phone ? h('span', { class: 'ltr' }, c.phone) : '—') }, { label: 'المدينة', render: (c) => c.city || '—' }, { label: 'الموقع', render: (c) => c.address || '—' },
        { label: 'الفئة', render: (c) => CATS.find((x) => x.value === c.category)?.label }, { label: 'يستطيع الدين حتى', num: true, render: (c) => (Number(c.credit_limit) ? money(c.credit_limit) : 'بلا حد') },
        { label: 'مدة السداد', num: true, render: (c) => (c.terms_days ? c.terms_days + ' يوم' : 'فوري') },
        { label: '', render: (c) => canEdit && !c.is_walk_in ? h('button', { class: 'btn secondary small', onclick: () => {
          const m = modal('تعديل ' + c.name, form(fields(c), 'حفظ', async (v) => { await put('customers/' + c.id, body(v)); await reloadLookups(); toast('تم الحفظ', 'ok'); m.close(); refresh(); }));
        } }, 'تعديل') : null }], state.lookups.customers, 'لا يوجد عملاء', { csv: 'customers' }));
  });
}

export function prices() {
  return h('div', null, h('p', { class: 'hint' }, 'ترتيب التسعير: سعر العميل التعاقدي ← قائمة أسعار الفئة ← القائمة الافتراضية. خصم الكمية يُطبَّق على عملاء الجملة تلقائياً.'),
    form([
      { name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true }, { name: 'price', label: 'السعر', type: 'number', required: true },
      { name: 'category', label: 'قائمة الفئة', type: 'select', options: [{ value: '', label: 'القائمة الافتراضية' }, ...CATS] },
      { name: 'customer_id', label: 'أو سعر خاص لعميل (يلغي الفئة)', type: 'ref', items: refItems.customers() },
    ], 'حفظ السعر', async (v) => {
      const body = { product_id: Number(v.product_id), price: v.price };
      if (v.customer_id) body.customer_id = Number(v.customer_id); else if (v.category) body.category = v.category;
      await put('prices', body); toast('تم حفظ السعر', 'ok');
    }));
}

export function suggestions() {
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

export function receivables() {
  return panel(async (refresh) => {
    const inv = (await get('receivables/invoices')).data;
    const box = h('div');
    const picker = can('receipts.record') ? form([{ name: 'customer_id', label: 'تحصيل من عميل', type: 'ref', items: refItems.customers().filter((c) => !state.lookups.customers.find((x) => x.id === c.id)?.is_walk_in), required: true }], 'عرض فواتيره المفتوحة', (v) => {
      const open = inv.filter((i) => String(i.customer_id) === String(v.customer_id) && Number(i.balance) > 0);
      if (!open.length) return box.replaceChildren(h('div', { class: 'card empty' }, 'لا توجد فواتير مفتوحة'));
      const amounts = {};
      const rows = open.map((i) => { const input = h('input', { dir: 'ltr' }); amounts[i.id] = input; return h('tr', null, h('td', null, i.number), h('td', null, i.due_date || ''), h('td', { class: 'num' }, money(i.balance)), h('td', null, input), h('td', null, h('button', { class: 'btn secondary small', type: 'button', onclick: () => { input.value = i.balance; } }, 'كامل المتبقي'))); });
      box.replaceChildren(h('div', { class: 'tablewrap' }, h('table', null, h('thead', null, h('tr', null, ['الفاتورة', 'الاستحقاق', 'المتبقي', 'المحصَّل', ''].map((t) => h('th', null, t)))), h('tbody', null, rows))), wireBank(form([
        { name: 'received_on', label: 'تاريخ التحصيل', type: 'date', value: today(), required: true }, { name: 'method', label: 'طريقة التحصيل', type: 'select', options: PAY_METHODS },
        { name: 'bank_account_id', label: 'المصرف المستلم', type: 'select', options: opts.banks() }, { name: 'reference', label: 'المرجع', type: 'text', required: true },
      ], 'تسجيل التحصيل', async (m) => {
        const allocations = open.filter((i) => amounts[i.id].value.trim()).map((i) => ({ invoice_id: i.id, amount: amounts[i.id].value.trim() }));
        if (!allocations.length) throw new Error('حدد مبلغاً لفاتورة واحدة على الأقل');
        await post('receivables/receipts', { customer_id: Number(v.customer_id), received_on: m.received_on, method: m.method, bank_account_id: m.method === 'cash' ? undefined : Number(m.bank_account_id), reference: m.reference, allocations }); toast('تم تسجيل التحصيل', 'ok'); refresh();
      })));
    }) : null;
    return h('div', null, picker, box, h('h3', null, 'الفواتير'),
      table([{ label: 'الفاتورة', key: 'number' }, { label: 'العميل', key: 'customer' }, { label: 'الطلب', key: 'order' }, { label: 'الاستحقاق', render: (i) => i.due_date || '' }, { label: 'الإجمالي', num: true, render: (i) => money(i.gross) },
        { label: 'محصَّل', num: true, render: (i) => money(i.paid) }, { label: 'إشعارات دائنة', num: true, render: (i) => money(i.credited) },
        { label: 'الفاتورة الإلكترونية', render: (i) => h('div', { class: 'row-actions' }, h('button', { class: 'btn secondary small', onclick: () => einvoiceView(i.id) }, 'عرض / طباعة'), h('button', { class: 'btn secondary small', onclick: () => download(`sales-invoices/${i.id}/ubl`, i.number + '.xml').catch((e) => toast(e.message, 'error')) }, 'XML')) }, { label: 'المتبقي', num: true, render: (i) => h('b', { class: Number(i.balance) > 0 ? 'neg' : 'pos' }, money(i.balance)) }], inv, 'لا توجد فواتير'));
  });
}

export function ageing() {
  return panel(async () => {
    const r = await get('receivables/ageing');
    return h('div', null, h('div', { class: 'cards' }, Object.entries(r.buckets).map(([k, v]) => h('div', { class: 'kpi' }, h('div', { class: 'l' }, k === 'current' ? 'غير مستحق' : k + ' يوم تأخير'), h('div', { class: 'v' }, money(v))))),
      table([{ label: 'العميل', key: 'customer' }, { label: 'الإجمالي', num: true, render: (c) => money(c.total) }, { label: 'الفواتير', render: (c) => c.invoices.map((i) => `${i.number} (${money(i.balance)}${i.days_overdue ? '، متأخرة ' + i.days_overdue + ' يوم' : ''})`).join(' · ') }], r.customers, 'لا توجد ذمم مفتوحة'));
  });
}

export default async function () {
  return tabs([
    ...(can('sales.create') ? [{ label: 'بيع جديد', render: async () => newOrder() }] : []),
    { label: can('reports.view') ? 'المبيعات (اليوم / الأسبوع / الشهر)' : 'مبيعاتي', render: async () => history() },
  ]);
}
