# Vendored third-party JavaScript

This project has no npm and no build step (see CLAUDE.md), so the few third-party
browser libraries it needs are committed here as single files and loaded with a
plain `<script src>` tag, exactly like every first-party file in `js/`.

| File | Library | Version | Licence | Used by |
|------|---------|---------|---------|---------|
| `jsqr.js` | [jsQR](https://github.com/cozmo/jsQR) | 1.4.0 | MIT | `js/clock-kiosk.js` — decodes a staff QR card from the camera |
| `qrcode.js` | [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) | 1.4.4 | MIT | `admin/attendance-cards.php` — draws the printed cards |

Both are unmodified upstream builds. To update one, replace the file and change
the version in this table — there is nothing to rebuild.

`jsqr.js` is only loaded by the kiosk page and `qrcode.js` only by the card-print
page, so no ordinary admin or guest page pays for them.

## They are known to interoperate

Verified before adoption, without a browser: a 32-hex card token was encoded with
`qrcode.js` into a 29×29 matrix, painted into a raw RGBA buffer, and decoded back
by `jsqr.js` to the identical string. If you ever swap either library, redo that
round trip rather than assuming a printed card will still scan.
