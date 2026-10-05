<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\CpuBudget;

/**
 * A fixture root with the given files, relative to it (e.g. 'proc/self/cgroup').
 *
 * @param array<string, string> $files
 */
function cpuFixture(array $files): string {
    $root = sys_get_temp_dir() . '/nfsen-cpu-' . bin2hex(random_bytes(4));
    foreach ($files as $path => $content) {
        $full = $root . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0o777, true);
        }
        file_put_contents($full, $content);
    }
    // Every root has the v2 mount point, as a real host does.
    if (!is_dir($root . '/sys/fs/cgroup')) {
        mkdir($root . '/sys/fs/cgroup', 0o777, true);
    }

    return $root;
}

function cpuStatus(string $list): string {
    return "Name:\tphp\nCpus_allowed:\tfffff\nCpus_allowed_list:\t{$list}\nMems_allowed_list:\t0\n";
}

describe('CpuBudget::detectFrom()', function (): void {
    beforeEach(function (): void {
        $this->roots = [];
        $this->fixture = fn (array $files): string => $this->roots[] = cpuFixture($files);
    });

    afterEach(function (): void {
        foreach ($this->roots as $root) {
            removeTree($root);
        }
    });

    test('counts the affinity mask when no quota applies', function (): void {
        $root = ($this->fixture)([
            'proc/self/status' => cpuStatus('0-19'),
            'proc/self/cgroup' => "0::/\n",
            'sys/fs/cgroup/cpu.max' => "max 100000\n",
        ]);

        expect(CpuBudget::detectFrom($root, 64))->toBe(['cores' => 20, 'source' => 'affinity', 'origin' => 'nproc', 'detail' => 'Cpus_allowed_list 0-19']);
    });

    // The case the plan names: a container limited to 4 CPUs on a 20-core host counts 4.
    test('a cgroup v2 quota below the affinity wins', function (): void {
        $root = ($this->fixture)([
            'proc/self/status' => cpuStatus('0-19'),
            'proc/self/cgroup' => "0::/\n",
            'sys/fs/cgroup/cpu.max' => "400000 100000\n",
        ]);

        expect(CpuBudget::detectFrom($root))->toBe(['cores' => 4, 'source' => 'cgroup', 'origin' => 'cpu.max', 'detail' => 'cgroup cpu.max 400000 100000']);
    });

    test('a fractional quota rounds up', function (): void {
        $root = ($this->fixture)([
            'proc/self/status' => cpuStatus('0-7'),
            'proc/self/cgroup' => "0::/\n",
            'sys/fs/cgroup/cpu.max' => "150000 100000\n",
        ]);

        expect(CpuBudget::detectFrom($root)['cores'])->toBe(2);
    });

    test('a quota above the affinity changes nothing', function (): void {
        $root = ($this->fixture)([
            'proc/self/status' => cpuStatus('0-3,8-11'),
            'proc/self/cgroup' => "0::/\n",
            'sys/fs/cgroup/cpu.max' => "3200000 100000\n",
        ]);

        expect(CpuBudget::detectFrom($root))->toMatchArray(['cores' => 8, 'source' => 'affinity']);
    });

    test('the tightest quota on the way up the hierarchy applies', function (): void {
        $root = ($this->fixture)([
            'proc/self/status' => cpuStatus('0-15'),
            'proc/self/cgroup' => "0::/machine/app\n",
            'sys/fs/cgroup/machine/app/cpu.max' => "max 100000\n",
            'sys/fs/cgroup/machine/cpu.max' => "200000 100000\n",
        ]);

        expect(CpuBudget::detectFrom($root))->toMatchArray(['cores' => 2, 'source' => 'cgroup', 'origin' => 'cpu.max']);
    });

    // Inside a cgroup namespace the path /proc names is not under the mount; the mount is the cgroup.
    test('a cgroup path missing under the mount falls back to the mount itself', function (): void {
        $root = ($this->fixture)([
            'proc/self/status' => cpuStatus('0-19'),
            'proc/self/cgroup' => "0::/docker/0123abcd\n",
            'sys/fs/cgroup/cpu.max' => "300000 100000\n",
        ]);

        expect(CpuBudget::detectFrom($root)['cores'])->toBe(3);
    });

    test('reads a cgroup v1 CFS quota', function (): void {
        $root = ($this->fixture)([
            'proc/self/status' => cpuStatus('0-11'),
            'proc/self/cgroup' => "12:memory:/docker/abc\n4:cpu,cpuacct:/docker/abc\n1:name=systemd:/docker/abc\n",
            'sys/fs/cgroup/cpu,cpuacct/cpu.cfs_quota_us' => "300000\n",
            'sys/fs/cgroup/cpu,cpuacct/cpu.cfs_period_us' => "100000\n",
        ]);

        expect(CpuBudget::detectFrom($root))->toBe([
            'cores' => 3,
            'source' => 'cgroup',
            'origin' => 'cpu.cfs_quota_us',
            'detail' => 'cgroup cpu.cfs_quota_us 300000 / cpu.cfs_period_us 100000',
        ]);
    });

    test('a v1 quota of -1 is no limit', function (): void {
        $root = ($this->fixture)([
            'proc/self/status' => cpuStatus('0-5'),
            'proc/self/cgroup' => "4:cpu,cpuacct:/\n",
            'sys/fs/cgroup/cpu,cpuacct/cpu.cfs_quota_us' => "-1\n",
            'sys/fs/cgroup/cpu,cpuacct/cpu.cfs_period_us' => "100000\n",
        ]);

        expect(CpuBudget::detectFrom($root))->toMatchArray(['cores' => 6, 'source' => 'affinity']);
    });

    test('falls back to the online CPU count when nothing is readable', function (): void {
        $root = ($this->fixture)([]);

        expect(CpuBudget::detectFrom($root, 12))->toBe(['cores' => 12, 'source' => 'online', 'origin' => 'sysconf', 'detail' => 'online CPUs']);
    });

    test('a quota still limits the online count', function (): void {
        $root = ($this->fixture)([
            'proc/self/cgroup' => "0::/\n",
            'sys/fs/cgroup/cpu.max' => "200000 100000\n",
        ]);

        expect(CpuBudget::detectFrom($root, 12))->toMatchArray(['cores' => 2, 'source' => 'cgroup']);
    });

    test('an unreadable affinity list or quota is ignored', function (): void {
        $root = ($this->fixture)([
            'proc/self/status' => cpuStatus('garbage'),
            'proc/self/cgroup' => "0::/\n",
            'sys/fs/cgroup/cpu.max' => "lots 0\n",
        ]);

        expect(CpuBudget::detectFrom($root, 7))->toMatchArray(['cores' => 7, 'source' => 'online']);
    });
});

