import { h, get, post, put, form, table, tabs, panel, act, can, modal, money, toast, state, opts, today, badge } from '../ui.js';

const ATT = [['present', 'حاضر'], ['absent', 'غائب'], ['late', 'متأخر'], ['leave', 'إجازة'], ['holiday', 'عطلة']];
const LEAVE = [['annual', 'سنوية'], ['sick', 'مرضية'], ['unpaid', 'بدون راتب'], ['other', 'أخرى']];
const KIND = { allowance: 'علاوة', bonus: 'مكافأة', deduction: 'خصم' };
const PSTATUS = { draft: ['مسودة', 'warn'], approved: ['معتمد', ''], paid: ['مدفوع', 'ok'] };
const neg = (v) => (Number(v) ? '-' + v : v);
const label = (list, v) => list.find(([k]) => k === v)?.[1] || v;
const prevMonth = () => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - 1); return d.toISOString().slice(0, 7); };
const empOptions = (list) => [{ value: '', label: '— اختر —' }, ...list.filter((e) => e.status === 'active').map((e) => ({ value: e.id, label: e.code + ' — ' + e.name }))];
const branchOptions = () => [{ value: '', label: '— بدون —' }, ...opts.warehouses()];

function employees() {
  return panel(async (refresh) => {
    const list = (await get('hr/employees')).data;
    const fields = (e) => [
      ...(e ? [] : [{ name: 'code', label: 'رقم الموظف', type: 'text', required: true }]),
      { name: 'name', label: 'الاسم', type: 'text', required: true, value: e?.name }, { name: 'job_title', label: 'الوظيفة', type: 'text', value: e?.job_title || '' },
      { name: 'department', label: 'القسم', type: 'text', value: e?.department || '' }, { name: 'phone', label: 'الهاتف', type: 'text', value: e?.phone || '' },
      { name: 'national_id', label: 'الرقم الوطني / الهوية', type: 'text', value: e?.national_id || '' },
      { name: 'warehouse_id', label: 'الفرع', type: 'select', options: branchOptions(), value: e?.warehouse_id ?? '' },
      { name: 'hire_date', label: 'تاريخ التعيين', type: 'date', required: true, value: e?.hire_date || today() },
      { name: 'base_salary', label: 'الراتب الأساسي الشهري (' + state.lookups.currency + ')', type: 'number', required: true, value: e ? e.base_salary.replace(/\.?0+$/, '') : '' },
      { name: 'monthly_allowance', label: 'علاوة شهرية ثابتة (اتركها فارغة إن كان لا يتحصل على علاوة)', type: 'number', value: e && Number(e.monthly_allowance) ? e.monthly_allowance.replace(/\.?0+$/, '') : '' },
    ];
    const body = (v) => ({ ...v, warehouse_id: v.warehouse_id ? Number(v.warehouse_id) : null });
    return h('div', null,
      can('hr.manage') ? h('div', { class: 'card' }, h('h3', null, 'إضافة موظف'), form(fields(), 'إضافة', async (v, f) => { await post('hr/employees', body(v)); toast('تمت إضافة الموظف', 'ok'); f.reset(); refresh(); })) : null,
      table([{ label: 'الرقم', key: 'code' }, { label: 'الاسم', key: 'name' }, { label: 'الوظيفة', render: (e) => e.job_title || '—' }, { label: 'الفرع', render: (e) => e.branch || '—' },
        { label: 'التعيين', key: 'hire_date' }, { label: 'الراتب', num: true, render: (e) => money(e.base_salary) }, { label: 'العلاوة الشهرية', num: true, render: (e) => (Number(e.monthly_allowance) ? money(e.monthly_allowance) : '—') }, { label: 'سلف مستحقة', num: true, render: (e) => (Number(e.advance_balance) ? money(e.advance_balance) : '—') },
        { label: 'الحالة', render: (e) => h('span', { class: 'badge ' + (e.status === 'active' ? 'ok' : 'bad') }, e.status === 'active' ? 'على رأس العمل' : 'منتهي الخدمة') },
        { label: '', render: (e) => (can('hr.manage') && e.status === 'active' ? h('div', { class: 'row-actions' },
          h('button', { class: 'btn secondary small', onclick: () => { const m = modal('تعديل ' + e.name, form(fields(e), 'حفظ', async (v) => { await put('hr/employees/' + e.id, body(v)); toast('تم الحفظ', 'ok'); m.close(); refresh(); })); } }, 'تعديل'),
          h('button', { class: 'btn danger small', onclick: () => { const m = modal('إنهاء خدمة ' + e.name, form([{ name: 'terminated_on', label: 'تاريخ انتهاء الخدمة', type: 'date', value: today(), required: true }], 'إنهاء الخدمة', async (v) => { await post(`hr/employees/${e.id}/terminate`, v); toast('تم', 'ok'); m.close(); refresh(); })); } }, 'إنهاء الخدمة')) : null) },
      ], list, 'لا يوجد موظفون بعد', { csv: 'employees' }));
  });
}

