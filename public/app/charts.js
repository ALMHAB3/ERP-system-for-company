// Dependency-free SVG charts (no CDN, works offline, passes a strict CSP). Values arrive as plain numbers.
import { h } from './ui.js';

const NS = 'http://www.w3.org/2000/svg';
const S = (tag, attrs, ...kids) => {
  const el = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs || {})) if (v !== null && v !== undefined) el.setAttribute(k, v);
  if (attrs?.class === 'draw') el.setAttribute('pathLength', '1');   // lines draw themselves (stroke-dash animation in CSS)
  kids.flat().forEach((c) => c !== null && c !== undefined && el.append(c instanceof Node ? c : document.createTextNode(String(c))));
  return el;
};
export const COLORS = ['#0b5cab', '#e08a00', '#1a7f4b', '#7a4bb5', '#c4372c', '#0f8fa6', '#8a6d3b', '#5b6b7b'];
const fmtNum = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
const fmtDec = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
export const compact = (v) => { const a = Math.abs(v); return a >= 1e9 ? fmtDec.format(v / 1e9) + 'B' : a >= 1e6 ? fmtDec.format(v / 1e6) + 'M' : a >= 1e4 ? fmtDec.format(v / 1e3) + 'K' : fmtNum.format(v); };
export const full = (v) => fmtDec.format(v);

function niceScale(min, max, ticks = 5) {
  if (min === max) { max = min + 1; }
  const span = max - min, raw = span / ticks, mag = 10 ** Math.floor(Math.log10(raw)), norm = raw / mag;
  const step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10) * mag;
  const lo = Math.floor(min / step) * step, hi = Math.ceil(max / step) * step, out = [];
  for (let v = lo; v <= hi + step / 2; v += step) out.push(+v.toFixed(10));
  return { lo, hi, ticks: out };
}
const empty = (msg) => h('div', { class: 'chart-empty' }, msg || 'لا توجد بيانات كافية للرسم');
const wrap = (title, sub, body, legend) => paint(h('div', { class: 'chartcard' }, h('div', { class: 'ct' }, title), sub ? h('div', { class: 'cs' }, sub) : null, body, legend ? h('div', { class: 'legend' }, legend.map(([c, n, dash]) => h('span', null, h('i', { class: dash ? 'dash' : '', 'data-c': c }), n))) : null));
// Legend swatches carry their color in data-c; applied with CSSOM (allowed by CSP, unlike inline style attributes).
const paint = (root) => { root.querySelectorAll('[data-c]').forEach((el) => el.style.setProperty('--c', el.dataset.c)); return root; };

function frame(W, H, label) { return S('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': label, class: 'svgc', preserveAspectRatio: 'xMidYMid meet' }, S('title', null, label)); }

// Vertical bars (grouped) + optional lines on the same scale. series: [{name,values,color,type:'bar'|'line'}]
export function barLine({ title, sub, labels, series, unit, height = 280 }) {
  const all = series.flatMap((s) => s.values);
  if (!labels.length || all.every((v) => !v)) return wrap(title, sub, empty());
  const W = 680, H = height, m = { l: 56, r: 12, t: 12, b: 36 };
  const sc = niceScale(Math.min(0, ...all), Math.max(0, ...all));
  const iw = W - m.l - m.r, ih = H - m.t - m.b, y = (v) => m.t + ih - ((v - sc.lo) / (sc.hi - sc.lo)) * ih;
  const bars = series.filter((s) => s.type !== 'line'), lines = series.filter((s) => s.type === 'line');
  const gw = iw / labels.length, bw = Math.max(3, Math.min(34, (gw * 0.7) / Math.max(1, bars.length)));
  const svg = frame(W, H, title);
  sc.ticks.forEach((t) => svg.append(S('line', { x1: m.l, x2: W - m.r, y1: y(t), y2: y(t), class: t === 0 ? 'zero' : 'grid' }), S('text', { x: m.l - 6, y: y(t) + 4, class: 'ax', 'text-anchor': 'end' }, compact(t))));
  labels.forEach((l, i) => {
    const cx = m.l + gw * i + gw / 2;
    svg.append(S('text', { x: cx, y: H - 14, class: 'ax', 'text-anchor': 'middle' }, /^\d{4}-\d{2}$/.test(l) ? l.slice(2) : l));
    bars.forEach((s, k) => {
      const v = s.values[i], x = cx - (bars.length * bw) / 2 + k * bw, y0 = y(0), y1 = y(v);
      svg.append(S('rect', { class: 'bar', x, y: Math.min(y0, y1), width: bw - 2, height: Math.max(1, Math.abs(y0 - y1)), fill: s.color || COLORS[k], rx: 2 }, S('title', null, `${l} — ${s.name}: ${full(v)} ${unit || ''}`)));
    });
  });
  lines.forEach((s, k) => {
    const pts = s.values.map((v, i) => [m.l + gw * i + gw / 2, y(v)]);
    svg.append(S('polyline', { class: 'draw', points: pts.map((p) => p.join(',')).join(' '), fill: 'none', stroke: s.color || COLORS[k + 2], 'stroke-width': 2.5, 'stroke-linejoin': 'round' }),
      ...pts.map(([px, py], i) => S('circle', { cx: px, cy: py, r: 3.5, fill: '#fff', stroke: s.color || COLORS[k + 2], 'stroke-width': 2 }, S('title', null, `${labels[i]} — ${s.name}: ${full(s.values[i])} ${unit || ''}`))));
  });
  return wrap(title, sub, svg, series.map((s, i) => [s.color || COLORS[i], s.name, s.type === 'line']));
}

