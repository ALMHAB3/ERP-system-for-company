import { h, get, post, put, form, table, tabs, panel, act, can, modal, badge, toast, state, opts } from '../ui.js';

function users() {
  return panel(async (refresh) => {
    const list = (await get('admin/users')).data;
    const roleOptions = state.lookups.roles.map((r) => ({ value: r.name, label: r.label }));
    const whBox = (selected) => state.lookups.warehouses.map((w) => ({ w, id: 'wh' + w.id, on: selected.includes(w.id) }));
    const edit = (u) => {
      const roleSel = h('select', { multiple: true, size: 6 }, roleOptions.map((o) => h('option', { value: o.value, selected: u.roles.includes(o.value) }, o.label)));
      const checks = whBox(u.warehouses).map((c) => ({ ...c, input: h('input', { type: 'checkbox', checked: c.on }) }));
      const active = h('input', { type: 'checkbox', checked: u.active });
      const m = modal('صلاحيات ' + u.name, h('div', null,
        h('div', { class: 'field' }, h('label', null, 'الأدوار (Ctrl لاختيار أكثر من دور)'), roleSel),
        h('div', { class: 'field' }, h('label', null, 'المستودعات المسموحة (لمن لا يملك صلاحية كل المستودعات)'), checks.map((c) => h('label', null, c.input, ' ', c.w.code + ' — ' + c.w.name))),
        h('div', { class: 'field check' }, active, h('label', null, 'الحساب فعّال (إيقافه يُنهي جلساته فوراً)')),
        h('button', { class: 'btn', onclick: async () => {
          try {
            await put(`admin/users/${u.id}/access`, { roles: [...roleSel.selectedOptions].map((o) => o.value), warehouses: checks.filter((c) => c.input.checked).map((c) => c.w.id), active: active.checked });
            toast('تم تحديث الصلاحيات', 'ok'); m.close(); refresh();
          } catch (e) { toast(e.message, 'error'); }
        } }, 'حفظ')));
    };
    return h('div', null,
      h('div', { class: 'card' }, h('h3', null, 'مستخدم جديد'), form([
        { name: 'name', label: 'الاسم', type: 'text', required: true }, { name: 'email', label: 'البريد', type: 'text', required: true },
        { name: 'password', label: 'كلمة المرور (12 حرفاً+، حروف كبيرة وصغيرة وأرقام)', type: 'password', required: true },
        { name: 'role', label: 'الدور', type: 'select', options: roleOptions },
        { name: 'warehouse_id', label: 'مستودع العمل (للأدوار المقيدة)', type: 'select', options: [{ value: '', label: '— كل المستودعات حسب الدور —' }, ...opts.warehouses()] },
      ], 'إنشاء', async (v, f) => { await post('admin/users', { name: v.name, email: v.email, password: v.password, roles: [v.role], warehouses: v.warehouse_id ? [Number(v.warehouse_id)] : [] }); toast('تم إنشاء المستخدم', 'ok'); f.reset(); refresh(); })),
      table([{ label: 'الاسم', key: 'name' }, { label: 'البريد', render: (u) => h('span', { class: 'ltr' }, u.email) }, { label: 'الأدوار', render: (u) => u.roles.join('، ') }, { label: 'المستودعات', render: (u) => u.warehouses.map((id) => state.lookups.warehouses.find((w) => w.id === id)?.code ?? id).join('، ') || '—' },
        { label: 'الحالة', render: (u) => h('span', { class: 'badge ' + (u.active ? 'ok' : 'bad') }, u.active ? 'فعّال' : 'موقوف') }, { label: '', render: (u) => h('button', { class: 'btn secondary small', onclick: () => edit(u) }, 'تعديل الصلاحيات') }], list));
  });
}

function audit() {
  const out = h('div');
  const load = async (v) => {
    const r = await get('audit-logs', { action: v.action, subject_type: v.subject_type, user_id: v.user_id, per_page: 100 });
    out.replaceChildren(table([{ label: 'الوقت', key: 'occurred_at' }, { label: 'المستخدم', num: true, render: (x) => x.user_id ?? '—' }, { label: 'الإجراء', render: (x) => h('span', { class: 'ltr' }, x.action) }, { label: 'السجل', render: (x) => (x.subject_type ? x.subject_type + ' #' + x.subject_id : '') },
      { label: 'IP', render: (x) => h('span', { class: 'ltr' }, x.ip || '') }, { label: 'التفاصيل', render: (x) => h('span', { class: 'ltr muted' }, JSON.stringify(x.data).slice(0, 160)) }], r.data, 'لا توجد سجلات'));
  };
  const verify = h('div', { class: 'toolbar' }, act('التحقق من سلامة السجل', async () => { const r = await get('audit-logs/verify'); toast(`السلسلة سليمة (${r.checked} سجل)`, 'ok'); return r; }, null, { ok: 'اكتمل الفحص' }));
  const f = form([{ name: 'action', label: 'الإجراء يبدأ بـ', type: 'text', placeholder: 'stock. / http.post / auth.' }, { name: 'subject_type', label: 'نوع السجل', type: 'text' }, { name: 'user_id', label: 'رقم المستخدم', type: 'number' }], 'بحث', load);
  load({});
  return h('div', null, verify, f, out);
}

export default async function () {
  return tabs([...(can('users.manage') ? [{ label: 'المستخدمون', render: async () => users() }] : []), ...(can('audit.view') ? [{ label: 'سجل التدقيق', render: async () => audit() }] : [])]);
}
