<?php
/**
 * Specification-driven serializer. Produces deterministic bytes from a layout and a dataset.
 * It never truncates or silently drops data: any value that does not fit the layout is
 * reported as an error, and every normalisation (upper-casing, N-tilde, punctuation) is
 * reported as a warning so it appears in the data-quality report.
 */
class DatWriter
{
    private $spec;
    private $ctx;
    private $issues = array();
    private $sums = array();

    public function __construct($spec, $ctx)
    {
        $this->spec = $spec;
        $this->ctx = $ctx;
    }

    public static function make($spec, $ctx)
    {
        $w = new self($spec, $ctx);
        return $w->run();
    }

    private function issue($sev, $code, $rec, $field, $msg)
    {
        $this->issues[] = array('severity' => $sev, 'code' => $code, 'record_no' => $rec, 'field_key' => $field, 'message' => $msg);
    }

    private function run()
    {
        $spec = $this->spec;
        $rows = $this->ctx['rows'];
        foreach ($rows as $r) {
            foreach ($r as $k => $v) {
                if (is_int($v)) {
                    $this->sums[$k] = (isset($this->sums[$k]) ? $this->sums[$k] : 0) + $v;
                }
            }
        }
        $headers = array();
        $detail = null;
        $controls = array();
        foreach ($spec['records'] as $r) {
            if ($r['role'] === 'header') {
                $headers[] = $r;
            } elseif ($r['role'] === 'detail' && $detail === null) {
                $detail = $r;
            } elseif ($r['role'] === 'control') {
                $controls[] = $r;
            }
        }
        if (!$detail) {
            throw new RuntimeException('Layout #' . $spec['id'] . ' has no detail record.');
        }
        $lines = array();
        $recNo = 0;
        if ((int)$spec['column_header_row'] === 1) {
            $cols = array();
            foreach ($detail['fields'] as $f) {
                $cols[] = '"' . str_replace('"', '', strtoupper($f['label'])) . '"';
            }
            $lines[] = implode($spec['delimiter'], $cols);
        }
        foreach ($headers as $h) {
            $recNo++;
            $lines[] = $this->record($h, null, 0, $recNo);
        }
        $seq = 0;
        foreach ($rows as $row) {
            $seq++;
            $recNo++;
            $lines[] = $this->record($detail, $row, $seq, $recNo);
        }
        foreach ($controls as $c) {
            $recNo++;
            $lines[] = $this->record($c, null, 0, $recNo);
        }
        if (!$rows) {
            $this->issue('error', 'NO_ROWS', null, null, 'There are no detail rows for this period. An empty file is not generated.');
        }
        $eol = $spec['line_ending'] === 'LF' ? "\n" : "\r\n";
        $text = implode($eol, $lines) . ((int)$spec['trailing_newline'] === 1 ? $eol : '');
        $bytes = $this->encode($text);
        return array(
            'bytes' => $bytes,
            'filename' => Specs::filename($spec, $this->ctx['agent'], $this->ctx['period'], isset($this->ctx['vars']) ? $this->ctx['vars'] : array()),
            'issues' => $this->issues,
            'lines' => $lines,
            'record_count' => count($rows),
            'sums' => $this->sums,
        );
    }