// Horizontal bars with labels and values; negative values drawn to the left in red.
export function hbars({ title, sub, items, unit, colorOf, valueLabel }) {
  items = items.filter((i) => Number.isFinite(i.value));
  if (!items.length) return wrap(title, sub, empty());
  const rowH = 30, W = 680, lw = 120, vw = 96, H = items.length * rowH + 12;
  const min = Math.min(0, ...items.map((i) => i.value)), max = Math.max(0, ...items.map((i) => i.value)) || 1;
  const x0 = lw + ((0 - min) / (max - min)) * (W - lw - vw), sx = (W - lw - vw) / (max - min);
  const svg = frame(W, H, title);
  svg.append(S('line', { x1: x0, x2: x0, y1: 4, y2: H - 4, class: 'zero' }));
  items.forEach((it, i) => {
    const y = 8 + i * rowH, w = Math.abs(it.value) * sx, x = it.value >= 0 ? x0 : x0 - w;
    svg.append(S('text', { x: lw - 8, y: y + 15, class: 'ax lab', 'text-anchor': 'end' }, it.label.length > 16 ? it.label.slice(0, 15) + '…' : it.label),
      S('rect', { class: 'hbar', x, y, width: Math.max(2, w), height: 20, rx: 3, fill: colorOf ? colorOf(it, i) : it.value < 0 ? '#c4372c' : COLORS[0] }, S('title', null, `${it.label}: ${full(it.value)} ${unit || ''}`)),
      S('text', { x: it.value >= 0 ? x + Math.max(2, w) + 6 : x - 6, y: y + 15, class: 'ax val', 'text-anchor': it.value >= 0 ? 'start' : 'end' }, valueLabel ? valueLabel(it) : compact(it.value)));
  });
  return wrap(title, sub, svg);
}

