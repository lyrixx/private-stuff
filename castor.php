<?php

namespace app;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Exception\ProblemException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\ExecutableFinder;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function Castor\check;
use function Castor\context;
use function Castor\finder;
use function Castor\fs;
use function Castor\import;
use function Castor\io;
use function Castor\load_dot_env;
use function Castor\open as do_open;
use function Castor\run;
use function Castor\variable;
use function Castor\watch;
use function Castor\yaml_parse;

import(__DIR__ . '/.castor');

#[AsTask(description: 'Install the dependencies')]
function install(): void
{
    io()->title('Installing dependencies');

    run(['npm', 'install', '--frozen-lockfile']);
}

#[AsTask(description: 'Build the project', aliases: ['build'])]
function build(bool $noOpen = false): void
{
    if (!is_dir(__DIR__ . '/node_modules')) {
        install();
    }

    io()->title('Building the project');

    if ('test' !== variable('APP_ENV') && variable('defaultPassword')) {
        io()->warning('Using the default password. Set the PASSWORD environment variable to change it, or use a `.env.local` file.');
    }

    fs()->remove(__DIR__ . '/dist');
    fs()->mkdir(__DIR__ . '/dist/public');
    fs()->remove(__DIR__ . '/var/tmp');
    fs()->mkdir(__DIR__ . '/var/tmp');
    fs()->mirror(__DIR__ . '/src/cloudflare-functions', __DIR__ . '/dist/functions');

    $filesDirectory = variable('FILES_DIRECTORY');
    fs()->mirror($filesDirectory, __DIR__ . '/dist/public/upload');
    $files = get_files($filesDirectory);

    $twig = build_twig();

    $index = $twig->render('index.html.twig', [
        'emergency_contacts' => yaml_parse(get_config_file('emergency_contacts')),
        'administrative_contacts' => yaml_parse(get_config_file('administrative_contacts')),
    ]);
    $recoveryCodes = $twig->render('recovery-codes.html.twig', [
        'recovery_codes' => yaml_parse(get_config_file('recovery_codes')),
    ]);
    $files = $twig->render('files.html.twig', [
        'files' => $files,
    ]);
    $cloudflareTemplateJs = $twig->render('cloudflare-template.ts.twig');
    $manifest = $twig->render('manifest.json.twig');
    $serviceWorker = $twig->render('service-worker.js.twig');
    $staticrypt = $twig->render('staticrypt.html.twig');

    file_put_contents(__DIR__ . '/dist/public/index.html', $index);
    file_put_contents(__DIR__ . '/dist/public/files.html', $files);
    file_put_contents(__DIR__ . '/dist/public/manifest.json', $manifest);
    file_put_contents(__DIR__ . '/dist/public/service-worker.js', $serviceWorker);
    file_put_contents(__DIR__ . '/dist/functions/template.ts', $cloudflareTemplateJs);
    if ('test' === variable('APP_ENV')) {
        file_put_contents(__DIR__ . '/dist/public/recovery-codes-decoded.html', $recoveryCodes);
    }
    // Encrypted files are stored in tmp folder
    file_put_contents(__DIR__ . '/var/tmp/recovery-codes.html', $recoveryCodes);
    file_put_contents(__DIR__ . '/var/tmp/staticrypt.html', $staticrypt);

    staticrypt('Recovery codes', 'recovery-codes.html');

    io()->success('Project built successfully');

    if (!$noOpen) {
        open();
    }
}

#[AsTask(name: 'watch', description: 'Watch the project and rebuild on changes', aliases: ['watch'])]
function watchAndBuild(): void
{
    watch(__DIR__ . '/src/...', function () {
        build(true);
    });
}

#[AsTask(description: 'Start the web server', aliases: ['start'])]
function start(): void
{
    if (is_server_running()) {
        io()->warning('The server is already running');

        return;
    }

    io()->comment('Starting the server... ');

    check(
        'Mkcert is installed',
        'Mkcert is required to use HTTPS',
        fn () => (new ExecutableFinder())->find('mkcert'),
    );

    try {
        check(
            'Certificates exists',
            'Certificates does not exist',
            fn () => is_file(__DIR__ . '/var/certs/private-stuff.test.pem'),
        );
    } catch (ProblemException) {
        fs()->mkdir(__DIR__ . '/var/certs');
        check(
            'Generate certificates',
            'Could not generate the certificates',
            fn () => run(
                command: [
                    'mkcert',
                    'private-stuff.test',
                ],
                context: context()
                    ->withWorkingDirectory(__DIR__ . '/var/certs')
                    ->withQuiet(true)
            ),
        );
    }

    $server = <<<'SHELL'
        docker run --rm --name private-stuff -d -p 9999:443 -v `pwd`:/app:ro $(
            docker build --quiet -<<-EOD
                FROM caddy:2.9-alpine
                COPY <<-EOF /etc/caddy/Caddyfile
                    :443 {
                        tls /app/var/certs/private-stuff.test.pem /app/var/certs/private-stuff.test-key.pem
                        root * /app/dist/public
                        try_files {path}.html
                        file_server
                    }
        EOF
        EOD
        )
        SHELL;

    run($server);

    io()->comment('Listening on https://private-stuff.test:9999/');
    open();

    io()->success('Server started');
}

