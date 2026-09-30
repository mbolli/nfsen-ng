<?php

declare(strict_types=1);

/** The memory_limit a Dockerfile writes into the image's PHP configuration. */
function imageMemoryLimit(string $dockerfile): string {
    $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $dockerfile);
    preg_match_all("/echo 'memory_limit = (\\S+)' > \"\\\$PHP_INI_DIR\\/conf\\.d\\/nfsen-ng\\.ini\"/", $text, $m);
    expect($m[1])->toHaveCount(1);

    return $m[1][0];
}

describe('the worker memory limit', function (): void {
    test('both images set the same one, and not the PHP default', function (): void {
        expect(imageMemoryLimit('deploy/Dockerfile.dev'))->toBe(imageMemoryLimit('deploy/Dockerfile'))
            ->and(imageMemoryLimit('deploy/Dockerfile'))->not->toBe('128M')
        ;
    });

    test('the bare-metal unit runs the worker with the limit of the images', function (): void {
        $unit = (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/systemd/nfsen-ng.service');
        preg_match_all('/^ExecStart=(.*)$/m', $unit, $m);

        expect($m[1])->toBe(['php -d memory_limit=' . imageMemoryLimit('deploy/Dockerfile') . ' /var/www/nfsen-ng/backend/app.php']);
    });
});
