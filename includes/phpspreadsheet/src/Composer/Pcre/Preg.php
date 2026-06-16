<?php

namespace Composer\Pcre;

class Preg
{
    public static function isMatch(string $pattern, string $subject, array &$matches = null): bool
    {
        return preg_match($pattern, $subject, $matches);
    }

    public static function match($pattern, $subject, $flags = 0)
    {
        preg_match($pattern, $subject, $matches, $flags);
        return $matches;
    }

    public static function matchAll($pattern, $subject, $flags = PREG_PATTERN_ORDER)
    {
        preg_match_all($pattern, $subject, $matches, $flags);
        return $matches;
    }

    public static function replace($pattern, $replacement, $subject, $limit = -1)
    {
        return preg_replace($pattern, $replacement, $subject, $limit);
    }

    public static function replaceCallback($pattern, $callback, $subject, $limit = -1)
    {
        return preg_replace_callback($pattern, $callback, $subject, $limit);
    }

    public static function split($pattern, $subject, $limit = -1, $flags = 0)
    {
        return preg_split($pattern, $subject, $limit, $flags);
    }
}
