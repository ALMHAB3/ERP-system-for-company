import { tabs, can } from '../ui.js';
import { customers, ageing, receivables } from './sales.js';

export default async function () {
  return tabs([
    { label: 'العملاء', render: async () => customers() },
    ...(can('receipts.record', 'finance.view') ? [{ label: 'تحصيل الديون والفواتير', render: async () => receivables() }] : []),
    ...(can('reports.view') ? [{ label: 'الديون المطلوبة من العملاء (أعمار الديون)', render: async () => ageing() }] : []),
  ]);
}
