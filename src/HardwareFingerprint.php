<?php
declare(strict_types=1);
namespace KDPika\LicenseSdk;

final class HardwareFingerprint
{
    private const NAMES=['baseboard','cpu','disk','nic','machine'];
    public static function fromComponents(string $product,array $raw,?bool $isVirtual=null):array
    {
        $salt=hash('sha256','kdpika-hardware-v1|'.$product);$parts=[];
        foreach($raw as$name=>$value)if(in_array($name,self::NAMES,true)&&($value=self::usable($value))!=='')$parts[$name]=hash('sha256',$salt.'|'.$name.'|'.$value);
        if(!array_diff(array_keys($parts),['cpu']))throw new CreditOperationException('HARDWARE_UNAVAILABLE');
        ksort($parts,SORT_STRING);return ['version'=>1,'hardware_id'=>self::combined($parts),'components'=>$parts,'is_virtual'=>$isVirtual];
    }
    private static function usable(mixed $value):string
    {
        if(!is_string($value))return '';$v=strtolower(trim((string)preg_replace('/\s+/u',' ',$value)));
        return $v===''||strlen($v)>1024||in_array($v,['unknown','none','default string','to be filled by o.e.m.','system serial number','not specified','not applicable'],true)||preg_match('/^[0f:\- ]+$/D',$v)?'':$v;
    }
    private static function combined(array $parts):string{ksort($parts,SORT_STRING);$v=[];foreach($parts as$k=>$value)$v[]=$k.'='.$value;return hash('sha256',implode("\n",$v));}
    private static function read(string $file):string{$v=@file_get_contents($file,false,null,0,65536);return is_string($v)?trim($v):'';}
    private static function run(string $exe,array $args):string
    {
        if(!function_exists('proc_open'))return '';$process=@proc_open(array_merge([$exe],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','a']],$pipes,null,null,['bypass_shell'=>true,'create_no_window'=>true]);if(!is_resource($process))return '';
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);$end=microtime(true)+4;$output='';$ok=false;
        try{while(microtime(true)<$end&&strlen($output)<=65536){$output.=stream_get_contents($pipes[1]);$status=proc_get_status($process);if(!$status['running']){$output.=stream_get_contents($pipes[1]);$ok=$status['exitcode']===0;break;}usleep(20000);}}finally{if(!$ok)proc_terminate($process);fclose($pipes[1]);proc_close($process);}
        return $ok&&strlen($output)<=65536?trim($output):'';
    }
    public static function collect(string $product):array
    {
        $raw=[];$model='';
        if(PHP_OS_FAMILY==='Windows'){
            $script='$ErrorActionPreference="SilentlyContinue";$b=(Get-CimInstance Win32_BaseBoard|Select-Object -First 1).SerialNumber;$c=(Get-CimInstance Win32_Processor|Select-Object -First 1).ProcessorId;$m=(Get-CimInstance Win32_ComputerSystemProduct|Select-Object -First 1).UUID;$d=(Get-Partition -DriveLetter $env:SystemDrive.Substring(0,1)|Get-Disk|Select-Object -First 1).SerialNumber;$n=(Get-NetAdapter -Physical|Where-Object Status -eq "Up"|Sort-Object InterfaceIndex|Select-Object -First 1).MacAddress;@{baseboard=$b;cpu=$c;machine=$m;disk=$d;nic=$n;model=(Get-CimInstance Win32_ComputerSystem).Model}|ConvertTo-Json -Compress';
            $data=json_decode(self::run('powershell.exe',['-NoLogo','-NoProfile','-NonInteractive','-Command',$script]),true)?:[];foreach(['baseboard','cpu','disk','nic']as$n)$raw[$n]=$data[$n]??'';if(self::usable($raw['baseboard'])==='')$raw['machine']=$data['machine']??'';$model=(string)($data['model']??'');
        }elseif(PHP_OS_FAMILY==='Darwin'){
            $registry=self::run('/usr/sbin/ioreg',['-rd1','-c','IOPlatformExpertDevice']);preg_match('/"IOPlatformUUID"\s*=\s*"([^"\n]+)"/',$registry,$m);$raw['baseboard']=$m[1]??'';$raw['cpu']=self::run('/usr/sbin/sysctl',['-n','machdep.cpu.brand_string'])?:self::run('/usr/sbin/sysctl',['-n','hw.model']);preg_match('/\bether\s+([0-9a-fA-F:]{17})/',self::run('/sbin/ifconfig',['en0']),$m);$raw['nic']=$m[1]??'';$model=$registry;
        }elseif(PHP_OS_FAMILY==='Linux'){
            $raw['baseboard']=self::read('/sys/class/dmi/id/board_serial')?:self::read('/sys/class/dmi/id/product_uuid');if(self::usable($raw['baseboard'])==='')$raw['machine']=self::read('/etc/machine-id')?:self::read('/var/lib/dbus/machine-id');preg_match('/^(?:model name|Hardware|Serial)\s*:\s*(.+)$/m',self::read('/proc/cpuinfo'),$m);$raw['cpu']=$m[1]??php_uname('m');
            foreach(glob('/sys/class/block/*')?:[]as$path){if(preg_match('/^(loop|ram|dm-|sr)/',basename($path))||is_file($path.'/partition'))continue;$s=self::read($path.'/device/serial');if(self::usable($s)!==''){$raw['disk']=$s;break;}}
            foreach(glob('/sys/class/net/*')?:[]as$path){if(!file_exists($path.'/device')||self::read($path.'/operstate')!=='up')continue;$s=self::read($path.'/address');if(self::usable($s)!==''){$raw['nic']=$s;break;}}$model=self::read('/sys/class/dmi/id/product_name').' '.self::read('/sys/class/dmi/id/sys_vendor');
        }else throw new CreditOperationException('HARDWARE_UNAVAILABLE');
        return self::fromComponents($product,$raw,trim($model)===''?null:(bool)preg_match('/vmware|virtualbox|virtual machine|qemu|kvm|parallels|xen/i',$model));
    }
    public static function matches(mixed $binding,array $current):bool
    {
        if(!is_array($binding)||($binding['version']??null)!==1||!is_array($binding['components']??null)||!$binding['components']||!is_array($binding['policy']??null))return false;$saved=$binding['components'];
        foreach($saved as$k=>$v)if(!in_array($k,self::NAMES,true)||!is_string($v)||!preg_match('/^[a-f0-9]{64}$/D',$v))return false;
        if(($binding['hardware_id']??'')!==self::combined($saved))return false;$p=$binding['policy'];$actual=$current['components']??[];
        if(($p['mode']??'')==='strict')return self::combined($saved)===self::combined($actual);
        if(($p['mode']??'')!=='fuzzy'||!is_int($p['min_matches']??null)||$p['min_matches']<1||$p['min_matches']>5)return false;
        foreach(['baseboard','cpu']as$k)if(isset($saved[$k])&&($actual[$k]??null)!==$saved[$k])return false;$matches=0;foreach($saved as$k=>$v)if(($actual[$k]??null)===$v)$matches++;
        return $matches>=min(count($saved),$p['min_matches']);
    }
}
