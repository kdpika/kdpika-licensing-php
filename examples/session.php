<?php
declare(strict_types=1);
// Sandbox session CLI: PHP8.2+, cURL, Composer-installed official SDK1.0.0.
// Run php session.php status|activate|check|validate|deactivate.
// Set KDP_APP_ID,KDP_STATE_DIR,KDP_SDK_DIR (extracted package path).
// Activation also uses KDP_LICENSE_KEY and optional KDP_CUSTOMER_EMAIL.
// Expected safe JSON; no raw payload/token. Never skip the validity guard.
use KDPika\LicenseSdk\LicenseClient;
function required(string $name):string{
    $value=trim((string)getenv($name));if($value==='')throw new RuntimeException('Missing configuration');return $value;
}
try{
    $action=$argc===2?$argv[1]:'';
    if(!in_array($action,['status','activate','check','validate','deactivate'],true))throw new RuntimeException('Choose an action');
    $base=rtrim(getenv('KDP_BASE_URL')?:'https://licensing.kdpika.com','/');$uri=parse_url($base);
    $local=is_array($uri)&&($uri['scheme']??'')==='http'&&in_array($uri['host']??'',['127.0.0.1','localhost'],true)&&getenv('KDP_ALLOW_LOOPBACK')==='1';
    if(!is_array($uri)||!isset($uri['host'])||isset($uri['user'])||isset($uri['pass'])||isset($uri['path'])||isset($uri['query'])||isset($uri['fragment'])||(($uri['scheme']??'')!=='https'&&!$local))throw new RuntimeException('Use an HTTPS site origin');
    require required('KDP_SDK_DIR').'/vendor/autoload.php';
    $client=new LicenseClient(product:required('KDP_APP_ID'),environment:'SANDBOX',baseUrl:$base,
        applicationName:'KDPika SDK session example',applicationVersion:'1.0.0',storageDir:required('KDP_STATE_DIR'));
    if($action==='status'){
        echo json_encode(['activated'=>$client->activated(),'storage_security'=>'permission-protected-file',
            'installation_hash'=>hash('sha256',$client->installationId())],JSON_THROW_ON_ERROR),PHP_EOL;exit;
    }
    if($action==='activate'){
        $key=required('KDP_LICENSE_KEY');if(!preg_match('/^TEST[A-Z0-9]*-/i',$key))throw new RuntimeException('Use a Sandbox key');
        $result=$client->activate($key,getenv('KDP_CUSTOMER_EMAIL')?:null);
    }else $result=$client->$action();
    $active=$action!=='deactivate'&&$result->valid&&$result->signatureValid;
    $limit=$active?$result->featureValue('seat_count'):null;
    echo json_encode(['valid'=>$result->valid,'source'=>$result->source,'error_code'=>$result->errorCode,
        'signature_valid'=>$result->signatureValid,'pdf_export_allowed'=>$active&&$result->feature('pdf_export'),
        'seat_count'=>is_int($limit)&&$limit>=0?$limit:null,
        'request_id'=>preg_match('/^req_[a-f0-9]{24}$/D',$result->requestId??'')?$result->requestId:null,
        'message'=>$action==='deactivate'&&$result->valid?'This installation is deactivated.':($result->valid?'Your license is ready.':'Your license could not be confirmed. Check your key or contact the software publisher.')],JSON_THROW_ON_ERROR),PHP_EOL;
    exit($result->valid?0:1);
}catch(Throwable){
    fwrite(STDERR,'License check unavailable. Review configuration, connectivity and private storage permissions. Do not disable signature verification.'.PHP_EOL);exit(2);
}
