<?php

namespace app;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsTask;
use Castor\Context;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

use function Castor\context;
use function Castor\finder;
use function Castor\fs;
use function Castor\io;
use function Castor\load_dot_env;
use function Castor\open;
use function Castor\run;
use function Castor\variable;
use function Castor\yaml_parse;

#[AsTask(description: 'Install the dependencies')]
function install(): void
{
    io()->title('Installing dependencies');

    run(['npm', 'install', '--frozen-lockfile']);
}

#[AsTask(description: 'Build the project', aliases: ['build'])]
function build(bool $noOpen = false): void
{
    if (!is_dir(__DIR__.'/node_modules')) {
        install();
    }

    io()->title('Building the project');

    if (!is_file(__DIR__.'/data/websites.yaml')) {
        io()->warning('No websites data found, copying the default one');

        fs()->copy(__DIR__.'/data/websites.yaml.dist', __DIR__.'/data/websites.yaml');
    }

    $twig = new Environment(new ArrayLoader([
        'index.html.twig' => file_get_contents(__DIR__.'/src/index.html.twig'),
    ]));

    $raw = $twig->render('index.html.twig', [
        'websites' => yaml_parse(file_get_contents(__DIR__.'/data/websites.yaml')),
    ]);

    file_put_contents(__DIR__.'/src/index.html', "<!-- This page has been generated using Twig templating engine. -->\n".$raw);

    $password = variable('PASSWORD');
    $defaultPassword = variable('defaultPassword');

    if ($defaultPassword) {
        io()->warning('Using the default password. Set the PASSWORD environment variable to change it.');
    }

    run(
        command: [
            __DIR__.'/node_modules/.bin/staticrypt',
            '--config', 'false', // No need to store the salt, remember me is disabled
            '--template-color-primary', '#2af598',
            '--template-color-secondary', '#101820',
            '--template-title', 'Secret Box',
            '--template-instructions', $defaultPassword ? 'Try "pass"' : '', // Empty on purpose when real password
            '--template-button', 'Open',
            '--remember', 'false', // Since data are sensitive, we don't want to remember the password
            '-d', 'build',
            ...($defaultPassword ? ['--short'] : []),
            'src/index.html',
        ],
        context: context()
            ->withEnvironment([
                'STATICRYPT_PASSWORD' => $password,
            ])
    );

    io()->success('Project built');

    if (!$noOpen) {
        openBuild();
    }
}

#[AsTask('open', description: 'Open the build in the browser')]
function openBuild(): void
{
    open(__DIR__.'/build/index.html');
}

#[AsTask(description: 'Open the source in the browser')]
function open_source(): void
{
    if (!is_file(__DIR__.'/src/index.html')) {
        throw new \RuntimeException('The source file does not exist yet. Please built the project first.');
    }

    open(__DIR__.'/src/index.html');
}

#[AsTask(description: 'Format the PHP code', aliases: ['cs'])]
function php_cs(): void
{
    run(['php-cs-fixer', 'fix', 'castor.php', '--rules=@PhpCsFixer']);
}

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
    if (strlen($password) < 14) {
        throw new \RuntimeException('The password must be at least 14 characters long.');
    }

    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $key = sodium_crypto_pwhash(
        SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        $password,
        $salt,
        SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
        SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
    );
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $files = finder()
        ->in($directory)
        ->files()
    ;
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $encrypted = sodium_crypto_secretbox($content, $nonce, $key);
        $encryptedData = $salt.$nonce.$encrypted;
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
        $salt = substr($decoded, 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        if (SODIUM_CRYPTO_PWHASH_SALTBYTES !== strlen($salt)) {
            throw new \RuntimeException(sprintf('Failed to decrypt the file "%s". Impossible to extract salt.', $file));
        }
        $nonce = substr($decoded, SODIUM_CRYPTO_PWHASH_SALTBYTES, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        if (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== strlen($nonce)) {
            throw new \RuntimeException(sprintf('Failed to decrypt the file "%s". Impossible to extract nonce', $file));
        }
        $cipherText = substr($decoded, SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );

        $decrypted = sodium_crypto_secretbox_open($cipherText, $nonce, $key);

        if (false === $decrypted) {
            throw new \RuntimeException(sprintf('Failed to decrypt the file "%s".', $file));
        }

        file_put_contents($file, $decrypted);
        sodium_memzero($decrypted);
        sodium_memzero($key);
    }

    sodium_memzero($password);
}

#[AsContext()]
function create_context(): Context
{
    $data = load_dot_env();
    $data['PASSWORD'] ?? throw new \RuntimeException('The PASSWORD environment variable is required');
    $data['defaultPassword'] = 'pass' === $data['PASSWORD'];

    return new Context($data);
}
