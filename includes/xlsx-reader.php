<?php
declare(strict_types=1);
/**
 * Minimal, dependency-free XLSX reader.
 *
 * The project's PHP (local AND the prod Docker image) ships WITHOUT the `zip`
 * extension and WITHOUT Composer, so ZipArchive/PhpSpreadsheet are unavailable.
 * An .xlsx file is a ZIP of XML parts; this reads the ZIP central directory by
 * hand and inflates the parts we need with gzinflate() (zlib, which IS present),
 * then parses the first worksheet with DOMDocument.
 *
 * Only what the booking importer needs: the first worksheet as a 2-D array of
 * plain-string cell values, columns 0-indexed by spreadsheet column (so an empty
 * cell that the XML omits still leaves the right gap). Formats/formulas ignored;
 * numeric cells come back as their stored string; shared strings are resolved.
 *
 * xlsx_read_rows(path): array of rows (each an array of string cells) or throws.
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
            if ($method === 8) { $out = @gzinflate($data); return $out === false ? null : $out; }
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

/** A1-style column letters → 0-based index. "A"→0, "Z"→25, "AA"→26. */
function xlsx_col_index(string $ref): int {
    if (!preg_match('/^([A-Z]+)/', $ref, $m)) return 0;
    $col = 0;
    foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
    return $col - 1;
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

/**
 * Read the first worksheet of an .xlsx file as rows of string cells.
 * Throws RuntimeException on an unreadable file.
 */
function xlsx_read_rows(string $path): array {
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') throw new RuntimeException('Could not read the uploaded file.');
    if (substr($bytes, 0, 2) !== 'PK')     throw new RuntimeException('That does not look like an .xlsx file.');

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

    $doc = new DOMDocument();
    if (!@$doc->loadXML($sheetXml)) throw new RuntimeException('The worksheet XML could not be parsed.');

    $rows = [];
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
            $cells[$idx] = $val;
            if ($idx > $max) $max = $idx;
        }
        // Normalise to a dense 0..max array so column positions line up.
        $dense = [];
        for ($i = 0; $i <= $max; $i++) $dense[$i] = $cells[$i] ?? '';
        $rows[] = $dense;
    }
    return $rows;
}
