<?php

/**
 * Junction enables on a fresh site, with the capabilities, hooks, area,
 * settings and tables its manifest declares.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Teme\Testing\TemeTestCase;

uses(TemeTestCase::class);

it('enables and creates its own tables', function (): void {
    $this->plugin(junctionFolder())->enable();

    foreach (['junction_endpoints', 'junction_events', 'junction_deliveries', 'junction_attempts'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table.' is missing');
    }
});