export function donut({ title, sub, items, unit, centerLabel }) {
  items = items.filter((i) => i.value > 0);
  const total = items.reduce((a, i) => a + i.value, 0);
  if (!total) return wrap(title, sub, empty());
  const W = 300, H = 220, cx = 110, cy = 110, R = 90, r = 56;
  const svg = frame(W, H, title);
  let a0 = -Math.PI / 2;
  items.forEach((it, i) => {
    const frac = it.value / total, a1 = a0 + frac * Math.PI * 2 - (items.length > 1 ? 0.012 : 0), large = frac > 0.5 ? 1 : 0;
    const p = (a, rad) => [cx + rad * Math.cos(a), cy + rad * Math.sin(a)];
    const [x1, y1] = p(a0, R), [x2, y2] = p(a1, R), [x3, y3] = p(a1, r), [x4, y4] = p(a0, r);
    const d = items.length === 1 ? `M${cx - R},${cy} a${R},${R} 0 1,0 ${2 * R},0 a${R},${R} 0 1,0 ${-2 * R},0 M${cx - r},${cy} a${r},${r} 0 1,1 ${2 * r},0 a${r},${r} 0 1,1 ${-2 * r},0` : `M${x1},${y1} A${R},${R} 0 ${large} 1 ${x2},${y2} L${x3},${y3} A${r},${r} 0 ${large} 0 ${x4},${y4} Z`;
    svg.append(S('path', { class: 'seg', d, fill: COLORS[i % COLORS.length], 'fill-rule': 'evenodd' }, S('title', null, `${it.label}: ${full(it.value)} ${unit || ''} (${(frac * 100).toFixed(1)}%)`)));
    a0 += frac * Math.PI * 2;
  });
  svg.append(S('text', { x: cx, y: cy - 2, class: 'dtot', 'text-anchor': 'middle' }, compact(total)), S('text', { x: cx, y: cy + 16, class: 'ax', 'text-anchor': 'middle' }, centerLabel || unit || ''));
  const list = h('ul', { class: 'dleg' }, items.map((it, i) => h('li', null, h('i', { 'data-c': COLORS[i % COLORS.length] }), h('span', null, it.label), h('b', null, ((it.value / total) * 100).toFixed(0) + '%'))));
  return wrap(title, sub, h('div', { class: 'donutrow' }, svg, list));
}

// History (solid) + forecast (dashed) on a month axis.
export function forecastChart({ title, sub, historyLabels, history, forecastLabels, forecast, unit }) {
  const labels = [...historyLabels, ...forecastLabels];
  if (!history.length && !forecast.some((v) => v > 0)) return wrap(title, sub, empty('لا توجد مبيعات سابقة للتنبؤ منها'));
  const W = 680, H = 280, m = { l: 56, r: 14, t: 14, b: 36 }, iw = W - m.l - m.r, ih = H - m.t - m.b;
  const all = [...history, ...forecast], sc = niceScale(0, Math.max(...all, 1));
  const x = (i) => m.l + (labels.length === 1 ? iw / 2 : (iw * i) / (labels.length - 1)), y = (v) => m.t + ih - ((v - sc.lo) / (sc.hi - sc.lo)) * ih;
  const svg = frame(W, H, title);
  sc.ticks.forEach((t) => svg.append(S('line', { x1: m.l, x2: W - m.r, y1: y(t), y2: y(t), class: 'grid' }), S('text', { x: m.l - 6, y: y(t) + 4, class: 'ax', 'text-anchor': 'end' }, compact(t))));
  const step = Math.ceil(labels.length / 12);
  labels.forEach((l, i) => i % step === 0 && svg.append(S('text', { x: x(i), y: H - 14, class: 'ax', 'text-anchor': 'middle' }, l.slice(2))));
  if (forecast.length) svg.append(S('rect', { x: x(history.length - 0.5 < 0 ? 0 : history.length - 0.5), y: m.t, width: W - m.r - x(Math.max(0, history.length - 0.5)), height: ih, class: 'fband' }));
  const hp = history.map((v, i) => [x(i), y(v)]);
  if (hp.length) svg.append(S('polyline', { class: 'draw', points: hp.map((p) => p.join(',')).join(' '), fill: 'none', stroke: COLORS[0], 'stroke-width': 2.5 }), ...hp.map(([px, py], i) => S('circle', { cx: px, cy: py, r: 3, fill: COLORS[0] }, S('title', null, `${historyLabels[i]}: ${full(history[i])} ${unit || ''}`))));
  const fp = [...(hp.length ? [hp[hp.length - 1]] : []), ...forecast.map((v, i) => [x(history.length + i), y(v)])];
  svg.append(S('polyline', { points: fp.map((p) => p.join(',')).join(' '), fill: 'none', stroke: COLORS[1], 'stroke-width': 2.5, 'stroke-dasharray': '6 5', class: 'fc' }), ...forecast.map((v, i) => S('circle', { cx: x(history.length + i), cy: y(v), r: 3.5, fill: '#fff', stroke: COLORS[1], 'stroke-width': 2 }, S('title', null, `${forecastLabels[i]}: ${full(v)} ${unit || ''} (متوقع)`))));
  return wrap(title, sub, svg, [[COLORS[0], 'المبيعات الفعلية'], [COLORS[1], 'التنبؤ', true]]);
}

export const grid = (...cards) => h('div', { class: 'grid2' }, cards);
