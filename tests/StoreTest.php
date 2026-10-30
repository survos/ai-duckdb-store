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

namespace Survos\Ai\DuckDb\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Survos\Ai\DuckDb\Store;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\UnsupportedQueryTypeException;
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\Query\VectorQuery;

/**
 * @author Tac Tacelosky <tacman@gmail.com>
 */
#[Group('integration')]
final class StoreTest extends TestCase
{
    public function testExactRankingAndRoundTrip(): void
    {
        $store = $this->createStore();
        $metadata = new Metadata(['_text' => 'Café and archives 🦆', 'nested' => ['tags' => ['a', 'b']], 'active' => true, 'year' => 1920]);
        $store->add([
            new VectorDocument('opposite', new Vector([-1.0, 0.0]), new Metadata()),
            new VectorDocument('orthogonal', new Vector([0.0, 1.0]), new Metadata()),
            new VectorDocument('same', new Vector([2.0, 0.0]), $metadata),
        ]);
        $results = iterator_to_array($store->query(new VectorQuery(new Vector([1.0, 0.0]))));
        $this->assertSame(['same', 'orthogonal', 'opposite'], array_map(static fn ($document) => $document->getId(), $results));
        foreach ([0.0, 1.0, 2.0] as $index => $score) {
            $this->assertEqualsWithDelta($score, $results[$index]->getScore(), 1e-12);
        }
        $this->assertSame($metadata->getArrayCopy(), $results[0]->getMetadata()->getArrayCopy());
        $this->assertEquals([2.0, 0.0], $results[0]->getVector()->getData());
        $store->add(new VectorDocument('same', new Vector([0.0, -1.0]), new Metadata(['_text' => 'replacement'])));
        $this->assertCount(3, $store);
        $updated = iterator_to_array($store->query(new VectorQuery(new Vector([0.0, -1.0])), ['limit' => 1]));
        $this->assertSame('replacement', $updated[0]->getMetadata()->getText());
    }

    public function testFiltersBeforeLimitWithEscapedKeysAndTypedValues(): void
    {
        $store = $this->createStore();
        $store->add([
            new VectorDocument('near', new Vector([1.0, 0.0]), new Metadata(['provider' => 'other', 'value' => '1'])),
            new VectorDocument('far', new Vector([0.0, 1.0]), new Metadata(['provider' => "museum' OR 1=1 --", 'a/b~c' => true, 'value' => 1, 'nullable' => null])),
        ]);
        $query = new VectorQuery(new Vector([1.0, 0.0]));
        foreach ([['provider' => "museum' OR 1=1 --", 'a/b~c' => true], ['value' => 1], ['nullable' => null]] as $filter) {
            $results = iterator_to_array($store->query($query, ['filter' => $filter, 'limit' => 1]));
            $this->assertCount(1, $results);
            $this->assertSame('far', $results[0]->getId());
        }
        $this->assertSame([], iterator_to_array($store->query($query, ['filter' => ['missing' => null]])));
        $results = iterator_to_array($store->query($query, ['filter' => ['value' => '1']]));
        $this->assertSame('near', $results[0]->getId());
    }

    public function testQuotedIdentifiersAndBoundIds(): void
    {
        $pdo = new \PDO('duckdb::memory:');
        $store = new Store($pdo, 'odd"; DROP TABLE documents; --', 2);
        $store->setup();
        $store->setup();
        $store->add(new VectorDocument("x' OR 1=1 --", new Vector([1.0, 0.0])));
        $store->add(new VectorDocument('keep', new Vector([1.0, 0.0])));
        $store->remove(["x' OR 1=1 --"]);
        $store->remove([]);
        $store->add([]);
        $this->assertCount(1, $store);
        $store->drop();
        $store->drop();
    }

    /**
     * @param list<float> $vector
     */
    #[DataProvider('invalidVectors')]
    public function testInvalidVectorsLeaveBatchUnchanged(array $vector): void
    {
        $store = $this->createStore();
        try {
            $store->add([new VectorDocument('valid', new Vector([1.0, 0.0])), new VectorDocument('invalid', new Vector($vector))]);
            $this->fail('Expected vector validation to fail.');
        } catch (InvalidArgumentException) {
            $this->assertCount(0, $store);
        }
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array($store->query(new VectorQuery(new Vector($vector))));
    }

