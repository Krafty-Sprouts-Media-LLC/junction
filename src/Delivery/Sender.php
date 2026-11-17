<?php

/**
 * Sends due deliveries through Teme's outbound door, records each try, and
 * retries, fails or pauses as the answers say.
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
use KraftySprouts\Junction\Junction;
use KraftySprouts\Junction\Storage\DeliveryStore;
use KraftySprouts\Junction\Storage\EndpointStore;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Http\RequestFailed;
use Teme\Contracts\Notices\NoticeMessage;
use Teme\Contracts\Notices\Notices;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

/**
 * One call for each due delivery, within the endpoint's time limit: a 2xx
 * answer delivers it; anything else waits for the next try, until its tries
 * are used. A host nobody agreed to fails at once. An endpoint that fails
 * too often in a row is paused, and the people who manage plugins are told.
 *
 * @since 1.0.0
 */
final readonly class Sender
{
    /** Deliveries sent in one run. @since 1.0.0 */
    public const int BATCH = 25;

    /** Seconds a run keeps sending; a job is stopped at 75. @since 1.0.0 */
    public const int BUDGET_SECONDS = 50;

    /** The notice kind for a paused endpoint. @since 1.0.0 */
    public const string PAUSED_NOTICE = 'endpoint_paused';

    /** @since 1.0.0 */
    public function __construct(
        private DeliveryStore $deliveries,
        private EndpointStore $endpoints,
        private OutboundHttp $http,
        private Notices $notices,
        private SettingsReader $settings,
    ) {}

    /**
     * Sends what is due, acting for this person, and answers how many
     * deliveries it tried.
     *
     * @since 1.0.0
     */
    public function send(string $personId): int
    {
        $started = hrtime(true);
        $tried = 0;

        foreach ($this->deliveries->due(self::BATCH) as $id) {
            if ((hrtime(true) - $started) / 1e9 > self::BUDGET_SECONDS) {
                break;
            }

            $this->sendOne($id, $personId);
            $tried++;
        }

        return $tried;
    }

    /**
     * Sends one delivery and records what happened.
     *
     * @since 1.0.0
     */
    public function sendOne(string $id, string $personId): void
    {
        $delivery = $this->deliveries->find($id);
        $endpoint = $delivery === null ? null : $this->endpoints->find($delivery['endpoint_id']);

        if ($delivery === null || $endpoint === null || $delivery['status'] !== DeliveryStore::WAITING) {
            return;
        }

        $attempt = $delivery['attempts'] + 1;
        $body = Signer::encode($delivery['payload']);
        $headers = Signer::headers($delivery['id'], $delivery['event'], $endpoint->secret, CarbonImmutable::now()->getTimestamp(), $body);
        $started = hrtime(true);
        $status = null;
        $reply = '';
        $error = null;
        $final = false;

        try {
            $answer = $this->http->postJson($endpoint->url, $delivery['payload'], $headers, max(1, min(10, $endpoint->timeout)));
            $status = $answer->status;
            $reply = self::excerpt($answer->body);
        } catch (HostRefused $refused) {
            $error = __('The endpoint’s host isn’t agreed to: list its address under Settings › Junction and agree to it.');
            $final = true;
        } catch (AnswerTooLarge) {
            $error = __('The endpoint answered with more than Junction reads.');
        } catch (RequestFailed $failed) {
            $error = self::excerpt($failed->getMessage());
        } catch (Throwable $thrown) {
            $error = self::excerpt($thrown->getMessage());
        }

        $ok = $status !== null && $status >= 200 && $status < 300;
        $this->deliveries->recordAttempt($delivery['id'], $endpoint->id, $endpoint->name, $delivery['event'], $ok, $status, $error, $reply, (int) ((hrtime(true) - $started) / 1e6));

        if ($ok) {
            $this->deliveries->delivered($delivery['id'], $attempt);
            $this->endpoints->recordSuccess($endpoint->id);

            return;
        }

        if ($final || $attempt >= $this->maxAttempts()) {
            $this->deliveries->failed($delivery['id'], $attempt);
        } else {
            $this->deliveries->retryAt($delivery['id'], $attempt, CarbonImmutable::now()->addSeconds(Backoff::after($attempt)));
        }

        $failures = $this->endpoints->recordFailure($endpoint->id);

        if ($failures >= $this->pauseAfter() && $this->endpoints->pause($endpoint->id)) {
            $this->tellPaused($endpoint, $failures, $personId);
        }
    }

    /**
     * Tells the people who manage plugins that an endpoint was paused.
     *
     * @since 1.0.0
     */
    private function tellPaused(Endpoint $endpoint, int $failures, string $personId): void
    {
        try {
            $this->notices->send(self::PAUSED_NOTICE, [$personId], new NoticeMessage(
                subject: mb_substr(__('Junction paused the endpoint “:name”', ['name' => $endpoint->name]), 0, 120),
                lines: [
                    mb_substr(__('Its last :count deliveries in a row failed, so Junction stopped sending to :host.', ['count' => $failures, 'host' => $endpoint->host()]), 0, 500),
                    __('Deliveries to it wait. Check the receiving app, then open the endpoint in Junction and save it to start it again.'),
                ],
                actionLabel: __('Open Junction'),
                actionRoute: 'plugins.area.show',
                actionParameters: ['code' => 'junction'],
            ));
        } catch (Throwable) {
            // The pause stands even when the notice can't be sent; the endpoint's row says it is paused.
        }
    }

    /**
     * How many tries a delivery gets.
     *
     * @since 1.0.0
     */
    private function maxAttempts(): int
    {
        return max(1, min(10, $this->settings->int(Junction::SETTINGS, 'max_attempts')));
    }

    /**
     * How many failures in a row pause an endpoint.
     *
     * @since 1.0.0
     */
    private function pauseAfter(): int
    {
        return max(1, min(100, $this->settings->int(Junction::SETTINGS, 'pause_after')));
    }

    /**
     * The start of a reply or message, as plain text on one line.
     *
     * @since 1.0.0
     */
    public static function excerpt(string $text, int $length = 300): string
    {
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
