<?php

namespace IsrarMinhas\FilamentAiVisibility\Detection;

use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * Finds the brand and its competitors in an answer: whole-word matches of
 * names and aliases in any case, and of their short forms and domain labels
 * ("Nintendo" for "Nintendo Co., Ltd.") in the name's own casing or in
 * capitals. Links, email addresses, bare domains and each subject's exclusion
 * phrases are ignored, except that a subject's own domain written as a name
 * ("Booking.com is ...") counts. Where names overlap, the longest match wins,
 * whichever subject it belongs to.
 */
class MentionDetector
{
    /**
     * Top-level domains treated as a bare domain in text ("notion.so"), besides links.
     */
    protected const TLDS = 'com|net|org|io|ai|co|app|dev|so|me|info|biz|xyz|tech|cloud|shop|store|site|online|gg|tv|ly|to|sh|fm|is|it|us|uk|de|fr|es|nl|eu|ca|au|in|jp|cn|br|ru|ch|se|no|dk|fi|pl|be|at|nz|za|kr|mx|ie|sg|hk|tw|il|edu|gov';

    /**
     * The names to look for, for a brand or competitor. Public so other
     * features (setup, discovery) match names the same way.
     *
     * @return array<string>
     */
    public static function terms(Brand|Competitor $subject): array
    {
        return Text::matchTerms($subject->names(), $subject->domains ?? []);
    }

    /**
     * What spans() needs for a brand or competitor: [names in any case,
     * exclusions, derived terms in exact case, domains].
     *
     * @return array{0: array<string>, 1: array<string>, 2: array<string>, 3: array<string>}
     */
    public static function subject(Brand|Competitor $subject): array
    {
        $domains = $subject->domains ?? [];
        [$named, $derived] = Text::terms($subject->names(), $domains);

        return [array_values($named), $subject->exclusions ?? [], array_values($derived), $domains];
    }

    /**
     * @param  iterable<Competitor>  $competitors
     * @return array<Mention> Ordered by position (first named first).
     */
    public function detect(string $answer, Brand $brand, iterable $competitors): array
    {
        $text = Text::clean($answer);

        $subjects = [['brand', $brand->getKey(), static::subject($brand)]];

        foreach ($competitors as $competitor) {
            $subjects[] = ['competitor', $competitor->getKey(), static::subject($competitor)];
        }

        $found = [];

        foreach ($this->spans($text, array_map(fn ($subject) => $subject[2], $subjects)) as $span) {
            $found[$span['subject']][] = $span;
        }

        $mentions = [];

        foreach ($found as $index => $matches) {
            [$type, $id] = $subjects[$index];

            $mentions[] = new Mention(
                subjectType: $type,
                subjectId: $id,
                nameMatched: $matches[0]['text'],
                offset: $matches[0]['start'],
                count: count($matches),
                snippet: $this->snippet($text, $matches[0]['start']),
            );
        }

        usort($mentions, fn (Mention $a, Mention $b) => $a->offset <=> $b->offset);

        foreach ($mentions as $index => $mention) {
            $mention->position = $index + 1;
        }

        return $mentions;
    }

