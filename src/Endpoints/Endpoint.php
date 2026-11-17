<?php

/**
 * One endpoint as Junction keeps it: where it sends, what it takes, how it
 * is signed and whether it is paused.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Endpoints;

use DateTimeImmutable;
use KraftySprouts\Junction\Events\EventNames;

/**
 * An endpoint an administrator added: its address, the events and items it
 * takes, the format and fields it is sent, and its signing secret in plain
 * text, read only while a delivery is signed or its form is open.
 *
 * @since 1.0.0
 */
final readonly class Endpoint
{
    /**
     * @param  list<string>  $events  Junction's own event names it takes
     * @param  list<string>  $otherEvents  other plugins' event names it takes, or ['*'] for all
     * @param  list<string>  $types  content-type keys it is limited to; empty for every type
     * @param  list<string>  $categories  category slugs it is limited to; empty for every category
     * @param  list<string>  $tags  tag slugs it is limited to; empty for every tag
     * @param  list<string>  $fields  item fields it is sent; empty for all of them
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public bool $enabled,
        public string $format,
        public int $timeout,
        public array $events,
        public array $otherEvents,
        public array $types,
        public array $categories,
        public array $tags,
        public array $fields,
        public string $secret,
        public int $failures = 0,
        public ?DateTimeImmutable $pausedAt = null,
    ) {}

    /**
     * Whether Junction sends to it now: it is on and not paused.
     *
     * @since 1.0.0
     */
    public function active(): bool
    {
        return $this->enabled && $this->pausedAt === null;
    }

    /**
     * Whether it takes this event, by name; every endpoint takes a test.
     *
     * @since 1.0.0
     */
    public function takes(string $event): bool
    {
        if ($event === EventNames::TEST || in_array($event, $this->events, true)) {
            return true;
        }

        if (! EventNames::isOtherPluginEvent($event)) {
            return false;
        }

        return in_array('*', $this->otherEvents, true) || in_array($event, $this->otherEvents, true);
    }

    /**
     * Whether it has any filter that needs an item's categories or tags.
     *
     * @since 1.0.0
     */
    public function filtersByTerms(): bool
    {
        return $this->categories !== [] || $this->tags !== [];
    }

    /**
     * Whether an item of this type with these terms passes its filters. An
     * event about no item always passes; one about an item whose terms
     * couldn't be read passes only when no category or tag filter is set.
     *
     * @param  array<string, list<string>>|null  $terms  term slugs by taxonomy, or null when unknown
     *
     * @since 1.0.0
     */
    public function acceptsItem(?string $type, ?array $terms): bool
    {
        if ($type === null) {
            return true;
        }

        if ($this->types !== [] && ! in_array($type, $this->types, true)) {
            return false;
        }

        if (! $this->filtersByTerms()) {
            return true;
        }

        if ($terms === null) {
            return false;
        }

        if ($this->categories !== [] && array_intersect($this->categories, $terms['category'] ?? []) === []) {
            return false;
        }

        return $this->tags === [] || array_intersect($this->tags, $terms['tag'] ?? []) !== [];
    }

    /**
     * The host its address names, in lower case.
     *
     * @since 1.0.0
     */
    public function host(): string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_HOST));
    }
}
