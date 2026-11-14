import { einvoiceView } from './einvoice.js';
import { attachSearch, cityItems, h, get, post, put, download, form, table, tabs, panel, act, can, modal, money, qty, pct, badge, toast, state, opts, refItems, reloadLookups, today, wireBank, PAY_METHODS, SALE_METHODS } from '../ui.js';

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
        o.status === 'reserved' && can('sales.ship') ? act('تسليم', () => post(`sales-orders/${o.id}/ship`), refresh, { confirm: 'تسليم الطلب ' + o.number + ' (صرف من المخزون)؟', ok: 'تم التسليم' }) : null,
        o.status === 'shipped' && can('sales.invoice') ? act('إصدار فاتورة', () => post(`sales-orders/${o.id}/invoice`), refresh, { ok: 'تم إصدار الفاتورة' }) : null,
        o.status === 'reserved' && can('sales.cancel') ? act('إلغاء', () => post(`sales-orders/${o.id}/cancel`), refresh, { cls: 'danger', confirm: 'إلغاء الطلب وتحرير الحجز؟', ok: 'تم الإلغاء' }) : null,
        o.status === 'invoiced' && can('sales.return') ? h('button', { class: 'btn secondary small', onclick: () => creditNote(o) }, 'مرتجع / إشعار دائن') : null) },
    ], r.data, 'لا توجد مبيعات بعد');
  });
}

