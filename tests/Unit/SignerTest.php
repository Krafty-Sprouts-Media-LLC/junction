<?php

/**
 * The signature's format, its check, and the waits between tries.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

use KraftySprouts\Junction\Delivery\Backoff;
use KraftySprouts\Junction\Delivery\Signer;

it('signs the timestamp and body as the README documents', function (): void {
    $body = '{"event":"junction.test"}';

    expect(Signer::sign('made-up-secret', 1700000000, $body))
        ->toBe('sha256='.hash_hmac('sha256', '1700000000.'.$body, 'made-up-secret'));
});

it('refuses a changed body, a wrong secret and an old timestamp', function (): void {
    $body = '{"a":1}';
    $signature = Signer::sign('made-up-secret', 1000, $body);

    expect(Signer::verify('made-up-secret', 1000, $body, $signature, 1000))->toBeTrue()
        ->and(Signer::verify('made-up-secret', 1000, '{"a":2}', $signature, 1000))->toBeFalse()
        ->and(Signer::verify('another-secret', 1000, $body, $signature, 1000))->toBeFalse()
        ->and(Signer::verify('made-up-secret', 1000, $body, $signature, 1000 + 301))->toBeFalse();
});

it('waits longer after each failure, up to six hours', function (): void {
    expect(array_map(Backoff::after(...), [1, 2, 3, 4, 5, 9]))->toBe([60, 300, 1800, 7200, 21600, 21600]);
});