function attendance() {
  const box = h('div'); let dateInput;
  const load = async () => {
    const r = await get('hr/attendance', { date: dateInput.value });
    const rows = r.data.map((x) => ({ x, sel: h('select', null, ATT.map(([k, l]) => h('option', { value: k, selected: (x.status || 'present') === k }, l))), note: h('input', { value: x.note || '', placeholder: 'ملاحظة' }) }));
    box.replaceChildren(rows.length ? h('div', null,
      h('div', { class: 'tablewrap' }, h('table', null, h('thead', null, h('tr', null, ['الموظف', 'الحالة', 'ملاحظة', 'مسجَّل؟'].map((t) => h('th', null, t)))),
        h('tbody', null, rows.map(({ x, sel, note }) => h('tr', null, h('td', null, x.code + ' — ' + x.name), h('td', null, sel), h('td', null, note), h('td', null, x.status ? h('span', { class: 'badge ok' }, 'نعم') : h('span', { class: 'badge warn' }, 'لا')))))) ),
      can('hr.manage') ? h('button', { class: 'btn', onclick: async (e) => {
        e.target.disabled = true;
        try { await put('hr/attendance', { date: dateInput.value, records: rows.map(({ x, sel, note }) => ({ employee_id: x.employee_id, status: sel.value, note: note.value || undefined })) }); toast('تم حفظ الحضور', 'ok'); load(); }
        catch (err) { toast(err.message, 'error'); } finally { e.target.disabled = false; }
      } }, 'حفظ الحضور') : null) : h('div', { class: 'card empty' }, 'لا يوجد موظفون على رأس العمل في هذا التاريخ'));
  };
  dateInput = h('input', { type: 'date', value: today(), max: today(), onchange: load });
  load();
  return h('div', null, h('div', { class: 'toolbar' }, h('div', { class: 'field' }, h('label', null, 'التاريخ'), dateInput)),
    h('p', { class: 'hint' }, 'الغياب المسجَّل يُخصم من الراتب (الراتب ÷ 30 لكل يوم). الأيام غير المسجَّلة لا تُخصم.'), box);
}

function leaves() {
  return panel(async (refresh) => {
    const [emps, list] = await Promise.all([get('hr/employees'), get('hr/leaves')]);
    return h('div', null,
      can('hr.manage') ? h('div', { class: 'card' }, h('h3', null, 'طلب إجازة'), form([
        { name: 'employee_id', label: 'الموظف', type: 'select', options: empOptions(emps.data), required: true }, { name: 'type', label: 'النوع', type: 'select', options: LEAVE.map(([value, l]) => ({ value, label: l })) },
        { name: 'from_date', label: 'من', type: 'date', value: today(), required: true }, { name: 'to_date', label: 'إلى', type: 'date', value: today(), required: true }, { name: 'reason', label: 'السبب', type: 'text' },
      ], 'تسجيل الطلب', async (v, f) => { const r = await post('hr/leaves', { ...v, employee_id: Number(v.employee_id) }); toast(`تم تسجيل الطلب (${r.days} يوم)`, 'ok'); f.reset(); refresh(); })) : null,
      table([{ label: 'الموظف', key: 'employee' }, { label: 'النوع', render: (l) => label(LEAVE, l.type) }, { label: 'من', key: 'from_date' }, { label: 'إلى', key: 'to_date' }, { label: 'الأيام', num: true, render: (l) => l.days },
        { label: 'الحالة', render: (l) => h('span', { class: 'badge ' + (l.status === 'approved' ? 'ok' : l.status === 'rejected' ? 'bad' : 'warn') }, { pending: 'بانتظار القرار', approved: 'موافَق عليها', rejected: 'مرفوضة' }[l.status]) },
        { label: '', render: (l) => (l.status === 'pending' && can('hr.manage') ? h('div', { class: 'row-actions' }, act('موافقة', () => post(`hr/leaves/${l.id}/decision`, { decision: 'approved' }), refresh, { ok: 'تمت الموافقة' }), act('رفض', () => post(`hr/leaves/${l.id}/decision`, { decision: 'rejected' }), refresh, { cls: 'danger', ok: 'تم الرفض' })) : null) },
      ], list.data, 'لا توجد طلبات إجازة'));
  });
}

