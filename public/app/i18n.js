// Arabic / English UI. The screens are written in Arabic; English is applied by translating phrases in the DOM
// (text nodes and a few attributes), so every screen, chart label, toast and confirmation follows the chosen language.
// In Arabic mode, a handful of server-side English names (ledger accounts, role labels, error messages) are shown in Arabic.
import { EN_PAIRS } from './i18n-en.js';

export const lang = (() => { try { return localStorage.getItem('erp_lang') === 'en' ? 'en' : 'ar'; } catch { return 'ar'; } })();
export const setLang = (l) => { try { localStorage.setItem('erp_lang', l); } catch { /* private mode */ } location.reload(); };

const HAS_AR = /[؀-ۿ]/;
const toEn = new Map(EN_PAIRS);
const enKeys = [...toEn.keys()].sort((a, b) => b.length - a.length);

// English names that come from the server, shown in Arabic when the UI is Arabic (whole-text matches only: SKUs and names stay untouched)
const AR_EXACT = new Map(Object.entries({
  'Cash on hand': 'الصندوق (نقد)', 'Bank - unspecified account': 'مصرف - حساب غير محدد', 'Accounts receivable': 'ذمم العملاء (المدينون)', Inventory: 'المخزون',
  'VAT receivable (input VAT)': 'ضريبة مدخلات مستردة', 'Goods received not invoiced (GR/IR)': 'بضاعة مستلمة غير مفوترة (GR/IR)', 'Accounts payable': 'ذمم الموردين (الدائنون)', 'VAT payable': 'ضريبة مبيعات مستحقة',
  'Sales revenue': 'إيرادات المبيعات', 'Cost of goods sold': 'تكلفة البضاعة المباعة', 'Inventory variance': 'فروق المخزون', 'Exchange differences': 'فروق العملة', 'Purchase price variance': 'فروق أسعار الشراء', 'General expenses': 'مصروفات عامة',
  'System administrator': 'مدير النظام', 'General manager': 'المدير العام', 'Finance manager': 'المدير المالي', 'Purchasing manager': 'مدير المشتريات', 'Warehouse clerk': 'أمين المستودع', 'Sales representative': 'مندوب المبيعات',
  'Cash customer - عميل نقدي': 'عميل نقدي',
}));
const AR_PREFIX = [['Bank - ', 'مصرف - ']];
// Server messages (only inside toasts and error boxes), fragment-replaced
const AR_MSG = [
  ['Missing permission:', 'لا تملك الصلاحية:'], ['No access to warehouse', 'لا تملك صلاحية الوصول إلى المستودع'],
  ['Insufficient stock for product', 'الكمية المتاحة غير كافية للصنف'], [' in warehouse ', ' في المستودع '], ['requested', 'المطلوب'], ['available', 'المتاح'],
  ['Credit limit', 'حد الائتمان'], ['exceeded: open exposure', 'تم تجاوزه: الالتزامات المفتوحة'], ['+ this order', '+ هذا الطلب'],
  ['Credit (deferred) sales are only for registered customers. Register the customer first, or sell for cash or bank transfer.', 'البيع بالأجل لا يتم إلا لعميل مسجَّل لدى الشركة. سجّل العميل أولاً أو بِع نقداً أو بالتحويل.'],
  ['Choose the bank account the money goes through.', 'اختر الحساب المصرفي الذي يمر عليه المبلغ.'], ['Cash and bank-transfer sales need the cash account or bank account the money goes to.', 'البيع النقدي أو بالتحويل يحتاج الصندوق أو الحساب المصرفي الذي يدخل إليه المبلغ.'],
  ['The creator of a purchase order cannot approve it.', 'منشئ أمر الشراء لا يمكنه اعتماده.'], ['This supplier invoice number is already booked.', 'رقم فاتورة المورد هذا مسجَّل مسبقاً.'],
  ['must be received into stock first', 'يجب استلامها في المخزون أولاً'], ['is not fully paid; the final cost is known only after payment.', 'غير مسددة بالكامل؛ تُعرف التكلفة النهائية بعد السداد.'],
  ['has already been settled to its final cost.', 'سُوّيت مسبقاً إلى تكلفتها النهائية.'], ['No supplier invoices are recorded for', 'لا توجد فواتير موردين مسجلة لـ'],
  ['No exchange rate for', 'لا يوجد سعر صرف للعملة'], ['Enter it under Accounting > Exchange rates.', 'سجّله من المحاسبة ← أسعار الصرف.'], ['is not enabled.', 'غير مفعّلة.'],
  ['The base currency always has rate 1.', 'العملة الأساسية سعرها دائماً 1.'], ['Cannot allocate', 'تعذر توزيع'], ['Enter volume/weight or choose another basis.', 'أدخل الحجم/الوزن أو اختر أساساً آخر.'],
  ['Allocation to invoice', 'التخصيص للفاتورة'], ['must be positive and not exceed its balance', 'يجب أن يكون موجباً ولا يتجاوز رصيدها'], ['These credentials are not valid.', 'بيانات الدخول غير صحيحة.'],
];

