<?php

/**
 * Pest's set-up for Junction: every feature test runs on Teme's testing kit,
 * against a fresh site on the junction_test database.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

// Each feature test file names the kit's test case itself, as the handbook
// shows: uses(Teme\Testing\TemeTestCase::class);

/**
 * The folder holding Junction's teme-plugin.json, for the kit's plugin().
 *
 * @since 1.0.0
 */
function junctionFolder(): string
{
    return dirname(__DIR__);
}
