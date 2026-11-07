<?php

namespace shamir;

use Castor\Attribute\AsTask;
use League\CommonMark\GithubFlavoredMarkdownConverter;

use function app\get_twig;
use function Castor\context;
use function Castor\finder;
use function Castor\fs;
use function Castor\http_download;
use function Castor\io;
use function Castor\open;
use function Castor\run;
use function Castor\variable;
use function Castor\yaml_parse;

const REMEMORY_VERSION = 'v0.0.23';

// From the checksums.txt of the release, pinned here so that a release
// tampered with after the fact is rejected
const REMEMORY_SHA256 = [
    'darwin-amd64' => 'dedd0b6f0684818f7c1f6315e565f895644a2e59d3aa1c5572b0496b29328714',
    'darwin-arm64' => 'f17e72fd732fa77434940d02f506db2cb84700355325faa1ded0adee9e52bdd8',
    'linux-amd64' => '8a5d50029225bb1752b5d829c6d4e75d4de5e3a50cc74475a86e2de0ace10cfe',
    'linux-arm64' => '38fe314dcaad9709342ded169db557afc4b6ea495bfbdf01372dc8ca050ca0a7',
];

// Name of the document once the holders have recovered it
const DOCUMENT_FILENAME = 'private-stuff.html';

#[AsTask(description: 'Seal the Shamir document for the holders of data/shamir.yaml: any K of them can open it')]
function seal(): void
{
    $configuration = Configuration::fromFile(get_data_file('shamir.yaml'));
    $markdown = file_get_contents(get_data_file('shamir.md'));

    io()->title(\sprintf('Sealing the Shamir document: %d of %d holders', $configuration->threshold, \count($configuration->holders)));

    if ($configuration->needsEveryHolder()) {
        io()->warning('Every holder is needed: if a single bundle is lost, nobody can open the document anymore.');
    }

    $rememory = get_rememory();

    $directory = get_project_directory();
    if (is_dir($directory)) {
        if (!io()->confirm('"var/shamir/" holds the bundles of a previous seal. Delete them and seal a new document?', false)) {
            io()->note('Nothing has been sealed.');

            return;
        }

        fs()->remove($directory);
    }

    run([
        $rememory, 'init', $directory,
        '--name', 'Private Stuff',
        '--threshold', (string) $configuration->threshold,
        '--language', $configuration->language,
        ...array_merge(...array_map(static fn (Holder $holder) => ['--friend', $holder->toFriendOption()], $configuration->holders)),
    ]);

    // Replace the template of rememory by the document
    fs()->remove($directory . '/manifest/README.md');

    try {
        write_document($directory . '/manifest/' . DOCUMENT_FILENAME, $markdown);

        run([$rememory, 'seal'], context: context()->withWorkingDirectory($directory));
    } finally {
        // The clear text copy of the document is not needed anymore
        fs()->remove($directory . '/manifest');
    }

    $bundles = finder()->in($directory . '/output/bundles')->files()->name('*.zip')->sortByName();

    io()->success(\sprintf('Document sealed: any %d of the %d holders can open it.', $configuration->threshold, \count($configuration->holders)));
    io()->listing(array_map(static fn (\SplFileInfo $file) => 'var/shamir/output/bundles/' . $file->getFilename(), iterator_to_array($bundles, false)));
    io()->warning('Together, these bundles open the document. Send each one to its holder, then delete "var/shamir/".');
}

#[AsTask(description: 'Render the Shamir document, to check it before sealing it')]
function preview(): void
{
    $path = \dirname(__DIR__) . '/var/shamir-preview.html';

    write_document($path, file_get_contents(get_data_file('shamir.md')));

    io()->success('The document has been rendered in "var/shamir-preview.html". It is in clear text: delete it once checked.');

    open('file://' . $path);
}

function get_project_directory(): string
{
    return \dirname(__DIR__) . '/var/shamir';
}

/**
 * Returns the path of the data file, relative to the project root: its dummy
 * version in dev mode.
 */
function get_data_file(string $filename): string
{
    if ('dev' === variable('APP_ENV')) {
        return "data/{$filename}.dist";
    }

    // Unlike the other data files, no fallback on the dummy one: it would be
    // sealed and sent to the holders
    if (!is_file("data/{$filename}")) {
        throw new \RuntimeException(\sprintf('The file "data/%1$s" does not exist. Write it first, "data/%1$s.dist" is an example.', $filename));
    }

    return "data/{$filename}";
}

function write_document(string $path, string $markdown): void
{
    fs()->dumpFile($path, get_twig()->render('shamir.html.twig', [
        'document' => (new GithubFlavoredMarkdownConverter())->convert($markdown)->getContent(),
    ]));
}

/**
 * Downloads the rememory binary of the current platform, once, and checks it.
 */
