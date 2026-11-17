<?php

/**
 * Listens to Teme's hooks and writes each event an endpoint takes to the
 * outbox, for the deliver job to send.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Events;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use KraftySprouts\Junction\Storage\EndpointStore;
use KraftySprouts\Junction\Storage\EventStore;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentRestored;
use Teme\Contracts\Content\ContentScheduled;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Media\MediaStored;
use Teme\Contracts\Settings\SettingsChanged;

/**
 * Turns each Teme hook into one of Junction's events and records it, only
 * when some active endpoint takes that event. It never sends anything and
 * never queues a job: a listener runs inside a page load, so it writes one
 * small row and returns. Teme sends these hooks after the change commits.
 *
 * @since 1.0.0
 */
final readonly class EventRecorder
{
    /** @since 1.0.0 */
    public function __construct(
        private EndpointStore $endpoints,
        private EventStore $events,
    ) {}

    /**
     * Subscribes to every hook Junction's manifest names.
     *
     * @since 1.0.0
     */
    public function register(Hooks $hooks): void
    {
        $hooks->on(ContentPublished::class, function (ContentPublished $hook): void {
            $this->record($hook->first ? EventNames::ITEM_PUBLISHED : EventNames::ITEM_UPDATED, [
                'item' => self::item($hook->item),
                'revision_id' => $hook->revisionId,
                'published_at' => self::time($hook->publishedAt),
            ]);
        });

        $hooks->on(ContentUnpublished::class, function (ContentUnpublished $hook): void {
            $this->record(EventNames::ITEM_UNPUBLISHED, ['item' => self::item($hook->item), 'former_url' => $hook->formerUrl]);
        });

        $hooks->on(ContentScheduled::class, function (ContentScheduled $hook): void {
            $this->record(EventNames::ITEM_SCHEDULED, ['item' => self::item($hook->item), 'scheduled_for' => self::time($hook->publishAt)]);
        });

        $hooks->on(ContentTrashed::class, function (ContentTrashed $hook): void {
            $this->record(EventNames::ITEM_TRASHED, ['item' => self::item($hook->item), 'former_url' => $hook->formerUrl]);
        });

        $hooks->on(ContentRestored::class, function (ContentRestored $hook): void {
            $this->record(EventNames::ITEM_RESTORED, ['item' => self::item($hook->item)]);
        });

        $hooks->on(ContentDeleted::class, function (ContentDeleted $hook): void {
            $this->record(EventNames::ITEM_DELETED, ['item' => ['id' => $hook->id, 'type' => $hook->type, 'title' => $hook->title]]);
        });

        $hooks->on(CommentStatusChanged::class, function (CommentStatusChanged $hook): void {
            $event = match (true) {
                $hook->from === null => EventNames::COMMENT_RECEIVED,
                $hook->to === 'approved' && $hook->from !== 'approved' => EventNames::COMMENT_APPROVED,
                default => null,
            };

            if ($event !== null) {
                $this->record($event, ['comment' => ['id' => $hook->commentId, 'status' => $hook->to], 'item' => self::item($hook->item)]);
            }
        });

        $hooks->on(MediaStored::class, function (MediaStored $hook): void {
            $this->record(EventNames::MEDIA_UPLOADED, ['media' => [
                'id' => $hook->media->id,
                'kind' => $hook->media->kind,
                'mime_type' => $hook->media->mimeType,
                'title' => $hook->media->title,
                'size_bytes' => $hook->media->sizeBytes,
                'width' => $hook->media->width,
                'height' => $hook->media->height,
                'replaced' => $hook->replaced,
            ]]);
        });

        $hooks->on(SettingsChanged::class, function (SettingsChanged $hook): void {
            // Names only, never values: the hook carries none, and Junction asks for none.
            $this->record(EventNames::SETTINGS_CHANGED, ['settings' => ['group' => $hook->group, 'keys' => $hook->keys]]);
        });
    }

    /**
     * Records an event when an active endpoint takes it.
     *
     * @param  array<string, mixed>  $data
     *
     * @since 1.0.0
     */
    public function record(string $event, array $data): void
    {
        if ($this->endpoints->anyTakes($event)) {
            $this->events->record($event, $data);
        }
    }

    /**
     * The plain values of an item that a hook names. A draft's or a trashed
     * item's address is null, as Teme gives it.
     *
     * @return array{id: string, type: string, title: string, status: string, url: string|null, language: string}
     *
     * @since 1.0.0
     */
    public static function item(ItemRef $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'title' => $item->title,
            'status' => $item->status,
            'url' => $item->url,
            'language' => $item->language,
        ];
    }

    /**
     * A moment as ISO 8601 in UTC.
     *
     * @since 1.0.0
     */
    public static function time(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