    /**
     * @return iterable<string, array{list<float>}>
     */
    public static function invalidVectors(): iterable
    {
        yield 'dimensions' => [[1.0]];
        yield 'zero' => [[0.0, 0.0]];
        yield 'infinite' => [[\INF, 0.0]];
        yield 'nan' => [[\NAN, 1.0]];
        yield 'overflow' => [[\PHP_FLOAT_MAX, 1.0]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidQueryOptions(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array($this->createStore()->query(new VectorQuery(new Vector([1.0, 0.0])), $options));
    }

    /**
     * @return iterable<array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield [['limit' => 0]];
        yield [['limit' => '1; DROP TABLE documents']];
        yield [['filter' => 'unsafe SQL']];
        yield [['filter' => ['key' => []]]];
        yield [['filter' => ['key' => \INF]]];
        yield [['where' => '1=1']];
        yield [['limit' => null]];
    }

    public function testUnsupportedQueries(): void
    {
        $store = $this->createStore();
        $this->assertTrue($store->supports(VectorQuery::class));
        $this->assertFalse($store->supports(TextQuery::class));
        $this->expectException(UnsupportedQueryTypeException::class);
        iterator_to_array($store->query(new TextQuery('museum')));
    }

    public function testCallerOwnsTransaction(): void
    {
        $pdo = new \PDO('duckdb::memory:');
        $store = new Store($pdo, 'documents', 2);
        $store->setup();
        $pdo->beginTransaction();
        $store->add(new VectorDocument('rollback', new Vector([1.0, 0.0])));
        $this->assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        $this->assertCount(0, $store);
    }

    public function testDatabaseFailureRollsBackOwnedBatch(): void
    {
        $pdo = new \PDO('duckdb::memory:');
        $pdo->exec("CREATE TABLE documents (id VARCHAR PRIMARY KEY CHECK(id <> 'bad'), embedding DOUBLE[2], metadata JSON)");
        $store = new Store($pdo, 'documents', 2);
        try {
            $store->add([new VectorDocument('good', new Vector([1.0, 0.0])), new VectorDocument('bad', new Vector([1.0, 0.0]))]);
            $this->fail('Expected database constraint failure.');
        } catch (\PDOException) {
            $this->assertFalse($pdo->inTransaction());
            $this->assertCount(0, $store);
        }
    }

    public function testCompletedSnapshotIsQueriedReadOnly(): void
    {
        $path = sys_get_temp_dir().'/symfony-ai-duckdb-'.bin2hex(random_bytes(8)).'.duckdb';
        try {
            $writer = new Store(new \PDO('duckdb:'.$path), 'documents', 2);
            $writer->setup();
            $writer->add(new VectorDocument('saved', new Vector([1.0, 0.0]), new Metadata(['_text' => 'Persisted document'])));
            unset($writer);
            $hash = hash_file('sha256', $path);
            $pdo = new \PDO('duckdb:'.$path, null, null, [\constant('PDO::DUCKDB_ATTR_CONFIG') => ['access_mode' => 'read_only']]);
            $reader = new Store($pdo, 'documents', 2);
            $this->assertCount(1, $reader);
            $results = iterator_to_array($reader->query(new VectorQuery(new Vector([1.0, 0.0]))));
            $this->assertSame('Persisted document', $results[0]->getMetadata()->getText());
            try {
                $reader->clear();
                $this->fail('Read-only database accepted a write.');
            } catch (\PDOException) {
                $this->assertCount(1, $reader);
            }
            unset($reader, $pdo);
            $this->assertSame($hash, hash_file('sha256', $path));
        } finally {
            unset($writer, $reader, $pdo);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function createStore(): Store
    {
        $store = new Store(new \PDO('duckdb::memory:'), 'documents', 2);
        $store->setup();

        return $store;
    }
}
