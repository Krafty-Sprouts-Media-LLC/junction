<?php

/**
 * Signs a delivery so its receiver can tell it came from this site and
 * wasn't changed on the way.
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
 * HMAC-SHA256 over the timestamp, a dot and the exact body, with the
 * endpoint's secret: `sha256=` and 64 hexadecimal characters. The receiver
 * recomputes it and also refuses an old timestamp, so a captured delivery
 * can't be replayed later.
 *
 * @since 1.0.0
 */
final class Signer
{
    /** The header carrying the delivery's ID, the same on every retry. @since 1.0.0 */
    public const string DELIVERY_HEADER = 'X-Junction-Delivery';

    /** The header carrying the event's name. @since 1.0.0 */
    public const string EVENT_HEADER = 'X-Junction-Event';

    /** The header carrying the Unix time the delivery was signed. @since 1.0.0 */
    public const string TIMESTAMP_HEADER = 'X-Junction-Timestamp';

    /** The header carrying the signature. @since 1.0.0 */
    public const string SIGNATURE_HEADER = 'X-Junction-Signature';

    /**
     * The body exactly as it is sent, which the signature covers.
     *
     * @param  array<string, mixed>  $body
     *
     * @since 1.0.0
     */
    public static function encode(array $body): string
    {
        return (string) json_encode($body, JSON_THROW_ON_ERROR);
    }

    /**
     * The signature: `sha256=` and the hex HMAC of "timestamp.body".
     *
     * @since 1.0.0
     */
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Whether a signature is right and its timestamp within $tolerance
     * seconds of $now: what a receiver checks, kept here for the tests and
     * the README's examples.
     *
     * @since 1.0.0
     */
    public static function verify(string $secret, int $timestamp, string $body, string $signature, int $now, int $tolerance = 300): bool
    {
        return abs($now - $timestamp) <= $tolerance && hash_equals(self::sign($secret, $timestamp, $body), $signature);
    }

    /**
     * Every header a delivery carries besides the content type.
     *
     * @return array<string, string>
     *
     * @since 1.0.0
     */
    public static function headers(string $deliveryId, string $event, string $secret, int $timestamp, string $body): array
    {
        return [
            self::DELIVERY_HEADER => $deliveryId,
            self::EVENT_HEADER => $event,
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::SIGNATURE_HEADER => self::sign($secret, $timestamp, $body),
            'User-Agent' => 'Junction/1.0 (TemeCMS)',
        ];
    }
}
