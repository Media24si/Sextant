# MySQL 9.7

Sextant runs on MySQL 9.7 LTS (SPEC §2, §16). This note covers the database user and connection. The constraint spike (generated-column unique indexes, `open_monitor_id`, the `RESTRICT` foreign key; §3.11, §3.17) is added here in its own task.

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
