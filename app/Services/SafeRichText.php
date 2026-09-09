<?php

namespace App\Services;

class SafeRichText
{
    public static function sanitize(?string $html): string
    {
        $html = strip_tags($html ?? '', '<p><br><strong><b><em><i><u><s><blockquote><ul><ol><li><h1><h2><h3><hr><code><pre>');

        return preg_replace('/<([a-z][a-z0-9]*)\b[^>]*>/i', '<$1>', $html) ?? '';
    }
}
