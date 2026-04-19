# Fonts – Lokale Installation

Für DSGVO-konforme lokale Fonts folgende Dateien hier ablegen:

## Option A: Barlow von Google Fonts herunterladen

1. Öffne: https://fonts.google.com/specimen/Barlow
2. Klicke "Download family"
3. Entpacke und kopiere diese Dateien hierher:
   - Barlow-Light.woff2
   - Barlow-Regular.woff2
   - Barlow-Medium.woff2
   - Barlow-SemiBold.woff2
   - Barlow-Italic.woff2

4. Öffne: https://fonts.google.com/specimen/Barlow+Condensed
5. Kopiere:
   - BarlowCondensed-Regular.woff2
   - BarlowCondensed-SemiBold.woff2
   - BarlowCondensed-Bold.woff2
   - BarlowCondensed-ExtraBold.woff2
   - BarlowCondensed-Black.woff2

## Option B: google-webfonts-helper (empfohlen)

https://gwfh.mranftl.com/fonts/barlow
https://gwfh.mranftl.com/fonts/barlow-condensed

Wähle "Best Support" → Download → woff2-Dateien hierher kopieren.

## Nach Installation

In config.php `FONTS_LOCAL` auf `true` setzen oder in den
Admin-Einstellungen "Fonts lokal laden" aktivieren.
