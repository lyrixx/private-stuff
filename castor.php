<?php

namespace app;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsTask;
use Castor\Context;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function Castor\context;
use function Castor\finder;
use function Castor\fs;
use function Castor\io;
use function Castor\load_dot_env;
use function Castor\open;
use function Castor\run;
use function Castor\variable;
use function Castor\watch;
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

    if (!variable('TEST') && variable('defaultPassword')) {
        io()->warning('Using the default password. Set the PASSWORD environment variable to change it, or use a `.env.local` file.');
    }

    fs()->remove(__DIR__.'/dist');
    fs()->mkdir(__DIR__.'/dist/public');
    fs()->remove(__DIR__.'/var/tmp');
    fs()->mkdir(__DIR__.'/var/tmp');
    fs()->mirror(__DIR__.'/src/cloudflare-functions', __DIR__.'/dist/functions');

    $files = get_files();
    fs()->mirror(__DIR__.'/data/files', __DIR__.'/dist/public/files');

    $twig = build_twig();

    $index = $twig->render('index.html.twig', [
        'emergency_contacts' => yaml_parse(get_config_file('emergency_contacts')),
        'administrative_contacts' => yaml_parse(get_config_file('administrative_contacts')),
    ]);
    $recoveryCodes = $twig->render('recovery-codes.html.twig', [
        'recovery_codes' => yaml_parse(get_config_file('recovery_codes')),
    ]);
    $staticrypt = $twig->render('staticrypt.html.twig');
    $cloudflareTemplateJs = $twig->render('cloudflare-template.ts.twig');
    $cloudflareTemplateHtml = $twig->render('cloudflare-template.html.twig');
    $files = $twig->render('files.html.twig', [
        'files' => $files,
    ]);

    file_put_contents(__DIR__.'/dist/public/index.html', $index);
    file_put_contents(__DIR__.'/dist/public/files.html', $files);
    file_put_contents(__DIR__.'/dist/functions/template.ts', $cloudflareTemplateJs);
    // Some files are put in tmp/ directory only for debug purpose
    file_put_contents(__DIR__.'/var/tmp/cloudflare-template.html', $cloudflareTemplateHtml);
    file_put_contents(__DIR__.'/var/tmp/files.html', $files);
    file_put_contents(__DIR__.'/var/tmp/index.html', $index);
    file_put_contents(__DIR__.'/var/tmp/recovery-codes.html', $recoveryCodes);
    file_put_contents(__DIR__.'/var/tmp/staticrypt.html', $staticrypt);

    staticrypt('Recovery codes', 'recovery-codes.html');

    io()->success('Project built');

    if (!$noOpen) {
        openDist();
    }
}

#[AsTask(name: 'watch', description: 'Watch the project and rebuild on changes', aliases: ['watch'])]
function watchAndBuild(): void
{
    watch(__DIR__.'/src/...', function () {
        build(true);
    });
}

#[AsTask('open', description: 'Open the build in the browser', aliases: ['open'])]
function openDist(): void
{
    open(__DIR__.'/dist/public/index.html');
}

#[AsTask(description: 'Open the tmp folder in the browser', aliases: ['open-tmp'])]
function openTmp(): void
{
    open(__DIR__.'/var/tmp/index.html');
}

#[AsTask('open-cloudflare', description: 'Open the cloudflare build in the browser', aliases: ['open-cloudflare'])]
function openCloudflare(): void
{
    if (variable('defaultCfpPassword')) {
        io()->warning('Using the default password for Cloudflare. Set the CFP_PASSWORD environment variable to change it.');
    }
    if (variable('defaultPassword')) {
        io()->warning('Using the default password. Set the PASSWORD environment variable to change it.');
    }

    run(
        command: [
            __DIR__.'/node_modules/.bin/wrangler',
            'pages',
            'dev',
            'public',
            '--binding', 'CFP_PASSWORD='.variable('CFP_PASSWORD'),
            '--compatibility-date', '2024-11-26',
        ],
        context: context()
            ->toInteractive()
            ->withWorkingDirectory(__DIR__.'/dist')
    );
}

