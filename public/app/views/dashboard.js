import { h, get, can, money, qty, table, state } from '../ui.js';

const kpi = (label, value, unit) => h('div', { class: 'kpi' }, h('div', { class: 'l' }, label), h('div', { class: 'v' }, value, unit ? h('span', { class: 'muted' }, ' ' + unit) : null));

export default async function () {
  if (!can('reports.view')) return h('div', { class: 'card' }, 'مرحباً ' + state.me.name + '. اختر قسماً من القائمة.');
  const [d, reorder, expiring] = await Promise.all([get('reports/dashboard'), get('reports/reorder-suggestions'), get('reports/expiring', { days: 60 })]);
  const cur = d.currency;
  return h('div', null,
    h('div', { class: 'cards' },
      kpi('قيمة المخزون', money(d.inventory_value), cur),
      kpi('مبيعات هذا الشهر (صافي)', money(d.revenue_this_month), cur),
      kpi('ذمم العملاء المفتوحة', money(d.receivables_open), cur),
      kpi('مستحقات الموردين', money(d.payables_open), cur),
      kpi('معدل دوران المخزون (سنوي)', d.inventory_turnover ?? '—'),
      kpi('أيام المخزون', d.days_of_inventory ?? '—', d.days_of_inventory ? 'يوم' : ''),
      kpi('طلبات بانتظار الشحن', d.orders_waiting_shipment),
      kpi('شحنات قادمة (لم تُستلم)', d.shipments.open + d.shipments.costed),
      kpi('مخزون ينتهي خلال 60 يوماً', money(d.expiring_60d_value), cur + ' · ' + d.expiring_60d_lots + ' دفعة')),
    h('h3', null, 'أصناف تحتاج إعادة طلب'),
    table([
      { label: 'الصنف', render: (r) => r.sku + ' — ' + r.name },
      { label: 'الرصيد المتاح + القادم', num: true, render: (r) => qty(r.position) },
      { label: 'الطلب اليومي', num: true, render: (r) => qty(r.daily_demand) },
      { label: 'أيام التغطية', num: true, render: (r) => r.days_of_cover ?? '' },
      { label: 'نقطة إعادة الطلب', num: true, render: (r) => qty(r.reorder_point) },
      { label: 'الكمية المقترحة', num: true, render: (r) => qty(r.suggested_quantity) },
    ], reorder.data.slice(0, 8), 'لا توجد أصناف تحت نقطة إعادة الطلب'),
    h('h3', null, 'دفعات قاربت على الانتهاء'),
    table([
      { label: 'الصنف', render: (r) => r.sku + ' — ' + r.name }, { label: 'المستودع', key: 'warehouse' }, { label: 'الدفعة', key: 'lot_no' },
      { label: 'الانتهاء', render: (r) => h('span', { class: r.expired ? 'neg' : '' }, r.expiry_date + (r.expired ? ' (منتهية)' : '')) },
      { label: 'الكمية', num: true, render: (r) => qty(r.on_hand) }, { label: 'القيمة', num: true, render: (r) => money(r.value) },
    ], expiring.data.slice(0, 8), 'لا توجد دفعات قريبة الانتهاء'));
}
