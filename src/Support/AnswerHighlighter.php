<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use Illuminate\Support\Str;
use IsrarMinhas\FilamentAiVisibility\Detection\MentionDetector;
use IsrarMinhas\FilamentAiVisibility\Models\Result;

/**
 * Renders an answer's markdown safely and highlights the brand and active
 * competitors, matching names the same way detection does.
 */
class AnswerHighlighter
{
    protected const BRAND_STYLE = 'background: rgba(16, 185, 129, 0.25); font-weight: 600; border-radius: 0.2rem; padding: 0 0.1rem;';

    protected const COMPETITOR_STYLE = 'background: rgba(239, 68, 68, 0.18); border-radius: 0.2rem; padding: 0 0.1rem;';

    public function __construct(
        protected MentionDetector $detector,
    ) {}

    public function html(Result $result): string
    {
        $html = Str::markdown(Text::clean((string) $result->answer), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $subjects = [];
        $styles = [];
        $brand = $result->brand;

        if ($brand) {
            $subjects[] = [MentionDetector::terms($brand), $brand->exclusions ?? []];
            $styles[] = static::BRAND_STYLE;

            foreach ($brand->competitors->where('is_active', true) as $competitor) {
                $subjects[] = [MentionDetector::terms($competitor), $competitor->exclusions ?? []];
                $styles[] = static::COMPETITOR_STYLE;
            }
        }

        if ($subjects === []) {
            return $html;
        }

        // Only touch text between tags, never tag names or attributes.
        $parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $index => $part) {
            if ($part === '' || $part[0] === '<') {
                continue;
            }

            // Match on the plain text, so "McDonald's" is found however it was escaped.
            $plain = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $spans = $this->detector->spans($plain, $subjects);

            if ($spans === []) {
                continue;
            }

            $out = '';
            $cursor = 0;

            foreach ($spans as $span) {
                $out .= e(substr($plain, $cursor, $span['start'] - $cursor))
                    . '<mark style="' . $styles[$span['subject']] . ' color: inherit;">' . e($span['text']) . '</mark>';
                $cursor = $span['end'];
            }

            $parts[$index] = $out . e(substr($plain, $cursor));
        }

        return implode('', $parts);
    }
}
