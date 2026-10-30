<?php

declare(strict_types=1);

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Survos\Ai\DuckDb\Store;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;

require dirname(__DIR__).'/vendor/autoload.php';

$path = $argv[1] ?? __DIR__.'/snapshot.duckdb';
if (file_exists($path)) {
    throw new LogicException('Choose a new snapshot path; existing snapshots are not overwritten.');
}

$store = new Store(new PDO('duckdb:'.$path), 'documents', 3);
$store->setup();
$store->add([
    new VectorDocument('museum', new Vector([1.0, 0.0, 0.0]), new Metadata(['_text' => 'A museum photograph collection.', 'provider' => 'museum'])),
    new VectorDocument('library', new Vector([0.0, 1.0, 0.0]), new Metadata(['_text' => 'A library newspaper collection.', 'provider' => 'library'])),
]);
unset($store);

echo 'Snapshot ready: '.$path.\PHP_EOL;
