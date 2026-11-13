<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain\Exceptions;

use DomainException;

final class InvalidPurchaseOrderTransition extends DomainException {}
