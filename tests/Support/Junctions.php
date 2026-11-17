<?php

/**
 * Helpers Junction's feature tests share: enabling Junction with an agreed
 * test host, and adding endpoints straight to its own table.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Tests\Support;

use KraftySprouts\Junction\Endpoints\Endpoint;
use KraftySprouts\Junction\Storage\EndpointStore;
use Teme\Testing\PluginUnderTest;

/**
 * Test helpers. The address is a made-up path the kit's fake answers, and every secret is a
 * made-up test value: no test reaches a real address.
 *
 * @since 1.0.0
 */
final class Junctions
{
    /**
     * The test endpoint's host: the one host Junction's manifest names, the
     * only one a test can agree to, since the kit's changeSettings() can't
     * tick the box that agrees to a new host (FINDINGS K1). The kit's fake
     * answers every call, so nothing is ever sent there.
     *
     * @since 1.0.0
     */
    public const string HOST = 'hooks.zapier.com';

    /** The test endpoint's address, a made-up path. @since 1.0.0 */
    public const string URL = 'https://hooks.zapier.com/hooks/catch/0/junction-test';

    /** A made-up signing secret, never a real one. @since 1.0.0 */
    public const string SECRET = 'jcsec_test_not_a_real_secret_0000000000000000';

    /**
     * Adds an endpoint and answers it, as an administrator's form would.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @since 1.0.0
     */
    public static function endpoint(array $overrides = []): Endpoint
    {
        $store = new EndpointStore;
        $id = $store->save(null, [
            'name' => 'Test endpoint',
            'url' => self::URL,
            'enabled' => true,
            'format' => 'json',
            'timeout' => 10,
            'events' => ['item.published', 'item.updated', 'item.unpublished', 'item.trashed', 'item.restored', 'item.deleted', 'item.scheduled', 'settings.changed'],
            'other_events' => [],
            'types' => [],
            'categories' => [],
            'tags' => [],
            'fields' => [],
            'secret' => self::SECRET,
            ...$overrides,
        ]);

        return $store->find($id) ?? throw new \LogicException('The endpoint was not saved.');
    }

    /**
     * Agrees to the test host by saving it in Junction's endpoint addresses.
     *
     * @param  list<string>  $urls
     *
     * @since 1.0.0
     */
    public static function agree(PluginUnderTest $plugin, array $urls = [self::URL]): PluginUnderTest
    {
        return $plugin->changeSettings(['endpoint_urls' => $urls]);
    }
}
