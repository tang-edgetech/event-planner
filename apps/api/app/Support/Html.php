<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans rich text typed in the backoffice before it is shown on the public site:
 * keeps formatting tags, removes scripts, event handlers and javascript: links.
 */
class Html
{
    /** @var array<string, list<string>> allowed tag => allowed attributes */
    private const ALLOWED = [
        'p' => ['style'], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'], 'h4' => ['style'], 'h5' => ['style'], 'h6' => ['style'],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'hr' => [], 'span' => ['style'], 'div' => ['style'],
        'a' => ['href', 'target', 'rel', 'title'], 'img' => ['src', 'alt', 'width', 'height', 'style'],
        'table' => ['style'], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['style', 'colspan', 'rowspan'], 'td' => ['style', 'colspan', 'rowspan'],
        'small' => [], 'sup' => [], 'sub' => [], 'code' => [], 'pre' => [],
    ];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html === null ? null : '';
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if (! $root) {
            return strip_tags($html);
        }
        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta'], true)) {
                    $node->removeChild($child);

                    continue;
                }
                if (! isset(self::ALLOWED[$tag])) {
                    self::walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $value = trim($attr->value);
                    $bad = ! in_array($name, self::ALLOWED[$tag], true)
                        || (in_array($name, ['href', 'src'], true) && ! preg_match('#^(https?:|mailto:|tel:|/|\#|media/)#i', $value))
                        || ($name === 'style' && preg_match('/expression|javascript|url\s*\(/i', $value));
                    if ($bad) {
                        $child->removeAttribute($attr->name);
                    }
                }
                if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                self::walk($child);
            }
        }
    }
}
