import { h, get, can, money, qty, table, state } from '../ui.js';
import { barLine, hbars, donut, grid, COLORS, compact } from '../charts.js';

const kpi = (label, value, unit, cls, sub) => h('div', { class: 'kpi ' + (cls || '') }, h('div', { class: 'l' }, label), h('div', { class: 'v' }, value, unit ? h('span', { class: 'muted' }, ' ' + unit) : null), sub ? h('div', { class: 's' }, sub) : null);
const BUCKET = { current: 'غير مستحق', '1-30': '1–30', '31-60': '31–60', '61-90': '61–90', '90+': '+90' };
const EXPIRY = { expired: 'منتهية', '0-30': '≤30 يوم', '31-60': '31–60', '61-90': '61–90', '90+': '+90 يوم', none: 'بلا صلاحية' };
const STATUS = { open: 'قيد الشحن', costed: 'محتسبة التكلفة', received: 'مستلمة' };
const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

export default async function () {
  if (!can('reports.view')) return h('div', { class: 'card' }, 'مرحباً ' + state.me.name + '. اختر قسماً من القائمة.');
  const cur = state.lookups.currency;
  const safe = (p) => p.catch(() => null);
  const [d, ch, reorder, expiring, ar, ap] = await Promise.all([
    get('reports/dashboard'), get('reports/charts'), get('reports/reorder-suggestions'), get('reports/expiring', { days: 60 }),
    safe(get('receivables/ageing')), safe(get('payables/ageing')),
  ]);
  const profitTotal = ch.profit ? ch.profit.reduce((a, b) => a + b, 0) : null;
  const revenueTotal = ch.revenue.reduce((a, b) => a + b, 0);
  const margin = profitTotal !== null && revenueTotal > 0 ? (profitTotal / revenueTotal) * 100 : null;
  const labels = ch.months.map((m) => MONTHS[Number(m.slice(5)) - 1].slice(0, 4));

  const charts = [
    barLine({ title: 'المبيعات والربح الشهري (آخر 12 شهراً)', sub: 'الصافي بعد المرتجعات — ' + cur, labels, unit: cur,
      series: [{ name: 'المبيعات', values: ch.revenue, color: COLORS[0] }, ...(ch.cogs ? [{ name: 'تكلفة المباع', values: ch.cogs, color: '#b8c4d0' }, { name: 'الربح', values: ch.profit, color: COLORS[2], type: 'line' }] : [])] }),
    donut({ title: 'قيمة المخزون حسب المستودع', items: ch.inventory_by_warehouse, unit: cur, centerLabel: cur }),
    ...(ch.top_products ? [hbars({ title: 'أعلى الأصناف ربحاً (آخر 90 يوماً)', items: ch.top_products, unit: cur, valueLabel: (i) => compact(i.value) + (i.margin_pct !== null ? ' · ' + i.margin_pct + '%' : '') })] : []),
    barLine({ title: 'قيمة المخزون حسب قرب انتهاء الصلاحية', sub: 'القيمة المعرّضة للخسارة بالأحمر', labels: Object.keys(ch.expiry_buckets).map((k) => EXPIRY[k]), unit: cur, height: 240,
      series: [{ name: cur, values: Object.values(ch.expiry_buckets), color: COLORS[0] }] }),
    ...(ar && ap ? [barLine({ title: 'أعمار الديون: لنا (العملاء) وعلينا (الموردون)', sub: 'حسب أيام التأخر — ' + cur, labels: Object.keys(ar.buckets).map((k) => BUCKET[k]), unit: cur, height: 240,
      series: [{ name: 'ذمم العملاء', values: Object.values(ar.buckets).map(Number), color: COLORS[2] }, { name: 'مستحقات الموردين', values: Object.values(ap.buckets).map(Number), color: COLORS[4] }] })] : []),
    donut({ title: 'مستحقات الموردين حسب عملة الفاتورة', sub: 'القيمة بالعملة الأساسية', items: ch.payables_by_currency, unit: cur, centerLabel: cur }),
    ...(ch.inventory_by_product ? [hbars({ title: 'أكبر الأصناف قيمة في المخزون', items: ch.inventory_by_product, unit: cur })] : []),
    hbars({ title: 'حالة الشحنات', items: Object.entries(ch.shipments_by_status).map(([k, v]) => ({ label: STATUS[k] || k, value: v })), colorOf: (it, i) => COLORS[i % COLORS.length], valueLabel: (i) => String(i.value) }),
  ];

  return h('div', null,
    h('div', { class: 'cards' },
      kpi('قيمة المخزون', money(d.inventory_value), cur),
      kpi('مبيعات هذا الشهر (صافي)', money(d.revenue_this_month), cur),
      ...(margin !== null ? [kpi('هامش الربح (12 شهراً)', margin.toFixed(1) + '%', '', margin < 0 ? 'bad' : 'good')] : []),
      kpi('ذمم العملاء المفتوحة', money(d.receivables_open), cur),
      kpi('مستحقات الموردين', money(d.payables_open), cur, Number(d.payables_open) > Number(d.receivables_open) ? 'bad' : ''),
      kpi('دوران المخزون (سنوي)', d.inventory_turnover ?? '—', '', '', d.days_of_inventory ? 'أي ' + d.days_of_inventory + ' يوم مخزون' : ''),
      kpi('طلبات بانتظار الشحن', d.orders_waiting_shipment),
      kpi('شحنات قادمة', d.shipments.open + d.shipments.costed, '', '', d.shipments.received + ' مستلمة'),
      kpi('مخزون ينتهي خلال 60 يوماً', money(d.expiring_60d_value), cur, d.expiring_60d_lots ? 'bad' : '', d.expiring_60d_lots + ' دفعة')),
    grid(...charts),
    h('h3', null, 'أصناف تحتاج إعادة طلب'),
    table([
      { label: 'الصنف', render: (r) => r.sku + ' — ' + r.name }, { label: 'الرصيد المتاح + القادم', num: true, render: (r) => qty(r.position) },
      { label: 'الطلب اليومي', num: true, render: (r) => qty(r.daily_demand) }, { label: 'أيام التغطية', num: true, render: (r) => r.days_of_cover ?? '' },
      { label: 'نقطة إعادة الطلب', num: true, render: (r) => qty(r.reorder_point) }, { label: 'الكمية المقترحة', num: true, render: (r) => h('b', null, qty(r.suggested_quantity)) },
    ], reorder.data.slice(0, 8), 'لا توجد أصناف تحت نقطة إعادة الطلب', { csv: 'reorder' }),
    h('h3', null, 'دفعات قاربت على الانتهاء'),
    table([
      { label: 'الصنف', render: (r) => r.sku + ' — ' + r.name }, { label: 'المستودع', key: 'warehouse' }, { label: 'الدفعة', key: 'lot_no' },
      { label: 'الانتهاء', render: (r) => h('span', { class: r.expired ? 'neg' : '' }, r.expiry_date + (r.expired ? ' (منتهية)' : '')) },
      { label: 'الكمية', num: true, render: (r) => qty(r.on_hand) }, { label: 'القيمة', num: true, render: (r) => money(r.value) },
    ], expiring.data.slice(0, 8), 'لا توجد دفعات قريبة الانتهاء'));
}
