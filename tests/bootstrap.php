<?php

/**
 * Test bootstrap: loads the development site's autoloader, then Junction's
 * own classes, so the kit's tests and the plain unit tests both find them.
 *
 * The development site is found through TEME_DEV_PATH, or beside this
 * package as ../teme-dev.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

$teme = getenv('TEME_DEV_PATH') ?: dirname(__DIR__, 2).'/teme-dev';

if (! is_file($teme.'/vendor/autoload.php')) {
    fwrite(STDERR, "Junction's tests need a Teme checkout: set TEME_DEV_PATH, or keep one at ../teme-dev.\n");
    exit(1);
}

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $teme.'/vendor/autoload.php';
$loader->addPsr4('KraftySprouts\\Junction\\', dirname(__DIR__).'/src/');
$loader->addPsr4('KraftySprouts\\Junction\\Tests\\', __DIR__.'/');
$loader->addPsr4('KraftySprouts\\JunctionAddonFixture\\', __DIR__.'/Fixtures/junction-addon/src/');

/**
 * The folder holding Junction's teme-plugin.json, for the kit's plugin().
 *
 * @since 1.0.0
 */
function junctionFolder(): string
{
    return dirname(__DIR__);
}
