<?php

declare(strict_types=1);

namespace KDPika\LicenseSdk;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class LicenseClient
{
    private string $environment;
    private string $baseUrl;
    private string $storageDir;
    private string $installationId;
    private ?array $verificationKey;
    private $transport;
    private $clock;
    private $fingerprintProvider;
    private int $lockDepth=0;

    public function __construct(
        private readonly string $product,
        string $environment = 'LIVE',
        string $baseUrl = 'https://licensing.kdpika.com',
        private readonly string $applicationName = 'Licensed Application',
        private readonly string $applicationVersion = '',
        ?array $verificationKey = null,
        ?string $storageDir = null,
        private readonly float $timeout = 15.0,
        ?callable $transport = null,
        ?callable $clock = null,
        ?callable $fingerprintProvider = null,
        private readonly bool $releaseSessionOnDestruct = true,
        private readonly string $applicationType = 'web',
    ) {
        if(!in_array($applicationType,['desktop','web'],true))throw new RuntimeException('Application type must be desktop or web.');
        if(PHP_INT_SIZE<8)throw new RuntimeException('This SDK requires 64-bit PHP for exact credit amounts.');
        if (trim($product) === '') throw new RuntimeException('A Public App ID is required.');
        $baseUrl = rtrim(trim($baseUrl), '/');
        if (!$this->safeBaseUrl($baseUrl)) throw new RuntimeException('The API base URL must use HTTPS; loopback HTTP is allowed for development.');
        $environment = strtoupper(trim($environment));
        $this->environment = ['PRODUCTION' => 'LIVE', 'TEST' => 'SANDBOX'][$environment] ?? $environment;
        if (!in_array($this->environment, ['SANDBOX', 'LIVE'], true)) throw new RuntimeException('Environment must be SANDBOX or LIVE.');
        if ($verificationKey !== null && (!isset($verificationKey['key_id'], $verificationKey['public_key']))) throw new RuntimeException('Pinned verification key is incomplete.');
        $this->baseUrl = $baseUrl;
        $this->verificationKey = $verificationKey;
        $this->transport = $transport;
        $this->clock = $clock;
        $this->fingerprintProvider=$fingerprintProvider;
        $namespace = substr(hash('sha256', strtolower($baseUrl) . '|' . $product . '|' . $this->environment . '|' . $this->applicationType), 0, 20);
        $this->storageDir = rtrim($storageDir ?? $this->defaultStorageDir($applicationName), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $namespace;
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0700, true) && !is_dir($this->storageDir)) throw new RuntimeException('License storage could not be created.');
        @chmod($this->storageDir, 0700);
        $identity = $this->readJson('installation.json');
        $this->installationId = is_string($identity['installation_id'] ?? null) ? $identity['installation_id'] : 'lmp-install-' . $this->uuid();
        if (!$identity) $this->writeJson('installation.json', ['installation_id' => $this->installationId]);
    }

    public function installationId(): string { return $this->installationId; }
    public function activated(): bool { return $this->token() !== null; }

    public function activate(string $licenseKey, ?string $email = null): LicenseResult
    {return $this->synchronized(fn()=>$this->activateInner($licenseKey,$email));}

    private function activateInner(string $licenseKey,?string $email):LicenseResult
    {
        if (trim($licenseKey) === '') return $this->error('INVALID_LICENSE', 'Enter a license key.', 'local');
        try{$fingerprint=$this->fingerprint();}catch(\Throwable){return $this->error('HARDWARE_UNAVAILABLE','Device information is unavailable. Contact the software publisher.','local');}
        $body = ['license_key' => trim($licenseKey), 'device_id' => $this->installationId, 'device_name' => gethostname() ?: $this->applicationName, 'platform' => PHP_OS_FAMILY, 'app_version' => $this->applicationVersion];
        if ($email !== null && trim($email) !== '') $body['email'] = trim($email);
        $body['fingerprint']=$fingerprint;
        try { $response = $this->request('POST', '/api/v1/licenses/activate', $body, ['X-App-ID' => $this->product, 'X-Environment' => $this->environment]); }
        catch (RuntimeException) { return $this->error('NETWORK_ERROR', 'Activation requires an internet connection.', 'local'); }
        if (!$response['ok']) return $this->responseError($response);
        $token = $response['body']['activation_token'] ?? null;
        $signed = $response['body']['license'] ?? null;
        if (!is_string($token) || !str_starts_with($token, 'act_') || !is_array($signed)) return $this->error('INVALID_RESPONSE', 'The activation response was incomplete.', 'online', $response);
        try { $result = $this->verified($signed, 'online', $response); }
        catch (\Throwable) { $this->bestEffortDeactivate($token); return $this->error('SIGNATURE_INVALID', 'The license could not be verified.', 'online', $response); }
        if (!$result->valid&&!in_array($result->errorCode,['SESSION_REQUIRED','INSUFFICIENT_CREDITS'],true)) { $this->bestEffortDeactivate($token); return $result; }
        $this->atomicWrite('activation-token', $token);
        $this->saveState($signed);
        return $result->errorCode==='SESSION_REQUIRED'?$this->seatInner('checkout'):$result;
    }

    public function check(bool $forceOnline = false): LicenseResult
    {return $this->synchronized(fn()=>$this->checkInner($forceOnline));}

    private function checkInner(bool $forceOnline):LicenseResult
    {
        $token = $this->token();
        if (!$token) return $this->error('NOT_ACTIVATED', 'No activation is stored for this installation.', 'local');
        try{$fingerprint=$this->fingerprint();}catch(\Throwable){return $this->error('HARDWARE_UNAVAILABLE','Device information is unavailable.','local');}
        $session=$this->readJson('floating-session.json');
        if(!$forceOnline&&$this->sessionToken()&&($session['next_heartbeat_at']??0)<=$this->now()->getTimestamp())return $this->seatInner('heartbeat');
        $state = $this->readJson('signed-state.json');
        if (!$forceOnline && is_array($state['signed_license'] ?? null)) {
            try {
                $cached = $this->verified($state['signed_license'], 'cache');
                $next = $this->time($cached->payload['next_validation_at'] ?? null);
                if ($cached->valid && $next && $this->now() < $next) { $this->checkpoint(); return $cached; }
            } catch (\Throwable) { $this->remove('signed-state.json'); }
        }
        try { $response = $this->request('POST', '/api/v1/licenses/validate', ['fingerprint'=>$fingerprint,'session_token'=>$this->sessionToken()], ['Authorization' => 'Bearer ' . $token, 'X-Installation-ID' => $this->installationId]); }
        catch (RuntimeException) { return $this->offline(); }
        if (!$response['ok']) {
            $result = $this->responseError($response);
            if(($response['status']??0)===402&&$result->errorCode==='INSUFFICIENT_CREDITS'){
                try{$signed=$response['body']['license']??[];$verified=$this->verified($signed,'online',$response);if($verified->signatureValid&&$verified->errorCode==='INSUFFICIENT_CREDITS'){$this->saveState($signed);return $verified;}}catch(\Throwable){}
                return $this->error('SIGNATURE_INVALID','The credit balance could not be verified.','online',$response);
            }
            $this->remove('floating-session.json');
            if($result->errorCode==='SESSION_NOT_FOUND')return $this->seatInner('checkout');
            $this->remove('signed-state.json');
            if (in_array($result->errorCode, ['INVALID_TOKEN','DEVICE_MISMATCH','FINGERPRINT_MISMATCH','LICENSE_REVOKED','LICENSE_EXPIRED','LICENSE_CANCELLED','LICENSE_SUSPENDED'], true)) $this->remove('activation-token');
            return $result;
        }
        $signed = $response['body']['license'] ?? null;
        if (!is_array($signed)) { $this->remove('signed-state.json');return $this->error('INVALID_RESPONSE', 'The validation response was incomplete.', 'online', $response); }
        try { $result = $this->verified($signed, 'online', $response); }
        catch (\Throwable) { $this->remove('signed-state.json');return $this->error('SIGNATURE_INVALID', 'The license could not be verified.', 'online', $response); }
        if ($result->valid) $this->saveState($signed); else $this->remove('signed-state.json');
        return $result;
    }

    public function validate(): LicenseResult { return $this->check(true); }

    public function deactivate(): LicenseResult
    {return $this->synchronized(fn()=>$this->deactivateInner());}

    private function deactivateInner():LicenseResult
    {
        $this->remove('floating-session.json');
        $token = $this->token();
        if (!$token) return $this->error('NOT_ACTIVATED', 'No activation is stored for this installation.', 'local');
        try { $response = $this->request('POST', '/api/v1/licenses/deactivate', [], ['Authorization' => 'Bearer ' . $token]); }
        catch (RuntimeException) { return $this->error('NETWORK_ERROR', 'Deactivation requires an internet connection.', 'local'); }
        if (!$response['ok']) return $this->responseError($response);
        if (($response['body']['deactivated']??null)!==true) return $this->error('INVALID_RESPONSE','Deactivation was not confirmed.','online',$response);
        $this->remove('activation-token'); $this->remove('signed-state.json');
        return new LicenseResult(true, 'online', message: 'License deactivated.', requestId: $response['body']['request_id'] ?? null);
    }

    public function feature(string $name, bool $default = false): bool { return $this->check()->feature($name, $default); }

    private function offline(): LicenseResult
    {
        $signed = $this->readJson('signed-state.json')['signed_license'] ?? null;
        if (!is_array($signed)) return $this->error('OFFLINE_CACHE_MISSING', 'Online validation is required because no verified cache is available.', 'offline');
        try { $result = $this->verified($signed, 'offline'); }
        catch (\Throwable) { $this->remove('signed-state.json'); return $this->error('OFFLINE_CACHE_INVALID', 'Connect to verify your license again.', 'offline'); }
        if ($result->valid) $this->checkpoint();
        return $result;
    }

    private function verified(array $signed, string $source, ?array $response = null): LicenseResult
    {
        $encoded=$signed['payload_json']??null;
        if(array_key_exists('payload_json',$signed)&&(!is_string($encoded)||strlen($encoded)>1048576))throw new RuntimeException('Invalid signed bytes.');
        $payload = $encoded===null?($signed['payload']??null):json_decode($encoded,true,512,JSON_THROW_ON_ERROR); $signature = $signed['signature'] ?? null;
        if (!is_array($payload) || !is_string($signature) || ($signed['algorithm'] ?? null) !== 'Ed25519') throw new RuntimeException('The signed license envelope is incomplete.');
        $key = $this->verificationKey($signed);
        if (($signed['key_id'] ?? null) !== ($key['key_id'] ?? null)) throw new RuntimeException('The license signature key does not match the trusted key.');
        $canonical = $encoded??json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if (!sodium_crypto_sign_verify_detached($this->decode($signature), $canonical, $this->decode((string) $key['public_key']))) throw new RuntimeException('The license signature is invalid.');
        if(!self::applicationAllowed($payload,$this->applicationType))return $this->error('APPLICATION_NOT_ALLOWED','This license does not include this type of app.',$source,$response,$payload,true);
        if (($payload['product_id'] ?? null) !== $this->product || strtoupper((string) ($payload['environment'] ?? '')) !== $this->environment) throw new RuntimeException('The signed license belongs to another product or environment.');
        if (($payload['device']['installation_hash'] ?? null) !== hash('sha256', $this->installationId)) throw new RuntimeException('The signed license belongs to another installation.');
        if(!HardwareFingerprint::matches($payload['device']['fingerprint']??null,$this->fingerprint()))return $this->error('FINGERPRINT_MISMATCH','This activation belongs to a different device.',$source,$response,$payload,true);
        $now = $this->now(); $state = $this->readJson('signed-state.json');
        $checkpoint = $this->time($state['last_seen_at'] ?? null); $server = $this->time($payload['server_time'] ?? null);
        $next=$this->time($payload['next_validation_at']??null);$signedGrace=$this->time($payload['offline_grace_until']??null);
        if(!$server||!$next||!$signedGrace||$next<$server||$signedGrace<$server||!array_key_exists('expires_at',$payload)||($payload['expires_at']!==null&&!$this->time($payload['expires_at'])))return $this->error('INVALID_SIGNED_TIME','The license dates could not be verified.',$source,$response,$payload,true);
        $latest = !$checkpoint ? $server : (!$server || $checkpoint > $server ? $checkpoint : $server);
        if ($latest && $now->getTimestamp() + 300 < $latest->getTimestamp()) return $this->error('CLOCK_ROLLBACK_DETECTED', 'The system clock moved behind the last trusted license time.', $source, $response, $payload, true);
        if (strtolower((string) ($payload['status'] ?? '')) !== 'active') return $this->error('LICENSE_NOT_ACTIVE', 'The license is not active.', $source, $response, $payload, true);
        $expires = $this->time($payload['expires_at'] ?? null);
        if ($expires && $now >= $expires) return $this->error('LICENSE_EXPIRED', 'The license has expired.', $source, $response, $payload, true);
        $grace = $this->time($payload['offline_grace_until'] ?? null);
        if (in_array($source, ['cache','offline'], true) && (!$grace || $now > $grace)) return $this->error('OFFLINE_GRACE_EXPIRED', 'The offline grace period ended; connect to validate the license.', $source, $response, $payload, true);
        $f=$payload['floating']??null;
        if(is_array($f)&&(!is_int($f['heartbeat_interval_seconds']??null)||$f['heartbeat_interval_seconds']<30||$f['heartbeat_interval_seconds']>600))return $this->error('INVALID_RESPONSE','The shared-seat settings are unavailable.',$source,$response,$payload,true);
        if(is_array($f)&&($f['session']??null)===null)return $this->error('SESSION_REQUIRED','Reserve a shared seat before using this app.',$source,$response,$payload,true);
        if(!LicensePolicy::lease($payload,$this->sessionToken(),$now))return $this->error('SESSION_EXPIRED','Your shared seat expired. Connect to reserve a seat again.',$source,$response,$payload,true);
        if(!LicensePolicy::credits($payload))return $this->error('INVALID_RESPONSE','The credit policy is unavailable.',$source,$response,$payload,true);
        if($payload['metered']&&$source!=='online')return $this->error('METERED_ONLINE_REQUIRED','Connect to check or use credits.',$source,$response,$payload,true);
        return new LicenseResult(true, $source, $payload, requestId: $response['body']['request_id'] ?? null, signatureValid: true, clock: fn()=>$this->now(),seatGuard:fn(array $p)=>LicensePolicy::lease($p,$this->sessionToken(),$this->now()));
    }

    private function verificationKey(array $signed): array
    {
        if ($this->verificationKey) return $this->verificationKey;
        $state = $this->readJson('signed-state.json');
        if (($state['verification_key']['key_id'] ?? null) === ($signed['key_id'] ?? null)) return $state['verification_key'];
        $response = $this->request('GET', '/api/v1/public-key', null, []);
        $key = $response['body']['key'] ?? null;
        if (!$response['ok'] || !is_array($key) || ($key['key_id'] ?? null) !== ($signed['key_id'] ?? null) || ($key['algorithm'] ?? null) !== 'Ed25519') throw new RuntimeException('The server verification key could not authenticate this license.');
        $this->verificationKey = $key;
        return $key;
    }

    private function saveState(array $signed): void { $this->writeJson('signed-state.json', ['version'=>1,'signed_license'=>$signed,'verification_key'=>$this->verificationKey($signed),'last_seen_at'=>$this->now()->format(DATE_ATOM)]); }
    private function checkpoint(): void { $state=$this->readJson('signed-state.json'); $state['last_seen_at']=$this->now()->format(DATE_ATOM); $this->writeJson('signed-state.json',$state); }
    private function token(): ?string { $value=@file_get_contents($this->storageDir . '/activation-token'); return is_string($value) && trim($value)!=='' ? trim($value) : null; }
    private function bestEffortDeactivate(string $token): void { try { $this->request('POST','/api/v1/licenses/deactivate',[],['Authorization'=>'Bearer '.$token]); } catch (\Throwable) {} }

    private function request(string $method, string $path, ?array $body, array $headers): array
    {
        $headers['X-Application-Type']=$this->applicationType;
        if ($this->transport) return ($this->transport)($method, $path, $body, $headers);
        $curl = curl_init($this->baseUrl . $path);
        $headerLines = ['Accept: application/json', 'User-Agent: KDPika-PHP-SDK/1.0']; foreach ($headers as $name=>$value) $headerLines[]=$name.': '.$value;
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$this->timeout,CURLOPT_HTTPHEADER=>$headerLines]);
        if ($body !== null) { $headerLines[]='Content-Type: application/json'; curl_setopt($curl,CURLOPT_HTTPHEADER,$headerLines); curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }
        $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException('The licensing service could not be reached.');
        $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl); $decoded=json_decode((string)$raw,true);
        if (!is_array($decoded)) $decoded=['success'=>false,'error'=>['code'=>'INVALID_RESPONSE','message'=>'The server returned an invalid response.']];
        return ['ok'=>$status>=200&&$status<300&&($decoded['success']??false)===true,'status'=>$status,'body'=>$decoded];
    }

    public static function applicationAllowed(array $payload,string $type):bool
    {
        $scope=array_key_exists('application_access',$payload)?$payload['application_access']:(array_key_exists('application_access',$payload['features']??[])?$payload['features']['application_access']:'both');$bound=$payload['application_type']??null;
        return in_array($type,['desktop','web'],true)&&in_array($scope,['both','desktop','web'],true)&&($scope==='both'||$scope===$type)&&($bound===null?$scope==='both':$bound===$type);
    }
    private function responseError(array $response): LicenseResult { return $this->error((string)($response['body']['error']['code']??'HTTP_ERROR'),(string)($response['body']['error']['message']??'The licensing service rejected the request.'),'online',$response); }
    private function error(string $code,string $message,string $source,?array $response=null,array $payload=[],bool $signature=false): LicenseResult { return new LicenseResult(false,$source,$payload,$code,$message,$response['body']['request_id']??null,$signature); }
    private function now(): DateTimeImmutable { $value=$this->clock ? ($this->clock)() : new DateTimeImmutable('now',new DateTimeZone('UTC')); return $value instanceof DateTimeImmutable ? $value->setTimezone(new DateTimeZone('UTC')) : new DateTimeImmutable((string)$value,new DateTimeZone('UTC')); }
    private function time(mixed $value): ?DateTimeImmutable { if (!is_string($value)||$value==='') return null; try{return new DateTimeImmutable($value,new DateTimeZone('UTC'));}catch(\Throwable){return null;} }
    private function decode(string $value): string { $decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true); if($decoded===false)throw new RuntimeException('Invalid base64url value.'); return $decoded; }
    private function sortRecursive(mixed $value): mixed { if(!is_array($value))return $value; foreach($value as $key=>$item)$value[$key]=$this->sortRecursive($item); if(!array_is_list($value))ksort($value,SORT_STRING); return $value; }
    private function readJson(string $name): array { $raw=@file_get_contents($this->storageDir.'/'.$name); if(!is_string($raw)||$raw==='')return[]; $data=json_decode($raw,true); return is_array($data)?$data:[]; }
    private function writeJson(string $name,array $value): void { $this->atomicWrite($name,json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)); }
    private function atomicWrite(string $name,string $value): void { $path=$this->storageDir.'/'.$name;$tmp=tempnam($this->storageDir,$name.'.');if($tmp===false||file_put_contents($tmp,$value,LOCK_EX)===false)throw new RuntimeException('License state could not be stored.');@chmod($tmp,0600);if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('License state could not be stored.');}@chmod($path,0600); }
    private function remove(string $name): void { $path=$this->storageDir.'/'.$name;if(is_file($path))@unlink($path); }
    private function uuid(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4)); }
    private function defaultStorageDir(string $name): string { $safe=preg_replace('/[^A-Za-z0-9_-]+/','-',trim($name))?:'licensed-app'; if(PHP_OS_FAMILY==='Darwin')return($_SERVER['HOME']??sys_get_temp_dir()).'/Library/Application Support/'.$safe.'/License';if(PHP_OS_FAMILY==='Windows')return($_SERVER['APPDATA']??sys_get_temp_dir()).'/'.$safe.'/License';return($_SERVER['XDG_DATA_HOME']??(($_SERVER['HOME']??sys_get_temp_dir()).'/.local/share')).'/'.$safe.'/license'; }
    private function safeBaseUrl(string $url): bool { $parsed=parse_url($url);if(!is_array($parsed)||isset($parsed['user'])||isset($parsed['pass'])||!isset($parsed['scheme'],$parsed['host']))return false;$scheme=strtolower((string)$parsed['scheme']);$host=strtolower((string)$parsed['host']);return $scheme==='https'||($scheme==='http'&&in_array($host,['127.0.0.1','::1','localhost'],true)); }

    private function fingerprint():array{return $this->fingerprintProvider?($this->fingerprintProvider)($this->product):HardwareFingerprint::collect($this->product);}
    private function sessionToken():?string{$t=$this->readJson('floating-session.json')['session_token']??null;return is_string($t)&&preg_match('/^flt_[A-Za-z0-9_-]{43}$/D',$t)?$t:null;}
    private function synchronized(callable $work):mixed
    {
        if($this->lockDepth>0)return $work();$h=@fopen($this->storageDir.'/.operations.lock','c+');if(!$h)throw new CreditOperationException('STORAGE_ERROR');@chmod($this->storageDir.'/.operations.lock',0600);
        try{if(!flock($h,LOCK_EX))throw new CreditOperationException('STORAGE_ERROR');$this->lockDepth++;return $work();}finally{$this->lockDepth=max(0,$this->lockDepth-1);flock($h,LOCK_UN);fclose($h);}
    }
    public function checkout():LicenseResult{return $this->synchronized(function():LicenseResult{$r=$this->seatInner('checkout');if($r->errorCode==='ALL_SEATS_IN_USE')throw new AllSeatsInUseException();return $r;});}
    public function heartbeat():LicenseResult{return $this->synchronized(fn()=>$this->seatInner('heartbeat'));}
    public function checkin():LicenseResult{return $this->synchronized(fn()=>$this->seatInner('checkin'));}
    public function close():LicenseResult{return $this->checkin();}
    /** PHP has no background thread. Long-running loops call tick() frequently. */
    public function tick():?LicenseResult{return $this->synchronized(function():?LicenseResult{$s=$this->readJson('floating-session.json');return $this->sessionToken()&&($s['next_heartbeat_at']??0)<=$this->now()->getTimestamp()?$this->seatInner('heartbeat'):null;});}
    public function __destruct(){if($this->releaseSessionOnDestruct&&isset($this->storageDir)&&$this->sessionToken()){try{$this->checkin();}catch(\Throwable){}}}
    private function seatInner(string $action):LicenseResult
    {
        $token=$this->token();$session=$this->sessionToken();
        if($action==='checkin'){$this->remove('floating-session.json');$this->remove('signed-state.json');}
        if(!$token)return $this->error('NOT_ACTIVATED','Activate this installation first.','local');
        if($action!=='checkout'&&!$session)return $action==='heartbeat'?$this->seatInner('checkout'):new LicenseResult(true,'online',message:'No shared seat is held.');
        try{$fp=$this->fingerprint();}catch(\Throwable){return $this->error('HARDWARE_UNAVAILABLE','Device information is unavailable.','local');}
        try{$response=$this->request('POST','/api/v1/licenses/'.$action,['fingerprint'=>$fp,'session_token'=>$session],['Authorization'=>'Bearer '.$token,'X-Installation-ID'=>$this->installationId]);}catch(RuntimeException){return $this->error('NETWORK_ERROR','Connect to manage your shared seat.','local');}
        if(!$response['ok']){$this->remove('floating-session.json');$this->remove('signed-state.json');$r=$this->responseError($response);return $action==='heartbeat'&&$r->errorCode==='SESSION_NOT_FOUND'?$this->seatInner('checkout'):$r;}
        if($action==='checkin')return ($response['body']['checked_in']??false)===true?new LicenseResult(true,'online',message:'Shared seat released.'):$this->error('INVALID_RESPONSE','Seat release was not confirmed.','online',$response);
        if($action==='checkout'){$s=$response['body']['session_token']??null;if(!is_string($s)||!preg_match('/^flt_[A-Za-z0-9_-]{43}$/D',$s))return $this->error('INVALID_RESPONSE','The shared seat could not be verified.','online',$response);$this->writeJson('floating-session.json',['session_token'=>$s]);}
        try{$signed=$response['body']['license']??[];$r=$this->verified($signed,'online',$response);}catch(\Throwable){$r=$this->error('SIGNATURE_INVALID','The shared seat could not be verified.','online',$response);}
        if($r->valid||$r->errorCode==='INSUFFICIENT_CREDITS'){$this->saveState($signed);$this->writeJson('floating-session.json',['session_token'=>$this->sessionToken(),'next_heartbeat_at'=>$this->now()->getTimestamp()+$r->payload['floating']['heartbeat_interval_seconds']]);}else{$this->remove('floating-session.json');$this->remove('signed-state.json');}return $r;
    }
    public function creditPacks():array{return $this->creditRequest('packs',[]);}
    public function creditHistory():array{return $this->creditRequest('history',[]);}
    public function createCreditCheckout(string $packId):array{return $this->creditRequest('checkout',['pack_id'=>$packId]);}
    public function redeemCredits(string $code,string $idempotencyKey):array
    {
        if(!preg_match('/\A[A-Za-z0-9_.:-]{8,190}\z/D',$idempotencyKey))throw new CreditOperationException('INVALID_REQUEST');
        return $this->creditRequest('redeem',['code'=>$code,'idempotency_key'=>$idempotencyKey]);
    }
    private function creditRequest(string $operation,array $fields):array
    {
        return $this->synchronized(function()use($operation,$fields):array{
            $token=$this->token();if(!$token)throw new CreditOperationException('NOT_ACTIVATED');
            try{$response=$this->request('POST','/api/v1/credits/'.$operation,$fields+['fingerprint'=>$this->fingerprint(),'session_token'=>$this->sessionToken()],['Authorization'=>'Bearer '.$token,'X-Installation-ID'=>$this->installationId]);}catch(RuntimeException){throw new CreditOperationException('NETWORK_ERROR');}
            if(!$response['ok'])throw new CreditOperationException($response['body']['error']['code']??'HTTP_ERROR');
            $signed=$response['body']['license']??[];$r=$this->verified($signed,'online',$response);
            if(!$r->valid||!$r->signatureValid)throw new CreditOperationException($r->errorCode??'SIGNATURE_INVALID');
            $this->saveState($signed);$data=$response['body'];unset($data['license']);$data['balance']=$r->payload['credit_balance'];
            if($operation==='redeem'){$receipt=$r->payload['credit_transaction']??[];$expected=hash('sha256','code:'.hash('sha256',$fields['idempotency_key']));if(($receipt['type']??'')!=='grant'||($receipt['idempotency_hash']??'')!==$expected||($receipt['id']??null)!==($data['transaction_id']??null))throw new CreditOperationException('SIGNATURE_INVALID');$data['added']=$receipt['amount'];$data['replayed']=$receipt['replayed'];}
            return $data;
        });
    }
    public function getBalance():int
    {
        return $this->synchronized(function():int{$r=$this->checkInner(true);if(!$r->signatureValid||(!$r->valid&&$r->errorCode!=='INSUFFICIENT_CREDITS'))throw new CreditOperationException($r->errorCode??'INVALID_RESPONSE');if(!$r->payload['metered'])throw new CreditOperationException('NOT_METERED_LICENSE');return $r->payload['credit_balance'];});
    }
    public function consumeAction(string $action,string $key,int $expectedUnits=1):CreditConsumption{return $this->consume($expectedUnits,$key,null,$action);}
    public function consume(mixed $amount,?string $idempotencyKey=null,?string $reason=null,?string $action=null):CreditConsumption
    {
        if(!is_int($amount)||$amount<1||$amount>LicensePolicy::MAX_CREDITS)throw new CreditOperationException('INVALID_CREDIT_AMOUNT');
        if(($idempotencyKey!==null&&!preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D',$idempotencyKey))||($reason!==null&&(preg_match_all('/./us',$reason)>190||preg_match('/[\x00-\x1f\x7f]/u',$reason))))throw new CreditOperationException('INVALID_REQUEST');
        return $this->synchronized(function()use($amount,$idempotencyKey,$reason,$action):CreditConsumption{
            $token=$this->token();if(!$token)throw new CreditOperationException('NOT_ACTIVATED');$fp=$this->fingerprint();
            try{$response=$this->request('POST','/api/v1/licenses/consume',['amount'=>$amount,'action'=>$action,'idempotency_key'=>$idempotencyKey,'reason'=>$reason,'fingerprint'=>$fp,'session_token'=>$this->sessionToken()],['Authorization'=>'Bearer '.$token,'X-Installation-ID'=>$this->installationId]);}catch(RuntimeException){throw new CreditOperationException('NETWORK_ERROR');}
            if(!$response['ok']&&($response['body']['error']['code']??'')!=='INSUFFICIENT_CREDITS'){$this->remove('signed-state.json');throw new CreditOperationException($response['body']['error']['code']??'HTTP_ERROR');}
            try{$signed=$response['body']['license']??[];$r=$this->verified($signed,'online',$response);}catch(\Throwable){throw new CreditOperationException('SIGNATURE_INVALID');}
            if(!$r->signatureValid||(!$r->valid&&$r->errorCode!=='INSUFFICIENT_CREDITS'))throw new CreditOperationException($r->errorCode??'SIGNATURE_INVALID');if(!$r->payload['metered'])throw new CreditOperationException('NOT_METERED_LICENSE');
            if(!$response['ok']){$balance=$r->payload['credit_balance'];if(($response['status']??0)!==402||($action===null&&$balance>=$amount)||($response['body']['balance']??null)!==$balance)throw new CreditOperationException('SIGNATURE_INVALID');$this->saveState($signed);throw new InsufficientCreditsException($balance);}
            $c=$r->payload['credit_transaction']??[];$body=$response['body'];if($action!==null){if(!is_int($c['amount']??null)||$c['amount']>=0)throw new CreditOperationException('SIGNATURE_INVALID');$amount=-$c['amount'];}
            if(!is_array($c)||($c['type']??'')!=='consume'||!is_string($c['id']??null)||!preg_match('/^ctx_[A-Za-z0-9_-]{1,36}$/D',$c['id'])||($c['amount']??null)!==-$amount||!LicensePolicy::integer($c['balance_after']??null)||!LicensePolicy::integer($c['total_allocated_after']??null)||($c['idempotency_hash']??null)!==($idempotencyKey===null?null:hash('sha256',$idempotencyKey))||!is_bool($c['replayed']??null)||($body['transaction_id']??null)!==$c['id']||($body['consumed']??null)!==$amount||($body['balance']??null)!==$c['balance_after']||($body['replayed']??null)!==$c['replayed'])throw new CreditOperationException('SIGNATURE_INVALID');
            $this->saveState($signed);return new CreditConsumption($c['id'],$amount,$c['balance_after'],$c['replayed']);
        });
    }
}
