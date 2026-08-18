V8.14 — Separate Teach and Save

REPLACE:
  public/index.php
  public/js/card-scanner.js

Badge templates and previously learned localStorage examples are unchanged.

Changes:
- Adds a separate “Teach selected examples” button.
- Teaching writes only to browser localStorage; it does not submit the inventory form.
- Inventory save is enabled only when all 5 pages are successfully assigned.
- Partial scans may still be used for teaching verified glyphs.
- Debug adds SCAN_COMPLETE | pages=N/5 | saveAllowed=true|false.
- Save-target audit line remains included when available.

Expected stamp:
  SCANNER | version=V8.14 | build="Separate Teach and Save" | id=v8.14-separate-teach-and-save

index.php loads:
  js/card-scanner.js?v=8.14
