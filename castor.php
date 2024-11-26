<?php

namespace app;

use Castor\Attribute\AsTask;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\load_dot_env;
use function Castor\open;
use function Castor\run;
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

    load_dot_env();

    $password = $_SERVER['PASSWORD'] ?? throw new \RuntimeException('The PASSWORD environment variable is required');

    $defaultPassword = 'pass' === $password;
    if ($defaultPassword) {
        io()->warning('Using the default password. Set the PASSWORD environment variable to change it.');
    }

    run(
        command: [
            __DIR__.'/node_modules/.bin/staticrypt',
            '--config', 'false', // No need to store the salt, remember me is disabled
            '--template-color-primary', 'rgb(43, 166, 255)',
            '--template-color-secondary', 'black',
            '--template-title', 'Secret Box',
            '--template-instructions', $defaultPassword ? 'Try "pass"' : '', // Empty on purpose when real password
            '--template-button', 'Open',
            '--short',
            '--remember', 'false',
            '-d', 'build',
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
