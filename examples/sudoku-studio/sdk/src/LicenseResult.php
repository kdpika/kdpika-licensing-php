<?php

declare(strict_types=1);

namespace KDPika\LicenseSdk;

final readonly class LicenseResult
{
    public function __construct(
        public bool $valid,
        public string $source,
        public array $payload = [],
        public ?string $errorCode = null,
        public string $message = '',
        public ?string $requestId = null,
        public bool $signatureValid = false,
        public string $storageSecurity = 'permission-protected-file',
        private mixed $clock = null,
        private mixed $seatGuard = null,
    ) {}

    private function allowed(): bool
    {
        if(!$this->valid||!$this->signatureValid||($this->payload['status']??'')!=='active'||!array_key_exists('expires_at',$this->payload))return false;
        try{
            $p=$this->payload;$now=$this->clock?($this->clock)():new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
            if(!LicensePolicy::credits($p)||($p['metered']&&$this->source!=='online'))return false;
            if(($p['floating']??null)!==null){if(!$this->seatGuard||!($this->seatGuard)($p))return false;}elseif(!LicensePolicy::lease($p,null,$now))return false;
            foreach(['server_time','next_validation_at','offline_grace_until']as$key)if(!is_string($p[$key]??null))return false;
            $server=new \DateTimeImmutable($p['server_time']);$next=new \DateTimeImmutable($p['next_validation_at']);$grace=new \DateTimeImmutable($p['offline_grace_until']);
            $expiry=($p['expires_at']??null)===null?null:new \DateTimeImmutable($p['expires_at']);
            return $next>=$server&&$grace>=$server&&$now->getTimestamp()+300>=$server->getTimestamp()&&(!$expiry||$now<$expiry)&&(in_array($this->source,['cache','offline'],true)?$now<=$grace:$now<$next);
        }catch(\Throwable){return false;}
    }

    public function feature(string $name, bool $default = false): bool
    {
        if(!$this->allowed())return false;
        $value=$this->payload['features'][$name]??$default;
        return $value===true||((is_int($value)||is_float($value))&&is_finite((float)$value)&&$value>0);
    }

    public function featureValue(string $name, mixed $default = null): mixed
    {
        return $this->allowed()?($this->payload['features'][$name] ?? $default):null;
    }
}
