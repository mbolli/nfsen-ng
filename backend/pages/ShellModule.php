<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** A part of the shell that every page shares (1.4.2). Implementations are static and stateless. */
interface ShellModule {
    public static function signals(Context $c): void;

    public static function register(Context $c, Via $app, PageStates $states): void;

    /**
     * Data for this module's top-level Twig key (range, graph, querykit, drawer), on every render.
     *
     * @return array<string, mixed>
     */
    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate, string $activePage): array;
}
