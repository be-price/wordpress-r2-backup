# Test runtimes

Install PHP dependencies with `composer install`. Unit tests run with `vendor/bin/phpunit`; integration suites require the explicit runtimes below. These fixtures create private temporary sites/databases and use loopback connections. They must never be pointed at a live site's database or working directory.

- `WRB_AGE_BIN`: checksum-verified official `age` executable.
- `WRB_AGE_KEYGEN_BIN`: official `age-keygen` executable (defaults to the sibling of `WRB_AGE_BIN`). Alternatively copy `runtime.example.php` to ignored `runtime.php` and set these two paths locally.
- `WRB_TEST_MYSQL_BIN_DIR`: directory containing `mysql`, `mysqldump`, and `mysqld`. When unset, the fixture looks for a directory containing all three on `PATH`. An invalid explicit setting fails instead of choosing another installation.
- `WRB_TEST_MARIADB_BASE` and `WRB_TEST_MARIADB105_BASE`: dedicated MariaDB installation roots, for the corresponding optional engine suites. MariaDB 10.5 needs its `scripts/mariadb-install-db` installer; other supported MariaDB versions use `bin/mariadb-install-db`.
- `WRB_WORDPRESS_CORE`: separately obtained and verified WordPress fixture directory containing `wp-includes/version.php`.
- `WRB_COMPOSER_BIN`: readable Composer PHP script, or provide `composer` on `PATH`.

For the synthetic R2 service, install its locked test dependencies with `npm ci --prefix tests/support/r2-connector`. Node.js 22 or later is required. That directory contains the connector implementation used by the local fixtures, not deployed service credentials or configuration.

Restoration tests create synthetic restore targets; only run them when restoration is within the authorized validation scope. A non-restoring runtime-discovery check is `vendor/bin/phpunit --filter IsolatedMysqlRuntimeTest`.
