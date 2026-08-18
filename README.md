# Clash Cards V8.25 Production Baseline

This ZIP is intentionally different from the incremental patch ZIPs:
it is a complete deployable baseline for a new hosted environment.

Start with DEPLOYMENT.md.

Important:
- Copy config.example.php to config.php and fill in production credentials.
- Import sql/001-bootstrap-clean-database.sql only into a NEW/EMPTY database.
- Point the web root at public/ whenever possible.
- Protect the beta site with the hosting provider's directory password.
- Admin now has a second independent password.
