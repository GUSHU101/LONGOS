<?php

namespace Plugin\BeikeShopTestSandbox\Services;

class SimpleXlsxWriter
{
    private string $tempDir;

    private array $sheets = [];

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir() . '/beike_xlsx_' . bin2hex(random_bytes(8));

        if (! mkdir($this->tempDir, 0700, true) && ! is_dir($this->tempDir)) {
            throw new \RuntimeException('Unable to create XLSX temporary directory.');
        }

        foreach (['_rels', 'xl', 'xl/_rels', 'xl/worksheets', 'docProps'] as $dir) {
            mkdir($this->tempDir . '/' . $dir, 0700, true);
        }
    }

    public function addSheet(
        string $name,
        array $headers,
        iterable $rows,
        array $sensitiveColumns = [],
        array $columnWidths = []
    ): int {
        $index    = count($this->sheets) + 1;
        $safeName = substr($name, 0, 31);
        $path     = $this->tempDir . '/xl/worksheets/sheet' . $index . '.xml';
        $fh       = fopen($path, 'wb');

        if (! $fh) {
            throw new \RuntimeException('Unable to create worksheet XML.');
        }

        $lastColumn = $this->columnLetter(max(1, count($headers)));

        fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($fh, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        fwrite($fh, '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');

        if ($columnWidths) {
            fwrite($fh, '<cols>');
            foreach ($columnWidths as $columnIndex => $width) {
                $n = (int) $columnIndex;
                $w = max(8, min(80, (float) $width));
                fwrite($fh, '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>');
            }
            fwrite($fh, '</cols>');
        }

        fwrite($fh, '<sheetData>');
        $this->writeRow($fh, 1, $headers, 1, []);

        $rowNumber = 2;
        foreach ($rows as $row) {
            $values = is_array($row) ? array_values($row) : [];
            $this->writeRow($fh, $rowNumber, $values, 0, $sensitiveColumns);
            $rowNumber++;
        }

        fwrite($fh, '</sheetData>');
        fwrite($fh, '<autoFilter ref="A1:' . $lastColumn . max(1, $rowNumber - 1) . '"/>');
        fwrite($fh, '</worksheet>');
        fclose($fh);

        $this->sheets[] = [
            'name' => $safeName,
            'path' => 'worksheets/sheet' . $index . '.xml',
            'rows' => max(0, $rowNumber - 2),
        ];

        return max(0, $rowNumber - 2);
    }

    public function save(string $targetPath): void
    {
        $this->writePackageFiles();

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $absolute         = $file->getPathname();
            $relative         = str_replace('\\', '/', substr($absolute, strlen($this->tempDir) + 1));
            $files[$relative] = $absolute;
        }

        $this->writeStoredZip($targetPath, $files);
        $this->cleanup();
    }

    private function writeStoredZip(string $targetPath, array $files): void
    {
        $out = fopen($targetPath, 'wb');
        if (! $out) {
            throw new \RuntimeException('Unable to create XLSX archive.');
        }

        [$dosTime, $dosDate] = $this->dosDateTime();
        $central             = [];

        foreach ($files as $name => $absolute) {
            $nameBytes = (string) $name;
            $size      = filesize($absolute);
            if ($size === false || $size > 0xFFFFFFFF) {
                fclose($out);

                throw new \RuntimeException('XLSX part is too large for ZIP32 output.');
            }

            $crcHex = hash_file('crc32b', $absolute);
            if ($crcHex === false) {
                fclose($out);

                throw new \RuntimeException('Unable to calculate XLSX part checksum.');
            }
            $crc    = (int) hexdec($crcHex);
            $offset = ftell($out);
            $flags  = 0x0800; // UTF-8 filenames.
            $method = 0; // Stored; avoids dependency on ext-zip and zlib.

            fwrite($out, pack(
                'VvvvvvVVVvv',
                0x04034B50,
                20,
                $flags,
                $method,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                strlen($nameBytes),
                0
            ));
            fwrite($out, $nameBytes);

            $in = fopen($absolute, 'rb');
            if (! $in) {
                fclose($out);

                throw new \RuntimeException('Unable to read XLSX part.');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);

            $central[] = [
                'name'   => $nameBytes,
                'size'   => $size,
                'crc'    => $crc,
                'offset' => $offset,
                'flags'  => $flags,
                'method' => $method,
                'time'   => $dosTime,
                'date'   => $dosDate,
            ];
        }

        $centralOffset = ftell($out);

        foreach ($central as $entry) {
            fwrite($out, pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                20,
                20,
                $entry['flags'],
                $entry['method'],
                $entry['time'],
                $entry['date'],
                $entry['crc'],
                $entry['size'],
                $entry['size'],
                strlen($entry['name']),
                0,
                0,
                0,
                0,
                0,
                $entry['offset']
            ));
            fwrite($out, $entry['name']);
        }

        $centralSize = ftell($out) - $centralOffset;
        $count       = count($central);

        fwrite($out, pack(
            'VvvvvVVv',
            0x06054B50,
            0,
            0,
            $count,
            $count,
            $centralSize,
            $centralOffset,
            0
        ));

        fclose($out);
    }

    private function dosDateTime(): array
    {
        $year   = max(1980, (int) date('Y'));
        $month  = (int) date('n');
        $day    = (int) date('j');
        $hour   = (int) date('G');
        $minute = (int) date('i');
        $second = (int) date('s');

        $dosTime = ($hour << 11)         | ($minute << 5) | intdiv($second, 2);
        $dosDate = (($year - 1980) << 9) | ($month << 5) | $day;

        return [$dosTime, $dosDate];
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function writeRow($fh, int $rowNumber, array $values, int $defaultStyle, array $sensitiveColumns): void
    {
        fwrite($fh, '<row r="' . $rowNumber . '">');

        foreach ($values as $zeroIndex => $value) {
            $columnNumber = $zeroIndex + 1;
            $cellRef      = $this->columnLetter($columnNumber) . $rowNumber;
            $style        = in_array($columnNumber, $sensitiveColumns, true) ? 2 : $defaultStyle;

            if ($value === null) {
                fwrite($fh, '<c r="' . $cellRef . '" s="' . $style . '" t="inlineStr"><is><t></t></is></c>');

                continue;
            }

            if (is_bool($value)) {
                fwrite($fh, '<c r="' . $cellRef . '" s="' . $style . '" t="b"><v>' . ($value ? '1' : '0') . '</v></c>');

                continue;
            }

            if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
                fwrite($fh, '<c r="' . $cellRef . '" s="' . $style . '" t="n"><v>' . $value . '</v></c>');

                continue;
            }

            $text = $this->xmlText((string) $value);
            fwrite($fh, '<c r="' . $cellRef . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>');
        }

        fwrite($fh, '</row>');
    }

    private function writePackageFiles(): void
    {
        file_put_contents(
            $this->tempDir . '/[Content_Types].xml',
            $this->contentTypesXml()
        );

        file_put_contents(
            $this->tempDir . '/_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
            '</Relationships>'
        );

        file_put_contents(
            $this->tempDir . '/xl/workbook.xml',
            $this->workbookXml()
        );

        file_put_contents(
            $this->tempDir . '/xl/_rels/workbook.xml.rels',
            $this->workbookRelsXml()
        );

        file_put_contents(
            $this->tempDir . '/xl/styles.xml',
            $this->stylesXml()
        );

        file_put_contents(
            $this->tempDir . '/docProps/core.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" ' .
            'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" ' .
            'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
            '<dc:creator>LONGOS Test Sandbox</dc:creator>' .
            '<cp:lastModifiedBy>LONGOS Test Sandbox</cp:lastModifiedBy>' .
            '<dc:title>LONGOS TEST Full Export</dc:title>' .
            '<dc:description>TEST/SANDBOX administrative export</dc:description>' .
            '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>' .
            '</cp:coreProperties>'
        );

        file_put_contents(
            $this->tempDir . '/docProps/app.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" ' .
            'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' .
            '<Application>LONGOS Test Sandbox</Application>' .
            '</Properties>'
        );
    }

    private function contentTypesXml(): string
    {
        $overrides = '';
        foreach ($this->sheets as $index => $sheet) {
            $n = $index + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
            '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
            $overrides .
            '</Types>';
    }

    private function workbookXml(): string
    {
        $sheets = '';

        foreach ($this->sheets as $index => $sheet) {
            $id = $index + 1;
            $sheets .= '<sheet name="' . $this->xmlAttribute($sheet['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<bookViews><workbookView/></bookViews>' .
            '<sheets>' . $sheets . '</sheets>' .
            '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        $rels = '';

        foreach ($this->sheets as $index => $sheet) {
            $id = $index + 1;
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $sheet['path'] . '"/>';
        }

        $stylesId = count($this->sheets) + 1;
        $rels .= '<Relationship Id="rId' . $stylesId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            $rels .
            '</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="3">' .
                '<font><sz val="10"/><name val="Aptos"/></font>' .
                '<font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Aptos"/></font>' .
                '<font><color rgb="FF9F1239"/><sz val="10"/><name val="Aptos"/></font>' .
            '</fonts>' .
            '<fills count="4">' .
                '<fill><patternFill patternType="none"/></fill>' .
                '<fill><patternFill patternType="gray125"/></fill>' .
                '<fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/><bgColor indexed="64"/></patternFill></fill>' .
                '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF1F2"/><bgColor indexed="64"/></patternFill></fill>' .
            '</fills>' .
            '<borders count="2">' .
                '<border><left/><right/><top/><bottom/><diagonal/></border>' .
                '<border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right><top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border>' .
            '</borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="3">' .
                '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="top" wrapText="1"/></xf>' .
                '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' .
                '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0"><alignment vertical="top" wrapText="1"/></xf>' .
            '</cellXfs>' .
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
            '</styleSheet>';
    }

    private function columnLetter(int $column): string
    {
        $result = '';

        while ($column > 0) {
            $column--;
            $result = chr(65 + ($column % 26)) . $result;
            $column = intdiv($column, 26);
        }

        return $result;
    }

    private function xmlText(string $value): string
    {
        $value = preg_replace('/[^\P{C}\t\r\n]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function cleanup(): void
    {
        if (! isset($this->tempDir) || ! is_dir($this->tempDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($this->tempDir);
    }
}
