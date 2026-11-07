<?php

namespace cloudflare;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Context;

use function app\check_wrangler_authentication;
use function app\get_wrangler;
use function Castor\context;
use function Castor\http_request;
use function Castor\io;
use function Castor\run;
use function Castor\variable;

const API_URL = 'https://api.cloudflare.com/client/v4';

#[AsTask(description: 'List the Cloudflare Pages deployments that are not in production, and delete them with --delete')]
function clean_deployments(
    #[AsOption(description: 'Delete the deployments, instead of only listing them')]
    bool $delete = false,
): void {
    if ('prod' !== variable('APP_ENV')) {
        throw new \RuntimeException(\sprintf('You cannot clean the deployments in "%s" mode, only in "prod" mode. Set APP_ENV=prod in `.env.local` or on the command line.', variable('APP_ENV')));
    }

    check_wrangler_authentication();

    $projectName = variable('CFP_PROJECT_NAME');
    $productionBranch = variable('CFP_PRODUCTION_BRANCH', 'main');
    $token = get_api_token();
    $accountId = get_account_id();
    $projectPath = \sprintf('/accounts/%s/pages/projects/%s', $accountId, $projectName);

    io()->title(\sprintf('Deployments of the "%s" Pages project', $projectName));

    $project = Project::fromApi(api_get($token, $projectPath)['result']);

    // Everything below relies on this branch to tell production from preview
    if ($productionBranch !== $project->productionBranch) {
        throw new \RuntimeException(\sprintf('The production branch of the Pages project is "%s", but CFP_PRODUCTION_BRANCH is "%s": `castor deploy` may have created unprotected preview deployments. Fix this mismatch first, nothing has been deleted.', $project->productionBranch, $productionBranch));
    }

    $deploymentsToDelete = [];
    $keptCountByReason = [];
    $keptPreviews = [];
    foreach (get_deployments($token, $projectPath) as $deployment) {
        $keepReason = $deployment->getKeepReason($project);
        if (null === $keepReason) {
            $deploymentsToDelete[] = $deployment;

            continue;
        }

        $keptCountByReason[$keepReason] = ($keptCountByReason[$keepReason] ?? 0) + 1;
        if ($deployment->isPreview()) {
            $keptPreviews[] = $deployment;
        }
    }

    io()->section('Kept');
    io()->text(\sprintf('Active production deployment: %s', $project->activeDeploymentId));
    io()->table(['Reason', 'Deployments'], array_map(null, array_keys($keptCountByReason), $keptCountByReason));

    if ($keptPreviews) {
        io()->warning('These preview deployments are kept, but they do not get the production secrets: check them by hand.');
        display_deployments($keptPreviews);
    }

    io()->section(\sprintf('To delete: %d', \count($deploymentsToDelete)));

    if (!$deploymentsToDelete) {
        io()->success('There is nothing to delete.');

        return;
    }

    display_deployments($deploymentsToDelete);

    if (!$delete) {
        io()->note('Nothing has been deleted. Run this task again with --delete to delete these deployments.');

        return;
    }

    delete_deployments($deploymentsToDelete, $accountId, $projectName);

    io()->success(\sprintf('%d deployments deleted', \count($deploymentsToDelete)));
}

/**
 * Wrangler has no command to read a project, and `wrangler pages deployment
 * list` only returns the first page of the API: the oldest deployments would
 * go unnoticed. So the deployments are read from the API, with wrangler's token.
 */
function get_api_token(): string
{
    $process = run(
        command: [get_wrangler(), 'auth', 'token', '--json'],
        context: get_wrangler_context(),
    );

    return decode_wrangler_output($process->getOutput())['token'] ?? throw new \RuntimeException('Wrangler uses a global API key, which is not supported here. Use `wrangler login` or CLOUDFLARE_API_TOKEN.');
}

function get_account_id(): string
{
    // $_SERVER holds both the shell variables and the ones of the env files
    $accountId = $_SERVER['CLOUDFLARE_ACCOUNT_ID'] ?? '';
    if ('' !== $accountId) {
        return $accountId;
    }

    $process = run(
        command: [get_wrangler(), 'whoami', '--json'],
        context: get_wrangler_context(),
    );

    $accounts = decode_wrangler_output($process->getOutput())['accounts'] ?? [];
    if (1 !== \count($accounts)) {
        throw new \RuntimeException(\sprintf('Wrangler sees %d Cloudflare accounts. Set CLOUDFLARE_ACCOUNT_ID in `.env.prod.local` to select one.', \count($accounts)));
    }

    return $accounts[0]['id'];
}

function get_wrangler_context(): Context
{
    return context()
        ->withQuiet()
        ->withTty(false)
        ->withPty(false)
    ;
}

/**
 * @return array<string, mixed>
 */
function decode_wrangler_output(string $output): array
{
    return json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
}

/**
 * The whole list is read before anything is deleted: deleting while paginating
 * would shift the pages, and skip some deployments.
 *
 * @return list<Deployment>
 */
