import { h, get, modal, toast, money, qty } from '../ui.js';
// window.qrcode comes from /app/lib/qrcode.js (MIT, Kazuhiko Arase), loaded as a classic script in index.html; the QR is drawn as a data: image so the strict CSP still holds

const METHOD = { cash: 'نقداً', transfer: 'تحويل مصرفي', credit: 'بالأجل' };

// The printable invoice. Arabic company name is data-no-translate: it never changes with the language.
export async function einvoiceView(invoiceId) {
  let d;
  try { d = await get('sales-invoices/' + invoiceId); } catch (e) { return toast(e.message, 'error'); }
  const qr = window.qrcode(0, 'M'); qr.addData(d.qr); qr.make();
  const party = (t, p) => h('div', { class: 'party' }, h('h4', null, t), h('div', { 'data-no-translate': '' }, p.name), p.tax_id ? h('div', null, 'الرقم الضريبي: ', h('span', { class: 'ltr' }, p.tax_id)) : null, p.address ? h('div', null, p.address) : null, p.phone ? h('div', { class: 'ltr' }, p.phone) : null);
  const sheet = h('div', { class: 'print-sheet' },
    h('div', { class: 'ps-head' }, h('div', null, h('h2', { 'data-no-translate': '' }, d.seller.name), h('div', { class: 'muted' }, 'فاتورة ضريبية')),
      h('div', null, h('div', null, 'رقم الفاتورة: ', h('b', { class: 'ltr' }, d.number)), h('div', null, 'التاريخ: ', h('span', { class: 'ltr' }, d.issued_at.slice(0, 10))),
        d.due_date ? h('div', null, 'الاستحقاق: ', h('span', { class: 'ltr' }, d.due_date)) : null, h('div', null, 'طريقة الدفع: ' + (METHOD[d.payment_method] || d.payment_method)))),
    h('div', { class: 'ps-parties' }, party('البائع', d.seller), party('المشتري', d.buyer)),
    h('table', null, h('thead', null, h('tr', null, ['الصنف', 'الكمية', 'سعر الوحدة', 'الإجمالي'].map((t) => h('th', null, t)))),
      h('tbody', null, d.lines.map((l) => h('tr', null, h('td', null, l.sku + ' — ' + l.name), h('td', { class: 'num' }, qty(l.quantity) + ' ' + l.uom), h('td', { class: 'num' }, money(l.unit_price)), h('td', { class: 'num' }, money(l.net)))))),
    h('div', { class: 'ps-foot' },
      h('div', { class: 'ps-qr' }, h('img', { src: qr.createDataURL(4, 0), alt: 'QR', width: 132, height: 132 }), h('div', { class: 'ltr fp' }, 'SHA-256: ' + d.fingerprint.slice(0, 24) + '…')),
      h('div', { class: 'ps-totals' }, h('div', null, 'الصافي: ', h('b', null, money(d.totals.net) + ' ' + d.currency)), h('div', null, 'الضريبة: ', h('b', null, money(d.totals.tax) + ' ' + d.currency)), h('div', { class: 'big' }, 'الإجمالي: ', h('b', null, money(d.totals.gross) + ' ' + d.currency)))));
  modal('الفاتورة ' + d.number, h('div', null, sheet, h('div', { class: 'formbar noprint' }, h('button', { class: 'btn', onclick: () => window.print() }, 'طباعة / حفظ PDF'))));
}
