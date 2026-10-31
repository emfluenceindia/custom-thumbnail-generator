## Per-image "Serve WebP" switch — report

Date: 2026-10-30. Scope (user request): only the per-image switch from R3, to be tested before the rest of R3 (bulk action, "Regenerate this size", uninstall cleanup, readme) is built.

### What was built
- `webp/class-ctgen-webp-serve-ajax.php` (new): `set_serve( $id, 'on'|'off' )` and AJAX action `ctgen_webp_set_serve` (nonce `ctgen_media_actions` → `manage_options` → `absint`/`sanitize_text_field`; errors as code + safe message; response `{ state, html }`, never a path).
  - On: refused with `ctgen_webp_nothing_to_serve` when no WebP passes the checks (nothing stored).
  - Fires `ctgen_webp_serving_changed( $id, 'on'|'off' )` only on a real change (§10 G4), for cache purging.
- `includes/class-ctgen-attachment-summary.php` (`.bak8`): the WebP status cell gets a "Serve WebP" switch (real `<input type="checkbox" role="switch">` + `<label>`), with:
  - "Serving WebP: N of M sizes" when on; expandable list of skipped sizes with readable reasons;
  - a warning when on but no size is currently valid ("visitors get the original");
  - disabled + "No valid WebP copy to serve. Generate WebP first." (`aria-describedby`) when nothing can be served;
  - a polite live region for the result; i18n strings for the JS, incl. the cache-purge hint (§10 G4).
- `assets/js/ctgen-attachment-summary.js` (`.bak8`) + rebuilt `.min.js` (`.bak8`): switch change → AJAX → swap in the refreshed cell, announce, keep focus on the switch; on failure the switch returns to its previous position with the reason.
- `assets/css/ctgen-attachment-summary.css` (`.bak8`): switch styling (focus ring, disabled state, reduced motion).
- `includes/init.php`: `ctgen_webp_serve_ajax()` accessor; endpoint registered.

### TDD log
| # | Test | Red (failure seen) | Green | Full suite after |
|---|---|---|---|---|
| 1 | Toggle: set_serve(id,'on') stores manifest, reports state | Error: Class 'CTGEN_Webp_Serve_Ajax' not found | OK (minimal) | OK |
| 2 | Toggle: 'off' removes manifest | returned 'on' | OK | OK (153) |
| 3 | Toggle: on with no valid WebP -> ctgen_webp_nothing_to_serve, nothing stored (req 41) | returned an array (stored) | OK | OK (154) |
| 4 | Toggle: unknown state refused, nothing changes | returned array, manifest kept? (enable ran) | OK | OK (155) |
| 5 | Toggle: ctgen_webp_serving_changed fires once per real change only (req 64, G4) | events [] | OK | OK (156) |
| 6 | AJAX: invalid nonce rejected (-1), nothing stored (req 39) | ErrorException: no method ajax_set_serve | OK: ajax_set_serve() with nonce -> manage_options -> absint/sanitize; register() | OK (157) |
| 7 | AJAX: non-admin (valid own nonce) rejected (req 39); admin success returns state + cell, no paths (req 42) | Both passed on first run (full handler pattern from #6). Seen red by mutation T (capability not checked) and U (path in response). Restored. | OK | OK (159) |
| 8 | Cell: unchecked, enabled switch + label for a ready image | no switch in cell | OK: render_serve_control() (backup class-ctgen-attachment-summary.php.bak8) | OK (160) |
| 9 | Cell: serving -> switch checked + 'Serving WebP: N of M sizes' | not checked, no count | OK | OK (161) |
| 10 | Cell: nothing to serve -> switch disabled + 'Generate WebP first' (aria-describedby) | switch enabled | OK: preview build() when not serving | OK (162) |
| 11 | Cell: skipped sizes listed with readable reasons (expandable) | no list | OK: render_skipped_list() with a reason-text map | OK (163) |
| 12 | Cell: serving on but 0 valid sizes -> warning, switch stays on (§3.4) | 'Serving WebP: 0 of 1 size' shown | OK | OK (164) |
| 13 | Wiring: ctgen_webp_set_serve registered via ctgen_webp_serve_ajax() | Error: undefined function ctgen_webp_serve_ajax() | OK (init.php; .bak8 from R2 is the pre-R2 state) | OK (165) |
| 14 | Cell: polite live region for the switch's result | no live region | OK | OK (166) |
| - | PHPCS fix: test exception class moved to tests/class-ctgen-test-ajax-die.php | n/a | OK | OK (166) |

### Checks
- PHPUnit: `OK (166 tests, 494 assertions)`. Only printed notices: the existing converter tests' deliberate `empty.jpg` (core `getimagesize`).
- PHPCS: plugin 18/18 files, tests 11/11 files — 0 errors, 0 warnings.
- JS: `node v2/tools/build-js.js --check` exit 0 (all three .min.js match their sources). No JS unit-test setup exists in this project; the switch's JS is not automatically tested.
- Plugin Check: identical to the pre-R1 baseline.
- Uploads md5 map identical; DB: 0 serve meta, 0 fixtures. (`ctgen_webp_health_cursor` = 0 exists legitimately: the live daily health check, scheduled on an admin_init, ran once.)
- Real Attachment Summary rendered server-side as admin (search "subrata"): row for #69 shows the switch, off and enabled; no PHP messages.

### Not done / known gaps
- Browser test of the switch (no browser access here) — for the user.
- Not built (rest of R3): bulk action, "Regenerate this size", uninstall cleanup of `_ctgen_webp_serve` / cursor / cron, readme FAQ. **Until uninstall cleanup exists, deleting the plugin leaves `_ctgen_webp_serve` meta behind** (harmless without the plugin: nothing reads it).
- Pending decision from R2: servers that send `.webp` without `Content-Type` (this Lando server).
