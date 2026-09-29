<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Shell;
use Mbolli\PhpVia\Context;

/**
 * Shell actions (1.6). Closures catch \Throwable so the tab shows the failure: php-via would
 * only log it and answer 500.
 */
final class ShellActions {
    public static function register(Context $c, PageStates $states): void {
        $c->action(static function (Context $c): void {
            try {
                self::navigate($c);
            } catch (\Throwable $e) {
                self::fail($c, 'Navigation failed', $e);
            }
            $c->sync();
        }, 'navigate');

        $c->action(static function (Context $c) use ($states): void {
            $id = $c->input('id');
            if (!\is_string($id) || $id === '') {
                return;
            }
            $page = $c->input('page');
            $page = \is_string($page) ? $page : '';

            try {
                self::dismiss($states, $page, $id);
            } catch (\Throwable $e) {
                self::fail($c, 'Could not dismiss the notification', $e);
            }
            $c->sync();
        }, 'dismiss-notification');
    }

    /** Resets an unknown page to the default; that value is pushed, correcting the client. */
    public static function navigate(Context $c): void {
        $page = $c->getSignal('page');
        if ($page !== null && PageRegistry::find($page->string()) === null) {
            $page->setValue(Shell::defaultPage(), broadcast: false);
        }
    }

    /** Without a page that keeps notifications, the id is looked for in every state. */
    public static function dismiss(PageStates $states, string $page, string $id): void {
        $state = $states->for($page);
        if ($state !== null) {
            $state->dismiss($id);

            return;
        }

        foreach ($states->all() as $each) {
            $each->dismiss($id);
        }
    }

    private static function fail(Context $c, string $what, \Throwable $e): void {
        Debug::getInstance()->log($what . ': ' . $e->getMessage(), LOG_ERR);
        $c->getSignal('_error')?->setValue($what . ': ' . $e->getMessage(), broadcast: false);
    }
}
