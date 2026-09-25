<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use Illuminate\Support\Str;
use IsrarMinhas\FilamentAiVisibility\Models\Result;

/**
 * Renders an answer's markdown safely and highlights the brand and competitors.
 */
class AnswerHighlighter
{
    public function html(Result $result): string
    {
        $html = Str::markdown((string) $result->answer, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $terms = [];

        foreach ($result->brand?->names() ?? [] as $name) {
            $terms[$name] = 'background: rgba(16, 185, 129, 0.25); font-weight: 600; border-radius: 0.2rem; padding: 0 0.1rem;';
        }

        foreach ($result->brand?->competitors ?? [] as $competitor) {
            foreach ($competitor->names() as $name) {
                $terms[$name] ??= 'background: rgba(239, 68, 68, 0.18); border-radius: 0.2rem; padding: 0 0.1rem;';
            }
        }

        if ($terms === []) {
            return $html;
        }

        // Longest first so "Acme Cloud" is highlighted as one phrase.
        uksort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $pattern = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(fn ($term) => preg_quote(e($term), '/'), array_keys($terms))) . ')(?![\p{L}\p{N}])/iu';
        $styles = array_change_key_case(array_combine(array_map(fn ($term) => e($term), array_keys($terms)), $terms), CASE_LOWER);

        // Only touch text between tags, never tag names or attributes.
        $parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $index => $part) {
            if ($part === '' || $part[0] === '<') {
                continue;
            }

            $parts[$index] = preg_replace_callback($pattern, function (array $match) use ($styles) {
                $style = $styles[mb_strtolower($match[1])] ?? reset($styles);

                return '<mark style="' . $style . ' color: inherit;">' . $match[1] . '</mark>';
            }, $part);
        }

        return implode('', $parts);
    }
}
