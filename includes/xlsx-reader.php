<?php
declare(strict_types=1);
/**
 * Minimal, dependency-free XLSX reader.
 *
 * The project's PHP (local AND the prod Docker image) ships WITHOUT the `zip`
 * extension and WITHOUT Composer, so ZipArchive/PhpSpreadsheet are unavailable.
 * An .xlsx file is a ZIP of XML parts; this reads the ZIP central directory by
 * hand and inflates the parts we need with gzinflate() (zlib, which IS present),
 * then parses the worksheet XML with DOMDocument.
 *
 * Columns are 0-indexed by spreadsheet column (so an empty cell the XML omits
 * still leaves the right gap; a column past XFD/16383 throws). Formats/formulas
 * ignored beyond bold; numeric cells come back as their stored string; shared
 * strings are resolved.
 *
 * xlsx_read_rows(path):   the FIRST worksheet as a sequential list of rows of
 *                         plain-string cells (booking importer).
 * xlsx_read_sheets(path): EVERY non-hidden worksheet by name, in workbook
 *                         order — [name => rows], each cell ['v' => string,
 *                         'b' => bool bold]. Rows are keyed by spreadsheet row
 *                         − 1 and SPARSE — a missing key is an empty row (a
 *                         huge `r` attribute would otherwise cost memory
 *                         filling every row in between; rows past Excel's
 *                         1,048,576-row cap throw instead). A numeric sheet
 *                         name may come back as an int array key (PHP's own
 *                         key coercion) — cast with (string) before comparing.
 *                         Shipment importer.
 */

/** Locate one entry in the ZIP central directory and return its inflated bytes, or null. */
function xlsx_zip_read(string $zip, string $wanted): ?string {
    // End of Central Directory record: signature PK\x05\x06, within the last 64KB.
    $eocd = strrpos($zip, "PK\x05\x06");
    if ($eocd === false) return null;
    $cdOffset = unpack('V', substr($zip, $eocd + 16, 4))[1];
    $cdCount  = unpack('v', substr($zip, $eocd + 10, 2))[1];

    $p = $cdOffset;
    for ($i = 0; $i < $cdCount; $i++) {
        if (substr($zip, $p, 4) !== "PK\x01\x02") return null;         // corrupt / not found
        $method   = unpack('v', substr($zip, $p + 10, 2))[1];
        $compSize = unpack('V', substr($zip, $p + 20, 4))[1];
        $fnLen    = unpack('v', substr($zip, $p + 28, 2))[1];
        $exLen    = unpack('v', substr($zip, $p + 30, 2))[1];
        $cmLen    = unpack('v', substr($zip, $p + 32, 2))[1];
        $localOff = unpack('V', substr($zip, $p + 42, 4))[1];
        $name     = substr($zip, $p + 46, $fnLen);

        if ($name === $wanted) {
            // Local file header: filename/extra lengths can differ from the central copy.
            if (substr($zip, $localOff, 4) !== "PK\x03\x04") return null;
            $lfn = unpack('v', substr($zip, $localOff + 26, 2))[1];
            $lex = unpack('v', substr($zip, $localOff + 28, 2))[1];
            $data = substr($zip, $localOff + 30 + $lfn + $lex, $compSize);
            if ($method === 0) return $data;                            // stored
            // Cap inflated size (zip-bomb guard): a hand-crafted part could
            // otherwise claim to inflate to gigabytes from a tiny payload.
            if ($method === 8) { $out = @gzinflate($data, 64 * 1024 * 1024); return $out === false ? null : $out; }
            return null;                                                 // unsupported method
        }
        $p += 46 + $fnLen + $exLen + $cmLen;
    }
    return null;
}

/** List entry names in the ZIP central directory. */
function xlsx_zip_list(string $zip): array {
    $eocd = strrpos($zip, "PK\x05\x06");
    if ($eocd === false) return [];
    $cdOffset = unpack('V', substr($zip, $eocd + 16, 4))[1];
    $cdCount  = unpack('v', substr($zip, $eocd + 10, 2))[1];
    $names = [];
    $p = $cdOffset;
    for ($i = 0; $i < $cdCount; $i++) {
        if (substr($zip, $p, 4) !== "PK\x01\x02") break;
        $fnLen = unpack('v', substr($zip, $p + 28, 2))[1];
        $exLen = unpack('v', substr($zip, $p + 30, 2))[1];
        $cmLen = unpack('v', substr($zip, $p + 32, 2))[1];
        $names[] = substr($zip, $p + 46, $fnLen);
        $p += 46 + $fnLen + $exLen + $cmLen;
    }
    return $names;
}

