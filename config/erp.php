<?php

return [
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
        'fx_difference' => '5200',
        'purchase_variance' => '5300',
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
