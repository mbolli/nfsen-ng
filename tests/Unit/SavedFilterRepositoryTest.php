<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\DuplicateFilterException;
use mbolli\nfsen_ng\store\SavedFilterRepository;

beforeEach(function (): void {
    // list() seeds the presets on first use: none here, so every row comes from the test.
    $this->deploymentFiltersBefore = Config::$deploymentFilters;
    $this->prefsFileBefore = isset(Config::$prefsFile) ? Config::$prefsFile : null;
    Config::$deploymentFilters = [];
    Config::$prefsFile = '';
    $this->db = Database::open(':memory:');
    $this->filters = new SavedFilterRepository($this->db);
});

afterEach(function (): void {
    Config::$deploymentFilters = $this->deploymentFiltersBefore;
    Config::$prefsFile = $this->prefsFileBefore ?? '';
});

describe('SavedFilterRepository::key()', function (): void {
    test('trims and collapses whitespace runs', function (): void {
        expect(SavedFilterRepository::key("  proto tcp\n\tand   dst port 443 \r\n"))->toBe('proto tcp and dst port 443')
            ->and(SavedFilterRepository::key('proto tcp'))->toBe('proto tcp')
            ->and(SavedFilterRepository::key(" \n "))->toBe('')
        ;
    });

    test('names an unnamed filter after its expression, cut to 60 characters', function (): void {
        $long = 'src net 10.0.0.0/8 and dst net 192.168.0.0/16 and not dst port 53 and bytes > 1000';

        expect(SavedFilterRepository::defaultName($long))->toBe(mb_substr($long, 0, 60))
            ->and(mb_strlen(SavedFilterRepository::defaultName($long)))->toBe(60)
            ->and(SavedFilterRepository::defaultName("proto\n  udp"))->toBe('proto udp')
        ;
    });
});

describe('SavedFilterRepository::create()', function (): void {
    test('stores the filter and returns its id', function (): void {
        $before = time();
        $id = $this->filters->create('Web', ' dst port 443 ', starred: true);

        $filter = $this->filters->find($id);

        expect($filter)->toMatchArray([
            'id' => $id,
            'name' => 'Web',
            'expression' => 'dst port 443',
            'starred' => true,
            'origin' => 'user',
            'lastUsedAt' => null,
            'useCount' => 0,
        ])
            ->and($filter['createdAt'])->toBeGreaterThanOrEqual($before)
            ->and($filter['updatedAt'])->toBe($filter['createdAt'])
            ->and($this->db->value('SELECT expression_key FROM saved_filters WHERE id = ?', [$id]))->toBe('dst port 443')
        ;
    });

    test('keeps the expression as written apart from the outer whitespace', function (): void {
        $id = $this->filters->create('Multi', "proto tcp # web\nand dst port 443\n");

        expect($this->filters->find($id)['expression'])->toBe("proto tcp # web\nand dst port 443");
    });

    test('names a filter without a name after its expression', function (): void {
        $id = $this->filters->create('  ', "dst port 53\n or src port 53");

        expect($this->filters->find($id)['name'])->toBe('dst port 53 or src port 53');
    });

    test('rejects the same normalised expression with the existing name', function (): void {
        $id = $this->filters->create('DNS', 'port 53');

        try {
            $this->filters->create('Other', "  port\t53\n");
            $this->fail('expected a DuplicateFilterException');
        } catch (DuplicateFilterException $e) {
            expect($e->existingName)->toBe('DNS')
                ->and($e->existingId)->toBe($id)
                ->and($e->getMessage())->toBe('Already saved as DNS')
            ;
        }
    });

    test('rejects an empty expression', function (): void {
        $this->filters->create('Nothing', " \n ");
    })->throws(InvalidArgumentException::class, 'The filter expression is empty.');

    test('rejects a name over 80 characters', function (): void {
        $this->filters->create(str_repeat('n', 81), 'proto tcp');
    })->throws(InvalidArgumentException::class);

    test('accepts a name of exactly 80 characters', function (): void {
        $name = str_repeat('ü', 80);

        expect($this->filters->find($this->filters->create($name, 'proto tcp'))['name'])->toBe($name);
    });

    test('rejects an unknown origin', function (): void {
        $this->filters->create('x', 'proto tcp', 'somewhere');
    })->throws(InvalidArgumentException::class);
});

