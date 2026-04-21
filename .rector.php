<?php

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__ . '/backend']);
    $rectorConfig->sets([LevelSetList::UP_TO_PHP_84]);

    // clean useless var tags
    $rectorConfig->rule(RemoveUselessVarTagRector::class);
};
