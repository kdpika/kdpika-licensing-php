<?php
declare(strict_types=1);
namespace KDPika\LicenseSdk;
final class AllSeatsInUseException extends CreditOperationException
{
    public function __construct(){parent::__construct('ALL_SEATS_IN_USE');}
}