function get_rememory(): string
{
    $os = match (\PHP_OS_FAMILY) {
        'Darwin' => 'darwin',
        'Linux' => 'linux',
        default => throw new \RuntimeException(\sprintf('Rememory has no binary for "%s".', \PHP_OS_FAMILY)),
    };
    $arch = match (php_uname('m')) {
        'x86_64', 'amd64' => 'amd64',
        'aarch64', 'arm64' => 'arm64',
        default => throw new \RuntimeException(\sprintf('Rememory has no binary for "%s".', php_uname('m'))),
    };
    $platform = "{$os}-{$arch}";
    $path = \dirname(__DIR__) . '/var/rememory/rememory-' . REMEMORY_VERSION . "-{$platform}";

    if (!is_file($path)) {
        io()->comment(\sprintf('Downloading rememory %s...', REMEMORY_VERSION));

        $response = http_download(
            \sprintf('https://github.com/eljojo/rememory/releases/download/%s/rememory-%s', REMEMORY_VERSION, $platform),
            $path,
            options: ['timeout' => 60],
        );
        if (200 !== $response->getStatusCode()) {
            fs()->remove($path);

            throw new \RuntimeException(\sprintf('Could not download rememory: HTTP status %d.', $response->getStatusCode()));
        }
    }

    // Checked on each run, so a partial download is caught too
    if (!hash_equals(REMEMORY_SHA256[$platform], hash_file('sha256', $path))) {
        fs()->remove($path);

        throw new \RuntimeException('The checksum of the rememory binary is wrong, it has been deleted. Run the task again to download it again.');
    }

    fs()->chmod($path, 0o755);

    return $path;
}

/**
 * The holders of the Shamir document, and how many of them are needed to open it.
 */
final readonly class Configuration
{
    /**
     * @param list<Holder> $holders
     */
    private function __construct(
        public int $threshold,
        public string $language,
        public array $holders,
    ) {
    }

    public static function fromFile(string $file): self
    {
        $data = yaml_parse(file_get_contents($file));
        if (!\is_array($data)) {
            throw new \RuntimeException(\sprintf('"%s" must define the "threshold" and the "holders".', $file));
        }

        $unknownKeys = array_diff(array_keys($data), ['holders', 'language', 'threshold']);
        if ($unknownKeys) {
            throw new \RuntimeException(\sprintf('"%s" contains unknown keys: "%s".', $file, implode('", "', $unknownKeys)));
        }

        // rememory needs at least 2 holders
        $holders = $data['holders'] ?? null;
        if (!\is_array($holders) || !array_is_list($holders) || \count($holders) < 2) {
            throw new \RuntimeException(\sprintf('The "holders" of "%s" must be a list of at least 2 holders.', $file));
        }
        $holders = array_map(static fn (mixed $holder) => Holder::fromYaml($holder, $file), $holders);

        $names = array_map(static fn (Holder $holder) => $holder->name, $holders);
        if (\count($names) !== \count(array_unique($names))) {
            throw new \RuntimeException(\sprintf('Each holder of "%s" must have a different name.', $file));
        }

        $threshold = $data['threshold'] ?? null;
        if (!\is_int($threshold) || $threshold < 2 || $threshold > \count($holders)) {
            throw new \RuntimeException(\sprintf('The "threshold" of "%s" must be an integer between 2 and the number of holders (%d).', $file, \count($holders)));
        }

        // rememory checks that it knows the language
        $language = $data['language'] ?? 'fr';
        if (!\is_string($language)) {
            throw new \RuntimeException(\sprintf('The "language" of "%s" must be a language code, like "fr".', $file));
        }

        return new self($threshold, $language, $holders);
    }

    public function needsEveryHolder(): bool
    {
        return $this->threshold === \count($this->holders);
    }
}

final readonly class Holder
{
    private function __construct(
        public string $name,
        public string $contact,
    ) {
    }

    public static function fromYaml(mixed $data, string $file): self
    {
        $name = \is_array($data) && \is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ('' === $name) {
            throw new \RuntimeException(\sprintf('Each holder of "%s" must have a "name".', $file));
        }

        $unknownKeys = array_diff(array_keys($data), ['contact', 'name']);
        if ($unknownKeys) {
            throw new \RuntimeException(\sprintf('The holder "%s" of "%s" has unknown keys: "%s".', $name, $file, implode('", "', $unknownKeys)));
        }

        $contact = $data['contact'] ?? '';
        if (!\is_string($contact)) {
            // YAML reads +33612345678 as a number, and drops the "+"
            throw new \RuntimeException(\sprintf('The contact of "%s" in "%s" must be a string: quote it, like "+33612345678".', $name, $file));
        }

        // rememory splits its --friend option on commas
        if (str_contains($name, ',') || str_contains($contact, ',')) {
            throw new \RuntimeException(\sprintf('The name and the contact of "%s" in "%s" cannot contain a comma.', $name, $file));
        }

        return new self($name, trim($contact));
    }

    /**
     * The value of the --friend option of rememory.
     */
    public function toFriendOption(): string
    {
        return '' === $this->contact ? $this->name : "{$this->name},{$this->contact}";
    }
}
