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

namespace Survos\Ai\DuckDb;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Platform\Vector\VectorInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\UnsupportedQueryTypeException;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;

/**
 * @author Tac Tacelosky <tacman@gmail.com>
 */
final class Store implements ManagedStoreInterface, StoreInterface
{
    private readonly string $table;

    public function __construct(
        private readonly \PDO $connection,
        string $tableName,
        private readonly int $dimensions,
    ) {
        if ('duckdb' !== $connection->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            throw new InvalidArgumentException('A native PDO DuckDB connection is required.');
        }
        if ('' === $tableName || str_contains($tableName, "\0")) {
            throw new InvalidArgumentException('The table name must be non-empty and contain no null bytes.');
        }
        if (1 > $dimensions) {
            throw new InvalidArgumentException('Dimensions must be positive.');
        }

        $this->table = '"'.str_replace('"', '""', $tableName).'"';
    }

    public function setup(array $options = []): void
    {
        $this->validateOptions($options, []);
        $this->connection->exec(\sprintf('CREATE TABLE IF NOT EXISTS %s (id VARCHAR PRIMARY KEY, embedding DOUBLE[%d] NOT NULL, metadata JSON NOT NULL)', $this->table, $this->dimensions));
    }

    public function drop(array $options = []): void
    {
        $this->validateOptions($options, []);
        $this->connection->exec('DROP TABLE IF EXISTS '.$this->table);
    }

    public function count(): int
    {
        return (int) $this->connection->query('SELECT COUNT(*) FROM '.$this->table)->fetchColumn();
    }

    public function add(VectorDocumentInterface|array $documents): void
    {
        if ($documents instanceof VectorDocumentInterface) {
            $documents = [$documents];
        }
        if ([] === $documents) {
            return;
        }

        $rows = [];
        foreach ($documents as $document) {
            $rows[] = [(string) $document->getId(), $this->encodeVector($document->getVector()), json_encode((object) $document->getMetadata()->getArrayCopy(), \JSON_THROW_ON_ERROR)];
        }

        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }
        try {
            $statement = $this->connection->prepare(\sprintf('INSERT INTO %s (id, embedding, metadata) VALUES (?, CAST(? AS DOUBLE[%d]), CAST(? AS JSON)) ON CONFLICT (id) DO UPDATE SET embedding = EXCLUDED.embedding, metadata = EXCLUDED.metadata', $this->table, $this->dimensions));
            foreach ($rows as $row) {
                $statement->execute($row);
            }
            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function remove(string|array $ids, array $options = []): void
    {
        $this->validateOptions($options, []);
        if (\is_string($ids)) {
            $ids = [$ids];
        }
        if ([] === $ids) {
            return;
        }
        $statement = $this->connection->prepare(\sprintf('DELETE FROM %s WHERE id IN (%s)', $this->table, implode(', ', array_fill(0, \count($ids), '?'))));
        $statement->execute(array_values($ids));
    }

    public function clear(array $options = []): void
    {
        $this->validateOptions($options, []);
        $this->connection->exec('DELETE FROM '.$this->table);
    }

    public function supports(string $queryClass): bool
    {
        return VectorQuery::class === $queryClass;
    }

    /**
     * @param array{limit?: positive-int, filter?: array<string, bool|int|float|string|null>} $options
     */
    public function query(QueryInterface $query, array $options = []): iterable
    {
        if (!$query instanceof VectorQuery) {
            throw new UnsupportedQueryTypeException($query::class, $this);
        }
        $this->validateOptions($options, ['limit', 'filter']);
        $limit = $options['limit'] ?? 5;
        if (!\is_int($limit) || 1 > $limit) {
            throw new InvalidArgumentException('The limit must be a positive integer.');
        }
        $filter = $options['filter'] ?? [];
        if (!\is_array($filter)) {
            throw new InvalidArgumentException('The filter must be an array of metadata keys and scalar values.');
        }

        $params = [$this->encodeVector($query->getVector())];
        $where = [];
        foreach ($filter as $key => $value) {
            if (!\is_string($key) || (null !== $value && !\is_scalar($value)) || (\is_float($value) && !is_finite($value))) {
                throw new InvalidArgumentException('Metadata filters require string keys and finite scalar or null values.');
            }
            $where[] = 'CAST(json_extract(metadata, ?) AS VARCHAR) = ?';
            $params[] = '/'.str_replace(['~', '/'], ['~0', '~1'], $key);
            $params[] = json_encode($value, \JSON_THROW_ON_ERROR);
        }

        $statement = $this->connection->prepare(\sprintf('SELECT id, CAST(to_json(embedding) AS VARCHAR) AS embedding, CAST(metadata AS VARCHAR) AS metadata, array_cosine_distance(embedding, CAST(? AS DOUBLE[%d])) AS score FROM %s %s ORDER BY score ASC, id ASC LIMIT %d', $this->dimensions, $this->table, [] === $where ? '' : 'WHERE '.implode(' AND ', $where), $limit));
        $statement->execute($params);

        try {
            while (false !== $row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                yield new VectorDocument($row['id'], new Vector(json_decode($row['embedding'], true, 512, \JSON_THROW_ON_ERROR)), new Metadata(json_decode($row['metadata'], true, 512, \JSON_THROW_ON_ERROR)), (float) $row['score']);
            }
        } finally {
            $statement->closeCursor();
        }
    }

    private function encodeVector(VectorInterface $vector): string
    {
        $data = $vector->getData();
        if ($this->dimensions !== \count($data) || !array_is_list($data)) {
            throw new InvalidArgumentException(\sprintf('Expected a vector with %d dimensions.', $this->dimensions));
        }
        $norm = 0.0;
        foreach ($data as $value) {
            if ((!\is_float($value) && !\is_int($value)) || !is_finite($value)) {
                throw new InvalidArgumentException('Vector coordinates must be finite numbers.');
            }
            $norm += $value * $value;
        }
        if (0.0 === $norm || !is_finite($norm)) {
            throw new InvalidArgumentException('Cosine distance requires a finite, non-zero vector norm.');
        }

        return json_encode($data, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $allowed
     */
    private function validateOptions(array $options, array $allowed): void
    {
        foreach ($options as $key => $value) {
            if (!\in_array($key, $allowed, true) || null === $value) {
                throw new InvalidArgumentException(\sprintf('Unsupported option "%s" or null value.', $key));
            }
        }
    }
}
