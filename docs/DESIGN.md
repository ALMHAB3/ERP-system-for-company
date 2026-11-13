# Interface design and motion

One vocabulary for the whole UI, defined once as CSS variables at the top of `public/app/style.css`.

## Visual rules
- **One accent** (blue) on a calm neutral scale; semantic green / amber / red only for meaning (paid, warning, error). All text meets 4.5:1 contrast.
- **No decoration without a job:** flat surfaces with a single soft shadow, no nested cards, no gradients, no icon tiles above headings.
- **Spacing scale** 4 / 8 / 12 / 16 / 24 / 32 px; logical properties (`inline-start`) so the same CSS serves Arabic (RTL) and English (LTR).
- **Numbers are tabular** (aligned digits) and always LTR inside RTL text; tables have sticky headers and a hover row.
- Navigation icons are small inline SVGs (no icon font, no external files).

## Motion vocabulary
Durations: micro 80 ms, stagger 40 ms, quick 150 ms (things closing), fast 250 ms (things opening / switching), medium 350 ms, slow 400 ms.
Easing: `cubic-bezier(.22, 1, .36, 1)` for all surface motion. Distances 8 px (16 px for toasts); blur 2 px; modal scale .96, toast scale .97.

| Where | Motion |
|---|---|
| Section / tab switch | content rises 8 px with a 2 px blur (250 ms); skeleton shimmer while loading |
| Tables | first 12 rows stagger in 40 ms apart |
| KPI cards, chart cards | pop in, staggered |
| Charts | bars grow from the baseline, lines draw themselves, donut segments fade in |
| Modal | scales from .96 in 250 ms, out in 150 ms; Esc and backdrop close; focus moves in and returns |
| Toast | rises with blur in 350 ms, leaves in 250 ms; the success check draws itself |
| Failed submit | the form shakes once; error toasts shake as they appear |
| Buttons | hover step, press scales .98, busy spinner while a request runs |

`prefers-reduced-motion: reduce` removes all of it (content is never left hidden), and animations are disabled in print.

The motion scale follows the token set published by [transitions.dev](https://transitions.dev); the CSS here is our own (that library's licence forbids redistributing its files).
