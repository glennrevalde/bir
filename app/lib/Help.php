<?php
/**
 * The User's Manual (app/help/manual.php): numbered chapters and sections, readable by every signed-in user.
 * A section whose key equals a page name is also that page's Help panel.
 */
class Help
{
    private static $data = null;
    private static $flat = null;

    private static function load()
    {
        if (self::$data === null) {
            self::$data = require APP_PATH . '/help/manual.php';
            self::$flat = array();
            foreach (self::$data['chapters'] as $ci => $ch) {
                $si = 0;
                foreach ($ch['sections'] as $key => $s) {
                    $si++;
                    $s['key'] = $key;
                    $s['chapter'] = $ci + 1;
                    $s['chapter_title'] = $ch['title'];
                    $s['num'] = ($ci + 1) . '.' . $si;
                    self::$flat[$key] = $s;
                }
            }
        }
    }

    public static function chapters()
    {
        self::load();
        $out = array();
        foreach (self::$data['chapters'] as $ci => $ch) {
            $secs = array();
            foreach ($ch['sections'] as $key => $s) {
                $secs[$key] = self::$flat[$key];
            }
            $out[] = array('num' => $ci + 1, 'title' => $ch['title'], 'sections' => $secs);
        }
        return $out;
    }

    public static function resolve($key)
    {
        self::load();
        if (isset(self::$flat[$key])) {
            return $key;
        }
        return isset(self::$data['aliases'][$key]) ? self::$data['aliases'][$key] : null;
    }

    public static function has($key)
    {
        return $key !== '' && self::resolve($key) !== null;
    }

    /** One section with its HTML ready to print. */
    public static function article($key)
    {
        $k = self::resolve($key);
        if ($k === null) {
            return null;
        }
        $s = self::$flat[$k];
        $s['html'] = self::render($s['html']);
        $s['group'] = $s['chapter_title'];
        return $s;
    }

    public static function render($html)
    {
        $html = preg_replace_callback('/\{img:([a-z0-9_]+)\}/', function ($m) {
            return e(asset('img/manual/' . $m[1] . '.jpg'));
        }, $html);
        $html = preg_replace_callback('/\{sec:([a-z0-9_]+)\}/', function ($m) {
            return e(url('manual', array('s' => $m[1])));
        }, $html);
        // Images load lazily and open full size on click.
        return preg_replace('/<img src="([^"]+)"/', '<img loading="lazy" data-zoom src="$1"', $html);
    }

    public static function neighbours($key)
    {
        self::load();
        $keys = array_keys(self::$flat);
        $i = array_search($key, $keys, true);
        return array(
            $i !== false && $i > 0 ? self::$flat[$keys[$i - 1]] : null,
            $i !== false && $i < count($keys) - 1 ? self::$flat[$keys[$i + 1]] : null,
        );
    }

    /** Sections matching every word of the query, best matches first, with a text snippet. */
    public static function search($q)
    {
        self::load();
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($q))), 'strlen');
        if (!$words) {
            return array();
        }
        $out = array();
        foreach (self::$flat as $key => $s) {
            $title = mb_strtolower($s['title']);
            $plain = preg_replace('/<figure[^>]*>.*?<\/figure>/s', ' ', $s['html']);
            $plain = preg_replace('/<\/(td|th|li|p|h3|tr|div)>/', '$0. ', $plain);
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(preg_replace('/<[^>]+>/', ' ', $plain), ENT_QUOTES, 'UTF-8')));
            $text = preg_replace('/\s+([.,:;])/', '$1', preg_replace('/\.(\s*\.)+/', '.', $text));
            $low = mb_strtolower($s['summary'] . ' ' . $text);
            $score = 0;
            foreach ($words as $w) {
                $inTitle = mb_strpos($title, $w) !== false;
                $inText = mb_strpos($low, $w) !== false;
                if (!$inTitle && !$inText) {
                    $score = -1;
                    break;
                }
                $score += ($inTitle ? 10 : 0) + substr_count($low, $w);
            }
            if ($score < 0) {
                continue;
            }
            $pos = mb_strpos(mb_strtolower($text), $words[0]);
            $start = $pos === false ? 0 : max(0, $pos - 70);
            $snip = ($start > 0 ? '... ' : '') . mb_substr($text, $start, 200) . (mb_strlen($text) > $start + 200 ? ' ...' : '');
            $out[] = array('section' => $s, 'score' => $score, 'snippet' => $snip);
        }
        usort($out, function ($a, $b) {
            return $b['score'] - $a['score'];
        });
        return $out;
    }

    /** Escape a snippet and mark the query words. */
    public static function highlight($text, $q)
    {
        $html = e($text);
        foreach (array_filter(preg_split('/\s+/', trim($q)), 'strlen') as $w) {
            $html = preg_replace('/(?<![\p{L}\p{N}])(' . preg_quote(e($w), '/') . ')/iu', '<mark>$1</mark>', $html);
        }
        return $html;
    }
}
