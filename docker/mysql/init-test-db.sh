#!/bin/bash
# Runs once on first container init (docker-entrypoint-initdb.d convention).
# Creates the dedicated test database used by `php artisan test` locally
# and by the GitLab CI mysql service, alongside the main dev DB that
# MYSQL_DATABASE already creates.
set -e

mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`contact_console_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL PRIVILEGES ON \`contact_console_test\`.* TO '${MYSQL_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
