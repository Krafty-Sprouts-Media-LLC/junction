<?php

/**
 * Reads what a payload may add about an item beyond what the hook carried:
 * its categories and tags, author's name and excerpt.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Payload;

use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ItemSnapshot;
use Teme\Contracts\People\PeopleReader;
use Throwable;

/**
 * Reads an item through ContentReader as the person the deliver job runs
 * for. Its terms are used only to match an endpoint's filters. Its excerpt,
 * terms and author are sent only when the revision read is the very one the
 * hook said is public, so a later unpublished edit never leaves the site.
 *
 * @since 1.0.0
 */
final class ItemDetails
{
    /** Words an excerpt keeps. @since 1.0.0 */
    public const int EXCERPT_WORDS = 55;

    /** @var array<string, string>|null writers' names by account ID, read once a run */
    private ?array $names = null;

    /** @since 1.0.0 */
    public function __construct(
        private readonly ContentReader $content,
        private readonly PeopleReader $people,
    ) {}

    /**
     * The item's terms, for filters, and what may be sent of it: null for
     * each part that can't be read or may not be sent.
     *
     * @param  string|null  $publicRevisionId  the revision the hook said is public; null when the item isn't public
     * @return array{terms: array<string, list<string>>|null, public: array{author: string|null, excerpt: string|null, terms: array<string, list<string>>}|null}
     *
     * @since 1.0.0
     */
    public function read(string $itemId, string $personId, ?string $publicRevisionId): array
    {
        try {
            $snapshot = $this->content->draft($itemId, $personId);
        } catch (Throwable) {
            return ['terms' => null, 'public' => null];
        }

        $public = $publicRevisionId !== null
            && $snapshot->revisionId === $publicRevisionId
            && $snapshot->item->status === 'published';

        return [
            'terms' => $snapshot->terms,
            'public' => $public ? [
                'author' => $this->author($snapshot),
                'excerpt' => self::excerpt($snapshot),
                'terms' => $snapshot->terms,
            ] : null,
        ];
    }

    /**
     * The credited people's names, joined with commas, or null when the
     * item credits nobody but its owner, whom no contract names.
     *
     * @since 1.0.0
     */
    private function author(ItemSnapshot $snapshot): ?string
    {
        if ($snapshot->credits === []) {
            return null;
        }

        $names = $this->names();
        $credited = array_values(array_filter(array_map(fn (string $id): ?string => $names[$id] ?? null, $snapshot->credits)));

        return $credited === [] ? null : implode(', ', $credited);
    }

    /**
     * Writers' names by account ID, read a page at a time, at most 2,000.
     *
     * @return array<string, string>
     *
     * @since 1.0.0
     */
    private function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $this->names = [];
        $after = null;

        try {
            for ($pages = 0; $pages < 10; $pages++) {
                $page = $this->people->writers($after, 200);

                foreach ($page->people as $person) {
                    $this->names[$person->id] = $person->name;
                }

                if ($page->next === null) {
                    break;
                }

                $after = $page->next;
            }
        } catch (Throwable) {
            // Names are a courtesy: a payload without one is still sent.
        }

        return $this->names;
    }

    /**
     * The item's own excerpt field when it has one, or the first words of
     * its body as plain text.
     *
     * @since 1.0.0
     */
    public static function excerpt(ItemSnapshot $snapshot): ?string
    {
        $own = $snapshot->fields['excerpt'] ?? null;

        if (is_string($own) && trim($own) !== '') {
            return trim(strip_tags($own));
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', implode(' ', self::texts($snapshot->body))));

        if ($text === '') {
            return null;
        }

        $words = explode(' ', $text);

        return count($words) <= self::EXCERPT_WORDS ? $text : implode(' ', array_slice($words, 0, self::EXCERPT_WORDS)).'…';
    }

    /**
     * Every text node of a Tiptap document, in order.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     *
     * @since 1.0.0
     */
    private static function texts(array $node): array
    {
        $texts = [];

        if (($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null)) {
            $texts[] = $node['text'];
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                array_push($texts, ...self::texts($child));
            }
        }

        return $texts;
    }
}
