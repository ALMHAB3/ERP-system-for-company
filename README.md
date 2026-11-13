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
| `Identity` | المصادقة (Sanctum tokens بصلاحية 8 ساعات)، RBAC بالأدوار والصلاحيات، نطاق المستودعات | Shared |
| `Audit` | سجل تدقيق متسلسل بالهاش (append-only)، سياق الطلب، التحقق من السلسلة | يستمع لأحداث الوحدات |
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

## الأمان والصلاحيات

- **المصادقة**: `POST /api/auth/token` (email, password, device_name) يعيد Bearer token ينتهي بعد `SANCTUM_TOKEN_TTL_MINUTES`.
  محدودة بـ 5 محاولات/دقيقة لكل بريد+IP، ونفس الرد للبريد غير الموجود أو كلمة المرور الخاطئة أو الحساب الموقوف.
- **RBAC**: كل مسار محمي بـ `permission:<name>` (القائمة في `Identity/Domain/Permission.php`). الأدوار الافتراضية:

| الدور | مستوى الاعتماد | أبرز الصلاحيات |
|---|---|---|
| `admin` | 3 | كل شيء بما فيه إدارة المستخدمين |
| `general_manager` | 3 | كل شيء عدا إدارة المستخدمين، والبيع تحت الهامش |
| `finance_manager` | 2 | التكلفة الفعلية، الفوترة، ميزان المراجعة، سجل التدقيق، رؤية التكاليف |
| `purchasing_manager` | 1 | أوامر الشراء، الشحنات، البيانات الأساسية |
| `warehouse_clerk` | 0 | التسويات، التحويل، الاستلام، الشحن — **في مستودعاته فقط** |
| `sales_rep` | 0 | طلبات البيع والإلغاء — **في مستودعاته فقط**، بدون رؤية التكلفة |

- **فصل المهام**: منشئ أمر الشراء لا يعتمده، ومستوى المعتمد يجب أن يغطي قيمة الأمر.
- **سجل التدقيق**: كل طلب كتابة (بما فيه المرفوض 403/422) وكل حدث تشغيلي يُسجَّل مع المستخدم والـIP و`X-Request-Id`.
  كلمات المرور محجوبة. السجلات غير قابلة للتعديل (Triggers) ومتسلسلة بـ SHA-256؛ `php artisan audit:verify` أو `GET /api/audit-logs/verify` يكشف أي تلاعب.
- **Idempotency-Key** header على التسويات المخزنية يمنع التكرار عند إعادة المحاولة.
- أخطاء الأعمال تعود 422 برسالة واضحة، نقص المخزون 409 (`insufficient_stock`)، الصلاحيات 403.

## مسارات الـ API

`php artisan route:list --path=api` — المجموعات: `auth`, `me`, `admin/users`, `products|warehouses|customers|prices`,
`inventory/{balances,atp,adjustments,transfers}`, `shipments`, `purchase-orders`, `sales-orders`, `finance/trial-balance`, `audit-logs`.

## التشغيل

**التشغيل الفعلي داخل الشركة:** راجع [`docs/LOCAL-DEPLOYMENT.md`](docs/LOCAL-DEPLOYMENT.md) (سيرفر محلي، HTTPS داخلي، نسخ احتياطي يومي واختبار استرجاع).

للتطوير:

```bash
composer install
cp .env.example .env && php artisan key:generate
docker compose up -d db redis          # أو أي PostgreSQL محلي
php artisan migrate
php artisan erp:create-admin admin@example.com   # أول مدير (كلمة المرور تُطلب بشكل مخفي)
vendor/bin/phpunit                     # sqlite افتراضياً؛ أو على PostgreSQL بضبط DB_*
./scripts/atp-concurrency-check.sh     # 40 عملية متوازية على PostgreSQL حقيقي
docker compose up --build              # التطبيق على http://localhost:8080
```

## ما هو خارج هذه النسخة (مخطط له في الوثيقة)

فواتير الموردين ومطابقة 3-way وتسوية GR/IR، تحصيلات العملاء والذمم، العملات المتعددة في القيود،
الدفعات/الصلاحية/السيريال في الأرصدة (العمود `lot_no` مسجَّل في الحركات فقط)، التنبؤ بالطلب ولوحات BI، تكامل شركات الشحن.