/** A1-style column letters → 0-based index. "A"→0, "Z"→25, "AA"→26, "XFD"→16383 (Excel's last column). */
function xlsx_col_index(string $ref): int {
    if (!preg_match('/^([A-Z]+)/', $ref, $m)) return 0;
    $col = 0;
    foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
    $idx = $col - 1;
    if ($idx > 16383) throw new RuntimeException('The sheet has more columns than Excel allows.');
    return $idx;
}

/** Parse xl/sharedStrings.xml → indexed array of plain strings. */
function xlsx_shared_strings(string $xml): array {
    $out = [];
    $doc = new DOMDocument();
    if (!@$doc->loadXML($xml)) return $out;
    foreach ($doc->getElementsByTagName('si') as $si) {
        $txt = '';
        foreach ($si->getElementsByTagName('t') as $t) $txt .= $t->textContent;
        $out[] = $txt;
    }
    return $out;
}

/** Parse xl/styles.xml → [cellXfs index => bold?]. */
function xlsx_bold_styles(string $xml): array {
    $out = [];
    $doc = new DOMDocument();
    if (!@$doc->loadXML($xml)) return $out;
    $fonts = [];
    $fontsEl = $doc->getElementsByTagName('fonts')->item(0);
    if ($fontsEl) foreach ($fontsEl->childNodes as $f) {
        if (!($f instanceof DOMElement) || $f->localName !== 'font') continue;
        $b = $f->getElementsByTagName('b')->item(0);
        $fonts[] = $b !== null && !in_array($b->getAttribute('val'), ['0', 'false'], true);
    }
    $xfs = $doc->getElementsByTagName('cellXfs')->item(0);
    if ($xfs) foreach ($xfs->childNodes as $xf) {
        if (!($xf instanceof DOMElement) || $xf->localName !== 'xf') continue;
        $out[] = $fonts[(int)$xf->getAttribute('fontId')] ?? false;
    }
    return $out;
}

/**
 * [sheet name => ZIP part path] in workbook order, from xl/workbook.xml and
 * its rels. Sheets marked `state="hidden"` or `"veryHidden"` are skipped —
 * they aren't part of what a person opening the file in Excel sees.
 */
function xlsx_sheet_parts(string $zip): array {
    $wb   = xlsx_zip_read($zip, 'xl/workbook.xml');
    $rels = xlsx_zip_read($zip, 'xl/_rels/workbook.xml.rels');
    if ($wb === null || $rels === null) return [];
    $targets = [];
    $rd = new DOMDocument();
    if (@$rd->loadXML($rels)) foreach ($rd->getElementsByTagName('Relationship') as $r) {
        $t = $r->getAttribute('Target');
        $targets[$r->getAttribute('Id')] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
    }
    $out = [];
    $wd = new DOMDocument();
    if (@$wd->loadXML($wb)) foreach ($wd->getElementsByTagName('sheet') as $s) {
        if (in_array($s->getAttribute('state'), ['hidden', 'veryHidden'], true)) continue;
        $rid = $s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if (isset($targets[$rid])) $out[$s->getAttribute('name')] = $targets[$rid];
    }
    return $out;
}

/** Read and sanity-check an .xlsx file's bytes. */
function xlsx_file_bytes(string $path): string {
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') throw new RuntimeException('Could not read the uploaded file.');
    if (substr($bytes, 0, 2) !== 'PK')     throw new RuntimeException('That does not look like an .xlsx file.');
    return $bytes;
}

/**
 * One worksheet's XML → rows. $bold NULL yields plain-string cells (a gap
 * cell in the dense column fill is ''); an array (possibly empty) yields
 * ['v' => string, 'b' => bool] cells instead.
 *
 * $keepRowNumbers stores rows SPARSELY, keyed by spreadsheet row number − 1,
 * instead of building a plain sequential list — so a huge `r` (e.g. a
 * hand-edited `<row r="99999999999">`) costs one array slot, not one empty
 * row per number in between. A row missing `r` continues right after the
 * last index used (duplicates overwrite); a row number past Excel's own
 * 1,048,576-row cap throws rather than being honoured.
 */
