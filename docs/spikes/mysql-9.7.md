# MySQL 9.7

Sextant runs on MySQL 9.7 LTS (SPEC §2, §16). This note covers the constraints the data model relies on (§3.11, §3.17), the database user, and connecting.

## Constraints spike

Tested on MySQL 9.7.2 (2026-11-20). The proof is `tests/Feature/Spikes/MySqlConstraintsTest.php`, which stays in the suite and runs against CI's `mysql:9.7` container. It creates throwaway `spike_*` tables, so it needs no migration.

**Everything in §3.11 and §3.17 works as specified.**

### Uniqueness among non-deleted rows (§3.17)

```php
$table->softDeletes();
$table->string('active_name', 100)->nullable()
    ->storedAs('IF(deleted_at IS NULL, name, NULL)')
    ->unique();
```

- Two live rows with the same name fail with `UniqueConstraintViolationException`. Any number of soft-deleted rows may share a name with each other and with one live row.
- **Restoring** a soft-deleted row fails the same way when a live row already has its name. P1.5's validation (and the Add site "duplicate and restore" flow) must check this before restoring, rather than relying on the exception.
- Uniqueness follows the column collation (`utf8mb4_unicode_ci`), so `web01` and `WEB01` clash. Hostnames and domains are stored lowercase anyway (§3); server and service names become case-insensitively unique, which is what we want.
- Give the generated column the same length as its source (`string('active_name', 100)` for `name` 100, 253 for hostnames and domains).
- Never write the generated column: MySQL rejects any value for it (`ERROR 3105 … The value specified for generated column 'active_name' … is not allowed`). Keep it out of `$fillable` and out of factories.

### One open incident per monitor (§3.11)

```php
$table->foreignId('monitor_id')->constrained()->restrictOnDelete();
$table->timestamp('resolved_at')->nullable();
$table->unsignedBigInteger('open_monitor_id')->nullable()
    ->storedAs('IF(resolved_at IS NULL, monitor_id, NULL)')
    ->unique();
```

- A second open incident for the same monitor fails with `UniqueConstraintViolationException`. Once the first is resolved a new one can open, and resolved incidents never clash (NULLs are not unique). `IncidentManager::open()` (P4.2) can catch this exception to stay duplicate-safe under concurrent runs.

### Foreign keys on a generated column's base column

- `ON DELETE CASCADE` and `ON DELETE SET NULL` on `incidents.monitor_id` are rejected, because `monitor_id` is the base column of `open_monitor_id`. MySQL reports only the generic `ERROR 1215 (HY000): Cannot add foreign key constraint`. A control test shows the same cascading key is accepted once the generated column is gone, so the generated column is the cause.
- Laravel adds foreign keys with a separate `ALTER TABLE` after `CREATE TABLE`, so a failing key leaves the table created without it. A failing migration must be rolled back by hand.
- `RESTRICT` works: deleting a monitor that has incidents fails with `SQLSTATE[23000] … foreign key constraint fails`. That's why monitors are soft-deleted (§3.8).

### Testing note

DDL in MySQL commits implicitly, so the spike's `Schema::create()` calls end `RefreshDatabase`'s transaction for that test. The suite still passes because the test drops its own tables in `afterEach`. Tests that only touch data aren't affected.

## Creating the user

MySQL 9.7 no longer has `mysql_native_password`, so the Sextant user authenticates with `caching_sha2_password`:

```sql
CREATE DATABASE sextant CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE sextant_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Same host, over the Unix socket (preferred):
CREATE USER 'sextant'@'localhost' IDENTIFIED WITH caching_sha2_password BY '<password>';
GRANT ALL PRIVILEGES ON sextant.* TO 'sextant'@'localhost';

-- Development and CI only: the test database.
GRANT ALL PRIVILEGES ON sextant_testing.* TO 'sextant'@'localhost';
```

For a database on another host, create the user for Sextant's address and require TLS:

```sql
CREATE USER 'sextant'@'<sextant-ip>' IDENTIFIED WITH caching_sha2_password BY '<password>' REQUIRE SSL;
GRANT ALL PRIVILEGES ON sextant.* TO 'sextant'@'<sextant-ip>';
```

## Connecting

`caching_sha2_password` needs either a secure connection or RSA key exchange on the first login. A Unix socket counts as secure, which is why it's preferred.

- **Socket** (same host): set `DB_SOCKET=/var/run/mysqld/mysqld.sock`. `DB_HOST` is then ignored.
- **TLS** (other host): set `DB_HOST`, `DB_PORT` and `MYSQL_ATTR_SSL_CA` to the CA file that signed the server certificate. `config/database.php` passes it to PDO as `Mysql::ATTR_SSL_CA`.

## Tests

`php artisan test` uses the `sextant_testing` database (`phpunit.xml`) with the host and credentials from `.env`. CI runs the suite against a `mysql:9.7` service container.
