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
        if(str_ends_with($path,'/validate')&&$state->metered&&$state->balance===0)return $reply(['success'=>false,'error'=>['code'=>'INSUFFICIENT_CREDITS'],'balance'=>0,'license'=>$signed()],402);
        $data=['success'=>true,'activation_token'=>'act_SYNTHETIC_FIXTURE','session_token'=>'flt_'.str_repeat('A',43),'license'=>$signed($receipt)];
        if($receipt)$data+=['transaction_id'=>$receipt['id'],'consumed'=>$body['amount'],'balance'=>$receipt['balance_after']+(int)$state->tamper,'replayed'=>$receipt['replayed']];return $reply($data);
    };
    $client=new LicenseClient('app_fixture','SANDBOX','https://licenses.example.test','PHP fixture','1.0',$key,$dir,15,$transport,static fn()=>$state->now,static fn($product)=>HardwareFingerprint::fromComponents($product,['baseboard'=>$state->board,'cpu'=>'fixture-cpu','nic'=>$state->nic],true));$clients[]=$client;return [$client,$state];
};
try{
    [$c,$s]=$fixture();$assert($c->activate('SYNTHETIC')->valid,'native binding activated');$assert($s->fp['components']['baseboard']===hash('sha256',hash('sha256','kdpika-hardware-v1|app_fixture').'|baseboard|board-1'),'product salted wire hash');$s->nic='nic-2';$assert($c->check()->valid,'fuzzy NIC change accepted');$s->board='board-2';$assert($c->validate()->errorCode==='FINGERPRINT_MISMATCH','baseboard mismatch denied');
    $deny(static fn()=>HardwareFingerprint::fromComponents('app_fixture',['cpu'=>'cpu-only']),'HARDWARE_UNAVAILABLE');
    [$c,$s]=$fixture(true);$held=$c->activate('SYNTHETIC');$assert($held->valid&&$s->held,'automatic checkout');$assert($c->checkout()->valid,'repeat checkout');$s->missing=true;$assert($c->heartbeat()->valid,'heartbeat recovers missing session');$assert($c->tick()===null,'tick does not hammer before due time');$s->now=$s->now->modify('+181 seconds');$assert($c->tick()?->valid===true,'tick renews at signed interval');$assert($c->checkin()->valid&&!$s->held&&!$held->feature('export'),'checkin revokes retained lease grant');
    [$c,$s]=$fixture(true);$held=$c->activate('SYNTHETIC');$s->offline=true;$s->now=$s->now->modify('+599 seconds');$assert($c->validate()->valid,'offline lease within deadline');$s->now=$s->now->modify('+1 second');$assert(!$held->feature('export')&&$c->validate()->errorCode==='SESSION_EXPIRED','signed lease expires independently of offline grace');
    [$c,$s]=$fixture(true);$s->full=true;$assert($c->activate('SYNTHETIC')->errorCode==='ALL_SEATS_IN_USE','activation full-seat refusal');$assert($deny(static fn()=>$c->checkout(),'ALL_SEATS_IN_USE') instanceof AllSeatsInUseException,'typed seat exception');
    [$c,$s]=$fixture(false,true);$assert($c->activate('SYNTHETIC')->valid,'metered activation');$first=$c->consume(2,'export-job-1');$assert($first->balance===3&&!$first->replayed,'first charge');$assert($c->consume(3,'export-job-2')->balance===0,'last charge succeeds');$replay=$c->consume(2,'export-job-1');$assert($replay->replayed&&$replay->balance===3&&$replay->transactionId===$first->transactionId,'historical signed replay at zero');$assert($c->getBalance()===0,'signed fresh zero balance');$e=$deny(static fn()=>$c->consume(1,'export-job-3'),'INSUFFICIENT_CREDITS');$assert($e instanceof InsufficientCreditsException&&$e->balance===0,'verified typed insufficient balance');$assert(!$c->validate()->feature('export',true),'zero does not grant features');
    [$c,$s]=$fixture(false,true);$s->balance=0;$assert($c->activate('SYNTHETIC')->errorCode==='INSUFFICIENT_CREDITS'&&$c->activated(),'zero activation kept');$s->balance=5;$assert($c->getBalance()===5,'topup recovers without activation');$s->lost=true;$deny(static fn()=>$c->consume(2,'durable-job'),'NETWORK_ERROR');$assert($s->balance===3&&count($s->ledger)===1,'no automatic retry');$assert($c->consume(2,'durable-job')->replayed,'explicit same-key retry');$deny(static fn()=>$c->consume(1,'durable-job'),'IDEMPOTENCY_CONFLICT');$s->offline=true;$assert($c->check()->errorCode==='OFFLINE_CACHE_MISSING','missing cache also fails closed');$s->offline=false;$assert($c->getBalance()===3,'refresh signed balance after conflict');$s->offline=true;$assert($c->check()->errorCode==='METERED_ONLINE_REQUIRED','metered offline denied');$deny(static fn()=>$c->getBalance(),'METERED_ONLINE_REQUIRED');
    [$c,$s]=$fixture(false,true);$c->activate('SYNTHETIC');$before=count($s->calls);$c->check();$assert(count($s->calls)===$before+1,'metered check bypasses cache');$s->tamper=true;$deny(static fn()=>$c->consume(1,'tamper-job'),'SIGNATURE_INVALID');$s->tamper=false;$s->foreign=true;$deny(static fn()=>$c->consume(1,'foreign-job'),'SIGNATURE_INVALID');foreach([0,-1,1.5,true,'1',9007199254740992]as$n)$deny(static fn()=>$c->consume($n,'invalid-job'),'INVALID_CREDIT_AMOUNT');
    [$c,$s]=$fixture();$s->missingPolicy=true;$assert(!$c->activate('SYNTHETIC')->valid,'missing required policy fails closed');
    echo json_encode(['result'=>'PASS','checks'=>$checks,'network'=>false,'browser'=>'unsupported','heartbeat'=>'cooperative tick; no background PHP thread'],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{
    foreach($clients as$client){try{$client->close();}catch(Throwable){}}$clients=[];
    $remove=function(string $dir)use(&$remove):void{foreach(glob($dir.'/*')?:[]as$file){if(is_dir($file))$remove($file);else unlink($file);}foreach(glob($dir.'/.*')?:[]as$file)if(is_file($file))unlink($file);if(is_dir($dir))rmdir($dir);};foreach($directories as$dir)if(is_dir($dir))$remove($dir);
}
