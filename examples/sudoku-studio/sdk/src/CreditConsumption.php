<?php
declare(strict_types=1);
namespace KDPika\LicenseSdk;
final readonly class CreditConsumption
{
    public function __construct(public string $transactionId,public int $consumed,public int $balance,public bool $replayed){}
}
