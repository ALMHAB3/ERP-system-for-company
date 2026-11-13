import { h, get, form, table, tabs, panel, money, qty, can, refItems, today, state } from '../ui.js';
import { hbars, forecastChart, grid, compact } from '../charts.js';

const sh = () => panel(async () => {
  if (!can('inventory.view_cost')) return h('div', { class: 'card empty' }, 'تتطلب صلاحية رؤية التكاليف');
  const r = await get('reports/shipment-profitability');
  return h('div', null, grid(hbars({ title: 'ربح كل شحنة', sub: 'المبيعات − التكلفة الفعلية للمباع، بعد المرتجعات — ' + state.lookups.currency, items: r.data.map((x) => ({ label: x.shipment, value: Number(x.profit) })), valueLabel: (i) => compact(i.value) }),
    hbars({ title: 'نسبة بيع الشحنة (من المستلم)', items: r.data.map((x) => ({ label: x.shipment, value: x.sell_through_pct ?? 0 })), colorOf: () => '#0f8fa6', valueLabel: (i) => i.value + '%' })),
    h('p', { class: 'hint' }, 'الربح = مبيعات دفعات الشحنة − تكلفتها الفعلية، بعد المرتجعات. المخزون غير المباع يظهر بتكلفته.'),
    table([{ label: 'الشحنة', key: 'shipment' }, { label: 'المستلم', num: true, render: (r) => qty(r.received_qty) }, { label: 'المباع', num: true, render: (r) => qty(r.sold_qty) }, { label: 'نسبة البيع %', num: true, render: (r) => r.sell_through_pct ?? '' },
      { label: 'التكلفة الفعلية', num: true, render: (r) => money(r.landed_cost) }, { label: 'الإيراد', num: true, render: (r) => money(r.revenue) }, { label: 'تكلفة المباع', num: true, render: (r) => money(r.cogs) }, { label: 'نوع التكلفة', render: (r) => h('span', { class: 'badge ' + (r.final_cost ? 'ok' : 'warn') }, r.final_cost ? 'نهائية' : 'تقديرية') },
      { label: 'الربح', num: true, render: (r) => h('b', { class: Number(r.profit) < 0 ? 'neg' : 'pos' }, money(r.profit)) }, { label: 'الهامش %', num: true, render: (r) => r.margin_pct ?? '' }, { label: 'مخزون غير مباع (تكلفة)', num: true, render: (r) => money(r.unsold_stock_cost) }], r.data, 'لا توجد شحنات مستلمة', { csv: 'shipment-profit' }));
});

const prod = () => {
  const out = h('div');
  const load = async (v) => {
    const r = await get('reports/product-profitability', v);
    out.replaceChildren(grid(hbars({ title: 'ربح الأصناف في الفترة', items: r.data.slice(0, 12).map((x) => ({ label: x.sku, value: Number(x.profit) })), valueLabel: (i) => compact(i.value) })), table([{ label: 'الصنف', render: (x) => x.sku + ' — ' + x.name }, { label: 'الكمية', num: true, render: (x) => qty(x.quantity) }, { label: 'الإيراد', num: true, render: (x) => money(x.revenue) }, { label: 'التكلفة', num: true, render: (x) => money(x.cogs) },
      { label: 'الربح', num: true, render: (x) => h('b', { class: Number(x.profit) < 0 ? 'neg' : 'pos' }, money(x.profit)) }, { label: 'الهامش %', num: true, render: (x) => x.margin_pct ?? '' }], r.data, 'لا توجد مبيعات في الفترة', { csv: 'product-profit' }));
  };
  const f = form([{ name: 'from', label: 'من', type: 'date', value: new Date(Date.now() - 90 * 864e5).toISOString().slice(0, 10) }, { name: 'to', label: 'إلى', type: 'date', value: today() }], 'عرض', load);
  load({}); return h('div', null, f, out);
};

const lotsExpiring = () => {
  const out = h('div');
  const load = async (v) => {
    const r = await get('reports/expiring', { days: v.days || 90 });
    out.replaceChildren(table([{ label: 'الصنف', render: (x) => x.sku + ' — ' + x.name }, { label: 'المستودع', key: 'warehouse' }, { label: 'الدفعة', key: 'lot_no' }, { label: 'الانتهاء', render: (x) => h('span', { class: x.expired ? 'neg' : '' }, x.expiry_date + (x.expired ? ' (منتهية)' : '')) },
      { label: 'الكمية', num: true, render: (x) => qty(x.on_hand) }, { label: 'القيمة المعرّضة للخسارة', num: true, render: (x) => money(x.value) }], r.data, 'لا توجد دفعات', { csv: 'expiring-lots' }));
  };
  const f = form([{ name: 'days', label: 'خلال (يوم)', type: 'number', value: '90' }], 'عرض', load); load({}); return h('div', null, f, out);
};

