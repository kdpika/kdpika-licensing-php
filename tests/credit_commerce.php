<?php
declare(strict_types=1);
// Focused B/C/D only. Synthetic hardware/signatures and local transport; no network.
spl_autoload_register(static function(string $name):void{if(str_starts_with($name,'KDPika\\LicenseSdk\\')){require dirname(__DIR__).'/src/'.substr($name,strlen('KDPika\\LicenseSdk\\')).'.php';}});
use KDPika\LicenseSdk\{LicenseClient,HardwareFingerprint,AllSeatsInUseException,InsufficientCreditsException,CreditOperationException};
$checks=0;$assert=static function(bool $v,string $name)use(&$checks){if(!$v)throw new RuntimeException($name);$checks++;};
$deny=static function(callable $call,string $code)use($assert){try{$call();throw new RuntimeException('Expected '.$code);}catch(CreditOperationException $e){$assert($e->errorCode===$code,'Expected '.$code);return $e;}};
$directories=[];$clients=[];
$fixture=static function(bool $floating=false,bool $metered=false)use(&$directories,&$clients):array{
    $dir=sys_get_temp_dir().'/kdpika-php-bcd-'.bin2hex(random_bytes(8));$directories[]=$dir;
    $state=(object)['now'=>new DateTimeImmutable('2026-10-25T12:00:00Z'),'nic'=>'nic-1','board'=>'board-1','fp'=>[],'installation'=>'','held'=>false,'full'=>false,'missing'=>false,'offline'=>false,'floating'=>$floating,'metered'=>$metered,'balance'=>5,'ledger'=>[],'calls'=>[],'tamper'=>false,'foreign'=>false,'missingPolicy'=>false,'lost'=>false];
    $pair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($pair);$public=sodium_crypto_sign_publickey($pair);$b64=static fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');$key=['algorithm'=>'Ed25519','key_id'=>'ed25519_'.substr(hash('sha256',$public),0,16),'public_key'=>$b64($public)];
    $sort=function($v)use(&$sort){if(!is_array($v))return $v;foreach($v as$k=>$item)$v[$k]=$sort($item);if(!array_is_list($v))ksort($v,SORT_STRING);return $v;};
    $signed=static function(?array $receipt=null)use($state,$secret,$key,$b64,$sort):array{
        $fp=$state->fp;$fp['policy']=['mode'=>'fuzzy','min_matches'=>2];$seat='flt_'.str_repeat('A',43);
        $payload=['license_id'=>'lic_fixture','product_id'=>$state->foreign?'app_foreign':'app_fixture','environment'=>'sandbox','status'=>'active','expires_at'=>null,'features'=>['export'=>true],'device'=>['installation_hash'=>hash('sha256',$state->installation),'fingerprint'=>$fp],'server_time'=>$state->now->format(DATE_ATOM),'next_validation_at'=>$state->now->modify('+1 hour')->format(DATE_ATOM),'offline_grace_until'=>$state->now->modify('+7 days')->format(DATE_ATOM),'floating'=>$state->floating?['max_concurrent_sessions'=>1,'heartbeat_interval_seconds'=>180,'session_timeout_seconds'=>600,'session'=>$state->held?['id'=>'ses_fixture','token_hash'=>hash('sha256',$seat),'expires_at'=>$state->now->modify('+600 seconds')->format(DATE_ATOM)]:null]:null,'metered'=>$state->metered,'credit_balance'=>$state->balance,'total_allocated'=>5,'credit_transaction'=>$receipt];
        if($state->missingPolicy)unset($payload['metered']);$encoded=json_encode($sort($payload),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
        return ['payload'=>$payload,'payload_json'=>$encoded,'signature'=>$b64(sodium_crypto_sign_detached($encoded,$secret)),'algorithm'=>'Ed25519','key_id'=>$key['key_id']];
    };
    $transport=static function(string $method,string $path,?array $body,array $headers)use($state,$signed):array{
        $state->calls[]=$path;if($state->offline)throw new RuntimeException('offline');
        $reply=static fn(array $data,int $status=200)=>['ok'=>$status===200,'status'=>$status,'body'=>$data];$refuse=static fn(string $code,int $status=409)=>$reply(['success'=>false,'error'=>['code'=>$code]],$status);
        if(str_ends_with($path,'/activate')){$state->installation=$body['device_id'];$state->fp=$body['fingerprint'];}
        elseif(!str_ends_with($path,'/deactivate')){if(($headers['X-Installation-ID']??'')!==$state->installation||!isset($body['fingerprint']))throw new LogicException('Missing installation/fingerprint wire fields');}
        if(str_starts_with($path,'/api/v1/credits/')){
            if(str_ends_with($path,'/redeem')){
                if(($body['code']??'')!=='KDP100CR')return $refuse('CREDIT_CODE_UNAVAILABLE',422);
                $key=$body['idempotency_key'];$old=$state->ledger[$key]??null;
                if($old)$receipt=$old+['replayed'=>true];else{$state->balance+=100;$receipt=['id'=>'ctx_credit_code','type'=>'grant','amount'=>100,'balance_after'=>$state->balance,'total_allocated_after'=>100,'idempotency_hash'=>hash('sha256','code:'.hash('sha256',$key)),'replayed'=>false];$state->ledger[$key]=$receipt;}
                if($old)$receipt['replayed']=true;
                if($state->lost){$state->lost=false;throw new RuntimeException('reply lost');}
                return $reply(['success'=>true,'license'=>$signed($receipt),'transaction_id'=>$receipt['id'],'added'=>999,'balance'=>999,'replayed'=>$receipt['replayed']]);
            }
            return $reply(['success'=>true,'license'=>$signed(),'packs'=>[['id'=>'cpk_fixture','units'=>100]],'checkout_url'=>'https://store.example.test/?kdpika_credit_checkout=opaque','transactions'=>[]]);
        }
        if(str_ends_with($path,'/deactivate'))return $reply(['success'=>true,'deactivated'=>true]);
        if(str_ends_with($path,'/checkout')){if($state->full)return $refuse('ALL_SEATS_IN_USE');$state->held=true;}
        if(str_ends_with($path,'/heartbeat')&&$state->missing){$state->missing=false;$state->held=false;return $refuse('SESSION_NOT_FOUND');}
        if(str_ends_with($path,'/checkin')){$state->held=false;return $reply(['success'=>true,'checked_in'=>true]);}
        if(str_ends_with($path,'/validate')&&$state->floating&&!$state->held)return $refuse('SESSION_NOT_FOUND');
        $receipt=null;
        if(str_ends_with($path,'/consume')){
            $n=$body['amount'];$k=$body['idempotency_key'];$old=$k===null?null:($state->ledger[$k]??null);
            if($old){if($old['amount']!==-$n)return $refuse('IDEMPOTENCY_CONFLICT');$receipt=$old;$receipt['replayed']=true;}
            elseif($state->balance<$n)return $reply(['success'=>false,'error'=>['code'=>'INSUFFICIENT_CREDITS'],'balance'=>$state->balance,'license'=>$signed()],402);
            else{$state->balance-=$n;$receipt=['id'=>'ctx_fixture_'.count($state->ledger),'type'=>'consume','amount'=>-$n,'balance_after'=>$state->balance,'total_allocated_after'=>5,'idempotency_hash'=>$k===null?null:hash('sha256',$k),'replayed'=>false];if($k!==null)$state->ledger[$k]=$receipt;if($state->lost){$state->lost=false;throw new RuntimeException('lost response');}}
        }
        $data=['success'=>true,'activation_token'=>'act_SYNTHETIC_FIXTURE','session_token'=>'flt_'.str_repeat('A',43),'license'=>$signed($receipt)];
        if($receipt)$data+=['transaction_id'=>$receipt['id'],'consumed'=>$body['amount'],'balance'=>$receipt['balance_after']+(int)$state->tamper,'replayed'=>$receipt['replayed']];return $reply($data);
    };
    $client=new LicenseClient('app_fixture','SANDBOX','https://licenses.example.test','PHP fixture','1.0',$key,$dir,15,$transport,static fn()=>$state->now,static fn($product)=>HardwareFingerprint::fromComponents($product,['baseboard'=>$state->board,'cpu'=>'fixture-cpu','nic'=>$state->nic],true));$clients[]=$client;return [$client,$state];
};
try{
    [$c,$s]=$fixture(false,true);$s->balance=0;$assert($c->activate('SYNTHETIC')->valid,'zero is active');
    $r=$c->redeemCredits('KDP100CR','credit-code-first');$assert($r['added']===100&&$r['balance']===100,'signed units and balance override untrusted outer fields');
    $assert($c->redeemCredits('KDP100CR','credit-code-first')['replayed']&&$s->balance===100,'redemption replay');
    $deny(static fn()=>$c->redeemCredits('WRONGCODE','credit-code-bad'),'CREDIT_CODE_UNAVAILABLE');
    $s->lost=true;$deny(static fn()=>$c->redeemCredits('KDP100CR','credit-code-lost'),'NETWORK_ERROR');$assert($c->redeemCredits('KDP100CR','credit-code-lost')['replayed']&&$s->balance===200,'uncertain request recovered exactly once');
    $assert($c->creditPacks()['packs'][0]['units']===100,'pack discovery');$assert(str_starts_with($c->createCreditCheckout('cpk_fixture')['checkout_url'],'https://store.example.test/'),'checkout link');$assert($c->creditHistory()['transactions']===[],'credit history');
    echo json_encode(['result'=>'PASS','checks'=>$checks,'network'=>false]).PHP_EOL;
}finally{
    foreach($clients as$client){try{$client->close();}catch(Throwable){}}$clients=[];
    $remove=function(string $dir)use(&$remove):void{foreach(glob($dir.'/*')?:[]as$file){if(is_dir($file))$remove($file);else unlink($file);}foreach(glob($dir.'/.*')?:[]as$file)if(is_file($file))unlink($file);if(is_dir($dir))rmdir($dir);};foreach($directories as$dir)if(is_dir($dir))$remove($dir);
}