// Arabic letters attach to their neighbours, so a phrase must not be cut out of the middle of a longer word
// (otherwise the particle "تم" would be found inside "سبتمبر").
const AR_LETTER = /[\u0621-\u064A]/;
function replacePhrase(s, key, value) {
  let from = 0, out = '', i;
  const startsAr = AR_LETTER.test(key[0]), endsAr = AR_LETTER.test(key[key.length - 1]);
  while ((i = s.indexOf(key, from)) !== -1) {
    const end = i + key.length;
    const cut = (startsAr && i > 0 && AR_LETTER.test(s[i - 1])) || (endsAr && end < s.length && AR_LETTER.test(s[end]));
    out += s.slice(from, i) + (cut ? key : value);
    from = end;
  }
  return out + s.slice(from);
}
const enText = (s) => {
  if (toEn.has(s)) return toEn.get(s);
  let out = s;
  for (const k of enKeys) if (out.includes(k)) out = replacePhrase(out, k, toEn.get(k));
  return out;
};
const arText = (s, backendMsg) => {
  const t = s.trim();
  if (AR_EXACT.has(t)) return s.replace(t, AR_EXACT.get(t));
  const coded = t.match(/^(\d{4}) — (.+)$/);   // "1200 — Accounts receivable" in account pickers
  if (coded) { const n = arText(coded[2], false); if (n !== coded[2]) return s.replace(coded[2], n); }
  for (const [p, r] of AR_PREFIX) if (t.startsWith(p) && t.length > p.length) return s.replace(p, r);
  if (!backendMsg) return s;
  let out = s;
  for (const [k, v] of AR_MSG) if (out.includes(k)) out = out.split(k).join(v);
  return out;
};

/** Translate any text the app prepares itself (confirm dialogs etc.). */
export const tr = (s) => (lang === 'en' && HAS_AR.test(s) ? enText(s) : s);

const inMessage = (n) => !!n.parentElement?.closest('.toast, .neg, .err, .card.neg');
function handleText(node) {
  const v = node.nodeValue;
  if (!v || !v.trim()) return;
  if (lang === 'en') { if (HAS_AR.test(v) && !node.parentElement?.closest('[data-no-translate]')) { const t = enText(v); if (t !== v) node.nodeValue = t; } return; }   // the company name is never translated
  if (/[A-Za-z]/.test(v)) { const t = arText(v, inMessage(node)); if (t !== v) node.nodeValue = t; }
}
const ATTRS = ['placeholder', 'title', 'aria-label'];
function handleAttrs(el) {
  for (const a of ATTRS) {
    const v = el.getAttribute?.(a);
    if (v && lang === 'en' && HAS_AR.test(v)) { const t = enText(v); if (t !== v) el.setAttribute(a, t); }
  }
}
function walk(root) {
  if (root.nodeType === Node.TEXT_NODE) return handleText(root);
  if (root.nodeType !== Node.ELEMENT_NODE) return;
  handleAttrs(root);
  const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
  for (let n = w.nextNode(); n; n = w.nextNode()) n.nodeType === Node.TEXT_NODE ? handleText(n) : handleAttrs(n);
}

export function startI18n() {
  document.documentElement.lang = lang;
  document.documentElement.dir = lang === 'en' ? 'ltr' : 'rtl';
  new MutationObserver((muts) => {
    for (const m of muts) {
      if (m.type === 'characterData') handleText(m.target);
      else if (m.type === 'attributes') handleAttrs(m.target);
      else m.addedNodes.forEach(walk);
    }
  }).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
  walk(document.body);
}
