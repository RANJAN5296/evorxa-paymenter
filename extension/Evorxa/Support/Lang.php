<?php

namespace Paymenter\Extensions\Servers\Evorxa\Support;

/**
 * Client-facing strings: resources/lang/english.php, then resources/lang/<language>.php,
 * then storage/app/evorxa/lang/<language>.php (your own wording; survives extension updates).
 */
class Lang
{
    /** Paymenter locale => language file. */
    const LOCALES = ['ar' => 'arabic', 'en' => 'english'];

    const RTL = ['arabic', 'farsi', 'hebrew', 'persian', 'urdu'];

    private static $loaded = [];

    public static function current()
    {
        $locale = strtolower(substr((string) app()->getLocale(), 0, 2));
        return isset(self::LOCALES[$locale]) ? self::LOCALES[$locale] : 'english';
    }

    public static function load($language = null)
    {
        $language = preg_replace('/[^a-z_-]/', '', strtolower($language ?: self::current()));
        if (isset(self::$loaded[$language])) {
            return self::$loaded[$language];
        }
        $dir = dirname(__DIR__) . '/resources/lang';
        $overrides = storage_path('app/evorxa/lang');
        $strings = self::file($dir . '/english.php');
        if ($language !== 'english') {
            $strings = array_merge($strings, self::file($dir . '/' . $language . '.php'));
        }
        $strings = array_merge($strings, self::file($overrides . '/english.php'));
        if ($language !== 'english') {
            $strings = array_merge($strings, self::file($overrides . '/' . $language . '.php'));
        }
        return self::$loaded[$language] = $strings;
    }

    public static function isRtl($language = null)
    {
        return in_array($language ?: self::current(), self::RTL, true);
    }

    public static function t($key, array $replace = [])
    {
        $strings = self::load();
        $text = isset($strings[$key]) ? $strings[$key] : $key;
        foreach ($replace as $k => $v) {
            $text = str_replace(':' . $k, $v, $text);
        }
        return $text;
    }

    private static function file($path)
    {
        if (!is_file($path)) {
            return [];
        }
        $_LANG = [];
        include $path;
        return is_array($_LANG) ? $_LANG : [];
    }
}
