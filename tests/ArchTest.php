<?php

declare(strict_types=1);

arch('no debug calls')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('env() is only used in config files')
    ->expect('env')
    ->not->toBeUsedIn('ForumKit\Forums');

arch('source files use strict types')
    ->expect('ForumKit\Forums')
    ->toUseStrictTypes();