const slow = () => {
  const out = h('div');
  const load = async (v) => {
    const r = await get('reports/slow-moving', { days: v.days || 90 });
    out.replaceChildren(table([{ label: 'الصنف', render: (x) => x.sku + ' — ' + x.name }, { label: 'الرصيد', num: true, render: (x) => qty(x.on_hand) }, { label: 'القيمة المجمّدة', num: true, render: (x) => money(x.value) }, { label: 'آخر بيع', render: (x) => x.last_sale || 'لم يُبع' }], r.data, 'لا يوجد مخزون راكد'));
  };
  const f = form([{ name: 'days', label: 'لم يُصرف منذ (يوم)', type: 'number', value: '90' }], 'عرض', load); load({}); return h('div', null, f, out);
};

const forecast = () => {
  const out = h('div');
  return h('div', null, h('p', { class: 'hint' }, 'التنبؤ من مبيعات الأشهر المكتملة: 24 شهراً فأكثر = موسمي (Holt-Winters)، 6–23 = اتجاه، أقل = متوسط. WAPE خطأ التنبؤ على آخر 3 أشهر (الأقل أفضل).'),
    form([{ name: 'product_id', label: 'الصنف', type: 'ref', items: refItems.products(), required: true }, { name: 'horizon', label: 'عدد الأشهر القادمة', type: 'number', value: '6' }], 'احسب', async (v) => {
      const r = await get('reports/forecast', { product_id: v.product_id, horizon: v.horizon || 6 });
      const names = { holt_winters: 'موسمي (Holt-Winters)', holt_linear: 'اتجاه (Holt)', mean: 'متوسط', none: 'لا توجد مبيعات' };
      out.replaceChildren(forecastChart({ title: 'المبيعات الفعلية والتنبؤ', sub: 'وحدات شهرياً (أشهر مكتملة)', historyLabels: r.history_labels, history: r.history, forecastLabels: r.forecast_labels, forecast: r.forecast, unit: 'وحدة' }), h('div', { class: 'card' }, 'الطريقة: ', h('b', null, names[r.method]), ' · أشهر التاريخ: ', r.history_months, ' · WAPE: ', r.wape === null ? 'غير متاح (تاريخ قصير)' : (r.wape * 100).toFixed(1) + '%'),
        table([{ label: 'الشهر القادم', render: (x) => '+' + x.i }, { label: 'الكمية المتوقعة', num: true, render: (x) => qty(x.v) }], r.forecast.map((v, i) => ({ i: i + 1, v }))));
    }), out);
};

const reorder = () => panel(async () => {
  const r = await get('reports/reorder-suggestions');
  return h('div', null, h('p', { class: 'hint' }, 'الرصيد = المتاح + القادم (شحنات وأوامر شراء) − المحجوز. الاقتراح يغطي مدة التوريد + مدة التغطية + مخزون الأمان (مستوى خدمة 95%).'),
    table([{ label: 'الصنف', render: (x) => x.sku + ' — ' + x.name }, { label: 'المتاح', num: true, render: (x) => qty(x.on_hand) }, { label: 'القادم', num: true, render: (x) => qty(x.incoming) }, { label: 'الرصيد', num: true, render: (x) => qty(x.position) },
      { label: 'الطلب اليومي', num: true, render: (x) => qty(x.daily_demand) }, { label: 'مدة التوريد', num: true, key: 'lead_time_days' }, { label: 'مخزون الأمان', num: true, render: (x) => qty(x.safety_stock) },
      { label: 'نقطة الطلب', num: true, render: (x) => qty(x.reorder_point) }, { label: 'أيام التغطية', num: true, render: (x) => x.days_of_cover ?? '' }, { label: 'الكمية المقترحة', num: true, render: (x) => h('b', null, qty(x.suggested_quantity)) }], r.data, 'كل الأصناف فوق نقطة إعادة الطلب', { csv: 'reorder-suggestions' }));
});

export default async function () {
  return tabs([{ label: 'اقتراحات الشراء', render: async () => reorder() }, { label: 'التنبؤ بالطلب', render: async () => forecast() }, { label: 'ربحية الشحنات', render: async () => sh() }, { label: 'ربحية الأصناف', render: async () => prod() },
    { label: 'الصلاحية', render: async () => lotsExpiring() }, { label: 'المخزون الراكد', render: async () => slow() }]);
}
