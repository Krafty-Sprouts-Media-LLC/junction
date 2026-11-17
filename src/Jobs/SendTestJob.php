<?php

/**
 * The job behind "Send a test event": a junction.test delivery to every
 * endpoint that is on, or to one endpoint, sent at once.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Jobs;

use Carbon\CarbonImmutable;
use KraftySprouts\Junction\Delivery\Sender;
use KraftySprouts\Junction\Endpoints\Endpoint;
use KraftySprouts\Junction\Events\EventNames;
use KraftySprouts\Junction\Payload\ChatFormat;
use KraftySprouts\Junction\Payload\PayloadBuilder;
use KraftySprouts\Junction\Storage\DeliveryStore;
use KraftySprouts\Junction\Storage\EndpointStore;
use KraftySprouts\Junction\Support\Ids;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Site\SiteInfo;

/**
 * Makes a test delivery for each endpoint asked for, signed like any other,
 * then sends them in the same run so the log shows the answer at once.
 *
 * @since 1.0.0
 */
final readonly class SendTestJob implements JobHandler
{
    /** @since 1.0.0 */
    public function __construct(
        private EndpointStore $endpoints,
        private DeliveryStore $deliveries,
        private SiteInfo $site,
        private Sender $sender,
    ) {}

    /**
     * Sends the tests. A payload's `endpoint` names one endpoint; without
     * one, every active endpoint gets a test.
     *
     * @since 1.0.0
     */
    public function handle(JobContext $context): void
    {
        $only = $context->payload()['endpoint'] ?? null;
        $endpoints = is_string($only)
            ? array_filter([$this->endpoints->find($only)])
            : $this->endpoints->active();

        $ids = array_map(fn (Endpoint $endpoint): string => $this->queueTest($endpoint), $endpoints);

        foreach ($ids as $done => $id) {
            $context->progress($done, count($ids), __('Sending test events'));
            $this->sender->sendOne($id, $context->personId());
        }

        $context->progress(count($ids), count($ids), trans_choice('{0} No endpoint is on.|{1} Sent one test event.|[2,*] Sent :count test events.', count($ids), ['count' => count($ids)]));
    }

    /**
     * Makes one endpoint's test delivery, and answers its ID.
     *
     * @since 1.0.0
     */
    private function queueTest(Endpoint $endpoint): string
    {
        $eventId = Ids::make();
        $payload = (new PayloadBuilder)->build(
            EventNames::TEST,
            $eventId,
            CarbonImmutable::now(),
            ['message' => __('This is a test from Junction. If you can read it, this endpoint works.'), 'endpoint' => $endpoint->name],
            $this->site->name(),
            $this->site->address(),
        );

        return $this->deliveries->create($endpoint->id, EventNames::TEST, $eventId, ChatFormat::body($payload, $endpoint->format));
    }
}