#[AsTask(description: 'Deploy the project to Cloudflare', aliases: ['deploy'])]
function deploy(): void
{
    if (variable('defaultCfpPassword')) {
        throw new \RuntimeException('You cannot deploy the project with the default password');
    }

    run(
        command: vsprintf('echo %s | %s pages secret put --project-name %s CFP_PASSWORD', [
            escapeshellarg(variable('CFP_PASSWORD')),
            __DIR__.'/node_modules/.bin/wrangler',
            escapeshellarg(variable('CFP_PROJECT_NAME')),
        ]),
        context: context()
            ->withPty(false)
            ->withTty(false)
    );

    run(
        command: [
            __DIR__.'/node_modules/.bin/wrangler',
            'pages',
            'deploy',
            'public',
            '--project-name', variable('CFP_PROJECT_NAME'),
        ],
        context: context()
            ->toInteractive()
            ->withWorkingDirectory(__DIR__.'/dist')
    );
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

    $data['TEST'] ??= false;
    if ($data['TEST']) {
        $data['PASSWORD'] = 'pass';
        $data['CFP_PASSWORD'] = 'pass';
    }

    $data['PASSWORD'] ?? throw new \RuntimeException('The PASSWORD environment variable is required');
    $data['CFP_PASSWORD'] ?? throw new \RuntimeException('The CFP_PASSWORD environment variable is required');

    $data['defaultPassword'] = 'pass' === $data['PASSWORD'];
    $data['defaultCfpPassword'] = 'pass' === $data['CFP_PASSWORD'];

    return new Context($data);
}

function build_twig(): Environment
{
    $twig = new Environment(
        new FilesystemLoader([
            __DIR__.'/src',
        ]),
        [
            'debug' => true,
            'strict_variables' => true,
        ]
    );
    // Hack to make the watch function works, we want to invalide the cache every time!
    $r = new \ReflectionProperty($twig, 'optionsHash');
    $r->setValue($twig, $r->getValue($twig).bin2hex(random_bytes(32)));

    $twig->addGlobal('default_password', variable('defaultPassword'));
    $twig->addGlobal('default_cfp_password', variable('defaultCfpPassword'));

    return $twig;
}

function staticrypt(string $title, string $filename): void
{
    run(
        command: [
            __DIR__.'/node_modules/.bin/staticrypt',
            '--template', __DIR__.'/var/tmp/staticrypt.html',
            '--template-title', $title,
            '--config', 'false', // No need to store the salt, remember me is disabled
            '--remember', 'false', // Since data are sensitive, we don't want to remember the password
            ...(variable('defaultPassword') ? ['--short'] : []),
            '-d', 'dist/public',
            __DIR__."/var/tmp/{$filename}",
        ],
        context: context()
            ->withEnvironment([
                'STATICRYPT_PASSWORD' => variable('PASSWORD'),
            ])
    );
}

function get_config_file(string $filename): string
{
    $path = __DIR__."/data/{$filename}.yaml";

    if (variable('TEST')) {
        io()->warning("Test mode enabled, using the default data for \"{$filename}\".");
        $path = __DIR__."/data/{$filename}.yaml.dist";
    } elseif (!is_file($path)) {
        io()->warning("File \"{$filename}\" was not found, using the default one.");
        $path = __DIR__."/data/{$filename}.yaml.dist";
    } elseif (!is_file($path)) {
        throw new \RuntimeException("The file {$filename} does not exist");
    }

    return file_get_contents($path);
}

function get_files(): array
{
    $files = finder()
        ->in(__DIR__.'/data/files')
        ->files()
    ;

    $result = [];
    foreach ($files as $file) {
        $result[] = [
            'path' => $file->getRelativePathname(),
            'emoji' => get_emoji_for_file_extension($e = $file->getExtension()),
            'name' => humanize($file->getBasename(".{$e}")),
        ];
    }

    return $result;
}

function get_emoji_for_file_extension(string $extension): string
{
    return match (strtolower($extension)) {
        'pdf' => '📄',
        'doc' => '📝',
        'docx' => '📝',
        'xls' => '📊',
        'xlsx' => '📊',
        'ppt' => '📽️',
        'pptx' => '📽️',
        'jpg' => '🖼️',
        'jpeg' => '🖼️',
        'png' => '🖼️',
        'gif' => '🎭',
        'mp3' => '🎵',
        'wav' => '🎵',
        'mp4' => '🎬',
        'avi' => '🎬',
        'zip' => '🗜️',
        'rar' => '🗜️',
        'txt' => '📄',
        'php' => '🐘',
        'html' => '🌐',
        'css' => '🎨',
        'js' => '⚡',
        default => '📰',
    };
}


function humanize(string $text): string
{
    return ucfirst(strtolower(trim(preg_replace(['/([A-Z])/', '/[_\s]+/'], ['_$1', ' '], $text))));
}