function xlsx_sheet_cells(string $sheetXml, array $shared, ?array $bold, bool $keepRowNumbers): array {
    $doc = new DOMDocument();
    if (!@$doc->loadXML($sheetXml)) throw new RuntimeException('The worksheet XML could not be parsed.');
    $rows = [];
    $next = 0; // next sparse index for a row lacking `r`, continuing after the last one used
    foreach ($doc->getElementsByTagName('row') as $rowEl) {
        $cells = [];
        $max   = -1;
        foreach ($rowEl->getElementsByTagName('c') as $c) {
            $ref  = $c->getAttribute('r');
            $idx  = $ref !== '' ? xlsx_col_index($ref) : count($cells);
            $type = $c->getAttribute('t');
            $val  = '';
            if ($type === 'inlineStr') {
                foreach ($c->getElementsByTagName('t') as $t) $val .= $t->textContent;
            } else {
                $vEl = $c->getElementsByTagName('v')->item(0);
                $raw = $vEl ? $vEl->textContent : '';
                $val = ($type === 's' && $raw !== '') ? ($shared[(int)$raw] ?? '') : $raw;
            }
            if ($bold === null) {
                $cells[$idx] = $val;
            } else {
                $s = $c->getAttribute('s');
                $cells[$idx] = ['v' => $val, 'b' => $s !== '' && ($bold[(int)$s] ?? false)];
            }
            if ($idx > $max) $max = $idx;
        }
        $empty = $bold === null ? '' : ['v' => '', 'b' => false];
        $dense = [];
        for ($i = 0; $i <= $max; $i++) $dense[$i] = $cells[$i] ?? $empty;

        if (!$keepRowNumbers) { $rows[] = $dense; continue; }
        $rn = (int)$rowEl->getAttribute('r');
        if ($rn <= 0) { $rows[$next] = $dense; $next++; continue; }
        if ($rn > 1048576) throw new RuntimeException('The sheet has more rows than Excel allows.');
        $rows[$rn - 1] = $dense;
        $next = $rn;
    }
    if ($keepRowNumbers) ksort($rows);
    return $rows;
}

/**
 * Every non-hidden worksheet of an .xlsx file, by name, in workbook order:
 * [name => rows]. See the file docblock for the sparse row keying and the
 * numeric-sheet-name key caveat. Throws RuntimeException.
 */
function xlsx_read_sheets(string $path): array {
    $bytes  = xlsx_file_bytes($path);
    $ss     = xlsx_zip_read($bytes, 'xl/sharedStrings.xml');
    $shared = $ss !== null ? xlsx_shared_strings($ss) : [];
    $st     = xlsx_zip_read($bytes, 'xl/styles.xml');
    $bold   = $st !== null ? xlsx_bold_styles($st) : [];
    $out = [];
    foreach (xlsx_sheet_parts($bytes) as $name => $part) {
        $xml = xlsx_zip_read($bytes, $part);
        if ($xml !== null) $out[(string)$name] = xlsx_sheet_cells($xml, $shared, $bold, true);
    }
    if (!$out) throw new RuntimeException('No worksheet found in the file.');
    return $out;
}

/**
 * Read the first worksheet of an .xlsx file as rows of string cells.
 * Throws RuntimeException on an unreadable file.
 */
function xlsx_read_rows(string $path): array {
    $bytes = xlsx_file_bytes($path);

    $shared = [];
    $ss = xlsx_zip_read($bytes, 'xl/sharedStrings.xml');
    if ($ss !== null) $shared = xlsx_shared_strings($ss);

    // First worksheet: prefer sheet1.xml, else the lowest-numbered sheet part.
    $sheetXml = xlsx_zip_read($bytes, 'xl/worksheets/sheet1.xml');
    if ($sheetXml === null) {
        $cands = array_values(array_filter(xlsx_zip_list($bytes),
            fn($n) => preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)));
        sort($cands, SORT_NATURAL);
        if ($cands) $sheetXml = xlsx_zip_read($bytes, $cands[0]);
    }
    if ($sheetXml === null) throw new RuntimeException('No worksheet found in the file.');

    return xlsx_sheet_cells($sheetXml, $shared, null, false);
}
