<?php
/**
 * Minimal, dependency-free XLSX reader and writer (requires the zip extension).
 * The reader streams sheet XML, reads cached values only (formulas are never evaluated),
 * keeps text cells as text, and reports which cells were numeric so TIN columns can be checked.
 */
class XlsxReader
{
    private $zip;
    private $shared = array();
    private $dateStyles = array();
    private $sheets = array();

    public function __construct($path)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is required to read .xlsx files. Enable extension=zip in php.ini.');
        }
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('The workbook could not be opened. Save it again as .xlsx and retry.');
        }
        if ($this->zip->locateName('xl/workbook.xml') === false) {
            throw new RuntimeException('This file is not an Excel workbook.');
        }
        if ($this->zip->locateName('xl/vbaProject.bin') !== false) {
            // Macros are never executed; we only note their presence.
        }
        $this->loadSheets();
        $this->loadShared();
        $this->loadStyles();
    }

    public function __destruct()
    {
        if ($this->zip) {
            @$this->zip->close();
        }
    }

    private function xml($name)
    {
        $s = $this->zip->getFromName($name);
        if ($s === false) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        return $x === false ? null : $x;
    }

    private function loadSheets()
    {
        $wb = $this->xml('xl/workbook.xml');
        $rels = $this->xml('xl/_rels/workbook.xml.rels');
        $targets = array();
        if ($rels) {
            foreach ($rels->Relationship as $r) {
                $targets[(string)$r['Id']] = (string)$r['Target'];
            }
        }
        if (!$wb) {
            throw new RuntimeException('The workbook structure is unreadable.');
        }
        $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($wb->sheets->sheet as $sh) {
            $attrs = $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = (string)$attrs['id'];
            $t = isset($targets[$rid]) ? $targets[$rid] : '';
            $t = ltrim($t, '/');
            if (strpos($t, 'xl/') !== 0) {
                $t = 'xl/' . $t;
            }
            $this->sheets[] = array('name' => (string)$sh['name'], 'path' => $t);
        }
    }

    private function loadShared()
    {
        $x = $this->xml('xl/sharedStrings.xml');
        if (!$x) {
            return;
        }
        foreach ($x->si as $si) {
            if (isset($si->t)) {
                $this->shared[] = (string)$si->t;
            } else {
                $s = '';
                foreach ($si->r as $r) {
                    $s .= (string)$r->t;
                }
                $this->shared[] = $s;
            }
        }
    }

    private function loadStyles()
    {
        $x = $this->xml('xl/styles.xml');
        if (!$x) {
            return;
        }
        $custom = array();
        if (isset($x->numFmts)) {
            foreach ($x->numFmts->numFmt as $f) {
                $code = strtolower((string)$f['formatCode']);
                $code = preg_replace('/"[^"]*"|\[[^\]]*\]/', '', $code);
                $custom[(int)$f['numFmtId']] = (bool)preg_match('/[dy]|m{1,4}(?![^h]*s)/', $code) && strpos($code, '@') === false && !preg_match('/^[#0.,\s]+$/', $code);
            }
        }
        $i = 0;
        if (isset($x->cellXfs)) {
            foreach ($x->cellXfs->xf as $xf) {
                $id = (int)$xf['numFmtId'];
                $isDate = ($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || (isset($custom[$id]) && $custom[$id]);
                $this->dateStyles[$i] = $isDate;
                $i++;
            }
        }
    }

    public function sheetNames()
    {
        $n = array();
        foreach ($this->sheets as $s) {
            $n[] = $s['name'];
        }
        return $n;
    }

    public static function colIndex($ref)
    {
        $letters = preg_replace('/\d/', '', $ref);
        $n = 0;
        for ($i = 0, $l = strlen($letters); $i < $l; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    public static function serialToDate($serial)
    {
        $days = (int)floor((float)$serial);
        if ($days < 1) {
            return null;
        }
        // Excel 1900 system: day 1 = 1900-01-01, with the fictitious 1900-02-29
        if ($days > 59) {
            $days--;
        }
        $ts = gmmktime(0, 0, 0, 1, 1, 1900) + ($days - 1) * 86400;
        return gmdate('Y-m-d', $ts);
    }

    /**
     * Read a sheet. Returns array of rows; each row is array(colIndex => cell),
     * cell = array('v' => string, 'num' => bool, 'date' => 'Y-m-d'|null). Row keys are 1-based Excel rows.
     */
    public function rows($sheetName = null, $limit = null)
    {
        $sheet = null;
        foreach ($this->sheets as $s) {
            if ($sheetName === null || $s['name'] === $sheetName) {
                $sheet = $s;
                break;
            }
        }
        if (!$sheet) {
            throw new RuntimeException('Sheet "' . $sheetName . '" was not found in the workbook.');
        }
        $data = $this->zip->getFromName($sheet['path']);
        if ($data === false) {
            throw new RuntimeException('Sheet data is missing from the workbook.');
        }
        $r = new XMLReader();
        $r->XML($data, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $rows = array();
        $rowNo = 0;
        $cur = null;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
                $rowNo = (int)$r->getAttribute('r') ?: $rowNo + 1;
                $cur = array();
                if ($r->isEmptyElement) {
                    $rows[$rowNo] = $cur;
                    $cur = null;
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'row') {
                if ($cur !== null) {
                    $rows[$rowNo] = $cur;
                }
                $cur = null;
                if ($limit && count($rows) >= $limit) {
                    break;
                }
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'c' && $cur !== null) {
                $ref = $r->getAttribute('r');
                $type = $r->getAttribute('t');
                $style = (int)$r->getAttribute('s');
                $col = $ref ? self::colIndex($ref) : count($cur);
                $node = $r->readOuterXml();
                $v = null;
                if (preg_match('/<(?:\w+:)?v>(.*?)<\/(?:\w+:)?v>/s', $node, $m)) {
                    $v = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                $cell = array('v' => '', 'num' => false, 'date' => null);
                if ($type === 's') {
                    $cell['v'] = isset($this->shared[(int)$v]) ? $this->shared[(int)$v] : '';
                } elseif ($type === 'inlineStr') {
                    preg_match_all('/<(?:\w+:)?t[^>]*>(.*?)<\/(?:\w+:)?t>/s', $node, $mm);
                    $cell['v'] = html_entity_decode(implode('', $mm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                } elseif ($type === 'str' || $type === 'e') {
                    $cell['v'] = (string)$v;
                } elseif ($type === 'b') {
                    $cell['v'] = $v === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $cell['v'] = $v === null ? '' : (string)$v;
                    if ($v !== null && $v !== '') {
                        $cell['num'] = true;
                        if (!empty($this->dateStyles[$style]) && is_numeric($v)) {
                            $cell['date'] = self::serialToDate($v);
                        }
                    }
                }
                $cur[$col] = $cell;
            }
        }
        $r->close();
        ksort($rows);
        return $rows;
    }
}

class XlsxWriter
{
    /**
     * $sheets = array(array('name'=>..., 'columns'=>array(array('title'=>..,'width'=>..,'format'=>'text'|'amount'|'general')),
     *                        'rows'=>array(array(...)), 'protect_header'=>bool, 'freeze'=>bool, 'lists'=>array(colIdx=>array(values))))
     */
    public static function build($sheets, $props = array())
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is required to build .xlsx files.');
        }
        $tmp = tempnam(storage_path('tmp'), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $wbSheets = '';
        $wbRels = '';
        foreach ($sheets as $i => $s) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . self::x(mb_substr($s['name'], 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', self::sheetXml($s));
        }
        $ct .= '</Types>';
        $nS = count($sheets) + 1;
        $wbRels .= '<Relationship Id="rId' . $nS . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $title = isset($props['title']) ? $props['title'] : 'G-SMaRT BIR template';
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::x($title) . '</dc:title><dc:creator>G-SMaRT BIR Integration System</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    private static function x($s)
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function colLetter($i)
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = (int)(($i - $m) / 26);
        }
        return $s;
    }

    /* style ids: 0 default, 1 header (bold, fill, locked), 2 text unlocked, 3 amount unlocked, 4 general unlocked, 5 note (italic) */
    private static function styles()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2F3478"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"><protection locked="1"/></xf>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyProtection="1"><protection locked="0"/></xf>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyProtection="1"><protection locked="0"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyProtection="1"><protection locked="0"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private static function sheetXml($s)
    {
        $cols = isset($s['columns']) ? $s['columns'] : array();
        $styleFor = array();
        $colsXml = '';
        foreach ($cols as $i => $c) {
            $fmt = isset($c['format']) ? $c['format'] : 'general';
            $style = $fmt === 'text' ? 2 : ($fmt === 'amount' ? 3 : ($fmt === 'note' ? 5 : 4));
            $styleFor[$i] = $style;
            $w = isset($c['width']) ? $c['width'] : 16;
            $n = $i + 1;
            $colsXml .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" style="' . $style . '" customWidth="1"/>';
        }
        $rowsXml = '';
        $r = 1;
        $hasHeader = !empty($cols) && empty($s['no_header']);
        if ($hasHeader) {
            $rowsXml .= '<row r="1">';
            foreach ($cols as $i => $c) {
                $rowsXml .= '<c r="' . self::colLetter($i) . '1" t="inlineStr" s="1"><is><t>' . self::x($c['title']) . '</t></is></c>';
            }
            $rowsXml .= '</row>';
            $r = 2;
        }
        foreach ((isset($s['rows']) ? $s['rows'] : array()) as $row) {
            $rowsXml .= '<row r="' . $r . '">';
            foreach (array_values($row) as $i => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = self::colLetter($i) . $r;
                $st = isset($styleFor[$i]) ? $styleFor[$i] : 0;
                $isText = $st === 2 || $st === 5 || !is_numeric($v) || (is_string($v) && preg_match('/^0\d/', $v));
                if ($isText) {
                    $rowsXml .= '<c r="' . $ref . '" t="inlineStr" s="' . $st . '"><is><t xml:space="preserve">' . self::x($v) . '</t></is></c>';
                } else {
                    $rowsXml .= '<c r="' . $ref . '" s="' . $st . '"><v>' . self::x($v) . '</v></c>';
                }
            }
            $rowsXml .= '</row>';
            $r++;
        }
        $views = '';
        if (!empty($s['freeze']) && $hasHeader) {
            $views = '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        $prot = '';
        if (!empty($s['protect_header'])) {
            $prot = '<sheetProtection sheet="1" objects="1" scenarios="1" formatCells="0" formatColumns="0" formatRows="0" insertRows="0" deleteRows="0" sort="0" autoFilter="0"/>';
        }
        $dv = '';
        if (!empty($s['lists'])) {
            $items = '';
            foreach ($s['lists'] as $colIdx => $values) {
                $letter = self::colLetter($colIdx);
                $items .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="' . $letter . '2:' . $letter . '20000"><formula1>"' . self::x(implode(',', $values)) . '"</formula1></dataValidation>';
            }
            $dv = '<dataValidations count="' . count($s['lists']) . '">' . $items . '</dataValidations>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . $views . ($colsXml ? '<cols>' . $colsXml . '</cols>' : '') . '<sheetData>' . $rowsXml . '</sheetData>' . $prot . $dv . '</worksheet>';
    }
}
