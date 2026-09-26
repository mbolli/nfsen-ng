<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** One routed page of the app (1.4.2). Implementations are static and stateless. */
interface Page {
    /** Route id: hash, `page` signal value, section id suffix. */
    public static function id(): string;

    /** Sidebar/tab bar label, e.g. 'Top Talkers'. */
    public static function title(): string;

    /** One sentence under the page title (4.0.4). */
    public static function lede(): string;

    /** Icon name from shell/icons.html.twig. */
    public static function icon(): string;

    /** 'analysis' | 'monitor' | 'system' (sidebar group). Analysis pages show the traffic graph. */
    public static function group(): string;

    /** Declare every TAB signal this page owns. Runs once per context, before registration. */
    public static function signals(Context $c): void;

    /** Register this page's actions. */
    public static function register(Context $c, Via $app, PageStates $states): void;

    /**
     * Data for `pages.<id>` in Twig. Only called while this page is active.
     *
     * @return array<string, mixed>
     */
    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array;
}
