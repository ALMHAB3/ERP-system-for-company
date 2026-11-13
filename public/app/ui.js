// Tiny UI toolkit. No framework, no innerHTML: every value from the server is inserted as text.
export const state = { me: null, lookups: null };

export function h(tag, attrs, ...kids) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2).toLowerCase(), v);
    else if (['value', 'checked', 'disabled', 'selected'].includes(k)) el[k] = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  const add = (c) => {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) return c.forEach(add);
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  };
  kids.forEach(add);
  return el;
}

// ---------- formatting (display only; amounts stay exact decimal strings on the wire) ----------
const money2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty4 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 });
export const money = (s) => (s === null || s === undefined || s === '' ? '' : money2.format(Number(s)));
export const qty = (s) => (s === null || s === undefined || s === '' ? '' : qty4.format(Number(s)));
export const pct = (rate) => qty4.format(Number(rate) * 100) + '%';
export const rateFromPercent = (p) => (Number(p) / 100).toFixed(4);
export const today = () => new Date().toISOString().slice(0, 10);
export const STATUS = {
  open: ['مفتوحة', 'warn'], costed: ['تم احتساب التكلفة', 'warn'], received: ['مستلمة', 'ok'],
  draft: ['مسودة', ''], pending_approval: ['بانتظار الاعتماد', 'warn'], approved: ['معتمد', 'ok'], cancelled: ['ملغى', 'bad'],
  reserved: ['محجوز', 'warn'], shipped: ['تم الشحن', 'warn'], invoiced: ['مفوتر', 'ok'],
};
export const badge = (s) => { const [t, c] = STATUS[s] || [s, '']; return h('span', { class: 'badge ' + c }, t); };

