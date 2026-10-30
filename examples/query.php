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
use Symfony\AI\Store\Query\VectorQuery;

require dirname(__DIR__).'/vendor/autoload.php';

$path = $argv[1] ?? __DIR__.'/snapshot.duckdb';
$pdo = new PDO('duckdb:'.$path, null, null, [PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only']]);
$store = new Store($pdo, 'documents', 3);
foreach ($store->query(new VectorQuery(new Vector([1.0, 0.0, 0.0])), ['filter' => ['provider' => 'museum'], 'limit' => 5]) as $document) {
    printf("%s: %.4f — %s\n", $document->getId(), $document->getScore(), $document->getMetadata()->getText());
}
