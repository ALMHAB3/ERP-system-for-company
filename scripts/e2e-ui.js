// End-to-end smoke test of the web UI against a running instance with a fresh database seeded with the admin user, 2 warehouses,
// products RICE-5 / OIL-1, supplier "Shenzhen Ltd", customer "Al Noor Trading" and a RICE-5 wholesale price of 80 (see docs/LOCAL-DEPLOYMENT.md).
// Needs: npm i playwright. Usage: BASE_URL=https://erp.local node scripts/e2e-ui.js
const { chromium } = require('playwright');
const SH = (process.env.SHOTS_DIR || '/tmp/erp-shots') + '/';
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
  const problems = [];
  page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
  page.on('dialog', (d) => d.accept());
  const step = async (name, fn) => { try { await fn(); console.log('ok   ', name); } catch (e) { console.log('FAIL ', name, '-', e.message.split('\n')[0]); problems.push(name); await page.screenshot({ path: SH + 'fail-' + name.replace(/\W+/g, '_') + '.png' }); } };
  const tab = (t) => page.locator('.tabs button', { hasText: t }).first().click();
  const toastText = async () => (await page.locator('.toast').last().textContent());
  const go = async (id) => { await page.evaluate((i) => (location.hash = '#/' + i), id); await page.waitForTimeout(500); };

  await step('login', async () => {
    await page.goto((process.env.BASE_URL || 'http://127.0.0.1:8000') + '/');
    await page.getByLabel('البريد الإلكتروني').fill('admin@example.com');
    await page.getByLabel('كلمة المرور').fill('Admin-Passw0rd-1');
    await page.getByRole('button', { name: 'دخول' }).click();
    await page.waitForSelector('nav.side');
  });
  await step('bad-login-shows-error', async () => {
    await page.evaluate(() => sessionStorage.clear()); await page.reload();
    await page.getByLabel('البريد الإلكتروني').fill('admin@example.com'); await page.getByLabel('كلمة المرور').fill('wrong');
    await page.getByRole('button', { name: 'دخول' }).click(); await page.waitForSelector('.toast.error');
    await page.getByLabel('كلمة المرور').fill('Admin-Passw0rd-1'); await page.getByRole('button', { name: 'دخول' }).click(); await page.waitForSelector('nav.side');
  });
  await step('set-exchange-rates-in-ui', async () => {
    await go('finance'); await tab('أسعار الصرف');
    for (const [c, r] of [['USD', '4.85'], ['EUR', '5.3'], ['CNY', '0.6781']]) {
      await page.getByLabel('العملة').selectOption(c); await page.getByLabel(/السعر مقابل/).fill(r); await page.getByRole('button', { name: 'تسجيل السعر' }).click();
      await page.waitForFunction((x) => document.body.innerText.includes('= ' + x), r);
    }
    await page.screenshot({ path: SH + '0-rates.png' });
    await page.reload(); await page.waitForSelector('nav.side');
  });

  await step('create-shipment', async () => {
    await go('imports'); await tab('شحنة جديدة');
    await page.getByLabel('مرجع الشحنة / رقم الحاوية').fill('CONT-UI');
    if ((await page.getByLabel('سعر الصرف إلى الدينار الليبي').inputValue()) !== '4.8500') throw new Error('rate not prefilled from the stored USD rate');
    await page.getByLabel('العملة').selectOption('EUR'); if ((await page.getByLabel('سعر الصرف إلى الدينار الليبي').inputValue()) !== '5.3000') throw new Error('rate did not follow the currency');
    await page.getByLabel('العملة').selectOption('USD');
    const line = page.locator('.subrow').first();
    await line.getByLabel('الصنف').fill('RICE-5 — Rice 5kg');
    await line.getByLabel('الكمية').fill('1000'); await line.getByLabel('سعر الوحدة').fill('10');
    await line.getByLabel('الحجم CBM').fill('10'); await line.getByLabel('الوزن كجم').fill('2000'); await line.getByLabel('الرسم الجمركي %').fill('5');
    await line.getByLabel('الانتهاء').fill('2027-12-31'); await line.getByLabel('رقم دفعة المورد').fill('BATCH-A1');
    await page.getByRole('button', { name: '+ إضافة سطر' }).nth(1).click();
    const c1 = page.locator('.subrow').nth(1);
    await c1.getByLabel(/البند/).fill('FREIGHT'); await c1.getByLabel('المبلغ').fill('1000'); await c1.getByLabel('التوزيع').selectOption('volume'); await c1.getByLabel('ضمن القيمة الجمركية').check();
    await page.getByRole('button', { name: '+ إضافة سطر' }).nth(1).click();
    const c2 = page.locator('.subrow').nth(2);
    await c2.getByLabel(/البند/).fill('INSURANCE'); await c2.getByLabel('المبلغ').fill('100'); await c2.getByLabel('التوزيع').selectOption('value'); await c2.getByLabel('ضمن القيمة الجمركية').check();
    await page.getByRole('button', { name: '+ إضافة سطر' }).nth(1).click();
    const c3 = page.locator('.subrow').nth(3);
    await c3.getByLabel(/البند/).fill('CLEARANCE'); await c3.getByLabel('المبلغ').fill('600'); await c3.getByLabel('التوزيع').selectOption('weight');
    await page.getByRole('button', { name: 'إنشاء الشحنة' }).click();
    await page.waitForSelector('.toast.ok');
  });
  await step('cost-and-receive-shipment', async () => {
    await tab('الشحنات'); await page.getByRole('button', { name: 'احتساب التكلفة الفعلية' }).click();
    await page.waitForFunction(() => document.body.innerText.includes('تم احتساب التكلفة'));
    await page.getByRole('button', { name: 'استلام في المستودع' }).click();
    await page.locator('.modal select').selectOption({ label: 'MAIN — Main warehouse' });
    await page.getByRole('button', { name: 'تأكيد الاستلام' }).click(); await page.waitForFunction(() => document.body.innerText.includes('مستلمة'));
    await page.screenshot({ path: SH + '2-shipments.png' });
  });
  await step('shipment-details-show-landed-cost', async () => {
    await page.getByRole('button', { name: 'عرض' }).first().click();
    await page.waitForFunction(() => document.querySelector('.modal')?.innerText.includes('52,680.00'));
    await page.screenshot({ path: SH + '3-shipment-detail.png' }); await page.locator('.modal .btn.secondary').last().click();
  });
  await step('inventory-balances-lots', async () => {
    await go('inventory'); await page.waitForFunction(() => document.body.innerText.includes('RICE-5'));
    await page.waitForFunction(() => document.body.innerText.includes('52.68'));
    await tab('الدفعات والصلاحية'); await page.waitForFunction(() => document.body.innerText.includes('BATCH-A1')); await page.screenshot({ path: SH + '4-lots.png' });
  });
  await step('sales-order-ship-invoice', async () => {
    await go('sales'); await tab('طلب بيع جديد');
    await page.getByLabel('العميل').selectOption({ label: 'Al Noor Trading' }); await page.getByLabel('المستودع').selectOption({ label: 'MAIN — Main warehouse' });
    const l = page.locator('.subrow').first(); await l.getByLabel('الصنف').fill('RICE-5 — Rice 5kg'); await l.getByLabel('الكمية').fill('100');
    await page.getByRole('button', { name: 'إنشاء الطلب وحجز المخزون' }).click(); await page.waitForSelector('.toast.ok');
    await tab('طلبات البيع'); await page.getByRole('button', { name: 'شحن', exact: true }).click(); await page.waitForFunction(() => document.body.innerText.includes('تم الشحن'));
    await page.getByRole('button', { name: 'إصدار فاتورة' }).click(); await page.waitForFunction(() => document.body.innerText.includes('مفوتر'));
    await page.screenshot({ path: SH + '5-sales.png' });
  });
  await step('receipt-collection', async () => {
    await tab('الفواتير والتحصيل');
    await page.getByLabel('تحصيل من عميل').selectOption({ label: 'Al Noor Trading' }); await page.getByRole('button', { name: 'عرض فواتيره المفتوحة' }).click();
    await page.getByRole('button', { name: 'كامل المتبقي' }).click(); await page.getByLabel('المرجع').last().fill('TRX-UI-1');
    await page.getByRole('button', { name: 'تسجيل التحصيل' }).click(); await page.waitForFunction(() => document.body.innerText.includes('تم تسجيل التحصيل'));
  });
  await step('supplier-bill-and-payment', async () => {
    await go('purchasing'); await tab('فواتير الموردين');
    await page.getByLabel('المورد').first().selectOption({ label: 'Shenzhen Ltd' }); await page.getByLabel('رقم فاتورة المورد').fill('SZ-100');
    await page.getByLabel('المبلغ قبل الضريبة').fill('10000');
    await page.getByLabel('الشحنة / أمر الشراء المرتبط').selectOption({ label: 'شحنة CONT-UI' });
    await page.getByRole('button', { name: 'تسجيل الفاتورة' }).click(); await page.waitForFunction(() => document.body.innerText.includes('SZ-100'));
    await tab('دفع للموردين'); await page.getByLabel('المورد').selectOption({ label: 'Shenzhen Ltd' }); await page.getByRole('button', { name: 'عرض فواتيره المفتوحة' }).click();
    await page.getByRole('button', { name: 'كامل المتبقي' }).click(); await page.getByLabel('مرجع الدفع').fill('TT-UI-1');
    await page.getByRole('button', { name: 'تنفيذ الدفع' }).click(); await page.waitForFunction(() => document.body.innerText.includes('تم الدفع'));
    await tab('مطابقة المستلم'); await page.getByLabel(/المرجع، مثال/).fill('shipment:CONT-UI'); await page.getByRole('button', { name: 'عرض الحالة' }).click();
    await page.waitForFunction(() => document.body.innerText.includes('المتبقي بدون فاتورة')); await page.screenshot({ path: SH + '6-grir.png' });
  });
  await step('trial-balance-balanced', async () => {
    await go('finance'); await page.waitForFunction(() => document.body.innerText.includes('الميزان متوازن')); await page.screenshot({ path: SH + '7-trial.png' });
  });
  await step('reports', async () => {
    await go('reports'); await page.waitForSelector('.tabs');
    for (const t of ['التنبؤ بالطلب', 'ربحية الشحنات', 'ربحية الأصناف', 'الصلاحية', 'المخزون الراكد']) { await tab(t); await page.waitForTimeout(400); const bad = await page.locator('.card.neg').count(); if (bad) throw new Error('error card in ' + t); }
    await tab('ربحية الشحنات'); await page.waitForFunction(() => document.body.innerText.includes('CONT-UI')); await page.screenshot({ path: SH + '8-shipment-profit.png' });
  });
  await step('admin-users-audit', async () => {
    await go('admin'); await page.waitForSelector('table'); await tab('سجل التدقيق'); await page.waitForFunction(() => document.body.innerText.includes('http.post'));
    await page.getByRole('button', { name: 'التحقق من سلامة السجل' }).click(); await page.waitForFunction(() => document.body.innerText.includes('السلسلة سليمة')); await page.screenshot({ path: SH + '9-audit.png' });
  });
  await step('xss-text-is-escaped', async () => {
    await go('inventory'); await tab('الأصناف والمستودعات');
    await page.getByLabel('رمز الصنف (SKU)').fill('XSS-1'); await page.getByLabel('الاسم').first().fill('<img src=x onerror=window.__pwned=1>');
    await page.getByRole('button', { name: 'إضافة' }).first().click(); await page.waitForSelector('.toast.ok');
    await page.reload(); await page.waitForSelector('nav.side'); await go('inventory'); await tab('الأصناف والمستودعات'); await page.waitForTimeout(500);
    if (await page.evaluate(() => window.__pwned)) throw new Error('XSS executed');
  });
  await step('dashboard-charts', async () => {
    await go('dashboard'); await page.waitForSelector('.kpi'); await page.waitForSelector('.chartcard svg');
    const n = await page.locator('.chartcard svg').count(); if (n < 5) throw new Error('expected charts, found ' + n);
    await page.screenshot({ path: SH + '1-dashboard.png', fullPage: true });
  });
  await step('reports-charts', async () => {
    await go('reports'); await tab('ربحية الشحنات'); await page.waitForSelector('.chartcard svg'); await page.screenshot({ path: SH + '8b-shipment-profit.png', fullPage: true });
    await tab('التنبؤ بالطلب'); await page.getByLabel('الصنف').fill('RICE-5 — Rice 5kg'); await page.getByRole('button', { name: 'احسب' }).click(); await page.waitForSelector('.chartcard'); await page.screenshot({ path: SH + '8c-forecast.png' });
  });
  await step('csv-export', async () => {
    await go('finance'); await tab('ميزان المراجعة'); const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /تصدير Excel/ }).click()]);
    if (!/trial-balance.*\.csv$/.test(dl.suggestedFilename())) throw new Error('bad csv name ' + dl.suggestedFilename());
  });
  await step('phone-width-layout', async () => { await page.setViewportSize({ width: 390, height: 800 }); await go('dashboard'); await page.waitForSelector('.kpi'); await page.screenshot({ path: SH + '10-phone.png' }); });
  console.log(problems.length ? 'PROBLEMS:\n' + problems.join('\n') : 'ALL UI STEPS PASSED, no console errors');
  await browser.close();
})();
