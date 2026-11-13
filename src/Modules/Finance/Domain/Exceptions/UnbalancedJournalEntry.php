<?php

declare(strict_types=1);

namespace Modules\Finance\Domain\Exceptions;

use DomainException;

final class UnbalancedJournalEntry extends DomainException {}
