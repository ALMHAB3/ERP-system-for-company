<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exceptions;

use DomainException;

final class PricingApprovalRequired extends DomainException {}
