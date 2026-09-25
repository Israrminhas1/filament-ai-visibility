<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

class Text
{
    /**
     * Collapse whitespace and trim.
     */
    public static function squish(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * Lowercase, strip punctuation and collapse whitespace, so near-identical
     * texts compare equal ("Best CRM?" == "best crm").
     */
    public static function normalize(?string $text): string
    {
        $text = mb_strtolower(static::squish($text));
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return static::squish($text);
    }

    public static function hash(?string $text): string
    {
        return hash('sha256', static::normalize($text));
    }

    /**
     * Whole-word, case-insensitive match of any of the names in the text.
     *
     * @param  array<string>  $names
     */
    public static function mentionsAny(string $text, array $names): bool
    {
        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Word-overlap similarity between 0 and 1 (Jaccard over word sets).
     */
    public static function similarity(string $a, string $b): float
    {
        $wordsA = array_unique(explode(' ', static::normalize($a)));
        $wordsB = array_unique(explode(' ', static::normalize($b)));

        $union = count(array_unique([...$wordsA, ...$wordsB]));

        return $union === 0 ? 0.0 : count(array_intersect($wordsA, $wordsB)) / $union;
    }
}
