<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Domain\CustomerCategory;
use Modules\Sales\Domain\Pricing\PriceRepository;
use Modules\Shared\Domain\Fixed;

final class DbPriceRepository implements PriceRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function contractPrice(int $customerId, int $productId): ?Fixed
    {
        $p = $this->db->table('contract_prices')->where('customer_id', $customerId)->where('product_id', $productId)->value('price');

        return $p === null ? null : Fixed::units((int) $p);
    }

    public function listPrice(int $productId, CustomerCategory $category): ?Fixed
    {
        $rows = $this->db->table('price_list_items')->where('product_id', $productId)
            ->where(fn ($q) => $q->where('category', $category->value)->orWhereNull('category'))
            ->get(['category', 'price']);
        if ($rows->isEmpty()) {
            return null;
        }
        $specific = $rows->firstWhere('category', $category->value);

        return Fixed::units((int) ($specific ?? $rows->first())->price);
    }
}
