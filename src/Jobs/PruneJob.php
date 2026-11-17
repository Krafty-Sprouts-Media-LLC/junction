<?php

/**
 * The daily job that removes log entries older than the administrator's
 * chosen period.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Jobs;

use KraftySprouts\Junction\Junction;
use KraftySprouts\Junction\Storage\DeliveryStore;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Removes tries and finished deliveries older than "Keep the log for";
 * deliveries that still wait are kept, whatever their age.
 *
 * @since 1.0.0
 */
final readonly class PruneJob implements JobHandler
{
    /** @since 1.0.0 */
    public function __construct(
        private DeliveryStore $deliveries,
        private SettingsReader $settings,
    ) {}

    /**
     * Prunes the log.
     *
     * @since 1.0.0
     */
    public function handle(JobContext $context): void
    {
        $days = max(1, min(365, $this->settings->int(Junction::SETTINGS, 'retention_days')));
        $removed = $this->deliveries->prune($days);

        $context->progress(1, 1, __('Removed :attempts tries and :deliveries deliveries older than :days days.', [
            'attempts' => $removed['attempts'],
            'deliveries' => $removed['deliveries'],
            'days' => $days,
        ]));
    }
}
