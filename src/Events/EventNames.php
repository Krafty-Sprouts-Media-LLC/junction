<?php

/**
 * The names of the events Junction sends, and the rule an event from
 * another plugin's name must follow.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Events;

/**
 * Every event Junction sends of its own, by name, and the pattern another
 * plugin's event name must match: its own prefix, a dot, then a name.
 *
 * @since 1.0.0
 */
final class EventNames
{
    /** @since 1.0.0 */
    public const string ITEM_PUBLISHED = 'item.published';

    /** @since 1.0.0 */
    public const string ITEM_UPDATED = 'item.updated';

    /** @since 1.0.0 */
    public const string ITEM_UNPUBLISHED = 'item.unpublished';

    /** @since 1.0.0 */
    public const string ITEM_SCHEDULED = 'item.scheduled';

    /** @since 1.0.0 */
    public const string ITEM_TRASHED = 'item.trashed';

    /** @since 1.0.0 */
    public const string ITEM_RESTORED = 'item.restored';

    /** @since 1.0.0 */
    public const string ITEM_DELETED = 'item.deleted';

    /** @since 1.0.0 */
    public const string COMMENT_RECEIVED = 'comment.received';

    /** @since 1.0.0 */
    public const string COMMENT_APPROVED = 'comment.approved';

    /** @since 1.0.0 */
    public const string MEDIA_UPLOADED = 'media.uploaded';

    /** @since 1.0.0 */
    public const string SETTINGS_CHANGED = 'settings.changed';

    /** The event a test sends; every endpoint takes it. @since 1.0.0 */
    public const string TEST = 'junction.test';

    /**
     * Prefixes kept for Junction's own events: another plugin's event may
     * not start with one.
     *
     * @since 1.0.0
     */
    public const array RESERVED_PREFIXES = ['item', 'comment', 'media', 'settings', 'person', 'junction'];

    /**
     * Another plugin's event name: a prefix of lower-case letters, digits
     * and hyphens, a dot, then lower-case letters, digits, dots and
     * underscores; at most 100 characters.
     *
     * @since 1.0.0
     */
    public const string OTHER_PATTERN = '/^[a-z][a-z0-9-]{1,39}\.[a-z][a-z0-9_.]{0,59}$/D';

    /**
     * Junction's own events, in the order the endpoint form lists them.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public static function builtIn(): array
    {
        return [
            self::ITEM_PUBLISHED,
            self::ITEM_UPDATED,
            self::ITEM_UNPUBLISHED,
            self::ITEM_SCHEDULED,
            self::ITEM_TRASHED,
            self::ITEM_RESTORED,
            self::ITEM_DELETED,
            self::COMMENT_RECEIVED,
            self::COMMENT_APPROVED,
            self::MEDIA_UPLOADED,
            self::SETTINGS_CHANGED,
        ];
    }

    /**
     * Whether a name another plugin gave is one Junction will send.
     *
     * @since 1.0.0
     */
    public static function isOtherPluginEvent(string $name): bool
    {
        if (preg_match(self::OTHER_PATTERN, $name) !== 1) {
            return false;
        }

        $prefix = explode('.', $name, 2)[0];

        return ! in_array($prefix, self::RESERVED_PREFIXES, true);
    }
}
