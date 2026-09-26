<?php

namespace IsrarMinhas\FilamentAiVisibility\Prompts;

use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * Cheap rule checks that run before any AI review: length, question-like,
 * no brand name (unless branded), and not a near-duplicate.
 */
class PromptQualityGate
{
    protected const QUESTION_STARTS = [
        'what', 'which', 'how', 'who', 'where', 'when', 'why', 'is', 'are', 'can', 'could', 'should', 'would', 'does', 'do',
        'best', 'top', 'recommend', 'compare', 'looking', 'i need', 'i want', "i'm", 'i am', 'find', 'suggest', 'list', 'give', 'help',
        'any', 'good', 'cheapest', 'easiest', 'alternatives', 'alternative', 'tips',
    ];

    /**
     * @param  array<string>  $existing  Texts already tracked (or accepted in this batch).
     * @return string|null Why the prompt is rejected, or null when it passes.
     */
    public function check(string $text, Brand $brand, PromptIntent $intent, array $existing): ?string
    {
        $length = mb_strlen($text);

        if ($length < 12) {
            return 'Too short to be a real question.';
        }

        if ($length > 300) {
            return 'Too long for a typical question.';
        }

        $lower = mb_strtolower($text);
        $isQuestion = str_ends_with(rtrim($text), '?')
            || collect(static::QUESTION_STARTS)->contains(fn ($start) => str_starts_with($lower, $start . ' ') || str_starts_with($lower, $start . ','));

        if (! $isQuestion) {
            return 'Not a question or request.';
        }

        if ($intent !== PromptIntent::Branded && Text::mentionsAny($text, $brand->names())) {
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
}
