#!/usr/bin/env bash
# Proves the ATP row lock on a REAL PostgreSQL: 40 parallel processes each try to reserve 1 unit
# of a product holding 10 units. Exactly 10 may succeed; ATP must end at 0 and the ledger must reconcile.
# Usage: DB_CONNECTION=pgsql DB_HOST=... DB_DATABASE=... ./scripts/atp-concurrency-check.sh
set -euo pipefail
cd "$(dirname "$0")/.."
WORKERS=${WORKERS:-40}; STOCK=${STOCK:-10}

php artisan migrate:fresh --force --quiet
php artisan tinker --execute="
\$p = DB::table('products')->insertGetId(['sku'=>'STRESS','name'=>'stress','created_at'=>now(),'updated_at'=>now()]);
\$w = DB::table('warehouses')->insertGetId(['code'=>'W','name'=>'W','created_at'=>now(),'updated_at'=>now()]);
app(Modules\Inventory\Application\InventoryLedger::class)->record(new Modules\Inventory\Domain\StockMoveData(\$p,\$w,Modules\Inventory\Domain\MoveType::Receipt,Modules\Shared\Domain\Fixed::of($STOCK),Modules\Shared\Domain\Fixed::of(5),'seed'));
" >/dev/null

seq "$WORKERS" | xargs -P "$WORKERS" -I{} php artisan tinker --execute="
try { app(Modules\Inventory\Application\AtpService::class)->reserve(1,1,Modules\Shared\Domain\Fixed::of(1),'race-{}'); echo 'OK'.PHP_EOL; }
catch (Modules\Inventory\Domain\Exceptions\InsufficientStock \$e) { echo 'REJECTED'.PHP_EOL; }
" > /tmp/atp_race.out 2>&1 || true

ok=$(grep -c '^OK' /tmp/atp_race.out || true); rejected=$(grep -c '^REJECTED' /tmp/atp_race.out || true)
echo "reserved=$ok rejected=$rejected (expected reserved=$STOCK)"
php artisan inventory:reconcile
[ "$ok" -eq "$STOCK" ] && echo "PASS: no overselling under concurrency" || { echo "FAIL"; exit 1; }
