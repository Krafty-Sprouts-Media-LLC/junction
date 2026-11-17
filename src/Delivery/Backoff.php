<?php

/**
 * How long Junction waits before trying a failed delivery again.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Delivery;

/**
 * Waits that grow with each failure: 1 minute, 5 minutes, 30 minutes,
 * 2 hours, then 6 hours for every later try.
 *
 * @since 1.0.0
 */
final class Backoff
{
    /** Seconds to wait after the first, second, third and fourth failure. @since 1.0.0 */
    public const array STEPS = [60, 300, 1800, 7200];

    /** Seconds to wait after any later failure. @since 1.0.0 */
    public const int LONGEST = 21600;

    /**
     * Seconds to wait after this many failed tries, counted from 1.
     *
     * @since 1.0.0
     */
    public static function after(int $failedTries): int
    {
        return self::STEPS[max(0, $failedTries - 1)] ?? self::LONGEST;
    }
}
