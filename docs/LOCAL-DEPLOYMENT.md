# التشغيل المحلي داخل الشركة (On-Premises)

نظام واحد على **سيرفر داخل مقر الشركة**، تصل إليه الفروع والأجهزة عبر الشبكة الداخلية فقط (أو عبر VPN للفروع البعيدة). لا شيء يخرج إلى الإنترنت.

## 1. المتطلبات

| البند | الحد الأدنى | الموصى به |
|---|---|---|
| الجهاز | 4 أنوية، 8 GB RAM، SSD 256 GB | 8 أنوية، 16 GB RAM، SSD 512 GB RAID1 |
| النظام | Ubuntu Server 24.04 LTS + Docker Engine + Docker Compose | نفسه |
| طاقة | **UPS** إلزامي (انقطاع الكهرباء المفاجئ أخطر ما يهدد قاعدة البيانات) | UPS يكفي 15 دقيقة على الأقل |
| نسخ احتياطي | قرص خارجي ثانٍ | قرص خارجي + جهاز/موقع آخر |

## 2. التثبيت

```bash
git clone <مستودعك> /opt/erp && cd /opt/erp
cp .env.production.example .env
# عدّل: DB_PASSWORD (طويلة وعشوائية)، ERP_HOST (اسم النظام في شبكتك، مثل erp.company.local)، APP_URL=https://<ERP_HOST>
docker compose build
docker compose run --rm app php artisan key:generate --force   # ثم انسخ APP_KEY الناتج إلى .env إن لم يُكتب تلقائياً
docker compose up -d
docker compose run --rm app php artisan erp:create-admin admin@company.local   # كلمة المرور تُطلب بشكل مخفي
```

تحقق: `docker compose ps` (كل الخدمات `running`/`healthy`، و`migrate` منتهية) ثم افتح `https://<ERP_HOST>/up`.

### بعد أول دخول

1. من «المحاسبة ← أسعار الصرف» سجّل سعر الدولار واليورو واليوان مقابل الدينار الليبي (وحدّثها دورياً).
2. أضف المستودعات والأصناف والموردين والعملاء، ثم أنشئ المستخدمين بأدوارهم ومستودعاتهم.
3. اضبط `ERP_VAT_RATE` إن كان لديك ضريبة مبيعات (الافتراضي 0%).

## 3. الوصول من الشبكة

1. **الاسم**: أضف سجل DNS في الراوتر/السيرفر المحلي يشير `ERP_HOST` إلى عنوان السيرفر الثابت (أو ملف `hosts` لكل جهاز).
2. **شهادة HTTPS**: Caddy يصدر شهادة من جهة داخلية. انسخ شهادة الجذر مرة واحدة ثم ثبّتها كـ«Trusted Root» على أجهزة المستخدمين:
   ```bash
   docker compose cp web:/data/caddy/pki/authorities/local/root.crt ./caddy-root.crt
   ```
   بدونها سيعرض المتصفح تحذير شهادة. لا تتجاوز التحذير بل ثبّت الشهادة.
3. **الجدار الناري**: افتح 443 (و80 لإعادة التوجيه) للشبكة الداخلية فقط. **لا** تفتح 5432 ولا 6379 (غير منشورة أصلاً). لا تعرض السيرفر للإنترنت؛ للفروع البعيدة استخدم VPN (WireGuard/IPsec).
4. **تحديث النظام والحزم** دورياً: `sudo apt update && sudo apt upgrade` ثم إعادة تشغيل مجدولة.

## 4. النسخ الاحتياطي (الأهم)

- خدمة `backup` تأخذ نسخة كاملة كل ليلة الساعة `BACKUP_HOUR` إلى `BACKUP_PATH` (الافتراضي `./backups`) مع ملف `.sha256`.
- **اجعل `BACKUP_PATH` على قرص مختلف** عن قرص قاعدة البيانات، وانسخ المجلد إلى **جهاز آخر أو قرص خارجي يُخرج من المبنى** أسبوعياً على الأقل (قاعدة البيانات والنسخ على نفس القرص ليست نسخاً احتياطياً).
- السكربت **لا يحذف** النسخ القديمة. احذف ما يزيد عن 30 يوماً بنفسك (راجع الأمر قبل تنفيذه):
  ```bash
  find /opt/erp/backups -name 'erp-*.dump*' -mtime +30 -print    # اطبع أولاً وراجع القائمة
  ```
- **نسخة يدوية فورية**: `docker compose exec backup sh /backup.sh`

### اختبار الاسترجاع (شهرياً — نسخة لم تُجرَّب ليست نسخة)

```bash
export PGHOST=<عنوان قاعدة بيانات الاختبار> PGUSER=erp PGPASSWORD=<...>
./scripts/restore-check.sh backups/erp-AAAAMMDD-HHMMSS.dump
```
يتحقق من الـchecksum ثم يسترجع النسخة في قاعدة مؤقتة ويشغّل فحص المخزون وسلسلة التدقيق. النجاح يعني أن النسخة قابلة للاسترجاع وسليمة.

### الاسترجاع الفعلي بعد كارثة

```bash
docker compose stop app worker scheduler web
docker compose exec -T db pg_restore --clean --if-exists --no-owner -U erp -d erp < backups/erp-AAAAMMDD-HHMMSS.dump
docker compose up -d
docker compose run --rm app php artisan inventory:reconcile && docker compose run --rm app php artisan audit:verify
```
آخر نسخة ليلية تعني أن أقصى خسارة ممكنة هي عمل يوم واحد؛ إن لزم أقل من ذلك قلّل الفاصل أو فعّل أرشفة WAL.

## 5. التحديث إلى إصدار جديد

أمر واحد يأخذ نسخة احتياطية ثم يحمّل التحديث ويبنيه ويطبّق تعديلات قاعدة البيانات ويعيد التشغيل:
```bash
./scripts/update.sh                                              # لينكس / ماك
```
```powershell
powershell -ExecutionPolicy Bypass -File scripts\update.ps1      # ويندوز (أو انقر مرتين على launcher\Update-ERP.bat)
```

## 6. المراقبة اليومية

- `docker compose ps` و`docker compose logs --tail=100 app`.
- فحص مجدول تلقائياً: `inventory:reconcile` (02:00) و`audit:verify` (02:15) — أي فشل يظهر في السجلات (`LOG_LEVEL=warning`). اربطهما بإشعار بريدي/رسالة قبل الاعتماد الكامل.
- مساحة القرص: `df -h` أسبوعياً.

## 7. ما لا تفعله

- لا تعدّل جداول قاعدة البيانات يدوياً (الحركات والقيود والتدقيق محمية بـTriggers وستفشل العملية).
- لا تشغّل `migrate:fresh` ولا `docker compose down -v` على نظام فيه بيانات (يمسح كل شيء).
- لا تترك `APP_DEBUG=true` ولا كلمات المرور الافتراضية.
