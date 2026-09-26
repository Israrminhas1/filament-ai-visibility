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

        if ($reason = $this->offTopic($text)) {
            return $reason;
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
     * Why an obvious non-question is rejected: small talk, jokes or pictures,
     * login searches, attempts to override the AI's instructions, or keyboard
     * mashing. Null when none apply.
     */
    protected function offTopic(string $text): ?string
    {
        $lower = mb_strtolower(str_replace('’', "'", $text));

        if (preg_match('/^(?:(?:hi|hello|hey|hiya|yo|sup|greetings|good (?:morning|afternoon|evening|night)|thanks|thank you|ok|okay|lol|there|how are you(?: doing)?(?: today)?|how\'s it going|how is it going|what\'s up|whats up|who are you|what(?: is|\'s) your name|are you (?:a bot|human|real|there))[\s,.!?]*)+$/u', $lower)) {
            return 'Small talk, not a question.';
        }

        if (preg_match('/\b(?:tell|write|make|give) (?:me |us )?(?:a |an |another |some )?(?:funny |good |short )?(?:joke|jokes|poem|riddle|limerick|pun)s?\b/u', $lower)
            || preg_match('/\b(?:show|send|give|generate|draw|create|make) (?:me |us )?(?:some |a |an )?(?:pictures?|photos?|images?|pics?|drawings?|videos?|memes?|gifs?|wallpapers?)\b/u', $lower)
            || preg_match('/^(?:pictures?|photos?|images?|pics?|draw)\b/u', $lower)) {
            return 'Asks for jokes or pictures, not a recommendation.';
        }

        if (preg_match('/\b(?:ignore|disregard|forget) (?:all |any )?(?:of )?(?:the |your |my )?(?:previous|prior|above|earlier|preceding|system) (?:instructions?|prompts?|messages?|rules|directions)\b|\bsystem prompt\b|\bjailbreak\b|\byou are now (?:in )?(?:dan|developer mode|unrestricted)\b|\bdeveloper mode\b/u', $lower)) {
            return 'Tries to override the AI\'s instructions.';
        }

        // Short navigational searches ("gmail sign in", "facebook login page").
        if (! $this->isQuestion($text) && count(explode(' ', $lower)) <= 4 && preg_match('/\b(?:log ?in|login|sign ?in|signin|sign ?up|signup)\b(?: page| screen| portal)?$/u', $lower)) {
            return 'A login search, not a question.';
        }

        if ($this->isMashing($lower)) {
            return 'Looks like random typing, not a question.';
        }

        return null;
    }

    /**
     * Keyboard mashing ("asdfghjkl zxcvbnm"): most longer Latin words have no
     * vowels, a long consonant run or one letter repeated. Short words are
     * ignored, so acronyms ("CRM", "SEO") don't count.
     */
    protected function isMashing(string $lower): bool
    {
        preg_match_all('/\b[a-z]{4,}\b/', $lower, $matches);
        $words = $matches[0];

        if ($words === []) {
            return false;
        }

        $odd = count(array_filter($words, fn ($word) => ! preg_match('/[aeiouy]/', $word) || preg_match('/[^aeiouy]{6,}/', $word) || preg_match('/(.)\1{3,}/', $word)));

        return $odd * 2 > count($words) || ($odd >= 2 && $odd * 2 >= count($words));
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
