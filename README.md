# Clash Cards Matchmaker MVP

A dependency-free PHP/MySQL starter app for tracking player card inventories and finding mutually beneficial trades.

## Requirements
- PHP 8.1+
- MySQL 8+ or MariaDB
- PDO MySQL extension

## Setup
1. Create a MySQL database.
2. Run `sql/schema.sql`.
3. Copy `config.example.php` to `config.php`.
4. Fill in your database credentials.
5. Set your web root to the `public/` directory if your host allows it.
   - On basic shared hosting, you can also upload the contents of `public/` to the public web directory and keep `src/` and `config.php` one level above it.
6. Add cards to the `cards` table.
7. Open `index.php`.

## Startup
1. Run locally `php -S localhost:8080 -t public`

## MVP behavior
- Create/select a player by display name.
- Enter how many copies of each card the player owns.
- `need = max(required_qty - owned_qty, 0)`
- `extra = max(owned_qty - required_qty, 0)`
- Find another player where each side can give at least one card the other needs.

## Next steps
- Add the full Clash of Cards card catalog.
- Add screenshot upload.
- Parse screenshots into structured card counts.
- Add user accounts.
- Add smarter multi-player / minimum-trade optimization.