// ---------- api ----------
export async function api(method, path, body) {
  const token = sessionStorage.getItem('erp_token');
  const res = await fetch('/api/' + path, {
    method,
    headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: 'Bearer ' + token } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (res.status === 204) return null;
  const json = await res.json().catch(() => ({}));
  if (res.status === 401 && token) { sessionStorage.removeItem('erp_token'); location.reload(); }
  if (!res.ok) {
    const msgs = json.errors ? Object.values(json.errors).flat() : [json.message || 'تعذر تنفيذ الطلب'];
    throw Object.assign(new Error(msgs.join(' — ')), { status: res.status, errors: json.errors || {}, code: json.code });
  }
  return json;
}
export const get = (p, q) => api('GET', p + (q ? '?' + new URLSearchParams(Object.entries(q).filter(([, v]) => v !== '' && v != null)) : ''));
export const post = (p, b) => api('POST', p, b || {});
export const put = (p, b) => api('PUT', p, b);

export function toast(msg, kind) {
  const t = h('div', { class: 'toast ' + (kind || '') }, msg);
  document.getElementById('toasts').append(t);
  setTimeout(() => t.remove(), kind === 'error' ? 9000 : 3500);
}
export const can = (...perms) => perms.length === 0 || perms.some((p) => state.me.permissions.includes(p));

// ---------- components ----------
function csvDownload(name, cols, rows) {
  const cell = (v) => '"' + String(v ?? '').replace(/"/g, '""').replace(/\s+/g, ' ').trim() + '"';
  const text = (c, r) => { const v = c.csv ? c.csv(r) : c.render ? c.render(r) : r[c.key]; return v instanceof Node ? v.textContent : v; };
  const body = [cols.map((c) => cell(c.label)).join(','), ...rows.map((r) => cols.map((c) => cell(text(c, r))).join(','))].join('\r\n');
  const url = URL.createObjectURL(new Blob(['\ufeff' + body], { type: 'text/csv;charset=utf-8' }));
  const a = h('a', { href: url, download: name + '-' + today() + '.csv' });
  document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 2000);
}

// table(cols, rows, emptyText, {csv:'file-name'}) — the csv option adds an Excel-friendly export button.
export function table(cols, rows, empty, o) {
  if (!rows || rows.length === 0) return h('div', { class: 'card empty' }, empty || 'لا توجد بيانات');
  const t = tableEl(cols, rows);
  return o?.csv ? h('div', null, h('div', { class: 'csvbar' }, h('button', { class: 'btn secondary small', type: 'button', onclick: () => csvDownload(o.csv, cols, rows) }, 'تصدير Excel (CSV)'), ' ', h('button', { class: 'btn secondary small', type: 'button', onclick: () => window.print() }, 'طباعة')), t) : t;
}
function tableEl(cols, rows) {
  return h('div', { class: 'tablewrap' }, h('table', null,
    h('thead', null, h('tr', null, cols.map((c) => h('th', { class: c.num ? 'num' : '' }, c.label)))),
    h('tbody', null, rows.map((r) => h('tr', null, cols.map((c) => {
      const v = c.render ? c.render(r) : r[c.key];
      return h('td', { class: c.num ? 'num' : '' }, v === null || v === undefined ? '' : v);
    })))),
  ));
}

export function tabs(items) {
  const body = h('div');
  const bar = h('div', { class: 'tabs' });
  const show = async (i) => {
    [...bar.children].forEach((b, j) => b.classList.toggle('active', i === j));
    body.replaceChildren(h('div', { class: 'muted' }, 'جارٍ التحميل…'));
    try { body.replaceChildren(await items[i].render()); } catch (e) { body.replaceChildren(h('div', { class: 'card neg' }, e.message)); }
  };
  items.forEach((it, i) => bar.append(h('button', { type: 'button', onclick: () => show(i) }, it.label)));
  show(0);
  return h('div', null, bar, body);
}

export function modal(title, content) {
  const bg = h('div', { class: 'modal-bg', onclick: (e) => e.target === bg && bg.remove() },
    h('div', { class: 'modal', role: 'dialog' }, h('h3', null, title), content, h('div', { class: 'formbar' }, h('button', { class: 'btn secondary small', type: 'button', onclick: () => bg.remove() }, 'إغلاق'))));
  document.body.append(bg);
  return { close: () => bg.remove() };
}

// A field is {name,label,type,options,required,value,wide,hint,fields(for rows),items(for ref)}
// types: text number date password select ref checkbox textarea rows
export function form(fields, submitLabel, onSubmit, opts) {
  const inputs = {};
  const el = h('form', { class: 'f', novalidate: true });
  const build = (f, bag) => {
    const id = 'f' + Math.random().toString(36).slice(2, 9);
    let input;
    if (f.type === 'select') {
      input = h('select', { id }, f.options.map((o) => h('option', { value: o.value, selected: String(o.value) === String(f.value ?? '') }, o.label)));
    } else if (f.type === 'ref') {
      const list = h('datalist', { id: id + 'l' }, f.items.map((i) => h('option', { value: i.label })));
      input = h('input', { id, list: id + 'l', autocomplete: 'off', placeholder: f.placeholder || 'اكتب للبحث…' });
      input._items = f.items; input._list = list;
    } else if (f.type === 'textarea') input = h('textarea', { id, rows: 3 });
    else if (f.type === 'checkbox') input = h('input', { id, type: 'checkbox', checked: !!f.value });
    else input = h('input', { id, type: f.type === 'number' ? 'text' : f.type || 'text', inputmode: f.type === 'number' ? 'decimal' : null, value: f.value ?? '', placeholder: f.placeholder, dir: ['number', 'date', 'password'].includes(f.type) ? 'ltr' : null, autocomplete: f.type === 'password' ? 'new-password' : 'off' });
    bag[f.name] = { f, input };
    if (f.onChange) input.addEventListener('change', () => f.onChange(input.value, bag));
    const err = h('div', { class: 'err' });
    bag[f.name].err = err;
    if (f.type === 'checkbox') return h('div', { class: 'field check' + (f.wide ? ' wide' : '') }, input, h('label', { for: id }, f.label));
    return h('div', { class: 'field' + (f.wide ? ' wide' : '') }, h('label', { for: id }, f.label + (f.required ? ' *' : '')), input, input._list, (bag[f.name].hintEl = f.hint ? h('div', { class: 'muted' }, f.hint) : null), err);
  };
  const read = (bag) => {
    const out = {};
    for (const [name, { f, input }] of Object.entries(bag)) {
      if (f.type === 'rows') { out[name] = input._rows.map((r) => read(r)).filter((r) => r !== null); continue; }
      let v;
      if (f.type === 'checkbox') v = input.checked;
      else if (f.type === 'ref') {
        const item = input._items.find((i) => i.label === input.value.trim());
        if (input.value.trim() && !item) throw new Error('اختر ' + f.label + ' من القائمة');
        v = item ? item.id : '';
      } else v = input.value.trim();
      if (f.required && (v === '' || v === null)) throw new Error('الحقل مطلوب: ' + f.label);
      if (v !== '' || f.type === 'checkbox') out[name] = f.type === 'select' && /^\d+$/.test(v) && f.asInt ? Number(v) : v;
    }
    return out;
  };
  for (const f of fields) {
    if (f.type === 'rows') {
      const holder = h('div', { class: 'subrows' });
      const rows = [];
      const add = () => {
        const bag = {};
        const row = h('div', { class: 'subrow' }, f.fields.map((sf) => build(sf, bag)),
          h('button', { class: 'btn danger small', type: 'button', onclick: () => { row.remove(); rows.splice(rows.indexOf(bag), 1); } }, 'حذف'));
        rows.push(bag); holder.append(row);
      };
      for (let i = 0; i < (f.min ?? 1); i++) add();
      const bag = {}; const wrap = h('div', { class: 'field wide' }, h('label', null, f.label), holder, h('div', null, h('button', { class: 'btn secondary small', type: 'button', onclick: add }, '+ إضافة سطر')));
      holder._rows = rows; bag.input = holder;
      inputs[f.name] = { f, input: holder }; holder._rows = rows;
      el.append(wrap);
    } else el.append(build(f, inputs));
  }
  const btn = h('button', { class: 'btn', type: 'submit' }, submitLabel);
  el.append(h('div', { class: 'formbar' }, btn, opts?.extra));
  el.addEventListener('submit', async (e) => {
    e.preventDefault();
    Object.values(inputs).forEach((x) => x.err && (x.err.textContent = ''));
    btn.disabled = true;
    try { await onSubmit(read(inputs), el); }
    catch (err) { toast(err.message, 'error'); }
    finally { btn.disabled = false; }
  });
  return el;
}

export const refItems = {
  products: () => state.lookups.products.map((p) => ({ id: p.id, label: p.sku + ' — ' + p.name })),
  customers: () => state.lookups.customers.map((c) => ({ id: c.id, label: c.name })),
  suppliers: () => state.lookups.suppliers.map((s) => ({ id: s.id, label: s.name })),
};
export const opts = {
  currencies: () => state.lookups.currencies.map((c) => ({ value: c.code, label: c.code + ' — ' + c.name })),
  warehouses: () => state.lookups.warehouses.map((w) => ({ value: w.id, label: w.code + ' — ' + w.name })),
  customers: () => state.lookups.customers.map((c) => ({ value: c.id, label: c.name })),
  suppliers: () => state.lookups.suppliers.map((s) => ({ value: s.id, label: s.name })),
  accounts: () => state.lookups.accounts.map((a) => ({ value: a.code, label: a.code + ' — ' + a.name })),
};
export async function reloadLookups() { state.lookups = await get('lookups'); }
// Fills a rate input from the latest stored rate whenever the currency select changes.
export function rateHint(currency) {
  const c = state.lookups.currencies.find((x) => x.code === currency);
  if (!c || currency === state.lookups.currency) return { value: '1', hint: 'العملة الأساسية: السعر 1' };
  return c.rate ? { value: c.rate, hint: `آخر سعر مسجل بتاريخ ${c.rate_date}` } : { value: '', hint: 'لا يوجد سعر مسجل — أدخله هنا أو من المحاسبة ← أسعار الصرف' };
}
export function currencyFields(defaultCode, rateLabel) {
  const first = rateHint(defaultCode);
  return [
    { name: 'currency', label: 'العملة', type: 'select', options: opts.currencies(), value: defaultCode, onChange: (v, bag) => { const r = rateHint(v); bag.exchange_rate.input.value = r.value; bag.exchange_rate.hintEl && (bag.exchange_rate.hintEl.textContent = r.hint); } },
    { name: 'exchange_rate', label: rateLabel || 'سعر الصرف إلى ' + state.lookups.currency, type: 'number', value: first.value, hint: first.hint, hintId: true },
  ];
}
export const dl = (o) => h('div', { class: 'card' }, Object.entries(o).map(([k, v]) => h('div', null, h('span', { class: 'muted' }, k + ': '), v)));

// A self-refreshing block: build(refresh) returns the node; actions call refresh() to reload it in place.
export function panel(build) {
  const box = h('div');
  const refresh = async () => {
    try { box.replaceChildren(await build(refresh)); } catch (e) { box.replaceChildren(h('div', { class: 'card neg' }, e.message)); }
  };
  refresh();
  return box;
}
// Action button: confirm -> call -> toast -> refresh.
export function act(label, fn, refresh, o) {
  const b = h('button', { class: 'btn small ' + (o?.cls || ''), type: 'button', onclick: async () => {
    if (o?.confirm && !confirm(o.confirm)) return;
    b.disabled = true;
    try { const r = await fn(); toast(o?.ok || 'تم', 'ok'); if (refresh) await refresh(); return r; }
    catch (e) { toast(e.message, 'error'); } finally { b.disabled = false; }
  } }, label);
  return b;
}
