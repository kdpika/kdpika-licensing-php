<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/LicenseResult.php';
require dirname(__DIR__) . '/src/LicenseClient.php';

use KDPika\LicenseSdk\LicenseClient;

$temporary = sys_get_temp_dir() . '/kdpika-php-sdk-' . bin2hex(random_bytes(5));
$now = new DateTimeImmutable('2026-10-10T12:00:00+00:00');
$keypair = sodium_crypto_sign_keypair();
$public = sodium_crypto_sign_publickey($keypair);
$secret = sodium_crypto_sign_secretkey($keypair);
$calls = [];
$mode = 'online';

$b64 = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$sort = function (mixed $value) use (&$sort): mixed { if (!is_array($value)) return $value; foreach ($value as $key => $item) $value[$key] = $sort($item); if (!array_is_list($value)) ksort($value, SORT_STRING); return $value; };
$signed = function (string $installationId) use (&$now, $secret, $b64, $sort): array {
    $payload = ['license_id'=>'lic_test','product_id'=>'app_test','product'=>'Test','plan'=>'pro','status'=>'active','environment'=>'sandbox','expires_at'=>$now->modify('+30 days')->format(DATE_ATOM),'features'=>['pdf_export'=>true,'seat_count'=>5],'device'=>['id'=>'dev_test','installation_hash'=>hash('sha256',$installationId),'name'=>'Test','platform'=>'test','app_version'=>'1.0'],'server_time'=>$now->format(DATE_ATOM),'next_validation_at'=>$now->modify('+24 hours')->format(DATE_ATOM),'offline_grace_until'=>$now->modify('+7 days')->format(DATE_ATOM)];
    $canonical = json_encode($sort($payload), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    return ['payload'=>$payload,'signature'=>$b64(sodium_crypto_sign_detached($canonical,$secret)),'algorithm'=>'Ed25519','key_id'=>'ed25519_test'];
};
$transport = function (string $method, string $path, ?array $body, array $headers) use (&$calls, &$mode, $signed, $public, $b64): array {
    $calls[] = compact('method','path','body','headers');
    if ($mode === 'network' && $path === '/api/v1/licenses/validate') throw new RuntimeException('offline');
    if ($path === '/api/v1/public-key') return ['ok'=>true,'status'=>200,'body'=>['success'=>true,'key'=>['algorithm'=>'Ed25519','key_id'=>'ed25519_test','public_key'=>$b64($public)]]];
    if ($path === '/api/v1/licenses/activate') return ['ok'=>true,'status'=>200,'body'=>['success'=>true,'activation_token'=>'act_test_token','license'=>$signed($body['device_id']),'request_id'=>'req_activate']];
    if ($path === '/api/v1/licenses/validate') return ['ok'=>true,'status'=>200,'body'=>['success'=>true,'license'=>$signed($headers['X-Installation-ID']),'request_id'=>'req_validate']];
    if ($path === '/api/v1/licenses/deactivate') return ['ok'=>true,'status'=>200,'body'=>['success'=>true,'deactivated'=>true,'request_id'=>'req_deactivate']];
    throw new RuntimeException('Unexpected request.');
};

$client = new LicenseClient('app_test','SANDBOX','https://licenses.example.test','PHP SDK Test','1.0',null,$temporary,15,$transport,function () use (&$now): DateTimeImmutable { return $now; });
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$result = $client->activate('TEST-AAAA-BBBB-CCCC','customer@example.test');
$assert($result->valid && $result->signatureValid && $result->feature('pdf_export'), 'Activation/signature/features failed.');
$before = count($calls); $cached = $client->check(); $assert($cached->valid && $cached->source === 'cache' && count($calls) === $before, 'Verified cache was not used.');
$now = $now->modify('+25 hours'); $mode = 'network'; $offline = $client->check(); $assert($offline->valid && $offline->source === 'offline', 'Offline grace failed: ' . json_encode($offline));
$now = $now->modify('+8 days'); $expired = $client->validate(); $assert(!$expired->valid && $expired->errorCode === 'OFFLINE_GRACE_EXPIRED', 'Expired grace was accepted.');
$mode = 'online'; $now = new DateTimeImmutable('2026-10-10T12:00:00+00:00'); $deactivated = $client->deactivate(); $assert($deactivated->valid && !$client->activated(), 'Deactivation did not clear local state.');
echo "PHP SDK parity: passed\n";
