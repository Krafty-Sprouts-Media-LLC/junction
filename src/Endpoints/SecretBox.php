<?php

/**
 * Makes, locks and unlocks endpoints' signing secrets.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Endpoints;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Keeps each endpoint's signing secret encrypted at rest with the site's
 * own key, and makes new secrets from the system's random source.
 *
 * @since 1.0.0
 */
final class SecretBox
{
    /** What every secret Junction makes starts with, so people can tell one. @since 1.0.0 */
    public const string PREFIX = 'jcsec_';

    /**
     * A new secret: the prefix and 48 hexadecimal characters (192 bits).
     *
     * @since 1.0.0
     */
    public static function generate(): string
    {
        return self::PREFIX.bin2hex(random_bytes(24));
    }

    /**
     * The secret encrypted for the table.
     *
     * @since 1.0.0
     */
    public static function lock(string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    /**
     * The secret in plain text, or an empty string when it can't be read,
     * such as after the site's key changed without its previous key kept.
     *
     * @since 1.0.0
     */
    public static function unlock(string $locked): string
    {
        try {
            return Crypt::decryptString($locked);
        } catch (DecryptException) {
            return '';
        }
    }
}
