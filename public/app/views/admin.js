import { h, get, post, put, download, form, table, tabs, panel, act, can, modal, badge, toast, state, opts } from '../ui.js';

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

const DELIVERY = { pending: ['بانتظار الإرسال', 'warn'], delivered: ['تم', 'ok'], failed: ['فشل نهائياً', 'bad'] };
const EVENT_LABEL = { 'invoice.issued': 'إصدار فاتورة', 'payment.received': 'استلام دفعة', 'credit_note.issued': 'إشعار دائن' };

function integrations() {
  return panel(async (refresh) => {
    const r = await get('integrations/webhooks');
    const deliveries = (ep) => get(`integrations/webhooks/${ep.id}/deliveries`).then((x) => modal('آخر عمليات الإرسال — ' + ep.name, table([
      { label: 'الحدث', render: (d) => h('span', { class: 'ltr' }, d.event) }, { label: 'الحالة', render: (d) => h('span', { class: 'badge ' + DELIVERY[d.status][1] }, DELIVERY[d.status][0]) },
      { label: 'المحاولات', num: true, render: (d) => d.attempts }, { label: 'الرمز', render: (d) => d.last_code ?? '—' }, { label: 'الخطأ', render: (d) => d.last_error || '' }, { label: 'الوقت', key: 'created_at' },
    ], x.data, 'لا توجد عمليات إرسال'))).catch((e) => toast(e.message, 'error'));
    return h('div', null,
      h('div', { class: 'card' }, h('h3', null, 'ويب هوك جديد (إشعار نظام آخر عند حدوث عملية)'),
        h('p', { class: 'hint' }, 'يرسل النظام طلب POST بصيغة JSON موقّعاً (X-ERP-Signature = HMAC-SHA256 للسر) مع إعادة محاولة تلقائية. التفاصيل في docs/INTEGRATIONS.md.'),
        form([
          { name: 'name', label: 'الاسم', type: 'text', required: true }, { name: 'url', label: 'العنوان (URL)', type: 'text', required: true, placeholder: 'https://…' },
          ...r.events.map((e) => ({ name: 'ev_' + e, label: (EVENT_LABEL[e] || e) + ' (' + e + ')', type: 'checkbox', value: true })),
        ], 'إنشاء', async (v) => {
          const events = r.events.filter((e) => v['ev_' + e]);
          if (!events.length) throw new Error('اختر حدثاً واحداً على الأقل');
          const c = await post('integrations/webhooks', { name: v.name, url: v.url, events });
          modal('احفظ السر الآن (لن يظهر مرة أخرى)', h('div', null, h('p', { class: 'hint' }, 'استخدمه في الطرف المستقبِل للتحقق من التوقيع.'), h('input', { class: 'ltr', readonly: true, value: c.secret, onfocus: (e) => e.target.select() })));
          refresh();
        })),
      table([{ label: 'الاسم', key: 'name' }, { label: 'العنوان', render: (e) => h('span', { class: 'ltr' }, e.url) }, { label: 'الأحداث', render: (e) => e.events.join(', ') },
        { label: 'قيد الإرسال', num: true, render: (e) => e.pending }, { label: 'فاشلة', num: true, render: (e) => e.failed },
        { label: 'الحالة', render: (e) => h('span', { class: 'badge ' + (e.active ? 'ok' : 'bad') }, e.active ? 'فعّال' : 'موقوف') },
        { label: '', render: (e) => h('div', { class: 'row-actions' }, h('button', { class: 'btn secondary small', onclick: () => deliveries(e) }, 'السجل'),
          act(e.active ? 'إيقاف' : 'تفعيل', () => put(`integrations/webhooks/${e.id}/active`, { active: !e.active }), refresh, { ok: 'تم' })) }], r.data, 'لا توجد ويب هوك'),
      h('div', { class: 'card' }, h('h3', null, 'تصدير للبيانات (CSV يفتح في Excel)'), h('div', { class: 'row-actions' },
        [['invoices', 'الفواتير'], ['products', 'الأصناف والأرصدة'], ['customers', 'العملاء']].map(([k, label]) => h('button', { class: 'btn secondary small', onclick: () => download(`export/${k}.csv`, k + '.csv').catch((e) => toast(e.message, 'error')) }, label)))),
      h('p', { class: 'hint' }, 'مفتاح API لنظام خارجي: من جهاز الخادم نفّذ  docker compose exec app php artisan erp:integration-token <بريد-مستخدم-مخصص>  (أعطِ المستخدم أضيق دور ممكن).'));
  });
}

export default async function () {
  return tabs([...(can('users.manage') ? [{ label: 'المستخدمون', render: async () => users() }] : []), ...(can('audit.view') ? [{ label: 'سجل التدقيق', render: async () => audit() }] : []), ...(can('integrations.manage') ? [{ label: 'التكاملات', render: async () => integrations() }] : [])]);
}
