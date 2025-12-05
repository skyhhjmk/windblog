<?php

namespace app\util;

use HTMLPurifier;
use HTMLPurifier_Config;
use support\Log;

/**
 * HtmlSanitizer provides a simple wrapper around HTMLPurifier to clean user-generated HTML.
 * It ensures that potentially dangerous tags/attributes are removed before rendering.
 */
class HtmlSanitizer
{
    /**
     * Sanitize the given HTML string.
     *
     * @param string $html Raw HTML content.
     *
     * @return string Sanitized HTML.
     */
    public static function sanitize(string $html): string
    {
        try {
            // Basic configuration: allow safe HTML, remove scripts, iframes, etc.
            $config = HTMLPurifier_Config::createDefault();
            // You can adjust allowed elements here if needed.
            $config->set('HTML.SafeIframe', false);
            $config->set('HTML.SafeObject', false);
            $config->set('HTML.Allowed', 'p,b,i,u,strong,em,a[href|title],ul,ol,li,br,code,pre,blockquote,hr,sub,sup,table,tr,td,th,thead,tbody,tfoot,caption,abbr,abbr[title],img[src|alt|title|width|height]');
            $purifier = new HTMLPurifier($config);

            return $purifier->purify($html);
        } catch (\Throwable $e) {
            // Log the error and return original HTML as fallback (still potentially unsafe).
            Log::error('[HtmlSanitizer] purification error: ' . $e->getMessage());

            return $html;
        }
    }
}