function get_deployments(#[\SensitiveParameter] string $token, string $projectPath): array
{
    $deployments = [];
    $page = 1;

    // No "per_page": its maximum is not documented, the default one is enough
    do {
        $knownCount = \count($deployments);
        $response = api_get($token, $projectPath . '/deployments', ['page' => $page]);

        foreach ($response['result'] as $deployment) {
            $deployment = Deployment::fromApi($deployment);
            $deployments[$deployment->id] = $deployment;
        }

        $totalCount = $response['result_info']['total_count'] ?? throw new \RuntimeException('The Cloudflare API did not return the number of deployments, so the list may be incomplete. Nothing has been deleted.');
        ++$page;
    } while (\count($deployments) > $knownCount && \count($deployments) < $totalCount);

    if (\count($deployments) !== $totalCount) {
        throw new \RuntimeException(\sprintf('Got %d deployments out of %d: the list changed during the task, or it is incomplete. Nothing has been deleted.', \count($deployments), $totalCount));
    }

    return array_values($deployments);
}

/**
 * @param array<string, int> $query
 *
 * @return array<string, mixed>
 */
function api_get(#[\SensitiveParameter] string $token, string $path, array $query = []): array
{
    $response = http_request('GET', API_URL . $path, [
        'auth_bearer' => $token,
        'query' => $query,
        'timeout' => 15,
    ]);

    $status = $response->getStatusCode();
    if (200 !== $status) {
        throw new \RuntimeException(\sprintf('The Cloudflare API answered with the status %d on "%s". Nothing has been deleted.', $status, $path));
    }

    return $response->toArray();
}

/**
 * @param list<Deployment> $deployments
 */
function display_deployments(array $deployments): void
{
    io()->table(['ID', 'Branch', 'Created on', 'URLs'], array_map(
        static fn (Deployment $deployment) => [
            $deployment->id,
            $deployment->branch,
            $deployment->createdOn->format('Y-m-d H:i'),
            implode("\n", $deployment->urls),
        ],
        $deployments,
    ));
}

/**
 * @param list<Deployment> $deployments
 */
function delete_deployments(array $deployments, string $accountId, string $projectName): void
{
    $failedCount = 0;

    foreach ($deployments as $deployment) {
        // --force is required: the last deployment of a branch holds its alias,
        // and without a TTY wrangler answers "no" to its own confirmation and
        // exits with 0, without deleting anything. Do not count on the API to
        // protect anything: only getKeepReason() decides what is deleted.
        // The account is forced to be the one that has been listed: otherwise
        // wrangler could use the one of its cache.
        $process = run(
            command: [get_wrangler(), 'pages', 'deployment', 'delete', $deployment->id, '--project-name', $projectName, '--force'],
            context: get_wrangler_context()
                ->withAllowFailure()
                ->withEnvironment(['CLOUDFLARE_ACCOUNT_ID' => $accountId]),
        );

        if ($process->isSuccessful()) {
            io()->writeln(\sprintf('Deleted %s', $deployment->id));

            continue;
        }

        ++$failedCount;
        io()->error(\sprintf('Could not delete %s: %s', $deployment->id, trim($process->getErrorOutput())));
    }

    if ($failedCount) {
        throw new \RuntimeException(\sprintf('%d of the %d deployments could not be deleted.', $failedCount, \count($deployments)));
    }
}

final readonly class Project
{
    private function __construct(
        public string $productionBranch,
        public string $activeDeploymentId,
    ) {
    }

    /**
     * @param array<string, mixed> $project
     */
    public static function fromApi(array $project): self
    {
        return new self(
            $project['production_branch'],
            $project['canonical_deployment']['id'] ?? throw new \RuntimeException('The active production deployment of the Pages project is unknown, so it cannot be protected. Nothing has been deleted.'),
        );
    }
}

final readonly class Deployment
{
    /**
     * @param list<string> $urls
     */
    private function __construct(
        public string $id,
        public string $environment,
        public string $branch,
        public \DateTimeImmutable $createdOn,
        public array $urls,
    ) {
    }

    /**
     * The API also returns the environment variables of the deployment: they
     * must not go further than this method.
     *
     * @param array<string, mixed> $deployment
     */
    public static function fromApi(array $deployment): self
    {
        return new self(
            $deployment['id'],
            $deployment['environment'],
            $deployment['deployment_trigger']['metadata']['branch'] ?? '',
            new \DateTimeImmutable($deployment['created_on']),
            [$deployment['url'], ...$deployment['aliases'] ?? []],
        );
    }

    public function isPreview(): bool
    {
        return 'preview' === $this->environment;
    }

    /**
     * The only source of truth of what can be deleted: a deployment is kept
     * unless it is proven to be a preview of another branch.
     *
     * @return ?string Why the deployment must be kept, null if it can be deleted
     */
    public function getKeepReason(Project $project): ?string
    {
        return match (true) {
            $this->id === $project->activeDeploymentId => 'Active production deployment',
            !$this->isPreview() => \sprintf('Environment "%s"', $this->environment),
            $project->productionBranch === $this->branch => 'Preview of the production branch',
            '' === $this->branch => 'Preview of an unknown branch',
            default => null,
        };
    }
}
