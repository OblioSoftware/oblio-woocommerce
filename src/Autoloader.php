<?php

namespace OblioSoftware;

class Autoloader {
    public static function init($src) {
        spl_autoload_register(function($class) use ($src) {
            if (substr($class, 0, 13) !== 'OblioSoftware') {
                return;
            }
            $filepath = preg_replace('/^OblioSoftware/', '', $class);
            $filepath = str_replace('\\', '/', $filepath);
            include "{$src}{$filepath}.php";
        });
    }
}