<?php
/**
 * Thin PDO wrapper. All queries use bound parameters.
 */
class DB
{
    /** @var PDO */
    private static $pdo;
    private static $depth = 0;

    public static function pdo()
    {
        if (!self::$pdo) {
            $c = config('db');
            $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';dbname=' . $c['name'] . ';charset=utf8';
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ));
            self::$pdo->exec("SET NAMES utf8");
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

    public static function q($sql, $params = array())
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(array_values($params) === $params ? $params : $params);
        return $st;
    }

    public static function all($sql, $params = array())
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function row($sql, $params = array())
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val($sql, $params = array())
    {
        $r = self::q($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function col($sql, $params = array())
    {
        return self::q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert($table, $data)
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update($table, $data, $where, $params = array())
    {
        $set = array();
        foreach (array_keys($data) as $c) {
            $set[] = '`' . $c . '` = ?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where;
        return self::q($sql, array_merge(array_values($data), $params))->rowCount();
    }

    public static function begin()
    {
        if (self::$depth === 0) {
            self::pdo()->beginTransaction();
        }
        self::$depth++;
    }

    public static function commit()
    {
        self::$depth--;
        if (self::$depth === 0) {
            self::pdo()->commit();
        }
    }

    public static function rollback()
    {
        if (self::$depth > 0) {
            self::$depth = 0;
            if (self::pdo()->inTransaction()) {
                self::pdo()->rollBack();
            }
        }
    }

    /** Run $fn inside a transaction; rolls back and rethrows on failure. */
    public static function tx($fn)
    {
        self::begin();
        try {
            $r = $fn();
            self::commit();
            return $r;
        } catch (Exception $e) {
            self::rollback();
            throw $e;
        }
    }

    public static function in($values)
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }

    public static function reset()
    {
        self::$pdo = null;
        self::$depth = 0;
    }
}
