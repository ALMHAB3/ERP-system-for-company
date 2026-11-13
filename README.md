# Import ERP — نظام ERP لشركات الاستيراد والتوزيع

PHP 8.3 + Laravel 13 · **Modular Monolith** · PostgreSQL 16

## الوحدات (`src/Modules`)

| الوحدة | المسؤولية | يعتمد على |
|---|---|---|
| `Shared` | `Fixed` (أرقام عشرية دقيقة 4 خانات بلا floats)، `EventBus`، `TransactionRunner` | — |
| `Inventory` | دفتر حركات المخزون (append-only)، الأرصدة المشتقة، ATP وحجز الصفوف، المطابقة | Shared |
| `Import` | الشحنات، **Landed Cost**، استلام الشحنة في المخزون | Shared (+ Inventory عبر Port) |
| `Procurement` | أوامر الشراء، سلّم الاعتماد، استلام محلي | Shared (+ Inventory عبر Port) |
| `Sales` | العملاء، محرك التسعير، طلبات البيع، الشحن، الفوترة | Shared (+ Inventory عبر Port) |
| `Finance` | دليل الحسابات، قيود مزدوجة غير قابلة للتعديل، قواعد ترحيل تلقائية | تستمع لأحداث الوحدات الأخرى |

كل وحدة: `Domain/` (كيانات + واجهات) · `Application/` (خدمات) · `Infrastructure/` (Repositories وAdapters) · `Database/migrations/`.
الوحدات لا تستدعي بعضها مباشرة: التواصل عبر **Ports** (واجهات في Domain) و**Domain Events**.

## أنماط التصميم المطبقة

- **Repository**: `StockLedgerRepository`, `ReservationRepository`, `ShipmentRepository`, `SalesOrderRepository`, `JournalRepository`… (الخدمات لا ترى SQL).
- **Strategy**: `AllocationStrategy` (ByValue/ByVolume/ByWeight/ByQuantity)، `PriceSource` و`DiscountRule`، `PostingRule`، `ApprovalPolicy`.
- **Factory**: `AllocationStrategyFactory` (حسب أساس التوزيع)، `PricingEngineFactory` (حسب فئة العميل).
- **Dependency Injection**: كل التبعيات بالواجهات عبر الـ constructor، والربط في `*ServiceProvider`.
- **Observer / Event-driven**: `StockMoved` و`InvoiceIssued` → `PostJournalOnDomainEvent` → قواعد الترحيل. القيد يُكتب داخل نفس معاملة العملية.
- **Ports & Adapters** بين الوحدات (`StockReceiver`, `StockAvailability`, `CostLookup`).

## ضمانات الدقة والسلامة

- **لا تعديل يدوي للأرصدة**: `InventoryLedger` هو الكاتب الوحيد؛ الرصيد = مجموع الحركات، ويُحدَّث بنفس المعاملة تحت قفل صف.
- جدول `stock_moves` وجداول القيود **ممنوعة من UPDATE/DELETE على مستوى قاعدة البيانات** (Triggers)، وقيد CHECK يمنع الرصيد السالب والحجز الأكبر من الرصيد (PostgreSQL).
- `php artisan inventory:reconcile` يعيد حساب كل شيء من الحركات ويكشف أي انحراف (مجدول يومياً 02:00).
- **Landed Cost**: توزيع بطريقة *Largest Remainder* بحيث مجموع البنود = إجمالي المصاريف حتى 1/10000، مع invariant يُفحص قبل الحفظ.
- **ATP**: `SELECT … FOR UPDATE` على صف الرصيد؛ ترتيب الأقفال ثابت (الحجز ثم الرصيد) لتفادي deadlock.
- كل ترحيل محاسبي idempotent بمفتاح `(source_type, source_id, rule)`، وكل حركة مخزنية بمفتاح idempotency اختياري.

## التشغيل

```bash
composer install
cp .env.example .env && php artisan key:generate
docker compose up -d db redis          # أو أي PostgreSQL محلي
php artisan migrate
vendor/bin/phpunit                     # sqlite افتراضياً؛ أو على PostgreSQL بضبط DB_*
./scripts/atp-concurrency-check.sh     # 40 عملية متوازية على PostgreSQL حقيقي
docker compose up --build              # التطبيق على http://localhost:8080
```

## ما هو خارج هذه النسخة (مخطط له في الوثيقة)

واجهة HTTP/API ومصادقتها، RBAC وAudit log، فواتير الموردين ومطابقة 3-way وتسوية GR/IR، تحصيلات العملاء والذمم، العملات المتعددة في القيود،
الدفعات/الصلاحية/السيريال في الأرصدة (العمود `lot_no` مسجَّل في الحركات فقط)، التنبؤ بالطلب ولوحات BI، تكامل شركات الشحن.