function advances() {
  return panel(async (refresh) => {
    const [emps, list] = await Promise.all([get('hr/employees'), get('hr/advances')]);
    return h('div', null,
      can('payroll.manage') ? h('div', { class: 'card' }, h('h3', null, 'صرف سلفة لموظف'),
        h('p', { class: 'hint' }, 'تُخصم أقساطها تلقائياً من راتب كل شهر حتى تُسدَّد.'), form([
          { name: 'employee_id', label: 'الموظف', type: 'select', options: empOptions(emps.data), required: true }, { name: 'amount', label: 'مبلغ السلفة', type: 'number', required: true },
          { name: 'repay_per_month', label: 'القسط الشهري', type: 'number', required: true }, { name: 'given_on', label: 'التاريخ', type: 'date', value: today(), required: true },
          { name: 'method', label: 'تُصرف من', type: 'select', options: [{ value: 'cash', label: 'الصندوق (نقداً)' }, { value: 'transfer', label: 'تحويل مصرفي' }] },
          { name: 'bank_account_id', label: 'المصرف', type: 'select', options: opts.banks() }, { name: 'note', label: 'ملاحظة', type: 'text' },
        ], 'صرف السلفة', async (v, f) => {
          if (v.method === 'transfer' && !v.bank_account_id) throw new Error('اختر المصرف');
          await post('hr/advances', { ...v, employee_id: Number(v.employee_id), bank_account_id: v.method === 'transfer' ? Number(v.bank_account_id) : undefined }); toast('تم صرف السلفة', 'ok'); f.reset(); refresh();
        })) : null,
      table([{ label: 'الموظف', key: 'employee' }, { label: 'التاريخ', key: 'given_on' }, { label: 'المبلغ', num: true, render: (a) => money(a.amount) }, { label: 'القسط الشهري', num: true, render: (a) => money(a.repay_per_month) },
        { label: 'المتبقي', num: true, render: (a) => money(a.balance) }, { label: 'ملاحظة', render: (a) => a.note || '' }], list.data, 'لا توجد سلف'));
  });
}

function payslip(run, l) {
  const row = (t, v, strong) => h('tr', null, h('td', null, t), h('td', { class: 'num' }, strong ? h('b', null, money(v)) : money(v)));
  modal('كشف راتب — ' + l.name, h('div', null, h('div', { class: 'print-sheet' },
    h('div', { class: 'ps-head' }, h('div', null, h('h2', { 'data-no-translate': '' }, state.lookups.company_name || ''), h('div', { class: 'muted' }, 'كشف راتب شهر ' + run.period)), h('div', null, h('div', null, 'الموظف: ', h('b', null, l.name)), h('div', null, l.code + (l.job_title ? ' — ' + l.job_title : '')))),
    h('table', null, h('tbody', null, row('الراتب الأساسي', l.base), row('العلاوات', l.allowances), row('المكافآت', l.bonuses),
      row('خصم الغياب (' + l.absent_days + ' يوم)', neg(l.absence_deduction)), row('خصومات أخرى', neg(l.other_deductions)), row('قسط السلفة', neg(l.advance_repayment)), row('صافي الراتب', l.net, true)))),
    h('div', { class: 'formbar noprint' }, h('button', { class: 'btn', onclick: () => window.print() }, 'طباعة'))));
}

