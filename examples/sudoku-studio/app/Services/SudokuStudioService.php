<?php
declare(strict_types=1);
namespace App\Services;
use KDPika\LicenseSdk\{LicenseClient,LicenseResult,HardwareFingerprint,CreditOperationException};

/** A real PHP SDK consumer. No management key, credit grant or payment authority in the app. */
final class SudokuStudioService
{
    private array $events=[];
    private bool $offline=false;
    public function __construct(private readonly array $profile,private readonly string $root,private readonly mixed $testTransport=null)
    {
        LicenseLabService::loadSdk();
        if(!preg_match('/\Aapp_[A-Za-z0-9_]{6,100}\z/D',$profile['app']??'')||!in_array($profile['environment']??'',['LIVE','SANDBOX'],true))throw new \DomainException('Check your App ID and choose Live or Test.');
        if(!is_dir($root)&&!mkdir($root,0700,true))throw new \RuntimeException('Private app storage is unavailable.');
    }
    public function client(string $slot='main'):LicenseClient
    {
        if(!in_array($slot,['main','second'],true))throw new \DomainException('Choose a valid app session.');
        return new LicenseClient(product:$this->profile['app'],environment:$this->profile['environment'],applicationName:'KDPika Sudoku Studio Web',applicationVersion:'1.0.0',storageDir:$this->root.'/'.$slot,transport:$this->transport(...),fingerprintProvider:static function(string $product):array{
            try{return HardwareFingerprint::collect($product);}catch(\Throwable){
                $host=gethostname();$cpu=php_uname('m');if(!is_string($host)||strlen($host)<5||in_array($host,['localhost','localhost.localdomain'],true)||strlen($cpu)<2)throw new \DomainException('The hosting server cannot supply a usable device identity. Contact the publisher.');
                return HardwareFingerprint::fromComponents($product,['machine'=>$host,'cpu'=>$cpu]);
            }
        },releaseSessionOnDestruct:false,applicationType:'web');
    }
    private function transport(string $method,string $path,?array $body,array $headers):array
    {
        if($this->offline)throw new \RuntimeException('Offline access experiment.');
        if($this->testTransport!==null)return ($this->testTransport)($method,$path,$body,$headers);
        if(!str_starts_with($path,'/api/v1/')||str_contains($path,'..'))throw new \RuntimeException('Invalid SDK route.');
        $curl=curl_init('https://licensing.kdpika.com'.$path);$lines=['Accept: application/json','Content-Type: application/json','User-Agent: KDPika-Sudoku-Studio/1.0'];
        foreach($headers as$name=>$value)$lines[]=$name.': '.$value;
        if(isset($_SERVER['HTTP_X_KDPIKA_DEPLOY_VERIFY']))$lines[]='X-KDPika-Deploy-Verify: '.$_SERVER['HTTP_X_KDPIKA_DEPLOY_VERIFY'];
        $raw='';$start=microtime(true);curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$lines,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>524288)return 0;$raw.=$chunk;return strlen($chunk);}]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        $decoded=json_decode($raw,true);$this->events[]=['method'=>$method,'path'=>$path,'status'=>$status,'duration_ms'=>(int)((microtime(true)-$start)*1000),'checked_at'=>gmdate('c'),'response'=>self::redact($decoded)];
        if($ok===false||!is_array($decoded))throw new \RuntimeException('The licensing service could not be reached.');
        return ['ok'=>$status>=200&&$status<300&&($decoded['success']??false)===true,'status'=>$status,'body'=>$decoded];
    }
    public static function redact(mixed $value):mixed
    {
        if(!is_array($value))return is_string($value)?preg_replace('/\b(?:act|flt|cps|cpt)_[A-Za-z0-9_-]+/','[hidden]',$value):$value;
        foreach($value as$key=>&$item){if(in_array(strtolower((string)$key),['activation_token','session_token','token','license_key','email','customer_email','authorization','password','secret','code','checkout_url'],true))$item='[hidden]';elseif($key==='payload_json')$item='[Exact signed bytes hidden; see the redacted payload. SDK verification uses the original bytes.]';else$item=self::redact($item);}unset($item);return $value;
    }
    private function online(LicenseClient $client):LicenseResult
    {
        $r=$client->validate();if(!$r->valid||!$r->signatureValid||$r->source!=='online')throw new CreditOperationException($r->errorCode??'ONLINE_REQUIRED');return $r;
    }
    private function summary(LicenseResult $r):array
    {
        $p=$r->payload;$floating=$p['floating']??null;
        return ['valid'=>$r->valid,'signature_verified'=>$r->signatureValid,'source'=>$r->source,'error_code'=>$r->errorCode,'request_id'=>$r->requestId,'checked_at'=>gmdate('c'),'license'=>array_intersect_key($p,array_flip(['license_id','license_key_mask','status','expires_at','metered','credit_balance','total_allocated','features','max_devices','max_activations','activation_count','active_devices','application_access','application_type','plan_details','next_validation_at','offline_grace_until'])),'floating'=>is_array($floating)?['limit'=>$floating['max_concurrent_sessions'],'heartbeat_seconds'=>$floating['heartbeat_interval_seconds'],'expires_at'=>$floating['session']['expires_at']??null]:null,'device_matching'=>$p['device']['fingerprint']['policy']??null,'profile'=>['app'=>$this->profile['app'],'environment'=>$this->profile['environment']]];
    }
    public function activate(string $key,?string $email,string $slot='main'):array
    {
        $r=$this->client($slot)->activate($key,$email);if(!$r->valid||!$r->signatureValid||$r->source!=='online')throw new CreditOperationException($r->errorCode??'LICENSE_NOT_ACTIVE');return ['state'=>$this->summary($r),'events'=>$this->events];
    }
    public function run(string $action,array $input=[]):array
    {
        $c=$this->client();$extra=[];$r=null;
        switch($action){
            case 'check':case 'reply':case 'generate':$r=$this->online($c);if($action==='generate')$extra['board']=self::board();break;
            case 'png':case 'jpg':case 'batch':
                $r=$this->online($c);$feature=$action==='png'?'png_export':($action==='batch'?'batch_generation':null);
                if($feature!==null&&!$r->feature($feature))throw new CreditOperationException('FEATURE_NOT_INCLUDED');
                if($action==='batch'){$extra['puzzles']=array_map(static fn()=>self::svg(self::board()),range(1,6));}else{$board=self::validateBoard($input['board']??null);$extra=['svg'=>self::svg($board),'format'=>$action];}break;
            case 'catalog':$r=$this->online($c);$extra=$c->creditPacks();break;
            case 'history':$r=$this->online($c);$extra=$c->creditHistory();break;
            case 'redeem':$extra=$c->redeemCredits((string)($input['code']??''),(string)($input['request_key']??''));$r=$this->online($c);break;
            case 'buy':$r=$this->online($c);$extra=$c->createCreditCheckout((string)($input['pack_id']??''));break;
            case 'pack':
                $r=$this->online($c);$job=$input['request_key']??null;$cost=$input['expected_units']??null;
                if(!is_string($job)||!preg_match('/\Asudoku-pack:[a-f0-9-]{36}\z/D',$job)||!is_int($cost)||$cost<1)throw new \DomainException('Confirm the current pack price and retry the same saved job.');
                if(!class_exists(\ZipArchive::class))throw new \DomainException('This server needs the PHP zip extension to create puzzle packs. No credits were charged.');
                $receipt=$c->consumeAction('create_pack',$job,$cost);$license=$r->payload['license_id'];$filename='pack-'.hash('sha256',$license.'|'.$job).'.zip';$destination=$this->root.'/'.$filename;
                if(!is_file($destination)){$tmp=tempnam($this->root,'puzzles-');$zip=new \ZipArchive();if($tmp===false||$zip->open($tmp,\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('The pack could not be prepared. Retry the same saved operation.');
                    for($i=1;$i<=10;$i++)$zip->addFromString('sudoku-'.$i.'.svg',self::svg(self::board(hash('sha256',$job.'|'.$i))));
                    if(!$zip->close()){unlink($tmp);throw new \RuntimeException('The pack could not be saved. Retry this operation.');}chmod($tmp,0600);if(!rename($tmp,$destination))throw new \RuntimeException('Retry this saved operation to recover the pack.');
                }
                $extra=['file'=>$filename,'consumed'=>$receipt->consumed,'replayed'=>$receipt->replayed,'balance'=>$c->getBalance()];$r=$this->online($c);break;
            case 'heartbeat':$r=$c->heartbeat();if(!$r->valid)throw new CreditOperationException($r->errorCode??'SESSION_EXPIRED');break;
            case 'checkin':$r=$c->checkin();break;
            case 'checkout':$r=$c->checkout();break;
            case 'offline':
                $this->online($c);$this->offline=true;try{$r=$c->validate();}finally{$this->offline=false;}break;
            case 'deactivate':$r=$c->deactivate();break;
            default:throw new \DomainException('Choose an available Sudoku Studio action.');
        }
        return ['state'=>$r?$this->summary($r):null,'extra'=>$extra,'events'=>$this->events];
    }
    public function download(string $file):string
    {
        if(!preg_match('/\Apack-[a-f0-9]{64}\.zip\z/D',$file)||!is_file($this->root.'/'.$file))throw new \DomainException('This download is unavailable in your app session.');return $this->root.'/'.$file;
    }
    public static function validateBoard(mixed $board):array
    {
        if(!is_array($board)||count($board)!==9)throw new \DomainException('Generate a puzzle first.');
        foreach($board as$row){if(!is_array($row)||count($row)!==9)throw new \DomainException('The puzzle is invalid.');foreach($row as$n)if(!is_int($n)||$n<0||$n>9)throw new \DomainException('The puzzle is invalid.');}return $board;
    }
    public static function board(?string $seed=null):array
    {
        $seed??=bin2hex(random_bytes(16));$digits=range(1,9);usort($digits,static fn($a,$b)=>strcmp(hash('sha256',$seed.'d'.$a),hash('sha256',$seed.'d'.$b)));
        $board=[];for($r=0;$r<9;$r++)for($c=0;$c<9;$c++)$board[$r][$c]=hexdec(substr(hash('sha256',$seed.'-'.$r.'-'.$c),0,2))%100<55?0:$digits[($r*3+intdiv($r,3)+$c)%9];return $board;
    }
    public static function svg(array $board):string
    {
        $board=self::validateBoard($board);$svg='<svg xmlns="http://www.w3.org/2000/svg" width="900" height="960" viewBox="0 0 900 960"><rect width="900" height="960" fill="#fff"/><text x="40" y="36" font-family="sans-serif" font-size="22" fill="#273046">KDPika Sudoku Studio</text>';
        for($r=0;$r<9;$r++)for($c=0;$c<9;$c++)if($board[$r][$c])$svg.='<text x="'.(50+$c*100).'" y="'.(125+$r*100).'" text-anchor="middle" font-family="sans-serif" font-size="45" fill="#202436">'.$board[$r][$c].'</text>';
        for($i=0;$i<=9;$i++){$width=$i%3===0?3:1;$pos=$i*100;$svg.='<path d="M'.$pos.' 60V960M0 '.(60+$pos).'H900" stroke="#414868" stroke-width="'.$width.'"/>';}
        return $svg.'</svg>';
    }
}
