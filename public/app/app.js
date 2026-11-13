import { h, state, api, get, post, toast, can, form } from './ui.js';
import dashboard from './views/dashboard.js';
import inventory from './views/inventory.js';
import imports from './views/imports.js';
import purchasing from './views/purchasing.js';
import sales from './views/sales.js';
import finance from './views/finance.js';
import reports from './views/reports.js';
import admin from './views/admin.js';

const SECTIONS = [
  { id: 'dashboard', label: 'لوحة المؤشرات', perms: [], view: dashboard },
  { id: 'inventory', label: 'المخزون', perms: ['inventory.view'], view: inventory },
  { id: 'imports', label: 'الاستيراد والشحنات', perms: ['shipments.manage', 'shipments.cost', 'shipments.receive'], view: imports },
  { id: 'purchasing', label: 'المشتريات والموردون', perms: ['purchase_orders.create', 'purchase_orders.approve', 'purchase_orders.receive', 'supplier_invoices.record', 'supplier_payments.make', 'suppliers.manage', 'finance.view'], view: purchasing },
  { id: 'sales', label: 'المبيعات والعملاء', perms: ['sales.create', 'sales.ship', 'sales.invoice', 'receipts.record', 'finance.view'], view: sales },
  { id: 'finance', label: 'المحاسبة', perms: ['finance.view'], view: finance },
  { id: 'reports', label: 'التقارير والتخطيط', perms: ['reports.view'], view: reports },
  { id: 'admin', label: 'المستخدمون والتدقيق', perms: ['users.manage', 'audit.view'], view: admin },
];
const root = document.getElementById('app');

function loginScreen() {
  root.replaceChildren(h('div', { class: 'login' }, h('h1', null, 'تسجيل الدخول'),
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

async function render() {
  const allowed = SECTIONS.filter((s) => can(...s.perms));
  const id = (location.hash.replace('#/', '') || 'dashboard').split('?')[0];
  const section = allowed.find((s) => s.id === id) || allowed[0];
  document.querySelectorAll('nav.side a').forEach((a) => a.classList.toggle('active', a.dataset.id === section.id));
  const main = document.getElementById('main');
  main.replaceChildren(h('h2', null, section.label), h('div', { class: 'muted' }, 'جارٍ التحميل…'));
  try { main.replaceChildren(h('h2', null, section.label), await section.view()); }
  catch (e) { main.replaceChildren(h('h2', null, section.label), h('div', { class: 'card neg' }, e.message)); }
}

let listening = false;
async function start() {
  const token = sessionStorage.getItem('erp_token');
  if (!token) return loginScreen();
  try { [state.me, state.lookups] = await Promise.all([get('me'), get('lookups')]); }
  catch { sessionStorage.removeItem('erp_token'); return loginScreen(); }
  const allowed = SECTIONS.filter((s) => can(...s.perms));
  root.replaceChildren(h('div', { class: 'layout' },
    h('nav', { class: 'side' }, h('h1', null, 'نظام الاستيراد والتوزيع'),
      allowed.map((s) => h('a', { href: '#/' + s.id, 'data-id': s.id }, s.label)),
      h('div', { class: 'who' }, state.me.name, h('br'), h('span', { class: 'ltr' }, state.me.email), h('br'),
        h('button', { class: 'btn secondary small', onclick: async () => { try { await api('DELETE', 'auth/token'); } catch {} sessionStorage.removeItem('erp_token'); location.hash = ''; location.reload(); } }, 'تسجيل الخروج'))),
    h('main', { id: 'main' })));
  if (!listening) { window.addEventListener('hashchange', render); listening = true; }
  render();
}
window.addEventListener('unhandledrejection', (e) => toast(e.reason?.message || 'خطأ غير متوقع', 'error'));
start();
