<?php
/**
 * Small, dependency-free PDF writer for print-ready certificates.
 * Supports Helvetica / Helvetica-Bold (WinAnsi), lines, rectangles, wrapped text and
 * JPEG / 8-bit PNG images including transparent signatures. Coordinates are points from the top-left.
 */
class Pdf
{
    private $w;
    private $h;
    private $pages = array();
    private $cur = -1;
    private $font = 'F1';
    private $size = 10;
    private $images = array();

    private static $wR = array(278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584);
    private static $wB = array(278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584);

    public static function sizes()
    {
        return array('A4' => array(595.28, 841.89), 'LETTER' => array(612, 792), 'FOLIO' => array(612, 936), 'LEGAL' => array(612, 1008));
    }

    public function __construct($size = 'A4')
    {
        $s = self::sizes();
        $size = strtoupper($size);
        list($this->w, $this->h) = isset($s[$size]) ? $s[$size] : $s['A4'];
    }

    public function width() { return $this->w; }
    public function height() { return $this->h; }

    public function addPage()
    {
        $this->pages[] = '';
        $this->cur = count($this->pages) - 1;
        $this->out('0 G 0 g 0.6 w');
        return $this;
    }

    private function out($s)
    {
        $this->pages[$this->cur] .= $s . "\n";
    }

    private static function n($v)
    {
        return rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
    }

    public function setFont($style = '', $size = null)
    {
        $map = array('B' => 'F2', 'I' => 'F3', 'BI' => 'F4');
        $this->font = isset($map[$style]) ? $map[$style] : 'F1';
        if ($size !== null) {
            $this->size = $size;
        }
        return $this;
    }

    /**
     * Draw what follows at $scale, with the drawing's top-left corner placed at ($x, $y) on the page.
     * Coordinates inside stay those of the unscaled drawing. Close with endScale().
     */
    public function beginScale($scale, $x = 0, $y = 0)
    {
        $this->out('q ' . self::n($scale) . ' 0 0 ' . self::n($scale) . ' ' . self::n($x) . ' ' . self::n($this->h * (1 - $scale) - $y) . ' cm');
        return $this;
    }

    public function endScale()
    {
        $this->out('Q');
        return $this;
    }

    public function setLineWidth($w) { $this->out(self::n($w) . ' w'); return $this; }
    public function setDash($on = 0, $off = 0) { $this->out($on ? '[' . self::n($on) . ' ' . self::n($off) . '] 0 d' : '[] 0 d'); return $this; }
    public function strokeRgb($r, $g, $b) { $this->out(self::n($r / 255) . ' ' . self::n($g / 255) . ' ' . self::n($b / 255) . ' RG'); return $this; }
    public function fillRgb($r, $g, $b) { $this->out(self::n($r / 255) . ' ' . self::n($g / 255) . ' ' . self::n($b / 255) . ' rg'); return $this; }

    public static function enc($s)
    {
        $s = (string)$s;
        $c = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
        if ($c === false) {
            $c = preg_replace('/[^\x20-\x7E]/', '?', $s);
        }
        return $c;
    }

    private static function esc($s)
    {
        return str_replace(array('\\', '(', ')', "\r", "\n"), array('\\\\', '\\(', '\\)', '', ' '), $s);
    }

    public function strWidth($s, $style = null, $size = null)
    {
        // Oblique faces share the widths of their upright faces.
        $tbl = ($style === null ? in_array($this->font, array('F2', 'F4'), true) : ($style === 'B' || $style === 'BI')) ? self::$wB : self::$wR;
        $size = $size === null ? $this->size : $size;
        $s = self::enc($s);
        $w = 0;
        for ($i = 0, $l = strlen($s); $i < $l; $i++) {
            $o = ord($s[$i]);
            $w += ($o >= 32 && $o <= 126) ? $tbl[$o - 32] : 556;
        }
        return $w * $size / 1000;
    }

    /** Draw text with its top edge at $y. */
    public function text($x, $y, $s)
    {
        $base = $this->h - ($y + $this->size * 0.78);
        $this->out('BT /' . $this->font . ' ' . self::n($this->size) . ' Tf ' . self::n($x) . ' ' . self::n($base) . ' Td (' . self::esc(self::enc($s)) . ') Tj ET');
        return $this;
    }