// ONE screen, like an invoice: a table of lines (no., code, name, quantity, piece price, total), the invoice total under it,
// then a bar with the payment method, the amount paid and the discount. One button issues the invoice.
function newOrder() {
  const products = state.lookups.products.map((p) => ({ id: p.id, sku: p.sku, name: p.name, label: p.sku + ' — ' + p.name }));
  const registered = state.lookups.customers.filter((c) => !c.is_walk_in).map((c) => ({ id: c.id, label: c.name }));
  const vat = Number(state.lookups.vat_rate || 0);
  const onlyWarehouse = state.lookups.warehouses.length === 1 ? String(state.lookups.warehouses[0].id) : '';
  const num = (v) => { const n = parseFloat(String(v).replace(/,/g, '')); return Number.isFinite(n) ? n : 0; };
  const fmt = (n) => money(n.toFixed(4));
  const lab = (text, el, cls) => {
    const target = el.matches('input,select,textarea') ? el : el.querySelector('input,select,textarea');   // label <-> input association (accessibility and tests)
    const id = 'f' + Math.random().toString(36).slice(2, 9); target.id = id;
    return h('div', { class: 'field ' + (cls || '') }, h('label', { for: id }, text), el);
  };

  // --- who / where
  const buyerName = h('input', { placeholder: 'غير معروف' });
  const customerBox = h('input', { autocomplete: 'off', placeholder: 'اكتب للبحث…', role: 'combobox' });
  let customerId = null;
  const customerList = attachSearch(customerBox, registered, { onPick: (c) => { customerId = c.id; requote(); recalc(); } });
  customerBox.addEventListener('input', () => { if (customerId !== null) { customerId = null; requote(); recalc(); } });
  const warehouse = h('select', null, [h('option', { value: '' }, '— اختر —'), ...state.lookups.warehouses.map((w) => h('option', { value: w.id, selected: String(w.id) === onlyWarehouse }, w.code + ' — ' + w.name))]);

  // --- the table
  const rows = [];
  const tbody = h('tbody');
  const totalCell = h('td', { class: 'num strong' }, '0.00'), subCell = h('td', { class: 'num' }, '0.00'), discCell = h('td', { class: 'num' }, '0.00'), vatCell = h('td', { class: 'num' }, '0.00');
  const renumber = () => rows.forEach((r, i) => { r.no.textContent = String(i + 1); });
  const timers = new WeakMap();
  const requote = () => rows.forEach((r) => quote(r));
  const quote = (r) => {
    clearTimeout(timers.get(r));
    if (!r.product) { r.price = 0; r.priceCell.textContent = ''; r.lineCell.textContent = ''; return; }
    timers.set(r, setTimeout(async () => {
      if (!warehouse.value || num(r.qty.value) <= 0) { r.price = 0; r.priceCell.textContent = warehouse.value ? '' : 'اختر المستودع'; recalc(); return; }
      try {
        const q = await get('sales/quote', { product_id: r.product.id, warehouse_id: warehouse.value, quantity: r.qty.value.trim(), customer_id: customerId || '' });
        r.price = num(q.unit_price); r.priceCell.textContent = money(q.unit_price); r.priceCell.classList.remove('neg');
      } catch (e) { r.price = 0; r.priceCell.textContent = 'لا يوجد سعر'; r.priceCell.classList.add('neg'); }
      recalc();
    }, 250));
  };
  const addRow = () => {
    const r = { product: null, price: 0 };
    r.no = h('td', { class: 'rownum-cell' }, '');
    r.code = h('input', { autocomplete: 'off', placeholder: 'كود الصنف أو اسمه', role: 'combobox', dir: 'ltr' });
    r.nameInput = h('input', { autocomplete: 'off', placeholder: 'اسم الصنف أو كوده', role: 'combobox' });   // typing in either box fills the other
    r.qty = h('input', { inputmode: 'decimal', dir: 'ltr', placeholder: '0', class: 'qty' });
    r.priceCell = h('td', { class: 'num' }, ''); r.lineCell = h('td', { class: 'num strong' }, '');
    const choose = (p) => {
      r.product = p; r.code.value = p.sku; r.nameInput.value = p.name;
      if (!r.qty.value.trim()) r.qty.value = '1';
      quote(r); r.qty.focus(); r.qty.select();
    };
    const codeList = attachSearch(r.code, products, { onPick: choose }), nameList = attachSearch(r.nameInput, products, { onPick: choose });
    // Whatever was typed (code, name, or part of either) is resolved when the box is left or Enter is pressed: an exact code, an exact name, or the only match.
    const resolve = (text) => {
      const q = text.trim().toLowerCase();
      if (!q) return null;
      const hits = products.filter((p) => p.sku.toLowerCase() === q || p.name.toLowerCase() === q);
      if (hits.length === 1) return hits[0];
      const part = products.filter((p) => p.label.toLowerCase().includes(q));
      return part.length === 1 ? part[0] : null;
    };
    for (const box of [r.code, r.nameInput]) {
      box.addEventListener('input', () => {
        if (r.product && box.value !== (box === r.code ? r.product.sku : r.product.name)) { r.product = null; (box === r.code ? r.nameInput : r.code).value = ''; quote(r); recalc(); }
      });
      box.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.defaultPrevented) { e.preventDefault(); if (!r.product) { const p = resolve(box.value); if (p) choose(p); } else r.qty.focus(); } });
      box.addEventListener('blur', () => setTimeout(() => { if (!r.product && box.value.trim()) { const p = resolve(box.value); if (p) { r.product = p; r.code.value = p.sku; r.nameInput.value = p.name; if (!r.qty.value.trim()) r.qty.value = '1'; quote(r); } } }, 160));
    }
    r.qty.addEventListener('input', () => { quote(r); recalc(); });
    r.qty.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      if (!r.product || num(r.qty.value) <= 0) return;
      const next = rows[rows.indexOf(r) + 1] || addRow(); next.code.focus();   // Enter on the quantity goes to the next numbered line
    });
    const tr = h('tr', null, r.no, h('td', { class: 'code' }, h('div', { class: 'searchbox' }, r.code, codeList)), h('td', { class: 'name' }, h('div', { class: 'searchbox' }, r.nameInput, nameList)), h('td', null, r.qty), r.priceCell, r.lineCell,
      h('td', null, h('button', { class: 'btn danger small', type: 'button', title: 'حذف السطر', onclick: () => {
        if (rows.length === 1) { r.product = null; r.code.value = ''; r.qty.value = ''; r.nameInput.value = ''; quote(r); recalc(); return; }
        tr.remove(); rows.splice(rows.indexOf(r), 1); renumber(); recalc();
      } }, '✕')));
    r.tr = tr; rows.push(r); tbody.append(tr); renumber();
    return r;
  };

  // --- the bar under the table
  const method = h('select', null, [['cash', 'نقداً (كاش)'], ['transfer', 'تحويل مصرفي'], ['credit', 'دين على العميل']].map(([v, l]) => h('option', { value: v }, l)));
  const bank = h('select', null, [h('option', { value: '' }, '— اختر المصرف —'), ...opts.banks().map((o) => h('option', { value: o.value }, o.label))]);
  const reference = h('input', { placeholder: 'اختياري', dir: 'ltr' });
  const paid = h('input', { inputmode: 'decimal', dir: 'ltr' });
  const discount = h('input', { inputmode: 'decimal', dir: 'ltr', placeholder: '0' });
  const remaining = h('div', { class: 'remaining' });
  const methodF = lab('طريقة الدفع', method), bankF = lab('المصرف', bank), referenceF = lab('رقم التحويل / الإيصال', reference), paidF = lab('المدفوع', paid), discountF = lab('الخصم %', discount);
  let paidTouched = false;
  const totals = () => {
    const sub = rows.reduce((a, r) => a + r.price * num(r.qty.value), 0);
    const disc = Math.min(100, Math.max(0, num(discount.value)));
    const net = sub - sub * disc / 100;
    return { sub, disc: sub * disc / 100, tax: net * vat, total: net + net * vat };
  };
  const recalc = () => {
    rows.forEach((r) => { r.lineCell.textContent = r.product && r.price ? fmt(r.price * num(r.qty.value)) : ''; });
    const t = totals();
    subCell.textContent = fmt(t.sub); discCell.textContent = t.disc ? '-' + fmt(t.disc) : fmt(0); vatCell.textContent = fmt(t.tax); totalCell.textContent = fmt(t.total);
    const credit = method.value === 'credit';
    bankF.hidden = method.value !== 'transfer'; referenceF.hidden = credit; paidF.hidden = credit;
    if (!paidTouched) paid.value = t.total ? t.total.toFixed(2) : '';
    const paidNow = !paidTouched || paid.value.trim() === '' ? t.total : num(paid.value);   // an empty box means paid in full
    const rest = credit ? t.total : Math.max(0, t.total - paidNow);
    remaining.hidden = rest <= 0.00005;
    remaining.textContent = 'المتبقي: ' + fmt(rest) + ' ' + state.lookups.currency + ' — يُسجَّل ديناً على العميل (لا يكون إلا لعميل مسجَّل)';
    vatRow.hidden = !vat;
  };
  paid.addEventListener('input', () => { paidTouched = paid.value.trim() !== ''; recalc(); });
  [method, discount].forEach((el) => el.addEventListener('input', () => { if (el === method) paidTouched = false; recalc(); }));
  warehouse.addEventListener('change', requote);
  const vatRow = h('tr', null, h('td', { colspan: 5, class: 'lbl' }, 'الضريبة'), vatCell, h('td'));
  const reset = () => {
    while (rows.length > 1) { rows.pop().tr.remove(); }
    const r = rows[0]; r.product = null; r.code.value = ''; r.qty.value = ''; r.nameInput.value = ''; r.priceCell.textContent = ''; r.lineCell.textContent = '';
    buyerName.value = ''; customerBox.value = ''; customerId = null; reference.value = ''; discount.value = ''; paid.value = ''; paidTouched = false; method.value = 'cash'; renumber(); recalc(); r.code.focus();
  };
  const submit = h('button', { class: 'btn', type: 'button', onclick: async (e) => {
    const lines = rows.filter((r) => r.product).map((r) => ({ product_id: r.product.id, quantity: r.qty.value.trim() }));
    try {
      if (!lines.length) throw new Error('أضف صنفاً واحداً على الأقل');
      if (lines.some((l) => num(l.quantity) <= 0)) throw new Error('أدخل كمية صحيحة لكل صنف');
      if (!warehouse.value) throw new Error('اختر المستودع');
      const credit = method.value === 'credit', t = totals();
      if (credit && !customerId) throw new Error('الدين لا يكون إلا على عميل مسجَّل: ابحث عن العميل، أو سجّله من شاشة العملاء');
      if (method.value === 'transfer' && !bank.value) throw new Error('اختر المصرف الذي حُوِّل عليه المبلغ');
      if (!credit && paidTouched && num(paid.value) < t.total - 0.005 && !customerId) throw new Error('المدفوع أقل من الإجمالي: الباقي دين على العميل، فابحث عن عميل مسجَّل أو ادفع كامل المبلغ');
      e.target.disabled = true;
      const r = await post('sales/quick', {
        warehouse_id: Number(warehouse.value), payment_method: method.value, customer_id: customerId || undefined, customer_name: !credit && !customerId ? buyerName.value.trim() || undefined : undefined,
        bank_account_id: method.value === 'transfer' ? Number(bank.value) : undefined, payment_reference: !credit ? reference.value.trim() || undefined : undefined,
        discount_pct: num(discount.value) > 0 ? discount.value.trim() : undefined, paid: !credit && paidTouched && paid.value.trim() ? paid.value.trim() : undefined,   // untouched = paid in full (the server decides the exact total)
        lines,
      });
      toast(`تمت عملية البيع — الفاتورة ${r.invoice_number} بإجمالي ${money(r.gross)}`, 'ok'); reset(); einvoiceView(r.invoice_id);
    } catch (err) { toast(err.message, 'error'); } finally { e.target.disabled = false; }
  } }, 'إتمام البيع وإصدار الفاتورة');

  addRow(); recalc();
  return h('div', { class: 'sale' },
    h('div', { class: 'sale-head' }, lab('اسم الزبون (اختياري — يُسجَّل «غير معروف» إن تُرك فارغاً)', buyerName), lab('العميل المسجَّل (للدين أو الدفع الجزئي)', h('div', { class: 'searchbox' }, customerBox, customerList)), lab('المستودع', warehouse)),
    h('div', { class: 'tablewrap' }, h('table', { class: 'sale-table' },
      h('thead', null, h('tr', null, ['#', 'كود الصنف', 'اسم الصنف', 'العدد', 'سعر القطعة', 'المجموع', ''].map((t) => h('th', null, t)))), tbody,
      h('tfoot', null,
        h('tr', null, h('td', { colspan: 5, class: 'lbl' }, 'المجموع'), subCell, h('td')), h('tr', null, h('td', { colspan: 5, class: 'lbl' }, 'الخصم'), discCell, h('td')), vatRow,
        h('tr', { class: 'grand' }, h('td', { colspan: 5, class: 'lbl' }, 'إجمالي الفاتورة'), totalCell, h('td'))))),
    h('div', { class: 'muted' }, 'اكتب كود الصنف أو اسمه واضغط Enter، ثم الكمية واضغط Enter للسطر التالي.'),
    h('div', { class: 'sale-bar' }, methodF, bankF, referenceF, paidF, discountF, remaining),
    h('div', { class: 'formbar' }, submit));
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
