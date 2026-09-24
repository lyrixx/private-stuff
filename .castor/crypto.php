<?php

namespace crypto;

use Castor\Attribute\AsTask;

use function Castor\decrypt_file_with_password;
use function Castor\encrypt_file_with_password;
use function Castor\finder;
use function Castor\fs;
use function Castor\io;
use function Castor\variable;

#[AsTask(description: 'Encrypt all the files of a directory, in place, with a ".enc" suffix', aliases: ['encrypt'])]
function encrypt(string $directory): void
{
    if (!is_dir($directory)) {
        throw new \RuntimeException(\sprintf('The directory "%s" does not exist.', $directory));
    }

    if (variable('defaultPassword')) {
        throw new \RuntimeException('You cannot encrypt data with the default password. Are you in "prod" mode?');
    }

    $password = variable('PASSWORD');
    if (\strlen($password) < 14) {
        throw new \RuntimeException('The password must be at least 14 characters long.');
    }

    $files = iterator_to_array(finder()->in($directory)->files()->notName('*.enc'), false);

    foreach ($files as $file) {
        encrypt_file_with_password($file->getPathname(), $password);
        fs()->remove($file->getPathname());

        io()->text(\sprintf('Encrypted "%s".', $file->getRelativePathname()));
    }

    io()->success(\sprintf('%d file(s) encrypted.', \count($files)));
}

#[AsTask(description: 'Decrypt all the ".enc" files of a directory, in place', aliases: ['decrypt'])]
function decrypt(string $directory): void
{
    if (!is_dir($directory)) {
        throw new \RuntimeException(\sprintf('The directory "%s" does not exist.', $directory));
    }

    if (variable('defaultPassword')) {
        throw new \RuntimeException('You cannot decrypt data with the default password. Are you in "prod" mode?');
    }

    $password = variable('PASSWORD');

    $files = iterator_to_array(finder()->in($directory)->files()->name('*.enc'), false);

    $failures = 0;
    foreach ($files as $file) {
        $path = $file->getPathname();
        $target = substr($path, 0, -\strlen('.enc'));

        if (file_exists($target)) {
            ++$failures;
            io()->warning(\sprintf('Skipped "%s": "%s" already exists.', $file->getRelativePathname(), basename($target)));

            continue;
        }

        try {
            decrypt_file_with_password($path, $password, $target);
        } catch (\Exception $e) {
            ++$failures;
            io()->error(\sprintf('Failed to decrypt "%s": %s', $file->getRelativePathname(), $e->getMessage()));

            continue;
        }

        fs()->remove($path);

        io()->text(\sprintf('Decrypted "%s".', $file->getRelativePathname()));
    }

    if ($failures > 0) {
        throw new \RuntimeException(\sprintf('%d file(s) could not be decrypted, see above.', $failures));
    }

    io()->success(\sprintf('%d file(s) decrypted.', \count($files)));
}
