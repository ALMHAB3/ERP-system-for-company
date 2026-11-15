import { h, get, post, form, table, tabs, panel, act, modal, can, money, toast, state, opts, today, reloadLookups } from '../ui.js';

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

function banks() {
  return panel(async (refresh) => {
    const r = await get('finance/bank-accounts');
    return h('div', null,
      h('p', { class: 'hint' }, 'كل حساب مصرفي له حساب مستقل في دفتر الأستاذ، فتذهب كل حوالة إلى المصرف الذي استُلمت عليه فعلاً. الصندوق (النقد) حساب مستقل أيضاً.'),
      can('banks.manage') ? h('div', { class: 'card' }, h('h3', null, 'إضافة حساب مصرفي'), form([
        { name: 'name', label: 'اسم المصرف', type: 'text', required: true }, { name: 'account_number', label: 'رقم الحساب / IBAN', type: 'text' },
        { name: 'currency', label: 'عملة الحساب', type: 'select', options: opts.currencies(), value: state.lookups.currency },
      ], 'إضافة', async (v) => { await post('finance/bank-accounts', v); await reloadLookups(); toast('تمت إضافة الحساب المصرفي', 'ok'); refresh(); })) : null,
      table([
        { label: 'الحساب', render: (b) => (b.kind === 'cash' ? 'الصندوق (نقد)' : b.name) }, { label: 'رقم الحساب', render: (b) => b.account_number || '' },
        { label: 'العملة', key: 'currency' }, { label: 'حساب الأستاذ', key: 'gl_account' },
        { label: 'الرصيد (' + state.lookups.currency + ')', num: true, render: (b) => h('b', { class: Number(b.balance) < 0 ? 'neg' : '' }, money(b.balance)) },
      ], r.data, 'لا توجد حسابات', { csv: 'cash-and-banks' }));
  });
}


// General ledger of one account: opening balance, every posted line in the period, running balance.
function ledger() {
  const box = h('div');
  return h('div', null,
    h('p', { class: 'hint' }, 'كشف حساب من دفتر الأستاذ العام: الرصيد الافتتاحي، ثم كل حركة مرحَّلة في الفترة مع الرصيد بعدها. كل سطر يذكر المستند الذي ولَّد القيد.'),
    h('div', { class: 'card' }, form([
      { name: 'account', label: 'الحساب', type: 'select', options: opts.accounts() },
      { name: 'from', label: 'من تاريخ', type: 'date', value: today().slice(0, 8) + '01', required: true }, { name: 'to', label: 'إلى تاريخ', type: 'date', value: today(), required: true },
    ], 'عرض كشف الحساب', async (v) => {
      const r = await get('finance/ledger', v);
      box.replaceChildren(
        h('div', { class: 'card' }, h('b', null, r.account.code + ' — ' + r.account.name), ' · الرصيد الافتتاحي: ', h('b', null, money(r.opening)), ' · الرصيد الختامي: ', h('b', { class: Number(r.closing) < 0 ? 'neg' : '' }, money(r.closing))),
        table([
          { label: 'التاريخ', key: 'date' }, { label: 'رقم القيد', key: 'entry_id' }, { label: 'البيان', render: (l) => l.description + (l.memo ? ' · ' + l.memo : '') }, { label: 'المصدر', key: 'source' },
          { label: 'مدين', num: true, render: (l) => (Number(l.debit) ? money(l.debit) : '') }, { label: 'دائن', num: true, render: (l) => (Number(l.credit) ? money(l.credit) : '') },
          { label: 'بالعملة الأجنبية', num: true, render: (l) => (l.currency ? money(l.amount_currency) + ' ' + l.currency : '') },
          { label: 'الرصيد', num: true, render: (l) => h('span', { class: Number(l.balance) < 0 ? 'neg' : '' }, money(l.balance)) },
        ], r.lines, 'لا توجد حركات في هذه الفترة', { csv: 'ledger-' + r.account.code }));
    })), box);
}

// Statement lines are typed or pasted one per line: date ; description ; amount (negative = money out).
function parseStatementLines(text) {
  return text.split('\n').map((x) => x.trim()).filter(Boolean).map((row, i) => {
    const p = row.split(/[;\t]/).map((x) => x.trim());
    if (p.length < 3 || !/^\d{4}-\d{2}-\d{2}$/.test(p[0]) || !/^-?\d+(\.\d{1,4})?$/.test(p[p.length - 1])) throw new Error('السطر ' + (i + 1) + ' غير صحيح. الصيغة: التاريخ ; البيان ; المبلغ');
    return { date: p[0], description: p.slice(1, -1).join(' '), amount: p[p.length - 1] };
  });
}

