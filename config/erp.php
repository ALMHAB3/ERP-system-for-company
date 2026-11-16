<?php

return [
    // The company's name. It is Arabic in every language of the interface (never translated).
    // An .env written in the wrong encoding turns Arabic into '?': never show that, fall back to the real name.
    'company_name' => (static function (): string {
        $name = trim((string) env('COMPANY_NAME', ''));
        // A wrong file encoding turns Arabic into '?' or into Latin junk such as "Ø´Ø±ÙƒØ©": never show that.
        $garbled = $name === '' || str_contains($name, '?') || ! mb_check_encoding($name, 'UTF-8') || preg_match('/[ÃÂØÙ][\x{0080}-\x{00FF}\x{0152}-\x{2122}]/u', $name);

        return $garbled ? 'شركة هب الريح لعوازل الأسطح ومواد البناء' : $name;
    })(),
    // Printed on every invoice (e-invoice seller block). Fill in the real registration data before real use.
    'company' => ['tax_id' => env('COMPANY_TAX_ID', ''), 'address' => env('COMPANY_ADDRESS', ''), 'phone' => env('COMPANY_PHONE', '')],
    // Payroll: absence is deducted as base salary / 30 per day.
    // Outgoing webhooks: refuse targets that resolve to link-local/metadata addresses (set true only to allow every private address).
    'webhooks' => ['allow_private' => env('WEBHOOKS_ALLOW_PRIVATE', true), 'max_attempts' => 6],

    // Company (accounting) currency. Everything is booked in it; foreign amounts keep their own currency and rate.
    'base_currency' => env('ERP_BASE_CURRENCY', 'LYD'),

    // VAT / sales tax rate as a 4-decimal string. Set to the rate of your jurisdiction.
    'vat_rate' => env('ERP_VAT_RATE', '0.0000'),

    // Currencies of the business (import invoices, supplier payments). Enter their rates under Accounting > Exchange rates.
    // ISO code => Arabic name. Libyan dinar is the base currency; USD, EUR and CNY are used for imports.
    'currencies' => ['LYD' => 'دينار ليبي', 'USD' => 'دولار أمريكي', 'EUR' => 'يورو', 'CNY' => 'يوان صيني'],

    // Chart-of-accounts codes used by the automatic posting rules.
    'accounts' => [
        'bank' => '1100',            // legacy / unspecified bank
        'cash' => '1110',            // cash on hand (the till)
        'receivables' => '1200',
        'inventory' => '1300',
        'goods_received_not_invoiced' => '2100',
        'vat_payable' => '2200',
        'revenue' => '4000',
        'cost_of_goods_sold' => '5000',
        'inventory_variance' => '5100',
        'payables' => '2000',
        'vat_input' => '1400',
        'fx_difference' => '5200',       // realised, booked when a foreign invoice is paid
        'fx_unrealized' => '5210',       // period-end revaluation of open foreign balances
        'bank_charges' => '5600',
        'purchase_variance' => '5300',
        'employee_advances' => '1350',
        'salaries_payable' => '2300',
        'payroll_expense' => '5500',
    ],

    // Purchase-order approval ladder: [upper limit of order total, approval level required].
    'po_approval_ladder' => [
        ['limit' => '10000.0000', 'level' => 1],   // purchasing manager
        ['limit' => '100000.0000', 'level' => 2],  // finance manager
        ['limit' => null, 'level' => 3],           // general manager
    ],

    // Wholesale quantity-break discounts: [minimum quantity, discount rate].
    'quantity_breaks' => [
        ['min_qty' => '100', 'rate' => '0.0300'],
        ['min_qty' => '500', 'rate' => '0.0600'],
        ['min_qty' => '1000', 'rate' => '0.1000'],
    ],

    // Suggested selling prices: cost (landed, in base currency) + margin, then rounded UP to a multiple of the step
    // (e.g. step 5: 353 -> 355, 356 -> 360, 355 -> 355). Margins are default percentages per customer category.
    'pricing' => [
        'rounding_step' => env('ERP_PRICE_STEP', '5'),
        'margins' => ['wholesale' => '10', 'retail' => '25', 'direct' => '30'],
    ],

    // Selling below cost x (1 + this margin) flags the quote for approval.
    'min_margin' => env('ERP_MIN_MARGIN', '0.0500'),

];