#[AsTask(description: 'Start the web server', aliases: ['stop'])]
function stop(): void
{
    if (!is_server_running()) {
        io()->warning('The server is not running');

        return;
    }

    run(['docker', 'stop', 'private-stuff']);

    io()->success('Server stopped');
}

#[AsTask(description: 'Open website in favorite browser', aliases: ['open'])]
function open(): void
{
    if (!is_server_running()) {
        start();

        return;
    }

    do_open('https://private-stuff.test:9999/');
}

function is_server_running(): bool
{
    $process = run(
        command: [
            'docker',
            'container',
            'inspect',
            '-f', '{{.State.Running}}',
            'private-stuff',
        ],
        context: context()
            ->withAllowFailure(true)
            ->withQuiet()
    );

    if (!$process->isSuccessful()) {
        return false;
    }

    return 'true' === trim($process->getOutput());
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
            __DIR__ . '/node_modules/.bin/wrangler',
            'pages',
            'dev',
            'public',
            '--binding', 'CFP_PASSWORD=' . variable('CFP_PASSWORD'),
            '--compatibility-date', '2024-11-26',
        ],
        context: context()
            ->toInteractive()
            ->withWorkingDirectory(__DIR__ . '/dist')
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
            __DIR__ . '/node_modules/.bin/wrangler',
            escapeshellarg(variable('CFP_PROJECT_NAME')),
        ]),
        context: context()
            ->withPty(false)
            ->withTty(false)
    );

    run(
        command: [
            __DIR__ . '/node_modules/.bin/wrangler',
            'pages',
            'deploy',
            'public',
            '--project-name', variable('CFP_PROJECT_NAME'),
        ],
        context: context()
            ->toInteractive()
            ->withWorkingDirectory(__DIR__ . '/dist')
    );
}

#[AsContext()]
function create_context(): Context
{
    $data = load_dot_env();

    if ('test' === $data['APP_ENV']) {
        $data['PASSWORD'] = 'pass';
        $data['CFP_PASSWORD'] = 'pass';
        $data['FILES_DIRECTORY'] = __DIR__ . '/data';
    }

    $data['PASSWORD'] ?? throw new \RuntimeException('The "PASSWORD" environment variable is required.');
    $data['CFP_PASSWORD'] ?? throw new \RuntimeException('The "CFP_PASSWORD" environment variable is required.');
    $data['FILES_DIRECTORY'] ?? throw new \RuntimeException('The "FILES_DIRECTORY" environment variable is required.');

    if (Path::isRelative($data['FILES_DIRECTORY'])) {
        $data['FILES_DIRECTORY'] = Path::makeAbsolute($data['FILES_DIRECTORY'], __DIR__);
    }

    $data['defaultPassword'] = 'pass' === $data['PASSWORD'];
    $data['defaultCfpPassword'] = 'pass' === $data['CFP_PASSWORD'];

    return new Context($data);
}

function build_twig(): Environment
{
    $twig = new Environment(
        new FilesystemLoader([
            __DIR__ . '/src',
        ]),
        [
            'debug' => true,
            'strict_variables' => true,
        ]
    );
    // Hack to make the watch function works, we want to invalide the cache every time!
    $r = new \ReflectionProperty($twig, 'optionsHash');
    $r->setValue($twig, $r->getValue($twig) . bin2hex(random_bytes(32)));

    $twig->addGlobal('default_password', variable('defaultPassword'));
    $twig->addGlobal('default_cfp_password', variable('defaultCfpPassword'));
    $twig->addGlobal('test', 'test' === variable('APP_ENV'));

    return $twig;
}

function staticrypt(string $title, string $filename): void
{
    run(
        command: [
            __DIR__ . '/node_modules/.bin/staticrypt',
            '--template', __DIR__ . '/var/tmp/staticrypt.html',
            '--template-title', $title,
            '--config', 'false', // No need to store the salt, remember me is disabled
            '--remember', 'false', // Since data are sensitive, we don't want to remember the password
            ...(variable('defaultPassword') ? ['--short'] : []),
            '-d', 'dist/public',
            __DIR__ . "/var/tmp/{$filename}",
        ],
        context: context()
            ->withEnvironment([
                'STATICRYPT_PASSWORD' => variable('PASSWORD'),
            ])
    );
}

function get_config_file(string $filename): string
{
    $path = __DIR__ . "/data/{$filename}.yaml";

    if ('test' === variable('APP_ENV')) {
        io()->warning("Test mode enabled, using the default data for \"{$filename}\".");
        $path = __DIR__ . "/data/{$filename}.yaml.dist";
    } elseif (!is_file($path)) {
        io()->warning("File \"{$filename}\" was not found, using the default one.");
        $path = __DIR__ . "/data/{$filename}.yaml.dist";
    } elseif (!is_file($path)) {
        throw new \RuntimeException("The file {$filename} does not exist");
    }

    return file_get_contents($path);
}

function get_files(string $directory): array
{
    $files = finder()
        ->in($directory)
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