    private function encode($text)
    {
        $enc = strtoupper($this->spec['encoding']);
        if ($enc === 'UTF-8') {
            return $text;
        }
        $target = in_array($enc, array('WINDOWS-1252', 'CP1252'), true) ? 'WINDOWS-1252' : ($enc === 'ASCII' ? 'ASCII' : $enc);
        $out = @iconv('UTF-8', $target, $text);
        if ($out === false) {
            $this->issue('error', 'ENCODING', null, null, 'The file contains characters that cannot be written in ' . $this->spec['encoding'] . '.');
            return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text);
        }
        return $out;
    }

    private function record($rec, $row, $seq, $recNo)
    {
        $out = array();
        foreach ($rec['fields'] as $f) {
            $out[] = $this->field($f, $row, $seq, $recNo, $rec['record_code']);
        }
        return implode($this->spec['delimiter'], $out);
    }

    private function resolve($expr, $row, $seq)
    {
        if ($expr === 'blank') {
            return '';
        }
        if ($expr === 'seq') {
            return $seq;
        }
        if (strpos($expr, 'lit:') === 0) {
            return substr($expr, 4);
        }
        if (strpos($expr, 'tpl:') === 0) {
            $vars = isset($this->ctx['vars']) ? $this->ctx['vars'] : array();
            return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
                return isset($vars[$m[1]]) ? $vars[$m[1]] : '';
            }, substr($expr, 4));
        }
        list($ns, $key) = array_pad(explode('.', $expr, 2), 2, '');
        if ($ns === 'agent') {
            return isset($this->ctx['agent'][$key]) ? $this->ctx['agent'][$key] : '';
        }
        if ($ns === 'row') {
            return $row !== null && array_key_exists($key, $row) ? $row[$key] : '';
        }
        if ($ns === 'sum') {
            return isset($this->sums[$key]) ? $this->sums[$key] : 0;
        }
        if ($ns === 'period') {
            $to = $this->ctx['period']['to'];
            switch ($key) {
                case 'mm_yyyy':
                    return substr($to, 5, 2) . '/' . substr($to, 0, 4);
                case 'mm_dd_yyyy_end':
                    return substr($to, 5, 2) . '/' . substr($to, 8, 2) . '/' . substr($to, 0, 4);
                case 'yyyy':
                    return substr($to, 0, 4);
                case 'mm':
                    return substr($to, 5, 2);
            }
        }
        if ($ns === 'ctx') {
            return isset($this->ctx['vars'][$key]) ? $this->ctx['vars'][$key] : '';
        }
        throw new RuntimeException('Unknown source expression "' . $expr . '" in layout #' . $this->spec['id']);
    }

    /** Text normalisation shared with the data-quality report. Returns array(value, warnings[], errors[]). */
    public static function cleanText($v, $fmt)
    {
        $w = array();
        $e = array();
        $orig = (string)$v;
        $s = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $orig)), 'UTF-8');
        $trans = array('Ñ' => 'N', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Í' => 'I', 'Ì' => 'I',
            'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C');
        $t = strtr($s, $trans);
        if ($t !== $s) {
            $w[] = 'accented letters converted (' . $orig . ')';
            $s = $t;
        }
        $t = str_replace(array("'", '’', '‘', '`', '"', '“', '”', ','), array('', '', '', '', '', '', '', ' '), $s);
        if ($t !== $s) {
            $w[] = 'apostrophes, quotes or commas removed (' . $orig . ')';
            $s = $t;
        }
        if (strpos($s, '&') !== false) {
            $s = str_replace('&', ' AND ', $s);
            $w[] = '"&" written as "AND" (' . $orig . ')';
        }
        $s = trim(preg_replace('/\s+/', ' ', $s));
        $allowed = $fmt === 'addr' ? '/[^A-Z0-9 .\-#\/()]/' : '/[^A-Z0-9 .\-]/';
        if (preg_match_all($allowed, $s, $m)) {
            $bad = implode(' ', array_unique($m[0]));
            if ($fmt === 'addr') {
                $s = trim(preg_replace('/\s+/', ' ', preg_replace($allowed, ' ', $s)));
                $w[] = 'symbols removed from address: ' . $bad . ' (' . $orig . ')';
            } else {
                $e[] = 'contains characters the validator may reject: ' . $bad . ' (' . $orig . '). Correct the name in the directory.';
            }
        }
        if ($orig !== '' && $s !== mb_strtoupper(trim($orig), 'UTF-8') && !$w && !$e) {
            // only whitespace changes; not reported
        }
        return array($s, $w, $e);
    }

    private function field($f, $row, $seq, $recNo, $recCode)
    {
        $raw = $this->resolve($f['source_expr'], $row, $seq);
        $key = $f['field_key'];
        $label = $f['label'];
        $val = '';
        switch ($f['data_type']) {
            case 'amount':
                $c = is_int($raw) ? $raw : Money::fromDb($raw === '' ? '0' : $raw);
                $val = $f['fmt'] === 'trim' ? Money::toTrim($c) : Money::toPlain($c);
                if ($c < 0) {
                    $this->issue('warning', 'NEGATIVE', $recNo, $key, $label . ' is negative (' . $val . '); confirm the validator accepts negative values for this form.');
                }
                break;
            case 'rate':
                $u = Money::rateUnits($raw);
                if ($u === null) {
                    $this->issue('error', 'RATE', $recNo, $key, $label . ' "' . $raw . '" is not a rate');
                    $u = 0;
                }
                $val = Money::rateFormat($u, $f['fmt'] ?: 'pct2');
                break;
            case 'date':
                if ($raw === '' || $raw === null) {
                    $val = '';
                } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
                    $val = $m[2] . '/' . $m[3] . '/' . $m[1];
                } else {
                    $this->issue('error', 'DATE', $recNo, $key, $label . ' "' . $raw . '" is not a date');
                    $val = '';
                }
                break;
            case 'digits':
                $val = (string)$raw;
                if ($val !== '' && !ctype_digit($val)) {
                    $this->issue('error', 'DIGITS', $recNo, $key, $label . ' must contain digits only (found "' . $val . '")');
                } elseif ($val !== '' && $f['max_len'] && strlen($val) !== (int)$f['max_len']) {
                    $this->issue('error', 'WIDTH', $recNo, $key, $label . ' must have exactly ' . $f['max_len'] . ' digits (found ' . strlen($val) . ': "' . $val . '")');
                }
                break;
            case 'int':
                $val = (string)(int)$raw;
                if ($raw === '' || $raw === null) {
                    $val = '';
                }
                break;
            case 'code':
                $val = strtoupper(trim((string)$raw));
                if ($val !== '' && !preg_match('/^[A-Z0-9\-]+$/', $val)) {
                    $this->issue('error', 'CODE', $recNo, $key, $label . ' "' . $val . '" has characters other than letters and digits');
                }
                break;
            case 'text':
                list($val, $w, $e) = self::cleanText($raw, $f['fmt'] ?: 'name');
                foreach ($w as $m) {
                    $this->issue('warning', 'TEXT_NORMALIZED', $recNo, $key, $label . ': ' . $m);
                }
                foreach ($e as $m) {
                    $this->issue('error', 'TEXT_CHARS', $recNo, $key, $label . ' ' . $m);
                }
                break;
            default:
                $val = (string)$raw;
        }
        if ((int)$f['required'] === 1 && $val === '') {
            $this->issue('error', 'REQUIRED', $recNo, $key, $label . ' is required in record ' . $recCode);
        }
        if ($f['max_len'] && $f['data_type'] !== 'digits' && mb_strlen($val) > (int)$f['max_len']) {
            $this->issue('error', 'WIDTH', $recNo, $key, $label . ' is ' . mb_strlen($val) . ' characters; the layout allows ' . $f['max_len'] . ' ("' . $val . '"). Shorten it at the source.');
        }
        $d = $this->spec['delimiter'];
        if (strpos($val, $d) !== false || strpos($val, '"') !== false || strpbrk($val, "\r\n") !== false) {
            $this->issue('error', 'DELIMITER', $recNo, $key, $label . ' contains a delimiter, quote or line break');
            $val = str_replace(array($d, '"', "\r", "\n"), ' ', $val);
        }
        $q = $f['quote_mode'];
        if ($q === 'always' || ($q === 'nonempty' && $val !== '')) {
            $val = '"' . $val . '"';
        }
        return $val;
    }
}
