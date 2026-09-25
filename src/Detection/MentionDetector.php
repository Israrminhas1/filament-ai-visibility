<?php

namespace IsrarMinhas\FilamentAiVisibility\Detection;

use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;

/**
 * Finds the brand and its competitors in an answer: whole-word,
 * case-insensitive matches of names and aliases, ignoring text inside URLs
 * and inside each subject's exclusion phrases.
 */
class MentionDetector
{
    /**
     * @param  iterable<Competitor>  $competitors
     * @return array<Mention> Ordered by position (first named first).
     */
    public function detect(string $answer, Brand $brand, iterable $competitors): array
    {
        $text = $this->maskUrls($answer);
        $mentions = [];

        if ($mention = $this->find($text, $answer, 'brand', $brand->getKey(), $brand->names(), $brand->exclusions ?? [])) {
            $mentions[] = $mention;
        }

        foreach ($competitors as $competitor) {
            if ($mention = $this->find($text, $answer, 'competitor', $competitor->getKey(), $competitor->names(), $competitor->exclusions ?? [])) {
                $mentions[] = $mention;
            }
        }

        usort($mentions, fn (Mention $a, Mention $b) => $a->offset <=> $b->offset);

        foreach ($mentions as $index => $mention) {
            $mention->position = $index + 1;
        }

        return $mentions;
    }

    /**
     * @param  array<string>  $names
     * @param  array<string>  $exclusions
     */
    protected function find(string $text, string $original, string $type, ?int $id, array $names, array $exclusions): ?Mention
    {
        $excluded = $this->excludedRanges($text, $exclusions);

        // Longest names first, so "Acme Cloud" wins over "Acme" at the same spot.
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $first = null;
        $firstName = null;
        $taken = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu';

            if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$match, $byteOffset]) {
                $end = $byteOffset + strlen($match);

                if ($this->inRanges($byteOffset, $end, $excluded) || $this->inRanges($byteOffset, $end, $taken)) {
                    continue;
                }

                $taken[] = [$byteOffset, $end];

                if ($first === null || $byteOffset < $first) {
                    $first = $byteOffset;
                    $firstName = $match;
                }
            }
        }

        if ($first === null) {
            return null;
        }

        return new Mention(
            subjectType: $type,
            subjectId: $id,
            nameMatched: $firstName,
            offset: $first,
            count: count($taken),
            snippet: $this->snippet($original, $first),
        );
    }

    /**
     * Replace URLs with spaces of the same byte length, so offsets stay valid
     * and names inside URLs ("acme.com/pricing") are not counted as mentions.
     */
    protected function maskUrls(string $answer): string
    {
        return (string) preg_replace_callback(
            '#(?:https?://|www\.)[^\s)\]>"\']+#i',
            fn (array $match) => str_repeat(' ', strlen($match[0])),
            $answer,
        );
    }

    /**
     * @param  array<string>  $exclusions
     * @return array<array{0: int, 1: int}>
     */
    protected function excludedRanges(string $text, array $exclusions): array
    {
        $ranges = [];

        foreach ($exclusions as $phrase) {
            $phrase = trim($phrase);

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

        preg_match('/^.*?(?:[.!?](?=\s|$)|\n|$)/s', $after, $match);
        $sentence = trim(substr($before, $start) . ($match[0] ?? $after));
        $sentence = trim((string) preg_replace('/\s+/u', ' ', $sentence));

        return mb_strlen($sentence) > 300 ? mb_substr($sentence, 0, 297) . '…' : $sentence;
    }
}