function reconciliation() {
  return panel(async (refresh) => {
    const list = (await get('finance/bank-statements')).data;
    const open = async (id) => {
      const r = await get('finance/bank-statements/' + id);
      const s = r.statement, sum = r.summary;
      const editable = s.status === 'open' && can('banks.manage');
      let m;
      const again = async () => { m.close(); await refresh(); open(id); };
      const pick = (l) => h('button', { class: 'btn secondary small', type: 'button', onclick: async () => {
        const c = (await get(`finance/bank-statement-lines/${l.id}/candidates`)).data;
        const mm = modal('مطابقة: ' + l.description, c.length ? table([
          { label: 'التاريخ', key: 'date' }, { label: 'البيان', render: (x) => x.description + (x.memo ? ' · ' + x.memo : '') }, { label: 'المبلغ', num: true, render: (x) => money(x.amount) },
          { label: '', render: (x) => act('مطابقة', () => post(`finance/bank-statement-lines/${l.id}/match`, { journal_line_id: x.journal_line_id }), async () => { mm.close(); await again(); }, { ok: 'تمت المطابقة' }) },
        ], c) : h('div', { class: 'card empty' }, 'لا توجد حركة غير مطابَقة في الدفتر بنفس المبلغ. إن كانت عمولة أو فائدة مصرفية فسجِّلها من الكشف.'));
      } }, 'مطابقة');
      const book = (l) => h('button', { class: 'btn secondary small', type: 'button', onclick: () => {
        const mm = modal('تسجيل من الكشف: ' + l.description, form([{ name: 'account', label: 'الحساب المقابل', type: 'select', options: opts.accounts(), value: '5600' }], 'تسجيل ومطابقة',
          async (v) => { await post(`finance/bank-statement-lines/${l.id}/book`, v); toast('تم تسجيل القيد ومطابقته', 'ok'); mm.close(); await again(); }));
      } }, 'تسجيل من الكشف');
      const balanced = Number(sum.difference) === 0;
      m = modal('كشف ' + s.reference + ' — ' + s.bank + ' (' + s.currency + ')', h('div', null,
        h('div', { class: 'card' }, 'رصيد الدفتر: ', h('b', null, money(sum.ledger_balance)), ' · حركات في الدفتر لم تظهر في الكشف بعد: ', h('b', null, money(sum.outstanding_ledger_total)),
          ' · سطور في الكشف غير مسجَّلة في الدفتر: ', h('b', null, money(sum.unbooked_statement_total)), ' · الرصيد المعدَّل: ', h('b', null, money(sum.adjusted_ledger_balance)),
          ' · رصيد الكشف الختامي: ', h('b', null, money(sum.statement_closing_balance)), ' · ', h('span', { class: 'badge ' + (balanced ? 'ok' : 'bad') }, balanced ? 'مطابق' : 'فرق ' + money(sum.difference))),
        editable ? h('div', { class: 'row-actions' },
          act('مطابقة تلقائية', () => post(`finance/bank-statements/${id}/auto-match`), again, { ok: 'تمت المطابقة التلقائية' }),
          act('اعتماد التسوية', () => post(`finance/bank-statements/${id}/reconcile`), again, { ok: 'تم اعتماد التسوية', confirm: 'اعتماد التسوية يقفل الكشف نهائياً. متابعة؟' })) : null,
        h('h3', null, 'سطور الكشف'),
        table([
          { label: 'التاريخ', key: 'date' }, { label: 'البيان', key: 'description' }, { label: 'المبلغ', num: true, render: (l) => h('span', { class: Number(l.amount) < 0 ? 'neg' : '' }, money(l.amount)) },
          { label: 'الحالة', render: (l) => h('span', { class: 'badge ' + (l.matched ? 'ok' : 'warn') }, l.matched ? 'مطابَق' : 'غير مطابَق') },
          { label: 'القيد المقابل', render: (l) => (l.matched ? '#' + l.entry_id + ' · ' + l.entry_date + ' · ' + l.entry_description : '') },
          { label: '', render: (l) => (!editable ? '' : l.matched
            ? act('فك المطابقة', () => post(`finance/bank-statement-lines/${l.id}/unmatch`), again, { ok: 'تم فك المطابقة', cls: 'secondary' })
            : h('div', { class: 'row-actions' }, pick(l), book(l))) },
        ], r.lines),
        h('h3', null, 'حركات في الدفتر لم تظهر في أي كشف بعد'),
        table([{ label: 'التاريخ', key: 'date' }, { label: 'البيان', render: (x) => x.description + (x.memo ? ' · ' + x.memo : '') }, { label: 'المبلغ', num: true, render: (x) => money(x.amount) }],
          r.outstanding_ledger_lines, 'لا توجد حركات معلَّقة')));
    };
    return h('div', null,
      h('p', { class: 'hint' }, 'التسوية البنكية: أدخل كشف المصرف كما ورد، ثم طابق كل سطر مع حركته في دفتر الأستاذ. العمولات والفوائد التي لا يعرفها الدفتر تُسجَّل مباشرة من الكشف. المبالغ بعملة الحساب المصرفي.'),
      can('banks.manage') ? h('div', { class: 'card' }, h('h3', null, 'إدخال كشف مصرفي'), form([
        { name: 'bank_account_id', label: 'الحساب المصرفي', type: 'select', options: opts.banks() }, { name: 'reference', label: 'رقم الكشف', type: 'text', required: true },
        { name: 'date', label: 'تاريخ الكشف', type: 'date', value: today(), required: true },
        { name: 'opening_balance', label: 'الرصيد الافتتاحي', type: 'text', required: true }, { name: 'closing_balance', label: 'الرصيد الختامي', type: 'text', required: true },
        { name: 'lines', label: 'سطور الكشف (سطر لكل حركة: التاريخ ; البيان ; المبلغ — السالب للمسحوبات)', type: 'textarea', required: true, wide: true },
      ], 'حفظ الكشف', async (v, f) => {
        const r = await post('finance/bank-statements', { ...v, bank_account_id: Number(v.bank_account_id), lines: parseStatementLines(v.lines) });
        toast('تم حفظ الكشف', 'ok'); f.reset(); await refresh(); open(r.id);
      })) : null,
      table([
        { label: 'المصرف', key: 'bank' }, { label: 'رقم الكشف', key: 'reference' }, { label: 'التاريخ', key: 'date' }, { label: 'العملة', key: 'currency' },
        { label: 'الرصيد الافتتاحي', num: true, render: (x) => money(x.opening_balance) }, { label: 'الرصيد الختامي', num: true, render: (x) => money(x.closing_balance) },
        { label: 'غير مطابَق', num: true, render: (x) => x.unmatched + ' / ' + x.lines },
        { label: 'الحالة', render: (x) => h('span', { class: 'badge ' + (x.status === 'reconciled' ? 'ok' : 'warn') }, x.status === 'reconciled' ? 'تمت التسوية' : 'مفتوح') },
        { label: '', render: (x) => h('button', { class: 'btn secondary small', type: 'button', onclick: () => open(x.id) }, 'فتح') },
      ], list, 'لا توجد كشوف مصرفية بعد'));
  });
}

