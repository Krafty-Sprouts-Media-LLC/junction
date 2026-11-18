<?php

/**
 * The job that runs every minute: makes deliveries from the outbox, and
 * sends what is due.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Jobs;

use KraftySprouts\Junction\Delivery\Dispatcher;
use KraftySprouts\Junction\Delivery\Sender;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

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
    /** @since 1.0.0 */
    public function __construct(
        private Dispatcher $dispatcher,
        private Sender $sender,
    ) {}

    /**
     * Dispatches and sends.
     *
     * @since 1.0.0
     */
    public function handle(JobContext $context): void
    {
        $person = $context->personId();

        $context->progress(0, 2, __('Making deliveries'));
        $this->dispatcher->dispatch($person);

        $context->progress(1, 2, __('Sending deliveries'));
        $tried = $this->sender->send($person);

        $context->progress(2, 2, trans_choice('{0} Nothing was due.|{1} Tried one delivery.|[2,*] Tried :count deliveries.', $tried, ['count' => $tried]));
    }
}
