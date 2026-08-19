<?php

/**
 * Standalone file decryption script — intentionally bypasses the app boot.
 *
 * Called directly by aws-deploy.sh (not through index.php) because app.json
 * is still encrypted at this point and the normal cron path would try to
 * parse it as config, which would fail.
 *
 * Usage:
 *   php src/RSCD/Cron/FileDecrypt.php \
 *       --params=/path/to/encrypter-params.json \
 *       --file=/path/to/rscd-community/app/app.json
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
    fwrite(STDERR, '[FileDecrypt] ERROR: --params=<path> is required' . PHP_EOL);
    exit(1);
}
if(empty($targetFile)) {
    fwrite(STDERR, '[FileDecrypt] ERROR: --file=<path> is required' . PHP_EOL);
    exit(1);
}
if(!file_exists($paramsFile)) {
    fwrite(STDERR, '[FileDecrypt] ERROR: params file not found: ' . $paramsFile . PHP_EOL);
    exit(1);
}

$params = json_decode(file_get_contents($paramsFile));
if(empty($params)) {
    fwrite(STDERR, '[FileDecrypt] ERROR: unable to parse params file: ' . $paramsFile . PHP_EOL);
    exit(1);
}
if(!file_exists($targetFile)) {
    fwrite(STDERR, '[FileDecrypt] ERROR: target file not found: ' . $targetFile . PHP_EOL);
    exit(1);
}

$ciphertext = file_get_contents($targetFile);
if($ciphertext === false) {
    fwrite(STDERR, '[FileDecrypt] ERROR: unable to read target file: ' . $targetFile . PHP_EOL);
    exit(1);
}

$encrypter = new OpenSSLAES($params);
$encoder   = new Hexadecimal();

$decoded = $encoder->decode($ciphertext);
if($decoded === null) {
    fwrite(STDERR, '[FileDecrypt] ERROR: hex decode returned null — file may not be encrypted' . PHP_EOL);
    exit(1);
}
$plaintext = $encrypter->decrypt($decoded);
if($plaintext === null) {
    fwrite(STDERR, '[FileDecrypt] ERROR: decryption returned null — check params (cipher/padding/key/iv)' . PHP_EOL);
    exit(1);
}

$written = file_put_contents($targetFile, $plaintext);
if($written === false) {
    fwrite(STDERR, '[FileDecrypt] ERROR: unable to write plaintext to: ' . $targetFile . PHP_EOL);
    exit(1);
}

echo '[FileDecrypt] Decrypted ' . basename($targetFile) . ' (' . strlen($ciphertext) . ' bytes → ' . $written . ' bytes)' . PHP_EOL;
