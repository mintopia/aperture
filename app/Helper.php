<?php
namespace App;

class Helper
{
    public static function humanSize(int $bytes): string
    {
        if ($bytes == 0)
            return "0.00 B";

        $s = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
        $e = floor(log($bytes, 1024));

        return round($bytes/pow(1024, $e), 2) . " {$s[$e]}";
    }
}
