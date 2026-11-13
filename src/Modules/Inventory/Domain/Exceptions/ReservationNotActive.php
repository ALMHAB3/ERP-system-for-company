<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exceptions;

use DomainException;

final class ReservationNotActive extends DomainException {}
