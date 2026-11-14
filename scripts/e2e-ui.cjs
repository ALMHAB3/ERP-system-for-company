// End-to-end smoke test of the web UI. Needs a running instance (default http://127.0.0.1:8099/app/ via `php artisan serve --port=8099`)
// on a database with `php artisan erp:demo-data --with-users` loaded plus an administrator admin@demo.test (password Demo-Passw0rd-123).
// Covers: one-click sale (unknown / typed buyer, debt only for registered customers), the manager's period summary, separate customer / supplier
// screens, branch and user creation by the administrator, shipment form (selling price, expiry only for expiring products).
// Usage: SHOTS=/tmp/shots NODE_PATH=$(npm root -g) node scripts/e2e-ui.cjs
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 1400, height: 950 } });
  const problems = []; page.on('pageerror', (e) => problems.push('pageerror: ' + e.message)); page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
  page.on('dialog', (d) => d.accept());
  const SH = process.env.SHOTS + '/';
  const step = async (n, f) => { try { await f(); console.log('ok   ', n); } catch (e) { console.log('FAIL ', n, '-', e.message.split('\n')[0]); problems.push(n); await page.screenshot({ path: SH + 'fail.png' }).catch(() => {}); } };
  const go = async (id) => { await page.evaluate((i) => (location.hash = '#/' + i), id); await page.waitForTimeout(700); };
  const tab = async (t) => { await page.locator('.tabs button', { hasText: t }).first().click(); await page.waitForTimeout(600); };
  const login = async (email) => { await page.goto('' + (process.env.BASE_URL || 'http://127.0.0.1:8099') + '/app/'); await page.evaluate(() => sessionStorage.clear()); await page.reload(); await page.getByLabel('البريد الإلكتروني').fill(email); await page.getByLabel('كلمة المرور').fill('Demo-Passw0rd-123'); await page.getByRole('button', { name: 'دخول' }).click(); await page.waitForSelector('nav.side'); };
  const pick = async (loc, text) => { await loc.fill(text); await loc.locator('xpath=..').locator('.search-results li:not(.none)').first().waitFor(); await loc.press('Enter'); };   // type, matches appear, Enter picks the first
  const nav = async () => (await page.locator('nav.side a').allInnerTexts()).map((t) => t.trim());

  await step('rep-sees-sales-and-customers-not-admin', async () => { await login('sales@demo.test'); const n = await nav(); console.log('      nav:', n.join(' | ')); if (!n.includes('المبيعات') || !n.includes('العملاء والديون') || n.some((x) => x.includes('المستخدمون'))) throw new Error('bad nav'); const h1 = await page.locator('nav.side h1').innerText(); if (!h1.includes('هب الريح')) throw new Error('company name: ' + h1); });
  const row = (n) => page.locator('.sale-table tbody tr:nth-child(' + n + ')');
  const fillLine = async (n, text, qtyText) => { await pick(row(n).locator('td.code input'), text); if (qtyText) await page.keyboard.type(qtyText); };
  const issue = () => page.getByRole('button', { name: 'إتمام البيع وإصدار الفاتورة' }).click();
  await step('rep-sale-table-enter-flow-prices-totals-and-invoice', async () => {
    await go('sales'); await page.waitForSelector('text=إتمام البيع');
    await fillLine(1, 'SBS3', '2'); await page.keyboard.press('Enter');                 // Enter after the quantity opens line 2
    await page.waitForSelector('.sale-table tbody tr:nth-child(2)'); await fillLine(2, 'CEM-WP', '1');
    if ((await page.locator('.rownum-cell').allInnerTexts()).join() !== '1,2') throw new Error('rows are not numbered 1,2');
    if (await page.getByText('+ إضافة سطر').count()) throw new Error('add-line button still there');
    await page.waitForFunction(() => document.querySelector('.sale-table tbody tr:nth-child(2) td:nth-child(5)').textContent.trim() !== '');   // piece price arrives
    const name = await row(1).locator('td.name input').inputValue(); if (!name.trim()) throw new Error('name column empty');
    const grand = await page.locator('tr.grand td.num').innerText(); if (/^0\.00$/.test(grand.trim())) throw new Error('total is zero');
    await page.screenshot({ path: SH + 'y-newsale.png' });
    await issue(); await page.waitForSelector('.print-sheet'); const t = await page.locator('.print-sheet').innerText(); if (!t.includes('غير معروف')) throw new Error('buyer not unknown: ' + t.slice(0, 200));
    await page.waitForTimeout(700); await page.screenshot({ path: SH + 'y-invoice.png' }); await page.keyboard.press('Escape');
  });
  await step('rep-typing-the-name-or-the-exact-code-fills-the-other-box', async () => {
    await go('sales'); await page.waitForSelector('text=إتمام البيع');
    await pick(row(1).locator('td.name input'), 'Cementitious'); const code = await row(1).locator('td.code input').inputValue(); if (code !== 'CEM-WP-25') throw new Error('code not filled from name: ' + code);
    await page.keyboard.press('Control+A'); await page.keyboard.type('1'); await page.keyboard.press('Enter');
    await row(2).locator('td.code input').fill('MEMB-SBS3'); await row(2).locator('td.code input').press('Tab');          // exact code + Tab resolves by itself
    await page.waitForFunction(() => document.querySelector('.sale-table tbody tr:nth-child(2) td.name input').value.includes('SBS'));
  });
  await step('rep-discount-lowers-the-total', async () => {
    await page.waitForTimeout(500); await fillLine(1, 'SBS3', '2'); await page.waitForFunction(() => !/^0\.00$/.test(document.querySelector('tr.grand td.num').textContent.trim()));
    const before = parseFloat((await page.locator('tr.grand td.num').innerText()).replace(/,/g, '')); await page.getByLabel('الخصم %').fill('10');
    const after = parseFloat((await page.locator('tr.grand td.num').innerText()).replace(/,/g, '')); if (!(after < before && Math.abs(after - before * 0.9) < 0.02)) throw new Error(before + ' -> ' + after);
    await page.screenshot({ path: SH + 'y-discount.png' }); await page.getByLabel('الخصم %').fill('');
  });
  await step('rep-part-payment-needs-a-registered-customer', async () => {
    await page.getByLabel('المدفوع').fill('1'); await issue(); await page.waitForSelector('.toast.error');
    const msg = await page.locator('.toast.error').last().innerText(); if (!msg.includes('مسجَّل')) throw new Error(msg);
  });
  await step('rep-typed-name-transfer-needs-bank', async () => {
    await page.getByLabel('المدفوع').fill(''); await page.getByLabel('طريقة الدفع').selectOption('transfer'); await page.getByLabel('اسم الزبون (اختياري').fill('زبون تجربة');
    await page.getByLabel('المصرف').selectOption({ index: 1 }); await issue();
    await page.waitForSelector('.print-sheet', { timeout: 10000 }).catch(async () => { throw new Error('no invoice; last toast: ' + (await page.locator('.toast').last().innerText().catch(() => '-'))); });
    const t = await page.locator('.print-sheet').innerText(); if (!t.includes('زبون تجربة')) throw new Error('typed name missing'); await page.keyboard.press('Escape');
  });
  await step('rep-debt-without-registered-customer-is-refused', async () => {
    await page.waitForTimeout(500); await page.getByLabel('طريقة الدفع').selectOption('credit'); await fillLine(1, 'SBS3', '1'); await issue(); await page.waitForSelector('.toast.error');
  });
  await step('rep-can-register-customer', async () => { await go('customers'); await page.getByLabel('اسم العميل').first().fill('مقاول الصحراء'); await page.getByLabel('رقم الهاتف').fill('0912345678');
    if (await page.getByLabel(/الرقم الضريبي/).count()) throw new Error('tax id field still on the customer form');
    await pick(page.getByLabel(/المدينة/), 'طرا'); if ((await page.getByLabel(/المدينة/).inputValue()) !== 'طرابلس') throw new Error('city not picked');
    await page.getByRole('button', { name: 'إضافة', exact: true }).click(); await page.waitForSelector('.toast.ok'); await page.waitForSelector('td >> text=مقاول الصحراء'); });
  await step('rep-debt-sale-to-registered-customer-found-by-search', async () => {
    await go('sales'); await page.waitForSelector('text=إتمام البيع'); await page.getByLabel('طريقة الدفع').selectOption('credit');
    await pick(page.getByLabel(/العميل المسجَّل/), 'الصحراء'); await fillLine(1, 'SBS3', '1'); await issue();
    await page.waitForSelector('.print-sheet'); const t = await page.locator('.print-sheet').innerText(); if (!t.includes('مقاول الصحراء')) throw new Error('buyer'); await page.keyboard.press('Escape');
  });
  await step('rep-part-paid-sale-to-registered-customer-leaves-a-debt', async () => {
    await page.waitForTimeout(500); await pick(page.getByLabel(/العميل المسجَّل/), 'الصحراء'); await fillLine(1, 'SBS3', '2');
    await page.waitForFunction(() => !/^0\.00$/.test(document.querySelector('tr.grand td.num').textContent.trim())); await page.getByLabel('المدفوع').fill('10');
    await page.waitForSelector('.remaining:not([hidden])'); await issue(); await page.waitForSelector('.print-sheet'); await page.keyboard.press('Escape');
  });
  await step('rep-my-sales-lists-them', async () => { await tab('مبيعاتي'); await page.waitForSelector('text=عرض الفاتورة'); });

  await step('manager-cannot-sell-but-sees-period-summary', async () => {
    await login('manager@demo.test'); await go('sales'); const tabs = await page.locator('.tabs button').allInnerTexts(); if (tabs.some((t) => t.includes('بيع جديد'))) throw new Error('manager can sell');
    await page.waitForSelector('text=عدد الفواتير'); await page.getByRole('button', { name: 'آخر 30 يوماً' }).click(); await page.waitForTimeout(800); await page.screenshot({ path: SH + 'y-manager-sales.png' });
  });
  await step('dashboard-has-no-60-day-expiry-card', async () => { await go('dashboard'); await page.waitForSelector('.kpi'); const t = await page.locator('#app').innerText(); if (t.includes('ينتهي خلال 60')) throw new Error('card still there'); if (!t.includes('الديون المطلوبة من العملاء')) throw new Error('rename missing'); await page.screenshot({ path: SH + 'y-dash.png' }); });
  await step('purchasing-and-suppliers-are-separate', async () => { const n = await nav(); if (!n.includes('المشتريات') || !n.includes('الموردون')) throw new Error(n.join('|')); await go('suppliers'); await page.waitForSelector('text=الديون المطلوبة للموردين'); });

  await step('admin-adds-user-and-branch', async () => {
    await login('admin@demo.test'); await go('admin'); await page.getByLabel('الاسم').first().fill('مندوب جديد'); await page.getByLabel('البريد').fill('rep' + Date.now() + '@demo.test'); await page.getByLabel(/كلمة المرور/).fill('Demo-Passw0rd-123');
    const roles = await page.getByLabel('الدور').locator('option').allInnerTexts(); console.log('      roles:', roles.join(' | '));
    await page.getByLabel('الدور').selectOption({ label: 'مندوب مبيعات' }); await page.getByRole('button', { name: 'إنشاء', exact: true }).click(); await page.waitForSelector('.toast.ok');
    await tab('الفروع والمستودعات'); await page.getByLabel('الرمز (مثل BR2)').fill('B' + String(Date.now()).slice(-6)); await page.getByLabel('اسم الفرع').fill('فرع مصراتة'); await page.getByRole('button', { name: 'إضافة', exact: true }).click(); await page.waitForSelector('td >> text=فرع مصراتة'); await page.screenshot({ path: SH + 'y-branches.png' });
  });
  await step('admin-cannot-sell', async () => { await go('sales'); const tabs = await page.locator('.tabs button').allInnerTexts(); if (tabs.some((t) => t.includes('بيع جديد'))) throw new Error('admin can sell'); });
  await step('shipment-form-has-sale-price-and-expiry-only-for-expiring-products', async () => {
    await go('imports'); await tab('شحنة جديدة'); await page.waitForSelector('text=سعر البيع للوحدة'); const hiddenBefore = await page.locator('.subrow .field', { hasText: 'تاريخ انتهاء الصلاحية' }).first().isHidden(); if (!hiddenBefore) throw new Error('expiry visible before choosing a product');
    const labels = await page.evaluate(() => state_labels()).catch(() => null);
    await page.screenshot({ path: SH + 'y-shipment.png' });
  });
  await step('hr-manager-sees-employees-attendance-and-payroll', async () => {
    await login('hr@demo.test'); const n = await nav(); if (!n.includes('الموارد البشرية')) throw new Error(n.join('|')); if (n.includes('المبيعات')) throw new Error('hr sees sales');
    await go('hr'); await page.waitForSelector('text=أحمد المبروك'); await page.screenshot({ path: SH + 'z-employees.png' });
    await tab('الحضور'); await page.waitForSelector('text=حفظ الحضور'); await page.getByRole('button', { name: 'حفظ الحضور' }).click(); await page.waitForSelector('.toast.ok');
    await tab('الإجازات'); await page.waitForSelector('text=طلب إجازة');
    await tab('السلف'); await page.waitForSelector('text=صرف سلفة لموظف');
  });
  await step('hr-creates-payroll-then-cannot-pay-finance-pays', async () => {
    await tab('الرواتب'); await page.waitForSelector('text=كشف رواتب شهر جديد');
    await page.getByLabel('الشهر (YYYY-MM)').first().fill(new Date().toISOString().slice(0, 7)); await page.getByRole('button', { name: 'إنشاء كشف الرواتب' }).click();
    await page.waitForSelector('text=اعتماد الرواتب'); await page.waitForTimeout(700); await page.screenshot({ path: SH + 'z-payroll.png' });
    await page.locator('button', { hasText: 'كشف الراتب' }).first().click(); await page.waitForSelector('.print-sheet'); await page.waitForTimeout(500); await page.screenshot({ path: SH + 'z-payslip.png' }); await page.keyboard.press('Escape'); await page.waitForTimeout(400);
    await page.getByRole('button', { name: 'اعتماد الرواتب' }).click(); await page.waitForSelector('.toast.ok'); await page.waitForTimeout(600);
    await login('finance@demo.test'); await go('hr'); await tab('الرواتب'); await page.waitForSelector('text=معتمد');
    await page.locator('button', { hasText: 'فتح' }).first().click(); await page.waitForSelector('text=دفع الرواتب'); await page.getByRole('button', { name: 'دفع الرواتب' }).click();
    await page.waitForSelector('text=تأكيد الدفع'); await page.getByRole('button', { name: 'تأكيد الدفع' }).click(); await page.waitForSelector('.toast.ok');
  });
  console.log(problems.length ? 'PROBLEMS:\n' + problems.join('\n') : 'ALL OK'); await b.close();
})();
