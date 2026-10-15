<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Reads old Excel files (.xls, Excel 97–2003, "BIFF8") without any library: the file is an OLE
 * compound document whose "Workbook" stream is a list of records (sheets, shared strings, cells).
 * Text and numbers are read, formulas give their last computed value, dates become YYYY-MM-DD.
 */
final class Xls
{
    private const END_OF_CHAIN = 0xFFFFFFFE;

    /** @return list<array{name: string, rows: list<list<string>>}> */
    public static function read(string $file, int $maxRows = PHP_INT_MAX): array
    {
        $data = (string) file_get_contents($file);
        $stream = self::oleStream($data, ['Workbook', 'Book']);
        return self::workbook($stream, $maxRows);
    }

    // ---- OLE compound document ----------------------------------------------------------------

    /** @param list<string> $names */
    public static function oleStream(string $data, array $names): string
    {
        if (strlen($data) < 512 || !str_starts_with($data, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw new UserError('هذا الملف ليس ملف Office قديماً صالحاً.');
        }
        $h = unpack('vminor/vmajor/vorder/vsectorShift/vminiShift', substr($data, 0x18, 10));
        $size = 1 << $h['sectorShift'];
        $miniSize = 1 << $h['miniShift'];
        $u = unpack('VfatCount/VdirStart/Vtx/Vcutoff/VminiFatStart/VminiFatCount/VdifatStart/VdifatCount', substr($data, 0x2C, 32));
        $sector = fn (int $n): string => substr($data, ($n + 1) * $size, $size);

        // Sector allocation table (FAT), from the header list then the extra DIFAT sectors.
        $fatSectors = array_values(unpack('V109', substr($data, 0x4C, 436)));
        for ($n = $u['difatStart'], $guard = 0; $n < self::END_OF_CHAIN && $guard < $u['difatCount'] + 1; $guard++) {
            $entries = array_values(unpack('V' . ($size / 4), $sector($n)));
            $n = array_pop($entries);
            $fatSectors = array_merge($fatSectors, $entries);
        }
        $fat = [];
        foreach (array_slice($fatSectors, 0, $u['fatCount']) as $s) {
            if ($s < self::END_OF_CHAIN) {
                $fat = array_merge($fat, array_values(unpack('V' . ($size / 4), $sector($s))));
            }
        }
        $chain = function (int $start, array $table) {
            $out = [];
            for ($n = $start; $n < self::END_OF_CHAIN && isset($table[$n]) && count($out) <= count($table); $n = $table[$n]) {
                $out[] = $n;
            }
            return $out;
        };
        $read = fn (int $start) => implode('', array_map($sector, $chain($start, $fat)));

        // Directory: 128-byte entries (name, type, first sector, size).
        $dir = $read($u['dirStart']);
        $entries = [];
        for ($off = 0; $off + 128 <= strlen($dir); $off += 128) {
            $e = unpack('vnameLen/Ctype/Ccolor/Vleft/Vright/Vchild', substr($dir, $off + 64, 17));
            $e += unpack('Vstart/Vsize', substr($dir, $off + 116, 8));
            $e['name'] = $e['nameLen'] > 2 ? mb_convert_encoding(substr($dir, $off, $e['nameLen'] - 2), 'UTF-8', 'UTF-16LE') : '';
            $entries[] = $e;
        }
        $root = $entries[0] ?? throw new UserError('ملف Office قديم تالف.');
        foreach ($names as $name) {
            foreach ($entries as $e) {
                if ($e['type'] !== 2 || strcasecmp($e['name'], $name) !== 0) {
                    continue;
                }
                if ($e['size'] >= $u['cutoff']) {
                    return substr($read($e['start']), 0, $e['size']);
                }
                // Small streams live in the mini stream (64-byte sectors).
                $mini = $read($root['start']);
                $miniFat = [];
                foreach ($chain($u['miniFatStart'], $fat) as $s) {
                    $miniFat = array_merge($miniFat, array_values(unpack('V' . ($size / 4), $sector($s))));
                }
                $bytes = implode('', array_map(fn ($n) => substr($mini, $n * $miniSize, $miniSize), $chain($e['start'], $miniFat)));
                return substr($bytes, 0, $e['size']);
            }
        }
        throw new UserError('لم يُعثر على المحتوى داخل هذا الملف القديم.');
    }

    // ---- BIFF8 workbook -----------------------------------------------------------------------

    private static function workbook(string $s, int $maxRows): array
    {
        $len = strlen($s);
        $bof = unpack('vtype/vlen/vversion', substr($s, 0, 6));
        if ($bof['type'] !== 0x0809 || $bof['version'] !== 0x0600) {
            throw new UserError('صيغة Excel قديمة جداً (قبل 97). احفظ الملف بصيغة .xlsx وأعد المحاولة.');
        }

        // Globals: sheet list, shared strings, cell formats, date system.
        $sheets = [];
        $sst = [];
        $xfFormats = [];
        $codes = [];
        $date1904 = false;
        for ($pos = 0; $pos + 4 <= $len;) {
            ['t' => $type, 'l' => $size] = unpack('vt/vl', substr($s, $pos, 4));
            $rec = substr($s, $pos + 4, $size);
            $next = $pos + 4 + $size;
            if ($type === 0x0085) {
                $b = unpack('Voffset/Cvisibility/Ckind', $rec);
                if ($b['kind'] === 0) {
                    $sheets[] = ['offset' => $b['offset'], 'name' => self::shortString(substr($rec, 6))];
                }
            } elseif ($type === 0x00FC) {
                // Shared strings, continued in CONTINUE records.
                $parts = [$rec];
                while ($next + 4 <= $len && unpack('v', substr($s, $next, 2))[1] === 0x003C) {
                    $l = unpack('v', substr($s, $next + 2, 2))[1];
                    $parts[] = substr($s, $next + 4, $l);
                    $next += 4 + $l;
                }
                $sst = self::sst($parts);
            } elseif ($type === 0x00E0) {
                $xfFormats[] = unpack('v', substr($rec, 2, 2))[1];
            } elseif ($type === 0x041E) {
                $id = unpack('v', $rec)[1];
                $codes[$id] = self::unicodeString(substr($rec, 2))[0];
            } elseif ($type === 0x0022) {
                $date1904 = unpack('v', $rec)[1] === 1;
            } elseif ($type === 0x000A) {
                break;
            }
            $pos = $next;
        }

        $out = [];
        $total = 0;
        foreach ($sheets as $sheet) {
            $cells = [];
            $put = function (int $row, int $col, string $value) use (&$cells) {
                $cells[$row][$col] = $value;
            };
            $num = function (float $v, int $xf) use ($xfFormats, $codes, $date1904): string {
                $fmt = $xfFormats[$xf] ?? 0;
                $code = $codes[$fmt] ?? '';
                if (Sheets::isDateFormat($fmt, $code)) {
                    return Sheets::date($v, $date1904);
                }
                return Sheets::isPercentFormat($fmt, $code) ? Sheets::number(round($v * 100, 10)) . '%' : Sheets::number($v);
            };
            $pendingFormula = null;
            for ($pos = $sheet['offset']; $pos + 4 <= $len;) {
                ['t' => $type, 'l' => $size] = unpack('vt/vl', substr($s, $pos, 4));
                $rec = substr($s, $pos + 4, $size);
                $pos += 4 + $size;
                if ($type === 0x000A) {
                    break;
                }
                if ($size < 6 && $type !== 0x0207) {
                    continue;
                }
                switch ($type) {
                    case 0x00FD: // LABELSST
                        $c = unpack('vrow/vcol/vxf/Visst', $rec);
                        $put($c['row'], $c['col'], $sst[$c['isst']] ?? '');
                        break;
                    case 0x0203: // NUMBER
                        $c = unpack('vrow/vcol/vxf/evalue', $rec);
                        $put($c['row'], $c['col'], $num($c['value'], $c['xf']));
                        break;
                    case 0x027E: // RK
                        $c = unpack('vrow/vcol/vxf/Vrk', $rec);
                        $put($c['row'], $c['col'], $num(self::rk($c['rk']), $c['xf']));
                        break;
                    case 0x00BD: // MULRK
                        $c = unpack('vrow/vcol', $rec);
                        for ($i = 0, $n = intdiv($size - 6, 6); $i < $n; $i++) {
                            $r = unpack('vxf/Vrk', substr($rec, 4 + $i * 6, 6));
                            $put($c['row'], $c['col'] + $i, $num(self::rk($r['rk']), $r['xf']));
                        }
                        break;
                    case 0x0204: // LABEL
                    case 0x00D6: // RSTRING
                        $c = unpack('vrow/vcol', $rec);
                        $put($c['row'], $c['col'], self::unicodeString(substr($rec, 6))[0]);
                        break;
                    case 0x0205: // BOOLERR
                        $c = unpack('vrow/vcol/vxf/Cvalue/Cerror', $rec);
                        $put($c['row'], $c['col'], $c['error'] ? '#ERR' : ($c['value'] ? 'TRUE' : 'FALSE'));
                        break;
                    case 0x0006: // FORMULA: last computed value
                        $c = unpack('vrow/vcol/vxf', $rec);
                        $result = substr($rec, 6, 8);
                        if (substr($result, 6, 2) !== "\xFF\xFF") {
                            $put($c['row'], $c['col'], $num(unpack('e', $result)[1], $c['xf']));
                        } elseif (ord($result[0]) === 0) {
                            $pendingFormula = [$c['row'], $c['col']]; // text result in the next STRING record
                        } elseif (ord($result[0]) === 1) {
                            $put($c['row'], $c['col'], ord($result[2]) ? 'TRUE' : 'FALSE');
                        }
                        break;
                    case 0x0207: // STRING (text result of the previous formula)
                        if ($pendingFormula !== null) {
                            $put($pendingFormula[0], $pendingFormula[1], self::unicodeString($rec)[0]);
                            $pendingFormula = null;
                        }
                        break;
                }
            }
            ksort($cells);
            $rows = [];
            foreach ($cells as $row) {
                $line = array_fill(0, max(array_keys($row)) + 1, '');
                foreach ($row as $col => $v) {
                    $line[$col] = $v;
                }
                $rows[] = $line;
                if (++$total > $maxRows) {
                    throw new UserError("هذا الملف يتجاوز $maxRows صف. احتفظ بأوراق الملخص فقط.");
                }
            }
            $out[] = ['name' => $sheet['name'], 'rows' => $rows];
        }
        return $out;
    }

    private static function rk(int $rk): float
    {
        if ($rk & 0x02) {
            $v = (float) (($rk & 0x80000000 ? $rk - 0x100000000 : $rk) >> 2);
        } else {
            $v = unpack('e', "\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC))[1];
        }
        return $rk & 0x01 ? $v / 100 : $v;
    }

    /** 8-bit length, flags, characters (sheet names). */
    private static function shortString(string $b): string
    {
        $n = ord($b[0] ?? "\0");
        $high = (ord($b[1] ?? "\0") & 0x01) === 1;
        $raw = substr($b, 2, $high ? $n * 2 : $n);
        return $high ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    /** 16-bit length, flags, [runs], [ext], characters. Returns [text, bytes used]. */
    private static function unicodeString(string $b): array
    {
        $n = unpack('v', substr($b, 0, 2))[1] ?? 0;
        $flags = ord($b[2] ?? "\0");
        $off = 3;
        $runs = 0;
        $ext = 0;
        if ($flags & 0x08) {
            $runs = unpack('v', substr($b, $off, 2))[1];
            $off += 2;
        }
        if ($flags & 0x04) {
            $ext = unpack('V', substr($b, $off, 4))[1];
            $off += 4;
        }
        $bytes = ($flags & 0x01) ? $n * 2 : $n;
        $raw = substr($b, $off, $bytes);
        $text = ($flags & 0x01) ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        return [$text, $off + $bytes + $runs * 4 + $ext];
    }

    /**
     * Shared string table. A string's characters may continue in the next record, which then starts
     * with a new flags byte (8-bit or 16-bit characters); formatting runs and extra data are skipped.
     *
     * @param list<string> $parts SST record data and its CONTINUE records
     * @return list<string>
     */
    private static function sst(array $parts): array
    {
        $p = 0;
        $o = 8; // after the two counts
        $count = unpack('Vtotal/Vunique', $parts[0])['unique'];
        $byte = function () use (&$p, &$o, $parts): int {
            while ($o >= strlen($parts[$p] ?? '') && $p < count($parts) - 1) {
                $p++;
                $o = 0;
            }
            return ord(($parts[$p] ?? '')[$o++] ?? "\0");
        };
        $bytes = function (int $n) use ($byte): string {
            $s = '';
            for ($i = 0; $i < $n; $i++) {
                $s .= chr($byte());
            }
            return $s;
        };
        $strings = [];
        for ($i = 0; $i < $count && $p < count($parts); $i++) {
            $n = unpack('v', $bytes(2))[1];
            $flags = $byte();
            $runs = ($flags & 0x08) ? unpack('v', $bytes(2))[1] : 0;
            $ext = ($flags & 0x04) ? unpack('V', $bytes(4))[1] : 0;
            $high = ($flags & 0x01) === 1;
            $text = '';
            for ($read = 0; $read < $n;) {
                if ($o >= strlen($parts[$p]) && $p < count($parts) - 1) {
                    // Characters continue in the next record, with their own flags byte.
                    $p++;
                    $o = 0;
                    $high = (ord($parts[$p][$o++] ?? "\0") & 0x01) === 1;
                }
                $available = intdiv(strlen($parts[$p]) - $o, $high ? 2 : 1);
                $take = max(1, min($n - $read, $available));
                $raw = substr($parts[$p], $o, $high ? $take * 2 : $take);
                $o += strlen($raw);
                $text .= $high ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
                $read += $take;
                if ($raw === '') {
                    break;
                }
            }
            $bytes($runs * 4 + $ext);
            $strings[] = $text;
        }
        return $strings;
    }
}
