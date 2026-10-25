<?php
/**
 * CSV reading with delimiter detection and encoding repair, returned in the same
 * cell structure as XlsxReader so the import pipeline treats both alike.
 */
class CsvReader
{
    public static function rows($path, $limit = null)
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('The CSV file could not be read.');
        }
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        } elseif (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($raw, "\n");
        $delims = array(',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t"), '|' => substr_count($firstLine, '|'));
        arsort($delims);
        $d = key($delims);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = array();
        $n = 0;
        while (($cols = fgetcsv($fh, 0, $d, '"', '\\')) !== false) {
            $n++;
            $row = array();
            foreach ($cols as $i => $v) {
                $row[$i] = array('v' => $v, 'num' => false, 'date' => null);
            }
            $rows[$n] = $row;
            if ($limit && $n >= $limit) {
                break;
            }
        }
        fclose($fh);
        return $rows;
    }
}
