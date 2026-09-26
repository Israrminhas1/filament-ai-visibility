<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use IsrarMinhas\FilamentAiVisibility\Detection\Domains;

class Text
{
    /**
     * Scripts written without spaces between words, where a name can sit
     * directly next to other letters ("ソニーの製品").
     */
    public const NO_SPACE_SCRIPTS = '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}';

    /**
     * Company-form suffixes removed by shortName(), matched case-insensitively.
     */
    protected const LEGAL_SUFFIX = '(?:co|ltd|inc|incorporated|llc|l\.l\.c|gmbh|ag|corp|corporation|plc|s\.a|sa|s\.p\.a|spa|b\.v|bv|n\.v|nv|pty|limited|company|k\.k|kk|holdings)';

    /**
     * Collapse whitespace and trim.
     */
    public static function squish(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_scrub((string) $text, 'UTF-8')));
    }

    /**
     * Valid UTF-8 with straight quotes, so /u patterns never fail silently
     * and "McDonald’s" matches "McDonald's". Changes byte offsets, so clean
     * the text once and work on the cleaned copy.
     */
    public static function clean(?string $text): string
    {
        return strtr(mb_scrub((string) $text, 'UTF-8'), ['’' => "'", '‘' => "'", '‛' => "'", '“' => '"', '”' => '"', '„' => '"']);
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

    /**
     * Normalised and without accents ("Pokémon" → "pokemon"), for keys that a
     * database may compare accent-insensitively (MySQL's default collations).
     * Scripts without accents, such as Chinese, are left as they are.
     */
    public static function foldKey(?string $text): string
    {
        $text = static::normalize($text);

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            $text = $decomposed === false ? $text : (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
        }

        return $text;
    }

    public static function hash(?string $text): string
    {
        return hash('sha256', static::normalize($text));
    }

    /**
     * The everyday name without company-form suffixes: "Nintendo Co., Ltd." → "Nintendo".
     * Null when nothing was removed or what is left is too short to match safely.
     */
    public static function shortName(?string $name): ?string
    {
        $name = static::squish(static::clean($name));
        $short = $name;

        do {
            $before = $short;
            $short = (string) preg_replace('/(?:[\s,]+|(?<=\.))(?:&\s*)?' . static::LEGAL_SUFFIX . '\.?[\s,.]*$/iu', '', $short);
            $short = rtrim($short, " \t,.&-");
        } while ($short !== $before && $short !== '');

        if ($short === $name || mb_strlen($short) < 3 || ! preg_match('/[\p{L}\p{N}]/u', $short)) {
            return null;
        }

        return $short;
    }

    /**
     * Everything to look for when detecting a brand or competitor: its names,
     * their short forms, and any domain label that appears in a name
     * ("nintendo.com" with "Nintendo Co., Ltd." adds "nintendo").
     * Derived terms are never shorter than 3 characters.
     *
     * @param  array<string>  $names
     * @param  array<string>  $domains
     * @return array<string>
     */
    public static function matchTerms(array $names, array $domains = []): array
    {
        $names = array_values(array_filter(array_map(fn ($name) => static::squish(static::clean((string) $name)), $names)));
        $terms = [];

        foreach ($names as $name) {
            $terms[mb_strtolower($name)] ??= $name;

            if ($short = static::shortName($name)) {
                $terms[mb_strtolower($short)] ??= $short;
            }
        }

        foreach ($domains as $domain) {
            $registrable = Domains::registrable((string) $domain);
            $label = $registrable ? explode('.', $registrable)[0] : '';

            if (mb_strlen($label) < 3) {
                continue;
            }

            foreach ($names as $name) {
                if (mb_stripos($name, $label) !== false) {
                    $terms[mb_strtolower($label)] ??= $label;

                    break;
                }
            }
        }

        return array_values($terms);
    }

    /**
     * Regex source matching the name as a whole word. The boundary checks are
     * skipped next to scripts written without spaces (Chinese, Japanese,
     * Korean, Thai). Pass $quoted to use your own regex for the name itself.
     * Use with the "iu" flags and "/" as the delimiter.
     */
    public static function namePattern(string $name, ?string $quoted = null): string
    {
        $scripts = static::NO_SPACE_SCRIPTS;
        $quoted ??= preg_quote($name, '/');

        $before = preg_match("/^[{$scripts}]/u", $name) ? '' : "(?<!(?=[\\p{L}\\p{N}])[^{$scripts}])";
        $after = preg_match("/[{$scripts}]$/u", $name) ? '' : "(?!(?=[\\p{L}\\p{N}])[^{$scripts}])";

        return $before . $quoted . $after;
    }

    /**
     * Whole-word, case-insensitive match of any of the names in the text.
     *
     * @param  array<string>  $names
     */
    public static function mentionsAny(string $text, array $names): bool
    {
        $text = static::clean($text);

        foreach ($names as $name) {
            $name = trim(static::clean((string) $name));

            if ($name !== '' && preg_match('/' . static::namePattern($name) . '/iu', $text)) {
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
