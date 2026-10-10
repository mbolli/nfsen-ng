<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\InterfaceNameRepository;
use OpenSwoole\Coroutine;

/**
 * Names for SNMP interface indexes (#178): the configured ones (NFSEN_INTERFACES, settings.php) and those the
 * exporters send with their flows, learned from the capture files. A configured name wins. An index only means
 * something on its exporter, so every name belongs to a source.
 */
final class InterfaceNames {
    /** A source's names are read from one of its new capture files at most this often. */
    public const int LEARN_EVERY = 3600;

    /** The learned names are read from the store again after this many seconds. */
    private const int RELOAD_AFTER = 60;

    /** What nfdump prints for an index without a name. */
    private const array NO_NAME = ['', '<no if name>', '<ingress not found>'];

    /** @var null|array<string, array<int, string>> */
    private static ?array $learned = null;

    private static int $loadedAt = 0;

    /** @var array<string, int> source => when its last learning run started */
    private static array $learnedAt = [];

    /** The name of `$index` on `$source`, or null. */
    public static function name(string $source, int|string $index): ?string {
        $index = (int) $index;

        return Config::$settings->interfaceNames[$source][$index] ?? self::learned()[$source][$index] ?? null;
    }

    /**
     * The name of `$index` across `$sources` (every configured source when empty): the one name the sources that
     * know the index agree on, or null when none knows it or they disagree.
     *
     * @param list<string> $sources
     */
    public static function nameIn(array $sources, int|string $index): ?string {
        if (!is_numeric($index) || (int) $index < 1) {
            return null;
        }
        $names = [];
        foreach ($sources !== [] ? $sources : Config::$settings->sources as $source) {
            $name = self::name($source, $index);
            if ($name !== null) {
                $names[$name] = true;
            }
        }

        return \count($names) === 1 ? (string) array_key_first($names) : null;
    }

    /**
     * The names in nfdump's `-A inif,outif -o 'fmt:%in|%inam|%out|%onam'` output.
     *
     * @return array<int, string> ifIndex => name
     */
    public static function parse(string $raw): array {
        $names = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $fields = array_map('trim', explode('|', $line));
            if (\count($fields) !== 4) {
                continue;
            }
            foreach ([[$fields[0], $fields[1]], [$fields[2], $fields[3]]] as [$index, $name]) {
                if (ctype_digit($index) && (int) $index > 0 && !\in_array($name, self::NO_NAME, true)) {
                    $names[(int) $index] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * After an import of `$relPath`: reads the names in it, in a coroutine of its own, unless the source was read
     * within LEARN_EVERY. Outside a coroutine (CLI, tests) it does nothing.
     */
    public static function learnFrom(string $profile, string $source, string $relPath, int $ts): void {
        $now = time();
        if (Coroutine::getCid() <= 0 || $now - (self::$learnedAt[$source] ?? 0) < self::LEARN_EVERY) {
            return;
        }
        self::$learnedAt[$source] = $now;
        Coroutine::create(static function () use ($profile, $source, $relPath, $ts): void {
            try {
                $names = self::read($profile, $source, $relPath);
                new InterfaceNameRepository(Database::shared())->remember($source, $names, $ts);
                self::$learned = null;
                if ($names !== []) {
                    Debug::getInstance()->log('Interface names: ' . \count($names) . " from {$source} {$relPath}", LOG_DEBUG);
                }
            } catch (\Throwable $e) {
                Debug::getInstance()->log("Interface names: cannot read {$source} {$relPath}: " . $e->getMessage(), LOG_DEBUG);
            }
        });
    }

    /**
     * Every known name by source and index, and whether it was learned (a configured one wins).
     *
     * @return array<string, array<int, array{name: string, learned: bool}>>
     */
    public static function all(): array {
        $all = [];
        foreach ([[self::learned(), true], [Config::$settings->interfaceNames, false]] as [$names, $learned]) {
            foreach ($names as $source => $indexes) {
                foreach ($indexes as $index => $name) {
                    $all[$source][$index] = ['name' => $name, 'learned' => $learned];
                }
            }
        }
        foreach ($all as &$indexes) {
            ksort($indexes);
        }
        ksort($all);

        return $all;
    }

    /** Forgets the cached learned names and learning times (tests). */
    public static function reset(): void {
        self::$learned = null;
        self::$loadedAt = 0;
        self::$learnedAt = [];
    }

    /** @return array<int, string> */
    private static function read(string $profile, string $source, string $relPath): array {
        $processor = new Config::$processorClass();
        $processor->setProfile($profile);
        $processor->setOption('-M', $source);
        $processor->setOption('-r', $relPath);
        $processor->setOption('-A', 'inif,outif');
        $processor->setOption('-o', 'fmt:%in|%inam|%out|%onam');
        $processor->setOption('-q', null);

        return self::parse(NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, static fn (): array => $processor->execute())['rawOutput']);
    }

    /** @return array<string, array<int, string>> */
    private static function learned(): array {
        if (self::$learned === null || time() - self::$loadedAt >= self::RELOAD_AFTER) {
            try {
                self::$learned = new InterfaceNameRepository(Database::shared())->all();
            } catch (\Throwable) {
                self::$learned = [];
            }
            self::$loadedAt = time();
        }

        return self::$learned;
    }
}