describe('CpuBudget::countCpuList()', function (): void {
    test('counts ranges and single CPUs', function (): void {
        expect(CpuBudget::countCpuList('0-3,8-11,15'))->toBe(9)
            ->and(CpuBudget::countCpuList('0'))->toBe(1)
            ->and(CpuBudget::countCpuList("0-19\n"))->toBe(20)
        ;
    });

    test('rejects what is not a CPU list', function (): void {
        expect(CpuBudget::countCpuList(''))->toBeNull()
            ->and(CpuBudget::countCpuList('3-1'))->toBeNull()
            ->and(CpuBudget::countCpuList('a-b'))->toBeNull()
            ->and(CpuBudget::countCpuList('0,,2'))->toBeNull()
        ;
    });
});

describe('CpuBudget::autoProcesses()', function (): void {
    // The examples the configuration docs give.
    test('is a third of the cores, between 2 and 8', function (int $cores, int $processes): void {
        expect(CpuBudget::autoProcesses($cores))->toBe($processes);
    })->with([
        [1, 2],
        [4, 2],
        [8, 2],
        [12, 4],
        [20, 6],
        [24, 8],
        [64, 8],
    ]);
});

describe('CpuBudget::detect()', function (): void {
    test('detects this host once and keeps the answer', function (): void {
        CpuBudget::forget();
        $first = CpuBudget::detect();

        expect($first['cores'])->toBeGreaterThanOrEqual(1)
            ->and(['cgroup', 'affinity', 'online'])->toContain($first['source'])
            ->and(CpuBudget::cores())->toBe($first['cores'])
            ->and(CpuBudget::detect())->toBe($first)
        ;
    });

    test('names where the count came from without a long dash', function (): void {
        foreach (['cgroup', 'affinity', 'online'] as $source) {
            expect(CpuBudget::sourceLabel($source))->not->toBe('')
                ->not->toMatch('/[\x{2013}\x{2014}]/u')
            ;
        }
    });
});
