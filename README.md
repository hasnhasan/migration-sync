# MigrationSync
Laravel Database to Migration SYNC

composer require hasnhasan/migration-sync

Added .env File
 
DB_DATABASE_SYNC=create another database on the server where your database is located. 

php artisan migrate:sync 

# What does it do?

Detects the changes you have made to the database or your additions to a new table.

Converts the detected changes to the Laravel Migration file.
