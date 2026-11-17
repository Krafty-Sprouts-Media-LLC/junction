<?php

/**
 * Turns the events waiting in the outbox into one delivery for each
 * endpoint that takes them.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Delivery;

use Carbon\CarbonImmutable;
use KraftySprouts\Junction\Endpoints\Endpoint;
use KraftySprouts\Junction\Events\EventNames;
use KraftySprouts\Junction\Payload\ChatFormat;
use KraftySprouts\Junction\Payload\ItemDetails;
use KraftySprouts\Junction\Payload\PayloadBuilder;
use KraftySprouts\Junction\Storage\DeliveryStore;
use KraftySprouts\Junction\Storage\EndpointStore;
use KraftySprouts\Junction\Storage\EventStore;
use Teme\Contracts\Site\SiteInfo;

/**
 * For each waiting event, reads what may be sent about its item once, then
 * makes a delivery, with its payload frozen as it will be sent, for each
 * active endpoint whose events and filters it passes. The event leaves the
 * outbox when its deliveries are made.
 *
 * @since 1.0.0
 */
final readonly class Dispatcher
{
    /** Events taken from the outbox in one run. @since 1.0.0 */
    public const int BATCH = 200;

    /** @since 1.0.0 */
    public function __construct(
        private EventStore $events,
        private EndpointStore $endpoints,
        private DeliveryStore $deliveries,
        private PayloadBuilder $payloads,
        private ItemDetails $details,
        private SiteInfo $site,
    ) {}

    /**
     * Makes the deliveries for waiting events, acting for this person, and
     * answers how many deliveries it made.
     *
     * @since 1.0.0
     */
    public function dispatch(string $personId): int
    {
        $made = 0;
        $endpoints = $this->endpoints->active();

        foreach ($this->events->oldest(self::BATCH) as $event) {
            $takers = array_values(array_filter($endpoints, fn (Endpoint $endpoint): bool => $endpoint->takes($event['event'])));
            $made += $takers === [] ? 0 : $this->fanOut($event, $takers, $personId);
            $this->events->forget($event['row']);
        }

        return $made;
    }

    /**
     * Makes one event's deliveries.
     *
     * @param  array{row: int, id: string, event: string, data: array<string, mixed>, occurred_at: CarbonImmutable}  $event
     * @param  list<Endpoint>  $takers
     *
     * @since 1.0.0
     */
    private function fanOut(array $event, array $takers, string $personId): int
    {
        $item = is_array($event['data']['item'] ?? null) ? $event['data']['item'] : null;
        $type = $item === null ? null : (string) ($item['type'] ?? '');
        $details = ['terms' => null, 'public' => null];

        $needsTerms = array_filter($takers, fn (Endpoint $endpoint): bool => $endpoint->filtersByTerms()) !== [];
        $isPublic = in_array($event['event'], [EventNames::ITEM_PUBLISHED, EventNames::ITEM_UPDATED], true);

        if ($item !== null && $event['event'] !== EventNames::ITEM_DELETED && ($needsTerms || $isPublic)) {
            $publicRevision = $isPublic ? (string) ($event['data']['revision_id'] ?? '') : null;
            $details = $this->details->read((string) $item['id'], $personId, $publicRevision === '' ? null : $publicRevision);
        }

        $payload = $this->payloads->build(
            $event['event'],
            $event['id'],
            $event['occurred_at'],
            $event['data'],
            $this->site->name(),
            $this->site->address(),
            $details['public'],
        );

        $made = 0;

        foreach ($takers as $endpoint) {
            if (! $endpoint->acceptsItem($type, $details['terms'])) {
                continue;
            }

            $body = ChatFormat::body(PayloadBuilder::pick($payload, $endpoint->fields), $endpoint->format);
            $this->deliveries->create($endpoint->id, $event['event'], $event['id'], $body);
            $made++;
        }

        return $made;
    }
}
