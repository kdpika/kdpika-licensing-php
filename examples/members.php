<?php
declare(strict_types=1);

// Local model/member exercise; no real token, network request or access grant.
// KDP_SDK_DIR points to the extracted SDK with its Composer vendor directory.
use KDPika\LicenseSdk\LicenseClient;
use KDPika\LicenseSdk\LicenseResult;

$sdk = getenv('KDP_SDK_DIR');
if (!$sdk || !is_file($sdk . '/vendor/autoload.php')) {
    fwrite(STDERR, "Set KDP_SDK_DIR to the installed source package.\n"); exit(2);
}
require $sdk . '/vendor/autoload.php';
$covered = [];
$check = static function (string $member, bool $condition) use (&$covered): void {
    if (!$condition) throw new RuntimeException('Member example failed: ' . $member);
    $covered[] = $member;
};
$root = sys_get_temp_dir() . '/kdpika-members-' . bin2hex(random_bytes(12));
if (!mkdir($root, 0700)) throw new RuntimeException('Private scratch directory unavailable.');
$client = new LicenseClient('YOUR_APP_ID', 'SANDBOX', storageDir: $root);
$check('LicenseClient.__construct', $client instanceof LicenseClient);
$check('LicenseClient.installationId', str_starts_with($client->installationId(), 'lmp-install-'));
$check('LicenseClient.activated', !$client->activated());
$check('LicenseClient.activate', $client->activate('')->errorCode === 'INVALID_LICENSE');
$check('LicenseClient.check', $client->check()->errorCode === 'NOT_ACTIVATED');
$check('LicenseClient.validate', $client->validate()->errorCode === 'NOT_ACTIVATED');
$check('LicenseClient.deactivate', $client->deactivate()->errorCode === 'NOT_ACTIVATED');
$check('LicenseClient.feature', $client->feature('pdf_export') === false);
// A constructed result is NOT a signature verification or license decision.
$payload = ['features' => ['pdf_export' => true, 'seat_count' => 2]];
$result = new LicenseResult(false, 'example', $payload, 'EXAMPLE_ONLY', 'Example', 'req_example', false, 'unknown');
$check('LicenseResult.__construct', $result instanceof LicenseResult);
$expected = ['valid' => false, 'source' => 'example', 'payload' => $payload, 'errorCode' => 'EXAMPLE_ONLY',
    'message' => 'Example', 'requestId' => 'req_example', 'signatureValid' => false, 'storageSecurity' => 'unknown'];
foreach ($expected as $name => $value) $check('LicenseResult.' . $name, $result->$name === $value);
$check('LicenseResult.feature', $result->feature('pdf_export') && !$result->feature('missing'));
$check('LicenseResult.featureValue', $result->featureValue('seat_count') === 2 && $result->featureValue('missing', 7) === 7);
$check('application.fail_closed', !($result->valid && $result->signatureValid && $result->feature('pdf_export')));
// Only this newly created scratch directory is cleaned; no user state is read.
foreach (new DirectoryIterator($root) as $directory) {
    if ($directory->isDot()) continue;
    foreach (new DirectoryIterator($directory->getPathname()) as $file) {
        if (!$file->isDot()) unlink($file->getPathname());
    }
    rmdir($directory->getPathname());
}
rmdir($root);
echo json_encode(['result' => 'PASS', 'members' => $covered, 'mode' => 'synthetic local objects; no HTTP', 'access_granted' => false], JSON_THROW_ON_ERROR) . PHP_EOL;
