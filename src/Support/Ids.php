<?php

/**
 * Makes the IDs Junction gives endpoints, events and deliveries.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Support;

use Illuminate\Support\Str;

/**
 * Lower-case ULIDs: sortable by time, unique, and safe in a header.
 *
 * @since 1.0.0
 */
final class Ids
{
    /**
     * A new lower-case ULID, 26 characters.
     *
     * @since 1.0.0
     */
    public static function make(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * Splits a list typed with commas into its trimmed, lower-case parts.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public static function splitList(mixed $typed): array
    {
        if (is_array($typed)) {
            $typed = implode(',', array_map(strval(...), $typed));
        }

        $parts = array_map(fn (string $part): string => strtolower(trim($part)), explode(',', (string) $typed));

        return array_values(array_unique(array_filter($parts, fn (string $part): bool => $part !== '')));
    }
}
