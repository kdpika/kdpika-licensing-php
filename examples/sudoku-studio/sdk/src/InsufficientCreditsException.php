<?php
declare(strict_types=1);
namespace KDPika\LicenseSdk;
final class InsufficientCreditsException extends CreditOperationException
{
    public function __construct(public readonly int $balance){parent::__construct('INSUFFICIENT_CREDITS');}
}
