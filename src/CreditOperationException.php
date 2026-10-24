<?php
declare(strict_types=1);
namespace KDPika\LicenseSdk;
class CreditOperationException extends \RuntimeException
{
    public readonly string $errorCode;
    public function __construct(string $errorCode='CREDIT_OPERATION_FAILED') {$this->errorCode=preg_match('/^[A-Z_]{1,50}$/D',$errorCode)?$errorCode:'CREDIT_OPERATION_FAILED';parent::__construct('The license operation could not be completed. Check your connection or contact the software publisher.');}
}
