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
    protected const LEGAL_SUFFIX = '(?:co|ltd|inc|incorporated|llc|l\.l\.c|gmbh|ag|corp|corporation|plc|s\.a|sa|s\.p\.a|spa|b\.v|bv|n\.v|nv|pty|limited|k\.k|kk)';

    /**
     * Generic words shortName() removes once, after the legal suffixes
     * ("Acme Holdings, Inc." → "Acme").
     */
    protected const GENERIC_SUFFIX = '(?:company|holdings|group)';

    /**
     * Ordinary words a short name may not consist of alone ("The Company Ltd").
     */
    protected const COMMON_WORDS = [
        'the', 'a', 'an', 'and', 'of', 'for', 'my', 'our', 'your', 'all', 'company', 'group', 'holdings', 'inc', 'co', 'corp',
        'global', 'international', 'national', 'general', 'united', 'american', 'services', 'solutions', 'systems',
        'technologies', 'technology', 'tech', 'labs', 'software', 'digital', 'media', 'partners', 'ventures', 'capital',
        'enterprises', 'industries', 'consulting', 'data', 'cloud', 'online', 'world', 'home', 'best', 'next',
        'new', 'one', 'first', 'top', 'good', 'great', 'big', 'smart', 'open', 'pro', 'plus', 'prime', 'star', 'free',
    ];

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
        // Strip marks before normalising, so "İ" becomes "i" rather than "i" and a stray dot.
        $text = mb_scrub((string) $text, 'UTF-8');

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            $text = $decomposed === false ? $text : (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
        }

        // Case folding also turns "ß" into "ss".
        return static::squish(mb_convert_case(static::normalize($text), MB_CASE_FOLD));
    }

    public static function hash(?string $text): string
    {
        return hash('sha256', static::normalize($text));
    }

    /**
     * The everyday name without company-form suffixes: "Nintendo Co., Ltd." → "Nintendo".
     * Legal suffixes are removed, then at most one generic word ("Holdings").
     * Null when nothing was removed, or what is left is shorter than 3
     * characters or only ordinary words ("The Company Ltd" → null).
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

        $short = rtrim((string) preg_replace('/[\s,]+(?:&\s*)?' . static::GENERIC_SUFFIX . '\.?$/iu', '', $short), " \t,.&-");

        if ($short === $name || mb_strlen($short) < 3 || ! preg_match('/[\p{L}\p{N}]/u', $short) || static::isCommon($short)) {
            return null;
        }

        return $short;
    }

    /**
     * Whether every word is an ordinary English word ("The", "Global Services").
     */
    protected static function isCommon(string $text): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_diff($words, static::COMMON_WORDS) === [];
    }

    /**
     * Everything to look for when detecting a brand or competitor: its names,
     * their short forms, and any domain label that is a word of a name
     * ("nintendo.com" with "Nintendo Co., Ltd." adds "Nintendo").
     * Derived terms are never shorter than 3 characters. See terms() for
     * which of them match in any case.
     *
     * @param  array<string>  $names
     * @param  array<string>  $domains
     * @return array<string>
     */
    public static function matchTerms(array $names, array $domains = []): array
    {
        [$named, $derived] = static::terms($names, $domains);

        return array_values($named + $derived);
    }

    /**
     * The names and aliases as typed, matched in any case, and the terms
     * derived from them (short names, domain labels), matched only in the
     * name's own casing or in capitals (see casings()), so "Target
     * Corporation" finds "Target" but not "target audience".
     *
     * @param  array<string>  $names
     * @param  array<string>  $domains
     * @return array{0: array<string, string>, 1: array<string, string>} [typed, derived], keyed by lowercase
     */
    public static function terms(array $names, array $domains = []): array
    {
        $names = array_values(array_filter(array_map(fn ($name) => static::squish(static::clean((string) $name)), $names)));
        $named = [];
        $derived = [];

        foreach ($names as $name) {
            $named[mb_strtolower($name)] ??= $name;
        }

        foreach ($names as $name) {
            if (($short = static::shortName($name)) && ! isset($named[mb_strtolower($short)])) {
                $derived[mb_strtolower($short)] ??= $short;
            }
        }

        foreach ($domains as $domain) {
            $registrable = Domains::registrable((string) $domain);
            $label = $registrable ? mb_strtolower(explode('.', $registrable)[0]) : '';

            if (mb_strlen($label) < 3 || isset($named[$label])) {
                continue;
            }

            // Only a whole word of a name, in that name's casing ("Sony" from "Sony Interactive Entertainment").
            foreach ($names as $name) {
                foreach (explode(' ', $name) as $word) {
                    $word = trim($word, ",.&()\"'!?:;");

                    if (mb_strtolower($word) === $label) {
                        $derived[$label] ??= $word;

                        continue 3;
                    }
                }
            }
        }

        return [$named, $derived];
    }

    /**
     * The casings a derived term matches in: as written and in capitals,
     * plus capitalised when it was written in lowercase.
     *
     * @return array<string>
     */
    public static function casings(string $term): array
    {
        $casings = [$term, mb_strtoupper($term)];

        if ($term === mb_strtolower($term)) {
            $casings[] = mb_strtoupper(mb_substr($term, 0, 1)) . mb_substr($term, 1);
        }

        return array_values(array_unique($casings));
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
