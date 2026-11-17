<?php

/**
 * Writes lang/en.json: every sentence Junction passes to __() or
 * trans_choice(), and every word people read from its manifest, each mapped
 * to itself. Translators copy it to lang/<locale>.json and translate the
 * values. Run: php tools/lang-catalog.php
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$lines = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    $code = (string) file_get_contents((string) $file);
    preg_match_all('/(?:__|trans_choice)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/u', $code, $found);

    foreach ($found[1] as $sentence) {
        $lines[] = stripslashes($sentence);
    }
}

$manifest = json_decode((string) file_get_contents($root.'/teme-plugin.json'), true, 512, JSON_THROW_ON_ERROR);

array_walk_recursive($manifest['contributes'], function (mixed $value, string|int $key) use (&$lines): void {
    if (in_array($key, ['label', 'description', 'text', 'unit', 'plural'], true) && is_string($value)) {
        $lines[] = $value;
    }
});

$walkOptions = function (array $node) use (&$walkOptions, &$lines): void {
    foreach ($node as $key => $value) {
        if ($key === 'options' && is_array($value)) {
            array_push($lines, ...array_values(array_filter($value, 'is_string')));
        } elseif (is_array($value)) {
            $walkOptions($value);
        }
    }
};
$walkOptions($manifest['contributes']);

$lines[] = $manifest['label'];
$lines[] = $manifest['description'];
foreach ($manifest['jobs'] as $job) {
    $lines[] = $job['label'];
}

$lines = array_values(array_unique($lines));
sort($lines);

file_put_contents($root.'/lang/en.json', json_encode(array_combine($lines, $lines), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

echo count($lines)." sentences written to lang/en.json\n";
