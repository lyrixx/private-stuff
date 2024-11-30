<?php

namespace crypto;

use Castor\Attribute\AsTask;

use function Castor\finder;
use function Castor\variable;

#[AsTask(description: 'Encrypt a directory', aliases: ['encrypt'])]
function encrypt(string $directory): void
{
    if (!is_dir($directory)) {
        throw new \RuntimeException('The directory does not exist');
    }

    if (variable('defaultPassword')) {
        throw new \RuntimeException('You cannot encrypt data with the default password');
    }

    $password = variable('PASSWORD');
    if (\strlen($password) < 14) {
        throw new \RuntimeException('The password must be at least 14 characters long.');
    }

    $salt = random_bytes(\SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $key = sodium_crypto_pwhash(
        \SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        $password,
        $salt,
        \SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
        \SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
    );
    $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $files = finder()
        ->in($directory)
        ->files()
    ;
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $encrypted = sodium_crypto_secretbox($content, $nonce, $key);
        $encryptedData = $salt . $nonce . $encrypted;
        $encoded = base64_encode($encryptedData);
        file_put_contents($file, $encoded);
        sodium_memzero($content);
    }

    sodium_memzero($password);
    sodium_memzero($key);
}

#[AsTask(description: 'Decrypt a directory', aliases: ['decrypt'])]
function decrypt(string $directory): void
{
    if (!is_dir($directory)) {
        throw new \RuntimeException('The directory does not exist.');
    }

    $files = finder()
        ->in($directory)
        ->files()
    ;

    $password = variable('PASSWORD');

    foreach ($files as $file) {
        $encryptedData = file_get_contents($file);
        $decoded = base64_decode($encryptedData);
        $salt = substr($decoded, 0, \SODIUM_CRYPTO_PWHASH_SALTBYTES);
        if (\SODIUM_CRYPTO_PWHASH_SALTBYTES !== \strlen($salt)) {
            throw new \RuntimeException(\sprintf('Failed to decrypt the file "%s". Impossible to extract salt.', $file));
        }
        $nonce = substr($decoded, \SODIUM_CRYPTO_PWHASH_SALTBYTES, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        if (\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== \strlen($nonce)) {
            throw new \RuntimeException(\sprintf('Failed to decrypt the file "%s". Impossible to extract nonce', $file));
        }
        $cipherText = substr($decoded, \SODIUM_CRYPTO_PWHASH_SALTBYTES + \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = sodium_crypto_pwhash(
            \SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $password,
            $salt,
            \SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            \SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );

        $decrypted = sodium_crypto_secretbox_open($cipherText, $nonce, $key);

        if (false === $decrypted) {
            throw new \RuntimeException(\sprintf('Failed to decrypt the file "%s".', $file));
        }

        file_put_contents($file, $decrypted);
        sodium_memzero($decrypted);
        sodium_memzero($key);
    }

    sodium_memzero($password);
}
