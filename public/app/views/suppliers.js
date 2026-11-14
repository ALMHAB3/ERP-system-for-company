import { tabs, can } from '../ui.js';
import { suppliers, bills, pay, ageing } from './purchasing.js';

export default async function () {
  return tabs([
    { label: 'الموردون', render: async () => suppliers() },
    ...(can('finance.view', 'supplier_invoices.record') ? [{ label: 'فواتير الموردين', render: bills }] : []),
    ...(can('supplier_payments.make') ? [{ label: 'دفع للموردين', render: async () => pay() }] : []),
    ...(can('reports.view') ? [{ label: 'الديون المطلوبة للموردين (أعمار الديون)', render: async () => ageing() }] : []),
  ]);
}
