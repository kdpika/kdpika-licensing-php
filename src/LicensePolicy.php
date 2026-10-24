<?php
declare(strict_types=1);
namespace KDPika\LicenseSdk;
final class LicensePolicy
{
    public const MAX_CREDITS=9007199254740991;
    public static function credits(array $p):bool{return is_bool($p['metered']??null)&&self::integer($p['credit_balance']??null)&&self::integer($p['total_allocated']??null);}
    public static function integer(mixed $v):bool{return is_int($v)&&$v>=0&&$v<=self::MAX_CREDITS;}
    public static function lease(array $p,?string $token,\DateTimeImmutable $now):bool
    {
        if(!array_key_exists('floating',$p))return false;$f=$p['floating'];if($f===null)return true;
        if(!is_array($f)||!is_int($f['max_concurrent_sessions']??null)||$f['max_concurrent_sessions']<1||!$token||!is_string($f['session']['expires_at']??null)||($f['session']['token_hash']??null)!==hash('sha256',$token))return false;
        try{return $now<new \DateTimeImmutable($f['session']['expires_at']);}catch(\Throwable){return false;}
    }
}
