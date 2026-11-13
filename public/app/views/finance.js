import { h, get, table, money, badge } from '../ui.js';

export default async function () {
  const r = await get('finance/trial-balance');
  const sum = (k) => r.data.reduce((a, x) => a + Number(x[k]), 0);
  const d = sum('debit'), c = sum('credit');
  const balanced = Math.abs(d - c) < 0.00005;
  return h('div', null,
    h('p', { class: 'hint' }, 'ميزان المراجعة من القيود المرحَّلة تلقائياً. القيود غير قابلة للتعديل؛ التصحيح يتم بقيد عكسي.'),
    table([
      { label: 'الحساب', key: 'account_code' }, { label: 'الاسم', key: 'name' },
      { label: 'مدين', num: true, render: (x) => money(x.debit) }, { label: 'دائن', num: true, render: (x) => money(x.credit) },
      { label: 'الرصيد', num: true, render: (x) => { const n = Number(x.debit) - Number(x.credit); return h('span', { class: n < 0 ? 'neg' : '' }, money(n)); } },
    ], r.data),
    h('div', { class: 'card' }, 'إجمالي المدين: ', h('b', null, money(d)), ' · إجمالي الدائن: ', h('b', null, money(c)), ' · ', h('span', { class: 'badge ' + (balanced ? 'ok' : 'bad') }, balanced ? 'الميزان متوازن' : 'الميزان غير متوازن!')));
}
