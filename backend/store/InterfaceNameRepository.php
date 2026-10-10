<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

/** The interface names learned from the exporters, per source and SNMP index (#178). */
final class InterfaceNameRepository {
    public function __construct(private readonly Database $db) {}

    /**
     * Stores what one capture file named; a name an older file gave is replaced, one a newer file gave stays.
     *
     * @param array<int, string> $names ifIndex => name
     */
    public function remember(string $source, array $names, int $seen): void {
        if ($names === []) {
            return;
        }
        $this->db->transaction(static function (Database $db) use ($source, $names, $seen): void {
            foreach ($names as $index => $name) {
                $db->exec(
                    'INSERT INTO interface_names (source, if_index, name, seen) VALUES (?, ?, ?, ?)
                     ON CONFLICT (source, if_index) DO UPDATE SET name = excluded.name, seen = excluded.seen WHERE excluded.seen >= interface_names.seen',
                    [$source, $index, $name, $seen],
                );
            }
        });
    }

    /** @return array<string, array<int, string>> source => ifIndex => name */
    public function all(): array {
        $names = [];
        foreach ($this->db->all('SELECT source, if_index, name FROM interface_names ORDER BY source, if_index') as $row) {
            $names[(string) $row['source']][(int) $row['if_index']] = (string) $row['name'];
        }

        return $names;
    }
}