function revaluation() {
  return panel(async (refresh) => {
    const hist = (await get('finance/fx-revaluations')).data;
    const preview = h('div');
    const cols = [
      { label: 'الحساب', key: 'account_code' }, { label: 'البند', key: 'subject' }, { label: 'العملة', key: 'currency' }, { label: 'السعر', num: true, key: 'rate' },
      { label: 'الرصيد بالعملة', num: true, render: (l) => money(l.foreign_balance) }, { label: 'القيمة الدفترية', num: true, render: (l) => money(l.carrying_base) },
      { label: 'القيمة بسعر الإقفال', num: true, render: (l) => money(l.revalued_base) }, { label: 'الفرق', num: true, render: (l) => h('span', { class: Number(l.difference) < 0 ? 'neg' : '' }, money(l.difference)) },
    ];
    const netText = (n) => (Number(n) > 0 ? 'صافي خسارة فروق عملة: ' : 'صافي ربح فروق عملة: ') + money(Math.abs(Number(n)));
    return h('div', null,
      h('p', { class: 'hint' }, 'تقييم فروق الصرف في نهاية الفترة: تُعاد قياس الأرصدة المفتوحة بالعملة الأجنبية (فواتير الموردين غير المسددة والحسابات المصرفية بالعملة الأجنبية) بسعر الإقفال، ويُرحَّل الفرق كقيد غير محقَّق ثم يُعكس تلقائياً في اليوم التالي. الفرق المحقَّق يُسجَّل عند السداد.'),
      h('div', { class: 'card' }, h('h3', null, 'إعادة تقييم العملات الأجنبية'), form([{ name: 'as_of', label: 'تاريخ الإقفال', type: 'date', value: today(), required: true }], 'معاينة', async (v) => {
        const r = await get('finance/fx-revaluations/preview', v);
        preview.replaceChildren(...[h('div', { class: 'card' }, netText(r.net)), table(cols, r.lines, 'لا توجد أرصدة مفتوحة بالعملة الأجنبية'),
          can('currencies.manage') && r.lines.length ? act('ترحيل قيد إعادة التقييم', () => post('finance/fx-revaluations', v), refresh, { ok: 'تم ترحيل قيد إعادة التقييم وقيد عكسه', confirm: 'سيُرحَّل قيد إعادة التقييم وقيد عكسه في اليوم التالي. متابعة؟' }) : null].filter(Boolean));   // replaceChildren would print a null as text
      }), preview),
      h('h3', null, 'عمليات إعادة التقييم السابقة'),
      table([{ label: 'تاريخ الإقفال', key: 'as_of' }, { label: 'تاريخ العكس', key: 'reverse_on' }, { label: 'الصافي', render: (x) => netText(x.net) }], hist, 'لم تُجرَ أي إعادة تقييم بعد'));
  });
}

export default async function () {
  return tabs([{ label: 'ميزان المراجعة', render: trial }, { label: 'الصندوق والحسابات المصرفية', render: async () => banks() }, { label: 'أسعار الصرف', render: async () => rates() },
    { label: 'كشف حساب', render: async () => ledger() },
    ...(can('finance.view', 'banks.manage') ? [{ label: 'التسوية البنكية', render: async () => reconciliation() }] : []),
    ...(can('finance.view') ? [{ label: 'تقييم فروق العملة', render: async () => revaluation() }] : [])]);
}
