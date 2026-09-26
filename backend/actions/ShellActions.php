<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Shell;
use Mbolli\PhpVia\Context;

/**
 * Shell actions: navigate, dismiss-notification and count-files (1.6). Each closure catches
 * \Throwable: php-via only catches \Exception, and an escaped \Error kills the worker.
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

        // Lightweight scan triggered by date and source changes.
        $c->action(static function (Context $c): void {
            try {
                self::countFiles($c);
            } catch (\Throwable $e) {
                self::fail($c, 'Could not count the capture files', $e);
            }
            $c->sync();
        }, 'count-files');
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

    private static function countFiles(Context $c): void {
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        $graphSources = $c->getSignal('graph_sources');
        $selectedProfile = $c->getSignal('selected_profile');
        if ($datestart === null || $dateend === null || $graphSources === null || $selectedProfile === null) {
            return;
        }

        // Never clamped: one count serves every page, and only some of them clamp their
        // window. It describes the selected range; a consumer that reads less says so.
        Helpers::measureNfcapdFiles(
            $c,
            $datestart->int(),
            $dateend->int(),
            Helpers::resolveSources($graphSources->array()),
            $selectedProfile->string(),
        );
    }

    private static function fail(Context $c, string $what, \Throwable $e): void {
        Debug::getInstance()->log($what . ': ' . $e->getMessage(), LOG_ERR);
        $c->getSignal('_error')?->setValue($what . ': ' . $e->getMessage(), broadcast: false);
    }
}
