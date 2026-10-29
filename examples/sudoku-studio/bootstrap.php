<?php
/** Small standalone example runtime. Contains no platform database or management credentials. */
declare(strict_types=1);
namespace {
    const BASE_PATH=__DIR__;
    function base_path(string $path=''):string{return BASE_PATH.($path!==''?'/'.ltrim($path,'/'):'');}
    function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    function asset_version(string $path):string{$path=str_replace('assets/sudoku/','public/assets/sudoku/',$path);return(string)(@filemtime(base_path($path))?:'1');}
    $private=base_path('storage');if(!is_dir($private)&&!mkdir($private,0700,true))throw new \RuntimeException('The app storage folder must be writable.');
    $lock=fopen($private.'/setup.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('The app could not initialize private storage.');
    if(!is_file($private.'/app-secret')){file_put_contents($private.'/app-secret',base64_encode(random_bytes(32)));chmod($private.'/app-secret',0600);}flock($lock,LOCK_UN);fclose($lock);
    spl_autoload_register(static function(string $class):void{
        foreach(['KDPika\\LicenseSdk\\'=>'sdk/src/','App\\'=>'app/']as$prefix=>$folder)if(str_starts_with($class,$prefix)){$relative=substr($class,strlen($prefix));if(!preg_match('/\A[A-Za-z\\\\]+\z/D',$relative))return;$path=base_path($folder.str_replace('\\','/',$relative).'.php');if(is_file($path))require_once $path;return;}
    });date_default_timezone_set('UTC');
}
namespace App\Core {
    final class Config {
        public static function get(string $key,mixed $default=null):mixed{return match($key){'app.license_pepper'=>trim(file_get_contents(\base_path('storage/app-secret'))),'app.session_secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off',default=>$default};}
    }
    final class Request {
        private ?array $body=null;
        public function method():string{return strtoupper($_SERVER['REQUEST_METHOD']??'GET');}
        public function all():array{if($this->body===null){$data=str_contains($_SERVER['CONTENT_TYPE']??'','application/json')?json_decode(file_get_contents('php://input'),true):array_merge($_GET,$_POST);$this->body=is_array($data)?$data:[];}return$this->body;}
        public function input(string $key,mixed $default=null):mixed{return$this->all()[$key]??$default;}
        public function header(string $key):?string{return $_SERVER['HTTP_'.strtoupper(str_replace('-','_',$key))]??null;}
        public function ip():string{return $_SERVER['REMOTE_ADDR']??'unknown';}
    }
    final class Response {
        public static function json(array $data,int $status=200,array $headers=[]):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');foreach($headers as$name=>$value)header($name.': '.$value);echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;}
    }
}
namespace App\Services {
    final class LicenseLabService {public static function loadSdk():void{}}
    final class SecretBox {
        private function key():string{return base64_decode(trim(file_get_contents(\base_path('storage/app-secret'))),true);}
        public function encrypt(string $value):string{$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return base64_encode($nonce.sodium_crypto_secretbox($value,$nonce,$this->key()));}
        public function decrypt(string $value):string{$raw=base64_decode($value,true);if($raw===false||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new \RuntimeException('Sign in again.');$plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->key());if($plain===false)throw new \RuntimeException('Sign in again.');return $plain;}
    }
    final class RateLimiter {
        public function hit(string $key,int $limit,int $seconds):array{
            $folder=\base_path('storage/rate-limits');if(!is_dir($folder))@mkdir($folder,0700,true);$file=$folder.'/'.hash('sha256',$key).'.json';$h=fopen($file,'c+');if(!$h||!flock($h,LOCK_EX))throw new \RuntimeException('Rate limiting is unavailable.');
            try{$data=json_decode(stream_get_contents($h),true);$now=time();if(!is_array($data)||($data['reset_at']??0)<=$now)$data=['count'=>0,'reset_at'=>$now+$seconds];$data['count']++;ftruncate($h,0);rewind($h);fwrite($h,json_encode($data,JSON_THROW_ON_ERROR));fflush($h);chmod($file,0600);return ['allowed'=>$data['count']<=$limit,'reset_at'=>$data['reset_at']];}finally{flock($h,LOCK_UN);fclose($h);}
        }
    }
}
