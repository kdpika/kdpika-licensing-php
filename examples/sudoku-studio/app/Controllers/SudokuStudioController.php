<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Config,Request,Response};
use App\Services\{SecretBox,RateLimiter,SudokuStudioService};
use KDPika\LicenseSdk\CreditOperationException;

/** A separate customer app session: never signs into or redirects to the platform account. */
final class SudokuStudioController
{
    public static function handles(string $path):bool{return in_array($path,['/sudoku-studio','/sudoku-studio/action','/sudoku-studio/download'],true);}
    private function start():void
    {
        header('Cache-Control: private, no-store, max-age=0');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
        ini_set('session.use_strict_mode','1');session_name('kdpika_sudoku');session_set_cookie_params(['lifetime'=>0,'path'=>'/sudoku-studio','secure'=>(bool)Config::get('app.session_secure',true),'httponly'=>true,'samesite'=>'Lax']);session_start();
        if((int)($_SESSION['seen']??time())<time()-7200){$_SESSION=[];session_regenerate_id(true);}$_SESSION['seen']=time();$_SESSION['csrf']??=bin2hex(random_bytes(32));
        if(!isset($_COOKIE['kdpika_sudoku_device'])||!preg_match('/\A[a-f0-9]{64}\z/D',(string)$_COOKIE['kdpika_sudoku_device'])){$_COOKIE['kdpika_sudoku_device']=bin2hex(random_bytes(32));setcookie('kdpika_sudoku_device',$_COOKIE['kdpika_sudoku_device'],['expires'=>time()+2592000,'path'=>'/sudoku-studio','secure'=>(bool)Config::get('app.session_secure',true),'httponly'=>true,'samesite'=>'Lax']);}
    }
    private function service(array $profile,?string $key=null):SudokuStudioService
    {
        $binding=$key===null?($_SESSION['binding']??''):hash_hmac('sha256',strtoupper(trim($key)),(string)Config::get('app.license_pepper'));
        $identity=hash_hmac('sha256',$_COOKIE['kdpika_sudoku_device'].'|'.$profile['app'].'|'.$profile['environment'].'|'.$binding,(string)Config::get('app.license_pepper'));
        return new SudokuStudioService($profile,base_path('storage/sudoku-studio/'.$identity));
    }
    public function serve(string $path):never
    {
        $this->start();$request=new Request();$api=$path==='/sudoku-studio/action';
        try{
            if(($path==='/sudoku-studio/action'&&$request->method()!=='POST')||($path!=='/sudoku-studio/action'&&$request->method()!=='GET')){header('Allow: '.($api?'POST':'GET'));http_response_code(405);exit;}
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)Response::json(['success'=>false,'message'=>'This request is too large.'],413);
            if($path==='/sudoku-studio'){$token=$_SESSION['csrf'];$signedIn=isset($_SESSION['profile'],$_SESSION['credentials']);require base_path('resources/views/sudoku/index.php');exit;}
            if(!$api){if(!isset($_SESSION['profile']))throw new \DomainException('Open your app before downloading.');$file=$this->service($_SESSION['profile'])->download((string)$request->input('file'));header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="sudoku-studio-pack.zip"');readfile($file);exit;}
            $csrf=$request->header('X-CSRF-Token');if(!is_string($csrf)||!hash_equals($_SESSION['csrf'],$csrf))Response::json(['success'=>false,'message'=>'Refresh the app page before trying again.'],419);
            $action=$request->input('action');if(!is_string($action))throw new \DomainException('Choose an app action.');
            if(!(new RateLimiter())->hit('sudoku-action:'.hash('sha256',$request->ip().'|'.session_id()),90,60)['allowed'])Response::json(['success'=>false,'code'=>'RATE_LIMITED','message'=>'Please wait a minute before trying again.'],429,['Retry-After'=>'60']);
            if($action==='login'){
                foreach(['app','key','email','environment']as$field)if(!is_string($request->input($field,'')))throw new \DomainException('Check your sign-in fields.');
                $key=trim($request->input('key'));$email=trim($request->input('email',''));$app=trim($request->input('app'));$environment=$request->input('environment');
                if(strlen($key)<8||strlen($key)>255||strlen($email)>254||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)))throw new \DomainException('Enter your key and, if selected, its matching email.');
                foreach(['ip:'.$request->ip(),'key:'.hash('sha256',$key)]as$bucket)if(!(new RateLimiter())->hit('sudoku-login:'.hash('sha256',$bucket),8,900)['allowed'])Response::json(['success'=>false,'code'=>'RATE_LIMITED','message'=>'Too many sign-in attempts. Wait a while and check your key, App ID and environment.'],429,['Retry-After'=>'900']);
                $profile=['app'=>$app,'environment'=>$environment];$service=$this->service($profile,$key);$answer=$service->activate($key,$email?:null);
                session_regenerate_id(true);$_SESSION['profile']=$profile;$_SESSION['binding']=hash_hmac('sha256',strtoupper($key),(string)Config::get('app.license_pepper'));$_SESSION['credentials']=(new SecretBox())->encrypt(json_encode(['key'=>$key,'email'=>$email],JSON_THROW_ON_ERROR));$_SESSION['csrf']=bin2hex(random_bytes(32));
                Response::json(['success'=>true,'csrf'=>$_SESSION['csrf']]+$answer);
            }
            if(!isset($_SESSION['profile'],$_SESSION['credentials']))Response::json(['success'=>false,'code'=>'SIGN_IN_REQUIRED','message'=>'Open Sudoku Studio with your software license. No platform account is needed.'],401);
            $service=$this->service($_SESSION['profile']);
            if(in_array($action,['second_seat','release_second'],true)){
                if($request->input('confirmed')!==true)throw new \DomainException('Confirm before opening or releasing a second test installation.');
                if($action==='second_seat'){$credentials=json_decode((new SecretBox())->decrypt($_SESSION['credentials']),true,8,JSON_THROW_ON_ERROR);$answer=$service->activate($credentials['key'],$credentials['email']?:null,'second');}
                else{$r=$service->client('second')->deactivate();$answer=['released'=>$r->valid];}
            }elseif($action==='logout'){
                foreach(['main','second']as$slot){try{$service->client($slot)->close();}catch(\Throwable){}}unset($_SESSION['profile'],$_SESSION['binding'],$_SESSION['credentials']);session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));$answer=['signed_out'=>true];
            }else{$answer=$service->run($action,$request->all());if(isset($answer['extra']['file']))$answer['extra']['download']='/sudoku-studio/download?file='.rawurlencode($answer['extra']['file']);if($action==='deactivate')unset($_SESSION['profile'],$_SESSION['credentials'],$_SESSION['binding']);}
            Response::json(['success'=>true,'csrf'=>$_SESSION['csrf']]+$answer);
        }catch(CreditOperationException $e){$messages=[
            'INSUFFICIENT_CREDITS'=>'You need more credits for this pack. Included tools still work. Redeem a code or choose a top-up; retry an unfinished pack with its original reference.',
            'CREDIT_CODE_UNAVAILABLE'=>'This code cannot be used for this license. Check its spelling, product and Test/Live choice. It may be expired or already used.',
            'CREDIT_PRICE_CHANGED'=>'The price changed. Refresh the pack cost and confirm it before trying again. This request did not charge credits.',
            'CREDIT_ACTION_UNAVAILABLE'=>'The publisher needs to enable create_pack in Usage credits → Settings. You can still use included tools.',
            'CREDIT_REVIEW_REQUIRED'=>'A previous credit purchase needs the publisher’s review. Included app features remain available.',
            'APPLICATION_NOT_ALLOWED'=>'This license permits desktop access only. Choose a Web or Desktop + Web software plan.',
            'FEATURE_NOT_INCLUDED'=>'This tool is not included in your software plan. Ask the publisher about a plan that includes it.',
            'ALL_SEATS_IN_USE'=>'All shared seats are in use. Close the app on another installation or release a stuck seat in your customer portal.',
            'SESSION_REQUIRED'=>'Reserve a shared seat before using the app.', 'SESSION_EXPIRED'=>'Your shared seat expired. Choose Reserve seat to reconnect.',
            'LICENSE_EXPIRED'=>'This license expired. Renew software access with the publisher. Purchased credits are retained.',
            'INVALID_LICENSE'=>'Check the key, App ID, Test/Live selection and matching email (if entered).',
            'DEVICE_LIMIT_REACHED'=>'This key has no free device slots. Release an old device or ask the publisher to increase its limit.',
            'ONLINE_REQUIRED'=>'Connect to the internet and check access again. A saved reply does not prove current access.',
            'NETWORK_ERROR'=>'The licensing service could not be reached. Keep your saved operation and retry it; do not pay a second time.'
        ];Response::json(['success'=>false,'code'=>$e->errorCode,'message'=>$messages[$e->errorCode]??'This action could not be completed ('.$e->errorCode.'). Check access or contact the publisher.'],422);
        }catch(\DomainException $e){Response::json(['success'=>false,'message'=>$e->getMessage()],422);}
        catch(\Throwable){Response::json(['success'=>false,'code'=>'TEMPORARILY_UNAVAILABLE','message'=>'The app could not finish this request. Keep the same pending operation and retry. Do not make a second purchase to recover it.'],503);}
    }
}
