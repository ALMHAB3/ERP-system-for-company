import { h, state, api, get, post, put, toast, can, form, modal, skeleton, enter, stagger } from './ui.js';
import { lang, setLang, startI18n } from './i18n.js';
import dashboard from './views/dashboard.js';
import inventory from './views/inventory.js';
import imports from './views/imports.js';
import purchasing from './views/purchasing.js';
import sales from './views/sales.js';
import customers from './views/customers.js';
import suppliers from './views/suppliers.js';
import finance from './views/finance.js';
import reports from './views/reports.js';
import admin from './views/admin.js';
import hr from './views/hr.js';

const SECTIONS = [
  { id: 'dashboard', label: 'لوحة المؤشرات', perms: [], view: dashboard },
  { id: 'inventory', label: 'المخزون', perms: ['inventory.view'], view: inventory },
  { id: 'imports', label: 'الاستيراد والشحنات', perms: ['shipments.manage', 'shipments.cost', 'shipments.receive'], view: imports },
  { id: 'sales', label: 'المبيعات', perms: ['sales.create', 'sales.ship', 'sales.invoice', 'reports.view'], view: sales },
  { id: 'customers', label: 'العملاء والديون', perms: ['sales.create', 'receipts.record', 'finance.view', 'master_data.manage'], view: customers },
  // finance.view opens these two only for company-wide staff: branch accountants have no suppliers or imports
  { id: 'purchasing', label: 'المشتريات', perms: ['purchase_orders.create', 'purchase_orders.approve', 'purchase_orders.receive', 'shipments.settle'], widePerms: ['finance.view'], view: purchasing },
  { id: 'suppliers', label: 'الموردون', perms: ['supplier_invoices.record', 'supplier_payments.make', 'suppliers.manage'], widePerms: ['finance.view'], view: suppliers },
  { id: 'hr', label: 'الموارد البشرية', perms: ['hr.view'], view: hr },
  { id: 'finance', label: 'المحاسبة', perms: ['finance.view'], view: finance },
  { id: 'reports', label: 'التقارير والتخطيط', perms: ['reports.view'], view: reports },
  { id: 'admin', label: 'المستخدمون والتدقيق', perms: ['users.manage', 'audit.view', 'integrations.manage'], view: admin },
];
const root = document.getElementById('app');
let COMPANY = '';
const companyName = (tag, cls) => h(tag, { class: cls, 'data-no-translate': '' }, COMPANY);   // always Arabic, in both languages

// Navigation icons: simple 24px stroke drawings (no icon font, no external files)
const ICONS = {
  dashboard: ['M3 3h7v9H3z', 'M14 3h7v5h-7z', 'M14 12h7v9h-7z', 'M3 16h7v5H3z'],
  inventory: ['M21 8l-9-5-9 5v8l9 5 9-5V8z', 'M3 8l9 5 9-5', 'M12 13v8'],
  customers: ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M4 21c0-4 3.5-7 8-7s8 3 8 7'],
  suppliers: ['M3 7h11v9H3z', 'M14 10h4l3 3v3h-7z', 'M7 19.5h.01', 'M17 19.5h.01'],
  hr: ['M16 11a3 3 0 1 0-6 0', 'M5 20c0-3 2.5-5 6-5s6 2 6 5', 'M18 8h3', 'M19.5 6.5v3'],
  imports: ['M3 17l2 3h14l2-3', 'M5 17v-6h14v6', 'M9 11V7h6v4', 'M12 3v4'],
  purchasing: ['M3 4h2l2.5 11h10L20 7H6', 'M9 19.5h.01', 'M17 19.5h.01'],
  sales: ['M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2V3z', 'M9 8h6', 'M9 12h6'],
  finance: ['M5 3h14v18H5z', 'M8 7h8', 'M8 12h2', 'M14 12h2', 'M8 16h2', 'M14 16h2'],
  reports: ['M5 21V11', 'M12 21V4', 'M19 21v-7'],
  admin: ['M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z', 'M9 12l2 2 4-4'],
};
function icon(id) {
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  for (const [k, v] of Object.entries({ viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.8', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', class: 'ico', 'aria-hidden': 'true' })) svg.setAttribute(k, v);
  for (const d of ICONS[id] || []) { const p = document.createElementNS(NS, 'path'); p.setAttribute('d', d); svg.append(p); }
  return svg;
}

const langButton = () => h('button', { class: 'btn secondary small', type: 'button', onclick: () => setLang(lang === 'en' ? 'ar' : 'en') }, lang === 'en' ? 'العربية' : 'English');

function loginScreen() {
  root.replaceChildren(h('div', { class: 'login' }, h('div', { class: 'toolbar' }, langButton()), companyName('div', 'brand'), h('h1', null, 'تسجيل الدخول'),
    form([
      { name: 'email', label: 'البريد الإلكتروني', type: 'text', required: true, wide: true },
      { name: 'password', label: 'كلمة المرور', type: 'password', required: true, wide: true },
    ], 'دخول', async (v) => {
      let res;
      try { res = await post('auth/token', { ...v, device_name: navigator.userAgent.slice(0, 60) || 'browser' }); }
      catch (e) { throw new Error(e.status === 429 ? 'محاولات كثيرة، انتظر دقيقة ثم أعد المحاولة' : 'البريد الإلكتروني أو كلمة المرور غير صحيحة'); }
      sessionStorage.setItem('erp_token', res.token);
      await start();
    })));
}

