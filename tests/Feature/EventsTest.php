<?php

/**
 * Which hook becomes which event, for the hooks the kit can't fire itself.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

use KraftySprouts\Junction\Events\EventRecorder;
use KraftySprouts\Junction\Storage\EndpointStore;
use KraftySprouts\Junction\Storage\EventStore;
use KraftySprouts\Junction\Tests\Support\Junctions;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentRestored;
use Teme\Contracts\Content\ContentScheduled;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Hooks\Order;
use Teme\Contracts\Hooks\Subscription;
use Teme\Contracts\Media\MediaRef;
use Teme\Contracts\Media\MediaStored;
use Teme\Testing\TemeTestCase;

uses(TemeTestCase::class);

/**
 * Subscribes an EventRecorder to a fake Hooks and answers its listeners by
 * hook class, so a test can fire a hook Teme's kit has no helper for.
 *
 * @return array<class-string, callable>
 */
function junctionListeners(): array
{
    $hooks = new class implements Hooks
    {
        /** @var array<class-string, callable> */
        public array $listeners = [];

        public function on(string $hook, callable $listener, ?Order $order = null): Subscription
        {
            $this->listeners[$hook] = $listener;

            return new Subscription('junction-test-'.count($this->listeners));
        }

        public function off(Subscription $subscription): void {}
    };

    (new EventRecorder(new EndpointStore, new EventStore))->register($hooks);

    return $hooks->listeners;
}

beforeEach(function (): void {
    $this->junction = $this->plugin(junctionFolder())->enable();
    $this->fake = $this->outbound();
    $this->item = new ItemRef('01jtestitem0000000000000000', 'post', 'Hooked', 'published', 'https://site.example/hooked/', 'en-US');
});

it('turns a new comment into comment.received, and an approval into comment.approved', function (): void {
    Junctions::endpoint(['events' => ['comment.received', 'comment.approved']]);
    $listen = junctionListeners()[CommentStatusChanged::class];

    $listen(new CommentStatusChanged('c1', $this->item, null, 'pending', null));
    $listen(new CommentStatusChanged('c1', $this->item, 'pending', 'approved', '1'));
    $listen(new CommentStatusChanged('c1', $this->item, 'approved', 'spam', '1'));

    expect(array_column((new EventStore)->oldest(10), 'event'))->toBe(['comment.received', 'comment.approved']);

    $data = (new EventStore)->oldest(1)[0]['data'];
    expect($data['comment'])->toBe(['id' => 'c1', 'status' => 'pending'])
        ->and(json_encode($data))->not->toContain('@');
});

it('turns a stored file into media.uploaded', function (): void {
    Junctions::endpoint(['events' => ['media.uploaded']]);

    junctionListeners()[MediaStored::class](new MediaStored(new MediaRef('01JMEDIA', 'image', 'image/png', 'cat.png', 'A cat', 1234, 640, 480), false, '1'));

    $data = (new EventStore)->oldest(1)[0]['data'];
    expect($data['media'])->toMatchArray(['id' => '01JMEDIA', 'kind' => 'image', 'title' => 'A cat', 'width' => 640]);
});

it('turns scheduling, restoring and deleting into their events', function (): void {
    Junctions::endpoint();
    $listeners = junctionListeners();

    $listeners[ContentScheduled::class](new ContentScheduled($this->item, 'rev1', new DateTimeImmutable('2026-12-01 09:30:00', new DateTimeZone('UTC')), '1'));
    $listeners[ContentRestored::class](new ContentRestored($this->item, '1'));
    $listeners[ContentDeleted::class](new ContentDeleted('01jtestitem0000000000000000', 'post', 'Hooked', '1'));

    $events = (new EventStore)->oldest(10);
    expect(array_column($events, 'event'))->toBe(['item.scheduled', 'item.restored', 'item.deleted'])
        ->and($events[0]['data']['scheduled_for'])->toBe('2026-12-01T09:30:00Z')
        ->and($events[2]['data']['item'])->toBe(['id' => '01jtestitem0000000000000000', 'type' => 'post', 'title' => 'Hooked']);
});