describe('SavedFilterRepository::list()', function (): void {
    test('orders starred first, then last used, then name', function (): void {
        $plain = $this->filters->create('beta', 'proto udp');
        $alpha = $this->filters->create('Alpha', 'proto tcp');
        $usedLongAgo = $this->filters->create('Zulu', 'proto icmp');
        $usedRecently = $this->filters->create('Yankee', 'port 53');
        $starred = $this->filters->create('Mike', 'port 80', starred: true);
        $starredUsed = $this->filters->create('November', 'port 443', starred: true);
        $this->filters->touch($usedLongAgo, 1000);
        $this->filters->touch($usedRecently, 2000);
        $this->filters->touch($starredUsed, 500);

        expect(array_column($this->filters->list(), 'id'))->toBe([$starredUsed, $starred, $usedRecently, $usedLongAgo, $alpha, $plain]);
    });

    test('searches name and expression case-insensitively', function (): void {
        $web = $this->filters->create('Web traffic', 'dst port 443');
        $dns = $this->filters->create('Resolver', 'dst port 53');
        $this->filters->create('Größe', 'bytes > 1000000');

        expect(array_column($this->filters->list('WEB'), 'id'))->toBe([$web])
            ->and(array_column($this->filters->list('Port 53'), 'id'))->toBe([$dns])
            ->and(array_column($this->filters->list('größe'), 'name'))->toBe(['Größe'])
            ->and(array_column($this->filters->list('GRÖ'), 'name'))->toBe(['Größe'])
            ->and($this->filters->list('  '))->toHaveCount(3)
            ->and($this->filters->list('nothing like it'))->toBe([])
        ;
    });

    test('returns the documented shape', function (): void {
        $this->filters->create('Web', 'dst port 443');

        expect(array_keys($this->filters->list()[0]))->toBe(['id', 'name', 'expression', 'starred', 'origin', 'createdAt', 'updatedAt', 'lastUsedAt', 'useCount']);
    });

    test('seeds the presets on the first call', function (): void {
        Config::$deploymentFilters = ['proto tcp'];

        expect(array_column($this->filters->list(), 'origin'))->toBe(['deployment']);
    });

    test('seeds again on the next call after a seed failed', function (): void {
        Config::$deploymentFilters = [42]; // not a string: the seed throws
        expect($this->filters->list())->toBe([]);

        Config::$deploymentFilters = ['proto tcp'];
        expect(array_column($this->filters->list(), 'origin'))->toBe(['deployment']);
    });

    test('does not seed a read-only store', function (): void {
        $path = sys_get_temp_dir() . '/nfsen-saved-filters-' . bin2hex(random_bytes(6)) . '.sqlite';
        Database::open($path);
        Config::$deploymentFilters = ['proto tcp'];

        try {
            expect(new SavedFilterRepository(Database::open($path, readOnly: true))->list())->toBe([]);
        } finally {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path . $suffix);
            }
        }
    });
});