    /** Text inside a box; shrinks the font down to 5pt before clipping with an ellipsis. */
    public function cell($x, $y, $w, $h, $s, $align = 'L', $pad = 2)
    {
        $s = (string)$s;
        $orig = $this->size;
        $avail = $w - 2 * $pad;
        while ($this->strWidth($s) > $avail && $this->size > 5) {
            $this->size -= 0.5;
        }
        while ($this->strWidth($s) > $avail && strlen($s) > 1) {
            $s = mb_substr($s, 0, mb_strlen($s) - 2) . '…';
        }
        $tw = $this->strWidth($s);
        $tx = $align === 'R' ? $x + $w - $pad - $tw : ($align === 'C' ? $x + ($w - $tw) / 2 : $x + $pad);
        $ty = $y + ($h - $this->size * 0.78) / 2 - $this->size * 0.06;
        $this->text($tx, $ty, $s);
        $this->size = $orig;
        return $this;
    }

    /** Wrapped paragraph; returns the y after the last line. */
    public function para($x, $y, $w, $s, $lead = null)
    {
        $lead = $lead ?: $this->size * 1.25;
        foreach (explode("\n", (string)$s) as $p) {
            $line = '';
            foreach (preg_split('/\s+/', trim($p)) as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->strWidth($try) > $w && $line !== '') {
                    $this->text($x, $y, $line);
                    $y += $lead;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            if ($line !== '') {
                $this->text($x, $y, $line);
                $y += $lead;
            }
        }
        return $y;
    }

    public function line($x1, $y1, $x2, $y2)
    {
        $this->out(self::n($x1) . ' ' . self::n($this->h - $y1) . ' m ' . self::n($x2) . ' ' . self::n($this->h - $y2) . ' l S');
        return $this;
    }

    public function rect($x, $y, $w, $h, $style = 'S')
    {
        $op = $style === 'F' ? 'f' : ($style === 'FD' || $style === 'DF' ? 'B' : 'S');
        $this->out(self::n($x) . ' ' . self::n($this->h - $y - $h) . ' ' . self::n($w) . ' ' . self::n($h) . ' re ' . $op);
        return $this;
    }

    /** Place a JPEG or PNG image. Offsets are already applied by the caller. */
    public function image($file, $x, $y, $w, $h = null)
    {
        $key = md5($file);
        if (!isset($this->images[$key])) {
            $info = @getimagesize($file);
            if (!$info) {
                throw new RuntimeException('Signature image could not be read.');
            }
            $img = $info[2] === IMAGETYPE_JPEG ? $this->parseJpeg($file, $info) : ($info[2] === IMAGETYPE_PNG ? $this->parsePng($file) : null);
            if (!$img) {
                throw new RuntimeException('Only JPEG and PNG signature images are supported.');
            }
            $img['name'] = 'Im' . (count($this->images) + 1);
            $this->images[$key] = $img;
        }
        $img = $this->images[$key];
        if ($h === null) {
            $h = $w * $img['h'] / $img['w'];
        }
        $this->out('q ' . self::n($w) . ' 0 0 ' . self::n($h) . ' ' . self::n($x) . ' ' . self::n($this->h - $y - $h) . ' cm /' . $img['name'] . ' Do Q');
        return $this;
    }

    private function parseJpeg($file, $info)
    {
        $ch = isset($info['channels']) ? $info['channels'] : 3;
        $cs = $ch === 1 ? 'DeviceGray' : ($ch === 4 ? 'DeviceCMYK' : 'DeviceRGB');
        return array('w' => $info[0], 'h' => $info[1], 'cs' => '/' . $cs, 'bpc' => 8, 'filter' => 'DCTDecode', 'data' => file_get_contents($file), 'parms' => '', 'smask' => null, 'decode' => $ch === 4 ? '/Decode [1 0 1 0 1 0 1 0]' : '');
    }

    private function parsePng($file)
    {
        $f = file_get_contents($file);
        if (substr($f, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        $pos = 8;
        $w = $h = $bpc = $ct = 0;
        $pal = '';
        $trns = null;
        $data = '';
        while ($pos < strlen($f)) {
            $len = unpack('N', substr($f, $pos, 4));
            $len = $len[1];
            $type = substr($f, $pos + 4, 4);
            $chunk = substr($f, $pos + 8, $len);
            if ($type === 'IHDR') {
                $hd = unpack('Nw/Nh/Cbpc/Cct/Ccomp/Cfilter/Cinterlace', $chunk);
                $w = $hd['w'];
                $h = $hd['h'];
                $bpc = $hd['bpc'];
                $ct = $hd['ct'];
                if ($hd['interlace'] !== 0) {
                    throw new RuntimeException('Interlaced PNG signatures are not supported. Save the PNG without interlacing.');
                }
                if ($bpc > 8) {
                    throw new RuntimeException('16-bit PNG signatures are not supported. Save the PNG as 8-bit.');
                }
            } elseif ($type === 'PLTE') {
                $pal = $chunk;
            } elseif ($type === 'IDAT') {
                $data .= $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
            $pos += 12 + $len;
        }
        $colors = $ct === 0 || $ct === 4 ? 1 : ($ct === 3 ? 1 : 3);
        $cs = $ct === 0 || $ct === 4 ? '/DeviceGray' : ($ct === 3 ? '[/Indexed /DeviceRGB ' . (strlen($pal) / 3 - 1) . ' <' . bin2hex($pal) . '>]' : '/DeviceRGB');
        $smask = null;
        if ($ct === 4 || $ct === 6) {
            $raw = gzuncompress($data);
            if ($raw === false) {
                throw new RuntimeException('PNG data could not be decompressed.');
            }
            $px = $ct === 6 ? 4 : 2;
            $rowLen = 1 + $w * $px;
            $color = '';
            $alpha = '';
            for ($r = 0; $r < $h; $r++) {
                $row = substr($raw, $r * $rowLen, $rowLen);
                $color .= $row[0];
                $alpha .= $row[0];
                $line = substr($row, 1);
                for ($i = 0; $i < $w; $i++) {
                    $color .= substr($line, $i * $px, $px - 1);
                    $alpha .= $line[$i * $px + $px - 1];
                }
            }
            $data = gzcompress($color);
            $smask = array('w' => $w, 'h' => $h, 'cs' => '/DeviceGray', 'bpc' => 8, 'filter' => 'FlateDecode',
                'parms' => '/DecodeParms <</Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns ' . $w . '>>', 'data' => gzcompress($alpha), 'smask' => null, 'decode' => '');
        }
        return array('w' => $w, 'h' => $h, 'cs' => $cs, 'bpc' => $bpc, 'filter' => 'FlateDecode',
            'parms' => '/DecodeParms <</Predictor 15 /Colors ' . $colors . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w . '>>',
            'data' => $data, 'smask' => $smask, 'decode' => '');
    }

    public function output($title = 'Document')
    {
        $objs = array();
        $add = function ($body) use (&$objs) {
            $objs[] = $body;
            return count($objs);
        };
        $catalog = $add(null);
        $pagesId = $add(null);
        $f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $f3 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>');
        $f4 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-BoldOblique /Encoding /WinAnsiEncoding >>');
        $xobj = '';
        foreach ($this->images as $img) {
            $smaskRef = '';
            if ($img['smask']) {
                $s = $img['smask'];
                $sid = $add("<< /Type /XObject /Subtype /Image /Width {$s['w']} /Height {$s['h']} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode {$s['parms']} /Length " . strlen($s['data']) . " >>\nstream\n" . $s['data'] . "\nendstream");
                $smaskRef = ' /SMask ' . $sid . ' 0 R';
            }
            $id = $add("<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} /ColorSpace {$img['cs']} /BitsPerComponent {$img['bpc']} /Filter /{$img['filter']} {$img['parms']} {$img['decode']}{$smaskRef} /Length " . strlen($img['data']) . " >>\nstream\n" . $img['data'] . "\nendstream");
            $xobj .= '/' . $img['name'] . ' ' . $id . ' 0 R ';
        }
        $res = '<< /Font << /F1 ' . $f1 . ' 0 R /F2 ' . $f2 . ' 0 R /F3 ' . $f3 . ' 0 R /F4 ' . $f4 . ' 0 R >>' . ($xobj ? ' /XObject << ' . $xobj . '>>' : '') . ' >>';
        $kids = array();
        foreach ($this->pages as $content) {
            $z = gzcompress($content);
            $cid = $add("<< /Filter /FlateDecode /Length " . strlen($z) . " >>\nstream\n" . $z . "\nendstream");
            $pid = $add('<< /Type /Page /Parent ' . $pagesId . ' 0 R /MediaBox [0 0 ' . self::n($this->w) . ' ' . self::n($this->h) . '] /Resources ' . $res . ' /Contents ' . $cid . ' 0 R >>');
            $kids[] = $pid . ' 0 R';
        }
        $objs[$catalog - 1] = '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>';
        $objs[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $info = $add('<< /Title (' . self::esc(self::enc($title)) . ') /Producer (G-SMaRT BIR Integration System) /CreationDate (D:' . date('YmdHis') . ') >>');

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array();
        foreach ($objs as $i => $body) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objs); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root " . $catalog . " 0 R /Info " . $info . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}
