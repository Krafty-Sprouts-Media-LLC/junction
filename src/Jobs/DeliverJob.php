<?php

/**
 * The job that runs every minute: gathers other plugins' events, makes
 * deliveries from the outbox, and sends what is due.
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
use KraftySprouts\Junction\Delivery\Dispatcher;
use KraftySprouts\Junction\Delivery\Sender;
use KraftySprouts\Junction\Events\EventNames;
use KraftySprouts\Junction\Events\EventRecorder;
use KraftySprouts\Junction\Hooks\CollectEvents;
use Teme\Contracts\Hooks\HookSender;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Throwable;

/**
 * Runs as the administrator who enabled Junction when its schedule queues
 * it, or as the person who pressed a button. Each step is safe to repeat: an
 * event leaves the outbox only once its deliveries exist, and a delivery is
 * sent only while it waits.
 *
 * @since 1.0.0
 */
final readonly class DeliverJob implements JobHandler
{
    /** The most bytes of data another plugin's event may carry. @since 1.0.0 */
    public const int MOST_OTHER_DATA = 32768;

    /** The most events taken from other plugins in one run. @since 1.0.0 */
    public const int MOST_OTHER_EVENTS = 500;

    /** @since 1.0.0 */
    public function __construct(
        private Dispatcher $dispatcher,
        private Sender $sender,
        private HookSender $hooks,
        private EventRecorder $recorder,
    ) {}

    /**
     * Gathers, dispatches and sends.
     *
     * @since 1.0.0
     */
    public function handle(JobContext $context): void
    {
        $person = $context->personId();

        $context->progress(0, 3, __('Gathering other plugins’ events'));
        $this->gather();

        $context->progress(1, 3, __('Making deliveries'));
        $this->dispatcher->dispatch($person);

        $context->progress(2, 3, __('Sending deliveries'));
        $tried = $this->sender->send($person);

        $context->progress(3, 3, trans_choice('{0} Nothing was due.|{1} Tried one delivery.|[2,*] Tried :count deliveries.', $tried, ['count' => $tried]));
    }

    /**
     * Asks other plugins for their events and records each well-formed one
     * an endpoint takes. A listener's fault is Teme's to record; the run goes on.
     *
     * @since 1.0.0
     */
    private function gather(): void
    {
        try {
            $items = $this->hooks->collect(new CollectEvents(CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z')));
        } catch (Throwable) {
            return;
        }

        foreach (array_slice($items, 0, self::MOST_OTHER_EVENTS) as $item) {
            $event = is_array($item) ? ($item['event'] ?? null) : null;
            $data = is_array($item) ? ($item['data'] ?? []) : null;

            if (! is_string($event) || ! is_array($data) || ! EventNames::isOtherPluginEvent($event)) {
                continue;
            }

            $encoded = json_encode($data);

            if ($encoded === false || strlen($encoded) > self::MOST_OTHER_DATA) {
                continue;
            }

            $this->recorder->record($event, ['source' => explode('.', $event, 2)[0], ...$data]);
        }
    }
}
