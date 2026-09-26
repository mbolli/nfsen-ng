<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Shell module for the filter builder drawer (4.5); its signals and actions arrive with the drawer. */
final class FilterDrawer implements ShellModule {
    public static function signals(Context $c): void {}

    public static function register(Context $c, Via $app, PageStates $states): void {}

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate, string $activePage): array {
        return [];
    }
}