    /**
     * Non-overlapping matches of several subjects in text already passed
     * through Text::clean(), in text order. Where matches overlap, the longest
     * wins, whichever subject it belongs to ("Acme Cloud Pro" over "Acme").
     * Names match in any case, derived terms only in the casings from
     * Text::casings(), and a bare domain that is one of the subject's own
     * domains ("Booking.com") counts as one match.
     *
     * @param  array<int|string, array{0: array<string>, 1: array<string>, 2?: array<string>, 3?: array<string>}>  $subjects  key => [names, exclusions, derived terms, domains]
     * @return array<int, array{start: int, end: int, text: string, subject: int|string}>
     */
    public function spans(string $text, array $subjects): array
    {
        $links = $this->linkRanges($text);
        $candidates = [];
        $order = 0;

        foreach ($subjects as $key => $subject) {
            [$names, $exclusions] = $subject;
            $excluded = $this->excludedRanges($text, $exclusions);
            $casings = [];

            foreach ($subject[2] ?? [] as $term) {
                array_push($casings, ...Text::casings(trim(Text::clean((string) $term))));
            }

            $matches = [
                ...$this->matches($text, $names, $links, $excluded, 'iu'),
                ...$this->matches($text, $casings, $links, $excluded, 'u'),
                ...$this->ownDomains($text, $subject[3] ?? [], $links, $excluded),
            ];

            foreach ($matches as $match) {
                $candidates[] = $match + ['subject' => $key, 'order' => $order];
            }

            $order++;
        }

        usort($candidates, fn ($a, $b) => [$b['end'] - $b['start'], $a['start'], $a['order']] <=> [$a['end'] - $a['start'], $b['start'], $b['order']]);

        $taken = [];
        $spans = [];

        foreach ($candidates as $candidate) {
            if ($this->inRanges($candidate['start'], $candidate['end'], $taken)) {
                continue;
            }

            $taken[] = [$candidate['start'], $candidate['end']];
            unset($candidate['order']);
            $spans[] = $candidate;
        }

        usort($spans, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $spans;
    }

    /**
     * @param  array<string>  $names
     * @param  array<array{0: int, 1: int}>  $links
     * @param  array<array{0: int, 1: int}>  $excluded
     * @param  string  $flags  "iu" for any case, "u" for exact case
     * @return array<int, array{start: int, end: int, text: string}>
     */
    protected function matches(string $text, array $names, array $links, array $excluded, string $flags = 'iu'): array
    {
        $matches = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '' || ! preg_match_all('/' . Text::namePattern($name) . '/' . $flags, $text, $found, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($found[0] as [$match, $start]) {
                $end = $start + strlen($match);

                if ($this->inRanges($start, $end, $excluded) || $this->inLink($start, $end, $links)) {
                    continue;
                }

                $matches[] = ['start' => $start, 'end' => $end, 'text' => $match];
            }
        }

        return $matches;
    }

    /**
     * Bare domains in the text that are one of the domains or a subdomain of
     * one ("Booking.com is the largest OTA"): the subject written as its domain.
     *
     * @param  array<string>  $domains
     * @param  array<array{0: int, 1: int, 2: ?string}>  $links
     * @param  array<array{0: int, 1: int}>  $excluded
     * @return array<int, array{start: int, end: int, text: string}>
     */
    protected function ownDomains(string $text, array $domains, array $links, array $excluded): array
    {
        $matches = [];

        foreach ($domains === [] ? [] : $links as [$start, $end, $host]) {
            if ($host !== null && ! $this->inRanges($start, $end, $excluded) && Domains::matches($host, $domains)) {
                $matches[] = ['start' => $start, 'end' => $end, 'text' => substr($text, $start, $end - $start)];
            }
        }

        return $matches;
    }

    /**
     * Byte ranges of links, email addresses and bare domains ("acme.com/pricing",
     * "support@acme.io", "notion.so"). Names inside them are not mentions.
     * A bare domain without a path also carries its host, as it may be a
     * subject's own domain written as its name. The top-level domain must be
     * lowercase, so "I use Acme.It works." has no domain in it.
     *
     * @return array<array{0: int, 1: int, 2: ?string}>
     */
    protected function linkRanges(string $text): array
    {
        $patterns = [
            '#(?:https?://|www\.)[^\s)\]>"\']+#iu',
            '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)+/u',
            '#(?<![\p{L}\p{N}@./-])((?:[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?\.)+(?:' . static::TLDS . '))(?![\p{L}\p{N}-])(/[^\s)\]>"\']*)?#u',
        ];

        $ranges = [];

        foreach ($patterns as $index => $pattern) {
            if (! preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }

            foreach ($found as $groups) {
                [$match, $start] = $groups[0];
                $bare = $index === 2 && ($groups[2][0] ?? '') === '' && stripos($match, 'www.') !== 0;

                $ranges[] = [$start, $start + strlen(rtrim($match, '.,;:!?')), $bare ? mb_strtolower($groups[1][0]) : null];
            }
        }

        return $ranges;
    }

    /**
     * Inside a link, unless the match is the whole link: a brand named
     * "Monday.com" is still found when written on its own.
     *
     * @param  array<array{0: int, 1: int, 2: ?string}>  $links
     */
    protected function inLink(int $start, int $end, array $links): bool
    {
        foreach ($links as [$linkStart, $linkEnd]) {
            if ($start < $linkEnd && $end > $linkStart && ! ($start === $linkStart && $end === $linkEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string>  $exclusions
     * @return array<array{0: int, 1: int}>
     */
    protected function excludedRanges(string $text, array $exclusions): array
    {
        $ranges = [];

        foreach ($exclusions as $phrase) {
            $phrase = trim(Text::clean((string) $phrase));

            if ($phrase === '' || ! preg_match_all('/' . preg_quote($phrase, '/') . '/iu', $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$match, $offset]) {
                $ranges[] = [$offset, $offset + strlen($match)];
            }
        }

        return $ranges;
    }

    /**
     * @param  array<array{0: int, 1: int}>  $ranges
     */
    protected function inRanges(int $start, int $end, array $ranges): bool
    {
        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            if ($start < $rangeEnd && $end > $rangeStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sentence (or line) containing the byte offset, trimmed to ~300 characters.
     */
    protected function snippet(string $answer, int $byteOffset): string
    {
        $before = substr($answer, 0, $byteOffset);
        $after = substr($answer, $byteOffset);

        $startCandidates = array_filter([strrpos($before, '. '), strrpos($before, "\n"), strrpos($before, '? '), strrpos($before, '! ')], fn ($pos) => $pos !== false);
        $start = $startCandidates ? max($startCandidates) + 1 : 0;

        preg_match('/^.*?(?:[.!?](?=\s|$)|\n|$)/su', $after, $match);
        $sentence = trim(substr($before, $start) . ($match[0] ?? $after));
        $sentence = trim((string) preg_replace('/\s+/u', ' ', $sentence));

        return mb_strlen($sentence) > 300 ? mb_substr($sentence, 0, 297) . '…' : $sentence;
    }
}
