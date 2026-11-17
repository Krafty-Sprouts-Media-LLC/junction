<?php

/**
 * A test fixture: a plugin that hands Junction events of its own.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\JunctionAddonFixture;

use KraftySprouts\Junction\Hooks\CollectEvents;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * Answers junction:CollectEvents with whatever its static outbox holds, then
 * empties it, as a real add-on hands each event over once.
 *
 * @since 1.0.0
 */
final class AddonFixture implements Plugin
{
    /** @var list<mixed> what the next ask hands over */
    public static array $outbox = [];

    /** @since 1.0.0 */
    public function register(Hooks $hooks): void
    {
        $hooks->on(CollectEvents::class, function (CollectEvents $ask): array {
            $events = self::$outbox;
            self::$outbox = [];

            return $events;
        });
    }
}
