<?php

/**
 * Builds the JSON payload Junction sends for an event, from what the hook
 * recorded and what may be read about the item.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Payload;

use Carbon\CarbonImmutable;
use KraftySprouts\Junction\Events\EventNames;

/**
 * Every payload has the same envelope: the event's name and ID, when it
 * happened, the site, and its data. Only public facts go in: an item's
 * excerpt, author and terms only while the item is public, and never a
 * password, a reader's email address, a comment's text or a secret.
 *
 * @since 1.0.0
 */
final class PayloadBuilder
{
    /**
     * The item fields an endpoint can choose from, in the order sent.
     *
     * @since 1.0.0
     */
    public const array ITEM_FIELDS = ['id', 'type', 'title', 'url', 'status', 'language', 'published_at', 'scheduled_for', 'author', 'excerpt', 'featured_image', 'terms'];

    /**
     * The payload for one recorded event.
     *
     * @param  array<string, mixed>  $data  what the listener recorded
     * @param  array{author: string|null, excerpt: string|null, terms: array<string, list<string>>}|null  $public  what may be sent about a public item
     * @return array{event: string, event_id: string, occurred_at: string, site: array{name: string, url: string}, data: array<string, mixed>}
     *
     * @since 1.0.0
     */
    public function build(string $event, string $eventId, CarbonImmutable $occurredAt, array $data, string $siteName, string $siteUrl, ?array $public = null): array
    {
        return [
            'event' => $event,
            'event_id' => $eventId,
            'occurred_at' => $occurredAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'site' => ['name' => $siteName, 'url' => $siteUrl],
            'data' => $this->data($event, $data, $public),
        ];
    }

    /**
     * The data part, by kind of event.
     *
     * @param  array<string, mixed>  $data
     * @param  array{author: string|null, excerpt: string|null, terms: array<string, list<string>>}|null  $public
     * @return array<string, mixed>
     *
     * @since 1.0.0
     */
    private function data(string $event, array $data, ?array $public): array
    {
        if (! is_array($data['item'] ?? null)) {
            return $data;
        }

        $item = $data['item'];
        $out = [];

        foreach (['id', 'type', 'title', 'url', 'status', 'language'] as $key) {
            if (array_key_exists($key, $item)) {
                $out[$key] = $item[$key];
            }
        }

        if (isset($data['published_at'])) {
            $out['published_at'] = $data['published_at'];
        }

        if (isset($data['scheduled_for'])) {
            $out['scheduled_for'] = $data['scheduled_for'];
        }

        if ($public !== null && in_array($event, [EventNames::ITEM_PUBLISHED, EventNames::ITEM_UPDATED], true)) {
            $out['author'] = $public['author'];
            $out['excerpt'] = $public['excerpt'];
            // Teme offers plugins no media address yet, so a featured image can't be named (FINDINGS W4).
            $out['featured_image'] = null;
            $out['terms'] = $public['terms'] === [] ? (object) [] : $public['terms'];
        }

        $rest = array_diff_key($data, ['item' => true, 'published_at' => true, 'scheduled_for' => true, 'revision_id' => true]);

        return ['item' => $out, ...$rest];
    }

    /**
     * Keeps only the item fields an endpoint chose; every field when it
     * chose none. Parts of the data that aren't the item are kept.
     *
     * @param  array{event: string, event_id: string, occurred_at: string, site: array{name: string, url: string}, data: array<string, mixed>}  $payload
     * @param  list<string>  $fields
     * @return array{event: string, event_id: string, occurred_at: string, site: array{name: string, url: string}, data: array<string, mixed>}
     *
     * @since 1.0.0
     */
    public static function pick(array $payload, array $fields): array
    {
        if ($fields === [] || ! is_array($payload['data']['item'] ?? null)) {
            return $payload;
        }

        $payload['data']['item'] = array_intersect_key($payload['data']['item'], array_flip($fields));

        return $payload;
    }
}
