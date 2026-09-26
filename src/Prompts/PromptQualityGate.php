<?php

namespace IsrarMinhas\FilamentAiVisibility\Prompts;

use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * Cheap rule checks that run before any AI review: length, a question,
 * request or short search-style phrase, no brand name (unless branded),
 * and not a near-duplicate.
 */
class PromptQualityGate
{
    /**
     * Openings of questions and requests ("Tell me…", "Looking for…").
     */
    protected const QUESTION_STARTS = [
        'what', "what's", 'whats', 'which', 'how', 'who', 'where', 'when', 'why', 'is', 'are', 'can', 'could', 'should', 'would', 'will', 'does', 'do',
        'best', 'top', 'recommend', 'compare', 'looking', 'i need', 'i want', "i'm", 'i am', 'find', 'suggest', 'list', 'give', 'help',
        'any', 'good', 'cheapest', 'easiest', 'alternatives', 'alternative', 'tips',
        'tell me', 'show me', 'explain', 'describe', 'name', 'please', "i'd like", 'i would like', 'we need', "we're looking", 'we are looking',
    ];

    /**
     * Longest statement still treated as a search-style query ("crm software for agencies").
     */
    protected const MAX_QUERY_WORDS = 12;

    /**
     * @param  array<string>  $existing  Texts already tracked (or accepted in this batch).
     * @param  bool|null  $allowBrand  Whether naming the brand is fine; defaults to true for branded prompts only.
     * @return string|null Why the prompt is rejected, or null when it passes.
     */
    public function check(string $text, Brand $brand, PromptIntent $intent, array $existing, ?bool $allowBrand = null): ?string
    {
        $text = Text::squish($text);
        $length = mb_strlen($text);

        if ($length < 12) {
            return 'Too short to be a real question.';
        }

        if ($length > 300) {
            return 'Too long for a typical question.';
        }

        if (preg_match('~^(https?://|www\.)\S+$|^[\w-]+(\.[\w-]+)+(/\S*)?$~i', $text)) {
            return 'Just a link, not a question.';
        }

        $words = count(preg_split('/\s+/u', $text));

        if ($words < 2) {
            return 'Too short to be a real question.';
        }

        if (! $this->isQuestion($text) && ($words > static::MAX_QUERY_WORDS || ! preg_match('/\p{L}/u', $text))) {
            return 'Not a question or request.';
        }

        if (! ($allowBrand ?? $intent === PromptIntent::Branded) && Text::mentionsAny($text, $brand->names())) {
            return 'Names the brand, which would bias the answer.';
        }

        $hash = Text::hash($text);

        foreach ($existing as $other) {
            if (Text::hash($other) === $hash) {
                return 'Duplicate of an existing prompt.';
            }

            if (Text::similarity($text, $other) >= 0.85) {
                return 'Too similar to: "' . mb_substr($other, 0, 80) . '"';
            }
        }

        return null;
    }

    /**
     * A question ("…?") or a request ("Tell me…", "Recommend…").
     */
    protected function isQuestion(string $text): bool
    {
        if (str_ends_with($text, '?')) {
            return true;
        }

        $lower = mb_strtolower(str_replace('’', "'", $text));

        foreach (static::QUESTION_STARTS as $start) {
            if (preg_match('/^' . preg_quote($start, '/') . '(?![\p{L}\p{N}\'])/u', $lower)) {
                return true;
            }
        }

        return false;
    }
}
