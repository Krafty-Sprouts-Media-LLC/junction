<?php

/**
 * Failed deliveries: retries with growing waits, failing for good, pausing
 * an endpoint that keeps failing, and a host nobody agreed to.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use KraftySprouts\Junction\Storage\DeliveryStore;
use KraftySprouts\Junction\Storage\EndpointStore;
use KraftySprouts\Junction\Tests\Support\Junctions;
use Teme\Testing\TemeTestCase;

uses(TemeTestCase::class);

beforeEach(function (): void {
    $this->junction = Junctions::agree($this->plugin(junctionFolder())->enable());
    $this->fake = $this->outbound();
});

it('tries a failed delivery again after the wait, with the same delivery id', function (): void {
    Junctions::endpoint();
    $this->fake->answerInTurn(Junctions::HOST, [500]);
    $this->publish('Flaky');

    $this->junction->runJob('deliver');
    expect(DB::table(DeliveryStore::TABLE)->value('status'))->toBe('waiting');

    $this->junction->runJob('deliver');
    expect($this->fake->sent(Junctions::HOST))->toHaveCount(1);

    $this->travel(61)->seconds();
    $this->junction->runJob('deliver');

    $sent = $this->fake->sent(Junctions::HOST);
    expect($sent)->toHaveCount(2)
        ->and($sent[0]->headers['X-Junction-Delivery'])->toBe($sent[1]->headers['X-Junction-Delivery'])
        ->and(DB::table(DeliveryStore::TABLE)->value('status'))->toBe('delivered')
        ->and(DB::table(DeliveryStore::ATTEMPTS)->orderBy('id')->pluck('status_code')->all())->toBe([500, 200]);
});

it('marks a delivery failed once its tries are used', function (): void {
    $this->junction->changeSettings(['max_attempts' => 2]);
    Junctions::endpoint();
    $this->fake->answer(Junctions::HOST, 503, 'Down for a bit');
    $this->publish('Never lands');

    $this->junction->runJob('deliver');
    $this->travel(61)->seconds();
    $this->junction->runJob('deliver');

    expect(DB::table(DeliveryStore::TABLE)->value('status'))->toBe('failed')
        ->and(DB::table(DeliveryStore::ATTEMPTS)->value('reply'))->toBe('Down for a bit');
});

it('pauses an endpoint after repeated failures, and sends to it no more', function (): void {
    $this->junction->changeSettings(['pause_after' => 2]);
    $endpoint = Junctions::endpoint();
    $this->fake->answer(Junctions::HOST, 500);
    $this->publish('One');
    $this->publish('Two');

    $this->junction->runJob('deliver');

    expect((new EndpointStore)->find($endpoint->id)->pausedAt)->not->toBeNull();

    $this->publish('Three');
    $this->travel(2)->hours();
    $this->junction->runJob('deliver');

    expect($this->fake->sent(Junctions::HOST))->toHaveCount(2);
});

it('fails a delivery at once when its host was never agreed to', function (): void {
    Junctions::endpoint(['url' => 'https://other.example.net/hook']);
    $this->publish('Nowhere');

    $this->junction->runJob('deliver');

    $this->fake->assertNothingSent();
    expect(DB::table(DeliveryStore::TABLE)->value('status'))->toBe('failed')
        ->and(DB::table(DeliveryStore::ATTEMPTS)->value('error'))->toContain('agreed');
});
