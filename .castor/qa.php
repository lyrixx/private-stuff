<?php

namespace qa;

use Castor\Attribute\AsTask;

use function Castor\run;

#[AsTask(description: 'Format the PHP code', aliases: ['cs'])]
function php_cs(): void
{
    run(['php-cs-fixer', 'fix']);
}