describe('SavedFilterRepository changes', function (): void {
    test('find() answers null for an unknown id', function (): void {
        expect($this->filters->find(999))->toBeNull();
    });

    test('rename() changes the name only', function (): void {
        $id = $this->filters->create('Old', 'proto tcp');
        $this->db->exec('UPDATE saved_filters SET updated_at = 1 WHERE id = ?', [$id]);

        $this->filters->rename($id, '  New   name ');

        expect($this->filters->find($id))->toMatchArray(['name' => 'New name', 'expression' => 'proto tcp'])
            ->and($this->filters->find($id)['updatedAt'])->toBeGreaterThan(1)
        ;
    });

    test('rename() to nothing falls back to the expression', function (): void {
        $id = $this->filters->create('Old', 'proto tcp');
        $this->filters->rename($id, '');

        expect($this->filters->find($id)['name'])->toBe('proto tcp');
    });

    test('rename() of a deleted filter says so', function (): void {
        $this->filters->rename(999, 'x');
    })->throws(InvalidArgumentException::class, 'The saved filter no longer exists.');

    test('update() changes name and expression', function (): void {
        $id = $this->filters->create('Web', 'dst port 80');
        $this->filters->update($id, 'TLS', 'dst port 443');

        expect($this->filters->find($id))->toMatchArray(['name' => 'TLS', 'expression' => 'dst port 443'])
            ->and($this->db->value('SELECT expression_key FROM saved_filters WHERE id = ?', [$id]))->toBe('dst port 443')
        ;
    });

    test('update() may reformat its own expression', function (): void {
        $id = $this->filters->create('Web', 'dst port 80');
        $this->filters->update($id, 'Web', "dst  port\n80");

        expect($this->filters->find($id)['expression'])->toBe("dst  port\n80");
    });

    test('update() rejects the expression of another filter', function (): void {
        $this->filters->create('DNS', 'port 53');
        $id = $this->filters->create('Web', 'port 80');

        $this->filters->update($id, 'Web', 'port  53');
    })->throws(DuplicateFilterException::class, 'Already saved as DNS');

    test('update() of a deleted filter says so', function (): void {
        $this->filters->update(999, 'x', 'proto tcp');
    })->throws(InvalidArgumentException::class, 'The saved filter no longer exists.');

    test('star() sets and clears the star', function (): void {
        $id = $this->filters->create('Web', 'port 80');

        $this->filters->star($id, true);
        $starred = $this->filters->find($id)['starred'];
        $this->filters->star($id, false);

        expect($starred)->toBeTrue()
            ->and($this->filters->find($id)['starred'])->toBeFalse()
        ;
    });

    test('touch() records the use', function (): void {
        $id = $this->filters->create('Web', 'port 80');

        $this->filters->touch($id, 1_700_000_000);
        $this->filters->touch($id, 1_700_000_600);

        expect($this->filters->find($id))->toMatchArray(['lastUsedAt' => 1_700_000_600, 'useCount' => 2]);
    });

    test('delete() removes the filter and frees its expression', function (): void {
        $id = $this->filters->create('Web', 'port 80');
        $this->filters->delete($id);

        expect($this->filters->find($id))->toBeNull()
            ->and($this->filters->create('Web again', 'port 80'))->toBeInt()
        ;
    });
});

describe('SavedFilterRepository::import()', function (): void {
    test('inserts new expressions, skips known keys and empty ones, and counts the inserts', function (): void {
        $this->filters->create('Mine', 'proto tcp');

        $count = $this->filters->import([
            ['name' => 'TCP again', 'expression' => '  proto   tcp '],
            ['name' => 'UDP', 'expression' => 'proto udp'],
            ['name' => 'UDP twice', 'expression' => "proto\nudp"],
            ['name' => 'Empty', 'expression' => '   '],
            ['name' => '', 'expression' => 'dst port 53'],
        ], 'browser');

        $filters = $this->filters->list();

        expect($count)->toBe(2)
            ->and(array_column($filters, 'name', 'expression'))->toBe([
                'dst port 53' => 'dst port 53',
                'proto tcp' => 'Mine',
                'proto udp' => 'UDP',
            ])
            ->and(array_column($filters, 'origin', 'name'))->toBe(['dst port 53' => 'browser', 'Mine' => 'user', 'UDP' => 'browser'])
        ;
    });

    test('cuts long names to 80 characters instead of failing', function (): void {
        $this->filters->import([['name' => str_repeat('n', 100), 'expression' => 'proto tcp']], 'preference');

        expect(mb_strlen($this->filters->list()[0]['name']))->toBe(80);
    });

    test('rejects an unknown origin', function (): void {
        $this->filters->import([['name' => 'x', 'expression' => 'proto tcp']], 'nowhere');
    })->throws(InvalidArgumentException::class);
});
