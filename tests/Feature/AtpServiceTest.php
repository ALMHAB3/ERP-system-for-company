<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Inventory\Application\AtpService;
use Modules\Inventory\Application\InventoryLedger;
use Modules\Inventory\Application\LedgerReconciler;
use Modules\Inventory\Domain\Exceptions\InsufficientStock;
use Modules\Inventory\Domain\Exceptions\ReservationNotActive;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Shared\Domain\Fixed;

final class AtpServiceTest extends ErpTestCase
{
    private function stock(string $qty = '10'): void
    {
        app(InventoryLedger::class)->record(new StockMoveData($this->product, $this->warehouse, MoveType::Receipt, Fixed::parse($qty), Fixed::parse('5'), 'seed'));
    }

    private function atp(): AtpService
    {
        return app(AtpService::class);
    }

    public function test_reservations_reduce_available_to_promise_but_not_on_hand(): void
    {
        $this->stock('10');
        $this->atp()->reserve($this->product, $this->warehouse, Fixed::parse('6'), 'so-1');

        $this->assertSame('4.0000', (string) $this->atp()->availableToPromise($this->product, $this->warehouse));
        $this->assertSame('10.0000', (string) app(StockLedgerRepository::class)->balance($this->product, $this->warehouse)->onHand);
    }

    public function test_the_last_units_cannot_be_promised_twice(): void
    {
        $this->stock('10');
        $this->atp()->reserve($this->product, $this->warehouse, Fixed::parse('7'), 'so-1');

        $this->expectException(InsufficientStock::class);
        $this->atp()->reserve($this->product, $this->warehouse, Fixed::parse('4'), 'so-2');
    }

    public function test_release_returns_stock_to_available(): void
    {
        $this->stock('10');
        $r = $this->atp()->reserve($this->product, $this->warehouse, Fixed::parse('10'), 'so-1');
        $this->atp()->release($r->id);

        $this->assertSame('10.0000', (string) $this->atp()->availableToPromise($this->product, $this->warehouse));
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());
    }

    public function test_a_reservation_cannot_be_released_or_consumed_twice(): void
    {
        $this->stock('10');
        $r = $this->atp()->reserve($this->product, $this->warehouse, Fixed::parse('3'), 'so-1');
        $this->atp()->consume($r->id, 'so-1', 'so-1:ship');

        $this->expectException(ReservationNotActive::class);
        $this->atp()->release($r->id);
    }

    public function test_consume_issues_stock_at_average_cost_and_clears_the_reservation(): void
    {
        $this->stock('10');
        $r = $this->atp()->reserve($this->product, $this->warehouse, Fixed::parse('4'), 'so-1');
        $issued = $this->atp()->consume($r->id, 'so-1', 'so-1:ship');

        $this->assertSame('20.0000', (string) $issued->totalCost());
        $balance = app(StockLedgerRepository::class)->balance($this->product, $this->warehouse);
        $this->assertSame('6.0000', (string) $balance->onHand);
        $this->assertSame('0.0000', (string) $balance->reserved);
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());
    }

    public function test_expired_reservations_are_released(): void
    {
        $this->stock('10');
        $this->atp()->reserve($this->product, $this->warehouse, Fixed::parse('5'), 'cart', new \DateTimeImmutable('-1 minute'));
        $this->atp()->reserve($this->product, $this->warehouse, Fixed::parse('2'), 'cart2', new \DateTimeImmutable('+1 hour'));

        $this->assertSame(1, $this->atp()->releaseExpired(new \DateTimeImmutable));
        $this->assertSame('8.0000', (string) $this->atp()->availableToPromise($this->product, $this->warehouse));
    }
}
