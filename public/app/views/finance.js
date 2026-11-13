import { h, get, post, form, table, tabs, panel, can, money, toast, state, opts, today, reloadLookups } from '../ui.js';

async function trial() {
  const r = await get('finance/trial-balance');
  const sum = (k) => r.data.reduce((a, x) => a + Number(x[k]), 0);
  const d = sum('debit'), c = sum('credit');
  const balanced = Math.abs(d - c) < 0.00005;
  return h('div', null,
    h('p', { class: 'hint' }, 'ميزان المراجعة من القيود المرحَّلة تلقائياً بالعملة الأساسية (' + state.lookups.currency + '). القيود غير قابلة للتعديل؛ التصحيح يتم بقيد عكسي.'),
    table([
      { label: 'الحساب', key: 'account_code' }, { label: 'الاسم', key: 'name' },
      { label: 'مدين', num: true, render: (x) => money(x.debit) }, { label: 'دائن', num: true, render: (x) => money(x.credit) },
      { label: 'الرصيد', num: true, render: (x) => { const n = Number(x.debit) - Number(x.credit); return h('span', { class: n < 0 ? 'neg' : '' }, money(n)); } },
    ], r.data, 'لا توجد قيود', { csv: 'trial-balance' }),
    h('div', { class: 'card' }, 'إجمالي المدين: ', h('b', null, money(d)), ' · إجمالي الدائن: ', h('b', null, money(c)), ' · ', h('span', { class: 'badge ' + (balanced ? 'ok' : 'bad') }, balanced ? 'الميزان متوازن' : 'الميزان غير متوازن!')));
}

function rates() {
  return panel(async (refresh) => {
    const [cur, hist] = await Promise.all([get('currencies'), can('finance.view', 'currencies.manage') ? get('currencies/history') : { data: [] }]);
    const foreign = cur.currencies.filter((c) => !c.is_base);
    return h('div', null,
      h('p', { class: 'hint' }, 'السعر = كم ' + cur.base + ' يساوي وحدة واحدة من العملة. كل سعر يُسجَّل بتاريخ ولا يُعدَّل لاحقاً؛ الفواتير تحتفظ بالسعر الذي سُجِّلت به. دقة السعر 4 خانات عشرية.'),
      h('div', { class: 'ratebox' }, cur.currencies.map((c) => h('div', { class: 'kpi' }, h('div', { class: 'l' }, c.name + ' (' + c.code + ')'),
        h('div', { class: 'v' }, c.is_base ? 'عملة الأساس' : c.rate ? '1 ' + c.code + ' = ' + c.rate + ' ' + cur.base : 'غير مسجَّل'), h('div', { class: 's' }, c.date ? 'بتاريخ ' + c.date : '')))),
      can('currencies.manage') ? h('div', { class: 'card' }, h('h3', null, 'تسجيل سعر صرف'), form([
        { name: 'currency', label: 'العملة', type: 'select', options: foreign.map((c) => ({ value: c.code, label: c.code + ' — ' + c.name })) },
        { name: 'rate', label: 'السعر مقابل ' + cur.base, type: 'number', required: true }, { name: 'date', label: 'التاريخ', type: 'date', value: today() },
      ], 'تسجيل السعر', async (v) => { await post('currencies/rates', v); await reloadLookups(); toast('تم تسجيل السعر', 'ok'); refresh(); })) : null,
      h('h3', null, 'سجل الأسعار'),
      table([{ label: 'التاريخ', key: 'date' }, { label: 'العملة', key: 'currency' }, { label: 'السعر (' + cur.base + ')', num: true, key: 'rate' }], hist.data, 'لا توجد أسعار مسجلة بعد', { csv: 'exchange-rates' }));
  });
}

export default async function () {
  return tabs([{ label: 'ميزان المراجعة', render: trial }, { label: 'أسعار الصرف', render: async () => rates() }]);
}
