<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

/**
 * Joins filter terms so none can change another's meaning: unparenthesised,
 * `bytes > 1M and proto udp or proto tcp` applied the threshold to UDP only.
 */
final class FilterComposer {
    /**
     * `(t1) and (t2)` over the non-empty terms. A single term is returned as it is, since
     * there is nothing it could bind to.
     *
     * @throws \InvalidArgumentException for a term whose `)` would close the wrapper around it
     */
    public static function and(string ...$terms): string {
        $kept = array_values(array_filter(array_map('trim', $terms), self::hasExpression(...)));

        foreach ($kept as $term) {
            if (!self::isBalanced($term)) {
                throw new \InvalidArgumentException('Unbalanced parentheses in the filter.');
            }
        }

        if (\count($kept) <= 1) {
            return $kept[0] ?? '';
        }

        return implode(' and ', array_map(self::parenthesise(...), $kept));
    }

    /** False for a term that is only whitespace and `#` comments, which nfdump reads as nothing. */
    private static function hasExpression(string $term): bool {
        return trim((string) preg_replace('/#[^\n]*/', '', $term)) !== '';
    }

    /** Counts parentheses the way nfdump tokenizes: not inside a one-line quoted string or a comment. */
    private static function isBalanced(string $term): bool {
        $bare = (string) preg_replace('/"[^"\n]*"|\'[^\'\n]*\'|#[^\n]*/', '', $term);
        $depth = 0;
        foreach (str_split((string) preg_replace('/[^()]/', '', $bare)) as $parenthesis) {
            $depth += $parenthesis === '(' ? 1 : -1;
            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /** A `#` comment runs to the end of its line, so its closing parenthesis goes on the next. */
    private static function parenthesise(string $term): string {
        return '(' . $term . (str_contains($term, '#') ? "\n" : '') . ')';
    }
}
