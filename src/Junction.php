<?php

/**
 * Junction's plugin class: what Teme builds, starts and asks for Junction's
 * jobs and admin area.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction;

use KraftySprouts\Junction\Area\JunctionArea;
use KraftySprouts\Junction\Delivery\Dispatcher;
use KraftySprouts\Junction\Delivery\Sender;
use KraftySprouts\Junction\Events\EventRecorder;
use KraftySprouts\Junction\Jobs\DeliverJob;
use KraftySprouts\Junction\Jobs\PruneJob;
use KraftySprouts\Junction\Jobs\SendTestJob;
use KraftySprouts\Junction\Payload\ItemDetails;
use KraftySprouts\Junction\Payload\PayloadBuilder;
use KraftySprouts\Junction\Storage\DeliveryStore;
use KraftySprouts\Junction\Storage\EndpointStore;
use KraftySprouts\Junction\Storage\EventStore;
use Teme\Contracts\Admin\HasPluginArea;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Notices\Notices;
use Teme\Contracts\People\PeopleReader;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Site\SiteInfo;

/**
 * Sends the site's events to the endpoints an administrator adds. Hook
 * listeners write each event to an outbox; a job every minute turns the
 * outbox into signed deliveries and sends them, retrying with growing waits.
 *
 * @since 1.0.0
 */
final class Junction implements HasPluginArea, Plugin, PluginHasJobs
{
    /** Junction's settings group. @since 1.0.0 */
    public const string SETTINGS = 'plugin.junction';

    private readonly EndpointStore $endpoints;

    private readonly EventStore $events;

    private readonly DeliveryStore $deliveries;

    /**
     * Teme hands the constructor only the doors Junction's capabilities open.
     *
     * @since 1.0.0
     */
    public function __construct(
        private readonly SettingsReader $settings,
        private readonly SiteInfo $site,
        private readonly ContentReader $content,
        private readonly PeopleReader $people,
        private readonly OutboundHttp $http,
        private readonly Jobs $jobs,
        private readonly Notices $notices,
    ) {
        $this->endpoints = new EndpointStore;
        $this->events = new EventStore;
        $this->deliveries = new DeliveryStore;
    }

    /**
     * Subscribes to Teme's hooks. Nothing slow happens here.
     *
     * @since 1.0.0
     */
    public function register(Hooks $hooks): void
    {
        (new EventRecorder($this->endpoints, $this->events))->register($hooks);
    }

    /**
     * The handlers for the jobs the manifest declares.
     *
     * @since 1.0.0
     */
    public function jobs(): array
    {
        return [
            'deliver' => new DeliverJob($this->dispatcher(), $this->sender()),
            'send-test' => new SendTestJob($this->endpoints, $this->deliveries, $this->site, $this->sender()),
            'prune' => new PruneJob($this->deliveries, $this->settings),
        ];
    }

    /**
     * The data behind Junction's own area in the admin.
     *
     * @since 1.0.0
     */
    public function pluginArea(): PluginArea
    {
        return new JunctionArea($this->endpoints, $this->deliveries, $this->events, $this->settings, $this->jobs);
    }

    /**
     * Turns waiting events into deliveries.
     *
     * @since 1.0.0
     */
    private function dispatcher(): Dispatcher
    {
        return new Dispatcher($this->events, $this->endpoints, $this->deliveries, new PayloadBuilder, new ItemDetails($this->content, $this->people), $this->site);
    }

    /**
     * Sends due deliveries.
     *
     * @since 1.0.0
     */
    private function sender(): Sender
    {
        return new Sender($this->deliveries, $this->endpoints, $this->http, $this->notices, $this->settings);
    }
}
