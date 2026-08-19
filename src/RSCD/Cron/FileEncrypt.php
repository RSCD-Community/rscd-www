<?php

/**
 * Standalone file encryption script — intentionally bypasses the app boot.
 *
 * Called directly by deploy.sh (not through index.php) to avoid the app
 * trying to parse app.json as config before it has been processed.
 *
 * Usage:
 *   php src/RSCD/Cron/FileEncrypt.php \
 *       --params=/path/to/encrypter-params.json \
 *       --file=/absolute/path/to/app/app.json
 */

require __DIR__ . '/../../../vendor/autoload.php';

use RSCD\Model\Encoder\Hexadecimal;
use RSCD\Model\Encrypter\OpenSSLAES;

$paramsFile = null;
$targetFile = null;

foreach($argv as $arg) {
    if(strpos($arg, '--params=') === 0) {
        $paramsFile = substr($arg, strlen('--params='));
    }
    elseif(strpos($arg, '--file=') === 0) {
        $targetFile = substr($arg, strlen('--file='));
    }
}

if(empty($paramsFile)) {
    fwrite(STDERR, '[FileEncrypt] ERROR: --params=<path> is required' . PHP_EOL);
    exit(1);
}
if(empty($targetFile)) {
    fwrite(STDERR, '[FileEncrypt] ERROR: --file=<path> is required' . PHP_EOL);
    exit(1);
}
if(!file_exists($paramsFile)) {
    fwrite(STDERR, '[FileEncrypt] ERROR: params file not found: ' . $paramsFile . PHP_EOL);
    exit(1);
}

$params = json_decode(file_get_contents($paramsFile));
if(empty($params)) {
    fwrite(STDERR, '[FileEncrypt] ERROR: unable to parse params file: ' . $paramsFile . PHP_EOL);
    exit(1);
}
if(!file_exists($targetFile)) {
    fwrite(STDERR, '[FileEncrypt] ERROR: target file not found: ' . $targetFile . PHP_EOL);
    exit(1);
}

$plaintext = file_get_contents($targetFile);
if($plaintext === false) {
    fwrite(STDERR, '[FileEncrypt] ERROR: unable to read target file: ' . $targetFile . PHP_EOL);
    exit(1);
}

$encrypter = new OpenSSLAES($params);
$encoder   = new Hexadecimal();

$encrypted = $encrypter->encrypt($plaintext);
if($encrypted === null) {
    fwrite(STDERR, '[FileEncrypt] ERROR: encryption returned null — check params (cipher/padding/key/iv)' . PHP_EOL);
    exit(1);
}
$encoded = $encoder->encode($encrypted);
if($encoded === null) {
    fwrite(STDERR, '[FileEncrypt] ERROR: encoding returned null' . PHP_EOL);
    exit(1);
}

$written = file_put_contents($targetFile, $encoded);
if($written === false) {
    fwrite(STDERR, '[FileEncrypt] ERROR: unable to write encrypted content to: ' . $targetFile . PHP_EOL);
    exit(1);
}

echo '[FileEncrypt] Encrypted ' . basename($targetFile) . ' (' . strlen($plaintext) . ' bytes → ' . $written . ' bytes)' . PHP_EOL;
