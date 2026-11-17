<?php

/**
 * Junction's hook for other plugins: hand Junction events of your own to
 * send to the endpoints that take them.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Hooks;

use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\HookContract;

/**
 * Asked once a minute, from Junction's deliver job, for events other plugins
 * want sent. A listener returns a list of arrays, each with `event`, a name
 * starting with the listener's own prefix and a dot such as
 * `shop.order_placed`, and `data`, plain JSON-ready values of at most 32 KB;
 * it hands each event over once, and keeps none of a person's private data
 * in it. Listen as `junction:CollectEvents`, with `krafty-sprouts/junction`
 * under `requires.plugins`.
 *
 * @since 1.0.0
 */
#[HookContract]
final readonly class CollectEvents implements Collect
{
    /**
     * @param  string  $askedAt  when Junction asked, ISO 8601 in UTC
     *
     * @since 1.0.0
     */
    public function __construct(public string $askedAt) {}

    /**
     * Each item is an array: `['event' => 'shop.order_placed', 'data' => [...]]`.
     *
     * @since 1.0.0
     */
    public static function itemType(): string
    {
        return 'array';
    }
}