function payroll() {
  return panel(async (refresh) => {
    const [runs, emps] = await Promise.all([get('hr/payroll'), can('payroll.manage') ? get('hr/employees') : { data: [] }]);
    const open = async (id) => {
      const r = await get('hr/payroll/' + id);
      const m = modal('رواتب ' + r.period + ' — ' + PSTATUS[r.status][0], h('div', null,
        table([{ label: 'الموظف', render: (l) => l.code + ' — ' + l.name }, { label: 'الأساسي', num: true, render: (l) => money(l.base) }, { label: 'علاوات + مكافآت', num: true, render: (l) => money(Number(l.allowances) + Number(l.bonuses)) },
          { label: 'غياب', num: true, render: (l) => (Number(l.absence_deduction) ? money(l.absence_deduction) + ' (' + l.absent_days + ' يوم)' : '—') }, { label: 'خصومات', num: true, render: (l) => money(l.other_deductions) },
          { label: 'قسط سلفة', num: true, render: (l) => money(l.advance_repayment) }, { label: 'الصافي', num: true, render: (l) => h('b', null, money(l.net)) },
          { label: '', render: (l) => h('button', { class: 'btn secondary small', onclick: () => payslip(r, l) }, 'كشف الراتب') }], r.lines, 'لا يوجد موظفون', { csv: 'payroll-' + r.period }),
        h('p', null, 'إجمالي الصافي: ', h('b', null, money(r.total_net) + ' ' + state.lookups.currency)),
        h('div', { class: 'row-actions' },
          r.status === 'draft' && can('payroll.manage') ? act('إعادة الحساب', () => post(`hr/payroll/${id}/recalculate`), async () => { m.close(); await refresh(); open(id); }, { ok: 'تمت إعادة الحساب' }) : null,
          r.status === 'draft' && can('payroll.manage') ? act('اعتماد الرواتب', () => post(`hr/payroll/${id}/approve`), async () => { m.close(); await refresh(); }, { confirm: 'اعتماد رواتب ' + r.period + '؟ يُسجَّل المصروف في الحسابات ولا يمكن التعديل بعدها.', ok: 'تم الاعتماد' }) : null,
          r.status === 'approved' && can('payroll.pay') ? h('button', { class: 'btn small', onclick: () => {
            const p = modal('دفع رواتب ' + r.period, form([{ name: 'method', label: 'تُدفع من', type: 'select', options: [{ value: 'cash', label: 'الصندوق (نقداً)' }, { value: 'transfer', label: 'تحويل مصرفي' }] },
              { name: 'bank_account_id', label: 'المصرف', type: 'select', options: opts.banks() }, { name: 'paid_on', label: 'تاريخ الدفع', type: 'date', value: today(), required: true }], 'تأكيد الدفع', async (v) => {
              if (v.method === 'transfer' && !v.bank_account_id) throw new Error('اختر المصرف');
              await post(`hr/payroll/${id}/pay`, { method: v.method, bank_account_id: v.method === 'transfer' ? Number(v.bank_account_id) : undefined, paid_on: v.paid_on }); toast('تم دفع الرواتب', 'ok'); p.close(); m.close(); refresh();
            }));
          } }, 'دفع الرواتب') : null)));
    };
    return h('div', null,
      can('payroll.manage') ? h('div', { class: 'card' }, h('h3', null, 'كشف رواتب شهر جديد'),
        h('p', { class: 'hint' }, 'يحسب النظام: الراتب الأساسي + العلاوة الشهرية الثابتة + العلاوات والمكافآت الإضافية − الغياب − الخصومات − أقساط السلف. أضف العلاوات والمكافآت والخصومات الإضافية للشهر أولاً ثم أنشئ الكشف (أو أعد حسابه).'),
        form([{ name: 'period', label: 'الشهر (YYYY-MM)', type: 'text', value: prevMonth(), required: true }], 'إنشاء كشف الرواتب', async (v) => { const r = await post('hr/payroll', { period: v.period }); toast('تم إنشاء كشف الرواتب', 'ok'); await refresh(); open(r.id); })) : null,
      can('payroll.manage') ? h('div', { class: 'card' }, h('h3', null, 'علاوة / مكافأة / خصم إضافي لموظف في شهر'), form([
        { name: 'period', label: 'الشهر (YYYY-MM)', type: 'text', value: prevMonth(), required: true }, { name: 'employee_id', label: 'الموظف', type: 'select', options: empOptions(emps.data), required: true },
        { name: 'kind', label: 'النوع', type: 'select', options: Object.entries(KIND).map(([value, l]) => ({ value, label: l })) }, { name: 'amount', label: 'المبلغ', type: 'number', required: true }, { name: 'note', label: 'ملاحظة', type: 'text' },
      ], 'إضافة', async (v, f) => { await post('hr/payroll/adjustments', { ...v, employee_id: Number(v.employee_id) }); toast('تمت الإضافة. أعد حساب الكشف ليظهر أثرها.', 'ok'); f.reset(); })) : null,
      table([{ label: 'الشهر', key: 'period' }, { label: 'الحالة', render: (r) => badge2(r.status) }, { label: 'الموظفون', num: true, render: (r) => r.employees }, { label: 'صافي الرواتب', num: true, render: (r) => money(r.total_net) }, { label: 'تاريخ الدفع', render: (r) => r.paid_on || '—' },
        { label: '', render: (r) => h('button', { class: 'btn secondary small', onclick: () => open(r.id) }, 'فتح') }], runs.data, 'لا توجد كشوف رواتب بعد'));
  });
}
const badge2 = (s) => h('span', { class: 'badge ' + PSTATUS[s][1] }, PSTATUS[s][0]);

export default async function () {
  return tabs([
    ...(can('hr.view') ? [{ label: 'الموظفون', render: async () => employees() }, { label: 'الحضور', render: async () => attendance() }, { label: 'الإجازات', render: async () => leaves() }, { label: 'السلف', render: async () => advances() }] : []),
    ...(can('hr.view') ? [{ label: 'الرواتب', render: async () => payroll() }] : []),
  ]);
}
