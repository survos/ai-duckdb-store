DuckDB Store
============

An embedded vector store accepting an existing native PDO DuckDB connection.
It implements `StoreInterface` and `ManagedStoreInterface` without a Symfony
application, Doctrine, a server, or the VSS extension.

```php
use Survos\Ai\DuckDb\Store;

$store = new Store(new PDO('duckdb:documents.duckdb'), 'documents', dimensions: 768);
$store->setup(); // indexing process only
```

Storage and search
------------------

* Embeddings use `DOUBLE[dimensions]`. Dimensions are required and must match
  both document and query vectors. Use a new table when changing dimensions.
* Search uses exact `array_cosine_distance`: `1 - cosine similarity`, ascending
  (0 means identical direction, 1 orthogonal, 2 opposite). Scores are distances,
  not relevance probabilities. Ties are ordered by document ID.
* Coordinates and their squared norm must be finite; zero vectors are rejected.
* Metadata is stored as JSON, including document text in `Metadata::KEY_TEXT`
  (`_text`), following the existing Store convention. Nested metadata survives
  round trips. IDs are stored and returned as strings.
* `add()` upserts by ID. Each batch owns a transaction unless the caller already
  started one. In that case the caller must commit or roll back, including after
  errors. Large imports should call `add()` in bounded chunks.
* Only `VectorQuery` is supported. Text and hybrid search are out of scope.
  No automatic schema migration, embedding generation, or ANN index is provided.

Metadata filters
----------------

```php
$results = $store->query($query, [
    'limit' => 10,
    'filter' => ['provider' => 'museum', 'public' => true],
]);
```

`limit` defaults to 5 and must be a positive integer. `filter` is an AND of
literal top-level metadata keys and scalar equality values. Keys and values
are bound parameters; keys containing dots, slashes or quotes are literal keys,
not paths or SQL. Filtering occurs inside DuckDB before ordering and limiting.
Nested paths, ranges, OR and array membership are not implemented.

Equality compares JSON scalar representations: strings and numbers are distinct,
as are booleans and strings. Numeric representations such as `1` and `1.0` may
differ. A null filter matches an explicit JSON null, not a missing key. Unknown
options are rejected instead of silently ignored.

Completed snapshots
-------------------

The indexing process calls `setup()` and writes a new database file. Finish
transactions and close all writer connections before distributing the completed
file. Publish versioned snapshot paths, and have readers reopen a new version;
do not copy a live database or overwrite an open reader's file.

```php
$pdo = new PDO('duckdb:documents.duckdb', null, null, [
    PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only'],
]);
$store = new Store($pdo, 'documents', dimensions: 768);
// Query or count only; do not call setup() on a reader.
```

Construction, `query()` and `count()` perform no DDL or writes. The injected
connection controls access mode; DuckDB itself rejects writes on a read-only
connection. `StoreInterface` includes mutation methods, so a separate read-only
interface is not introduced. The bridge neither closes nor reconfigures the
caller's connection. Tables are single quoted identifiers in the connection's
current schema, not schema-qualified names.

Use the same embedding model, revision, dimensions and document/query prompt
conventions for a snapshot's lifetime. Matching dimensions alone does not prove
that two models' vectors are compatible.

Installation and tests
----------------------

```console
composer require survos/ai-duckdb-store
```

Requires PHP 8.5 and native `pdo_duckdb`. The Symfony AI 1.0 beta is explicitly
allowed until 1.0 is stable. No Symfony kernel, container, bundle, or Doctrine
connection is required. An existing `survos/duckdb-bundle` connection can be
injected, but that bundle is not a dependency.

A minimal write followed by a separate read-only process:

```php
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Query\VectorQuery;
use Survos\Ai\DuckDb\Store;

// Indexing process, using a fresh path:
$writer = new Store(new PDO('duckdb:snapshot.duckdb'), 'documents', 3);
$writer->setup();
$writer->add(new VectorDocument('one', new Vector([1.0, 0.0, 0.0]),
    new Metadata(['_text' => 'A museum photograph.', 'provider' => 'museum'])));
unset($writer);

// Retrieval process:
$pdo = new PDO('duckdb:snapshot.duckdb', null, null, [
    PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only'],
]);
$reader = new Store($pdo, 'documents', 3);
foreach ($reader->query(new VectorQuery(new Vector([1.0, 0.0, 0.0])),
    ['filter' => ['provider' => 'museum']]) as $document) {
    echo $document->getMetadata()->getText(), PHP_EOL;
}
```

The two small scripts in `examples/` demonstrate the same lifecycle with fixed
vectors (no model or product data): `php examples/index.php /tmp/example.duckdb`
then `php examples/query.php /tmp/example.duckdb`. Use a fresh path for each build.

From this package directory:

```console
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The integration suite uses native PDO, including a closed writer followed by a
read-only file connection. It needs no network, model, API key or Docker service.
Validated with PHP 8.5.12 and the driver's DuckDB library v2.0.0. CI runners must
install native `pdo_duckdb` before Composer and tests; no remote database service
is involved.

The products/Nomic demo belongs in the consuming searchbench application and is
not distributed with this library. Keep data fetching and embedding generation
in that application; this package stores the resulting vectors.

DuckDB Documentation
--------------------

* [Native PDO driver and read-only configuration](https://github.com/thomas-0816/pdo-duckdb-php)
* [Array distance functions](https://duckdb.org/docs/stable/sql/functions/array.html)
* [JSON extraction and JSON Pointer](https://duckdb.org/docs/stable/data/json/json_functions.html)
* [Concurrency and access modes](https://duckdb.org/docs/stable/connect/concurrency.html)

Resources
---------

* [Report issues](https://github.com/survos/ai-duckdb-store/issues)
* [Monorepo](https://github.com/survos/mono)