// The signed-in user changes their own name, login e-mail or password (always confirmed with the current password).
function account() {
  const m = modal('حسابي', h('div', null,
    h('p', { class: 'hint' }, 'لتغيير الاسم أو البريد الإلكتروني (اسم الدخول) أو كلمة المرور أدخل كلمة المرور الحالية. اترك كلمة المرور الجديدة فارغة إن لم ترد تغييرها. تغيير كلمة المرور يُخرج حسابك من الأجهزة الأخرى.'),
    form([
      { name: 'name', label: 'الاسم', type: 'text', required: true, value: state.me.name, wide: true },
      { name: 'email', label: 'البريد الإلكتروني (اسم الدخول)', type: 'text', required: true, value: state.me.email, wide: true },
      { name: 'password', label: 'كلمة المرور الجديدة (12 حرفاً+، حروف كبيرة وصغيرة وأرقام)', type: 'password', wide: true },
      { name: 'password_confirmation', label: 'تأكيد كلمة المرور الجديدة', type: 'password', wide: true },
      { name: 'current_password', label: 'كلمة المرور الحالية', type: 'password', required: true, wide: true },
    ], 'حفظ', async (v) => {
      if (v.password !== v.password_confirmation) throw new Error('كلمة المرور الجديدة وتأكيدها غير متطابقين');
      const body = { current_password: v.current_password, name: v.name, email: v.email };
      if (v.password) Object.assign(body, { password: v.password, password_confirmation: v.password_confirmation });
      const r = await put('me/account', body);
      Object.assign(state.me, r);
      toast('تم حفظ بيانات حسابك', 'ok'); m.close(); start();
    })));
}

async function render() {
  const allowed = SECTIONS.filter((s) => can(...s.perms) || (s.widePerms && can('warehouses.all') && can(...s.widePerms)));
  const id = (location.hash.replace('#/', '') || 'dashboard').split('?')[0];
  const section = allowed.find((s) => s.id === id) || allowed[0];
  document.querySelectorAll('nav.side a').forEach((a) => a.classList.toggle('active', a.dataset.id === section.id));
  const main = document.getElementById('main');
  const banner = main.querySelector('.banner');
  main.replaceChildren(...(banner ? [banner] : []), h('h2', null, section.label), skeleton(section.id === 'dashboard' ? 'cards' : undefined));
  try { const view = await section.view(); main.replaceChildren(...(banner ? [banner] : []), h('h2', null, section.label), enter(h('div', null, view))); stagger(main); }
  catch (e) { main.replaceChildren(...(banner ? [banner] : []), h('h2', null, section.label), h('div', { class: 'card neg' }, e.message)); }
}

let listening = false;
async function start() {
  try { COMPANY = (await (await fetch('/api/public-info')).json()).company_name || ''; if (COMPANY) document.title = COMPANY; } catch { /* name stays empty */ }
  const token = sessionStorage.getItem('erp_token');
  if (!token) return loginScreen();
  try { [state.me, state.lookups] = await Promise.all([get('me'), get('lookups')]); }
  catch { sessionStorage.removeItem('erp_token'); return loginScreen(); }
  const allowed = SECTIONS.filter((s) => can(...s.perms) || (s.widePerms && can('warehouses.all') && can(...s.widePerms)));
  root.replaceChildren(h('div', { class: 'layout' },
    h('nav', { class: 'side' }, companyName('h1'),
      allowed.map((s) => h('a', { href: '#/' + s.id, 'data-id': s.id }, icon(s.id), s.label)),
      h('div', { class: 'who' }, h('div', null, h('b', null, state.me.name), h('br'), h('span', { class: 'ltr' }, state.me.email)), h('div', { class: 'row' }, langButton(), ' ', h('button', { class: 'btn secondary small', onclick: account }, 'حسابي'), ' ',
        h('button', { class: 'btn secondary small', onclick: async () => { try { await api('DELETE', 'auth/token'); } catch {} sessionStorage.removeItem('erp_token'); location.hash = ''; location.reload(); } }, 'تسجيل الخروج')))),
    h('main', { id: 'main' }, state.lookups.demo ? h('div', { class: 'banner' }, 'هذا النظام يحتوي بيانات تجريبية للتجربة فقط. ابدأ من قاعدة بيانات فارغة قبل إدخال البيانات الحقيقية.') : null)));
  if (!listening) { window.addEventListener('hashchange', render); listening = true; }
  render();
}
startI18n();
window.addEventListener('unhandledrejection', (e) => toast(e.reason?.message || 'خطأ غير متوقع', 'error'));
start();
