<?php

/**
 * Junction's admin area as core calls it: endpoint forms and their checks,
 * the endpoint and log tables, row actions, and the overview.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use KraftySprouts\Junction\Area\JunctionArea;
use KraftySprouts\Junction\Endpoints\SecretBox;
use KraftySprouts\Junction\Storage\DeliveryStore;
use KraftySprouts\Junction\Storage\EndpointStore;
use KraftySprouts\Junction\Storage\EventStore;
use KraftySprouts\Junction\Tests\Support\FakeDoors;
use KraftySprouts\Junction\Tests\Support\Junctions;
use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Admin\Chart;
use Teme\Contracts\Admin\TableQuery;
use Teme\Contracts\Content\WriteRefused;
use Teme\Testing\TemeTestCase;

uses(TemeTestCase::class);

beforeEach(function (): void {
    $this->plugin(junctionFolder())->enable();
    $this->jobs = FakeDoors::jobs();
    $this->area = new JunctionArea(
        new EndpointStore,
        new DeliveryStore,
        new EventStore,
        FakeDoors::settings(['endpoint_urls' => [Junctions::URL]]),
        $this->jobs,
    );
    $this->context = new AreaContext('1', 'en', 'UTC', ['manage']);
    $this->form = fn (array $overrides = []): array => [
        'name' => 'Make: new posts',
        'url' => Junctions::URL,
        'enabled' => true,
        'timeout' => 10,
        'events' => ['item.published'],
        'other_events' => '',
        'types' => [],
        'secret' => '',
        'send_test' => false,
        ...$overrides,
    ];
});

it('saves a new endpoint with a fresh secret, kept encrypted', function (): void {
    $saved = $this->area->save('endpoint', null, ($this->form)(), $this->context);

    $values = $this->area->values('endpoint', $saved->id, $this->context);
    expect($values['secret'])->toStartWith(SecretBox::PREFIX)
        ->and($values['name'])->toBe('Make: new posts')
        ->and($values['send_test'])->toBeFalse()
        ->and(DB::table(EndpointStore::TABLE)->value('secret'))->not->toContain($values['secret']);
});

it('keeps a secret typed in, and splits typed lists', function (): void {
    $saved = $this->area->save('endpoint', null, ($this->form)([
        'secret' => 'my-own-made-up-secret-for-tests',
        'other_events' => 'shop.order_placed, *',
    ]), $this->context);

    $endpoint = (new EndpointStore)->find($saved->id);
    expect($endpoint->secret)->toBe('my-own-made-up-secret-for-tests')
        ->and($endpoint->categories)->toBe([])
        ->and($endpoint->otherEvents)->toBe(['shop.order_placed', '*']);
});

it('refuses an address whose host was not agreed to', function (): void {
    $this->area->save('endpoint', null, ($this->form)(['url' => 'https://elsewhere.example.org/hook']), $this->context);
})->throws(WriteRefused::class, 'Settings › Junction');

it('refuses an address that is not https', function (): void {
    $this->area->save('endpoint', null, ($this->form)(['url' => 'http://hooks.example.com/junction-test']), $this->context);
})->throws(WriteRefused::class, 'https');

it('refuses an event name another plugin could not send', function (): void {
    $this->area->save('endpoint', null, ($this->form)(['other_events' => 'item.sneaky']), $this->context);
})->throws(WriteRefused::class);

it('queues a test to the endpoint when asked on save', function (): void {
    $saved = $this->area->save('endpoint', null, ($this->form)(['send_test' => true]), $this->context);

    expect($this->jobs->queued)->toBe([['key' => 'send-test', 'payload' => ['endpoint' => $saved->id]]]);
});

it('lists endpoints with their state, and finds them by name', function (): void {
    Junctions::endpoint(['name' => 'Zapier digest']);
    $paused = Junctions::endpoint(['name' => 'n8n flow']);
    (new EndpointStore)->pause($paused->id);

    $page = $this->area->rows('endpoint', new TableQuery(1, 25, '', [], 'name', 'asc'), $this->context);
    expect($page->total)->toBe(2)
        ->and($page->rows[0]->cells['name']->value)->toBe('n8n flow')
        ->and($page->rows[0]->cells['state']->value)->toBe('Paused')
        ->and($page->rows[1]->cells['host']->value)->toBe(Junctions::HOST);

    $found = $this->area->rows('endpoint', new TableQuery(1, 25, 'digest', [], null, 'asc'), $this->context);
    expect($found->total)->toBe(1);
});

it('turns endpoints off and on, copies and deletes them', function (): void {
    $endpoint = Junctions::endpoint();
    $store = new EndpointStore;

    $this->area->act('endpoint', 'disable', [$endpoint->id], $this->context);
    expect($store->find($endpoint->id)->enabled)->toBeFalse();

    $this->area->act('endpoint', 'enable', [$endpoint->id], $this->context);
    expect($store->find($endpoint->id)->enabled)->toBeTrue();

    $this->area->act('endpoint', 'duplicate', [$endpoint->id], $this->context);
    expect(DB::table(EndpointStore::TABLE)->count())->toBe(2);

    $this->area->act('endpoint', 'delete', [$endpoint->id], $this->context);
    expect($store->find($endpoint->id))->toBeNull();
});

it('resends a delivery from its try, as a new delivery of the same event', function (): void {
    $endpoint = Junctions::endpoint();
    $store = new DeliveryStore;
    $delivery = $store->create($endpoint->id, 'item.published', 'event00000000000000000001', ['event' => 'item.published']);
    $store->recordAttempt($delivery, $endpoint->id, $endpoint->name, 'item.published', false, 500, null, 'oops', 12);
    $store->failed($delivery, 6);
    $attempt = (string) DB::table(DeliveryStore::ATTEMPTS)->value('id');

    $this->area->act('attempt', 'duplicate', [$attempt], $this->context);

    $new = DB::table(DeliveryStore::TABLE)->where('id', '!=', $delivery)->first();
    expect($new->status)->toBe('waiting')
        ->and($new->event_id)->toBe('event00000000000000000001')
        ->and($this->jobs->queued[0]['key'])->toBe('deliver');
});

it('shows a try with what was sent and what came back, and lists tries', function (): void {
    $endpoint = Junctions::endpoint();
    $store = new DeliveryStore;
    $delivery = $store->create($endpoint->id, 'junction.test', 'event00000000000000000002', ['event' => 'junction.test']);
    $store->recordAttempt($delivery, $endpoint->id, $endpoint->name, 'junction.test', true, 200, null, 'Accepted', 40);
    $attempt = (string) DB::table(DeliveryStore::ATTEMPTS)->value('id');

    $values = $this->area->values('attempt', $attempt, $this->context);
    expect($values['reply'])->toBe('Accepted')
        ->and($values['sent'])->toContain('junction.test')
        ->and($values['delivery'])->toBe($delivery);

    $page = $this->area->rows('attempt', new TableQuery(1, 50, '', [], null, 'desc'), $this->context);
    expect($page->rows[0]->cells['result']->value)->toBe('Delivered')
        ->and($page->rows[0]->cells['code']->value)->toBe(200);
});

it('refuses to change a try', function (): void {
    $this->area->save('attempt', '1', [], $this->context);
})->throws(WriteRefused::class);

it('answers the overview figures and chart', function (): void {
    Junctions::endpoint();

    $figures = $this->area->figures('overview', '0', $this->context);
    $chart = $this->area->chart('overview', '1', $this->context);

    expect($figures['endpoints']->value)->toBe(1)
        ->and($figures['waiting']->value)->toBe(0)
        ->and($chart)->toBeInstanceOf(Chart::class)
        ->and($chart->labels)->toHaveCount(14);
});
