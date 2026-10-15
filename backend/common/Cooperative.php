<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use OpenSwoole\Coroutine;

/**
 * Long CPU work in a request yields now and then: one worker serves every tab, and a large Flows listing takes
 * seconds of formatting (#182). Outside a coroutine (tests, CLI) there is nothing to yield to.
 */
final class Cooperative {
    public static function pause(): void {
        if (Coroutine::getCid() > 0) {
            Coroutine::usleep(1000);
        }
    }
}
