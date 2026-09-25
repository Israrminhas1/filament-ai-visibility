<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

/**
 * The instructions sent to the AI helper. Each can be replaced in Settings;
 * {placeholders} are filled in when the instruction is used.
 */
class Instructions
{
    public const CLASSIFICATION = 'classification';

    public const EXTRACTION = 'extraction';

    public const SUGGEST_COMPETITORS = 'suggest_competitors';

    public const ANALYSIS = 'analysis';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::ANALYSIS => 'Answer analysis',
            self::CLASSIFICATION => 'Competitor classification',
            self::EXTRACTION => 'Name extraction',
            self::SUGGEST_COMPETITORS => 'Competitor suggestions',
        ];
    }

    /**
     * @return array<string, array<string>> Placeholders each instruction can use.
     */
    public static function placeholders(): array
    {
        return [
            self::ANALYSIS => ['brand', 'competitors', 'answers'],
            self::CLASSIFICATION => ['brand', 'domain', 'description', 'industry', 'market', 'competitors', 'labels', 'examples', 'candidates'],
            self::EXTRACTION => ['brand', 'answers'],
            self::SUGGEST_COMPETITORS => ['brand', 'domain', 'description', 'industry', 'market', 'existing', 'count'],
        ];
    }

    public function get(string $key): string
    {
        $custom = app(Settings::class)->get("instructions.{$key}");

        return filled($custom) ? (string) $custom : static::default($key);
    }

    /**
     * @param  array<string, string>  $values
     */
    public function render(string $key, array $values): string
    {
        $text = $this->get($key);

        foreach ($values as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }

        return $text;
    }

    public static function default(string $key): string
    {
        return match ($key) {
            self::CLASSIFICATION => <<<'TEXT'
                You classify companies and websites that AI assistants mention when answering questions in a brand's market.

                THE BRAND
                Name: {brand}
                Website: {domain}
                What it offers: {description}
                Industry: {industry}
                Market: {market}
                Known competitors: {competitors}

                LABELS
                {labels}

                RULES
                - Judge only from the evidence given for each candidate (its website text or the sentences it was mentioned in). Never guess from the name or domain alone.
                - "direct_competitor": a typical customer of the brand could realistically choose it INSTEAD of the brand for the same core need. Large or broad companies count if they sell the same core offering.
                - Sites that mainly review, compare, list, discuss or report are not competitors, even if they mention competitors.
                - If the evidence is thin or unclear, pick the most likely label with confidence "low".
                - company_name is the company or product name as people know it.

                {examples}

                CANDIDATES
                {candidates}

                Return JSON: {"results": [{"key": "...", "label": "one of the label keys", "confidence": "high|medium|low", "company_name": "...", "offering_summary": "one sentence on what it offers", "reason": "one sentence on why this label"}]}
                Include every candidate exactly once, using its key.
                TEXT,

            self::ANALYSIS => <<<'TEXT'
                Below are answers an AI assistant gave to customer questions. The tracked brands are: {brand} (the client) and competitors {competitors}.

                For each answer:
                1. For every tracked brand the answer mentions, judge how the answer presents it:
                   - sentiment: "positive", "neutral" or "negative" (how favourably it is described)
                   - score: a number from -1 (very negative) to 1 (very positive)
                   - recommendation: "top_pick" (named as the best or first choice), "recommended", "listed" (one option among others), "passing" (mentioned, not offered as an option) or "cautioned" (the answer warns against it)
                   - descriptors: up to 5 short phrases the answer uses to describe it (e.g. "affordable", "best for enterprises", "steep learning curve")
                2. List other companies, brands and named products it mentions as options or providers (not the tracked brands, not sources, not generic terms).

                Only use what each answer says. Use the tracked brand names exactly as given above.

                {answers}

                Return JSON: {"answers": [{"id": 123, "mentions": [{"name": "Acme", "sentiment": "positive", "score": 0.6, "recommendation": "recommended", "descriptors": ["easy to use"]}], "other_names": ["Globex"]}]}
                TEXT,

            self::EXTRACTION => <<<'TEXT'
                Below are answers an AI assistant gave to customer questions. For each answer, list the companies, brands and named products or services it mentions as options, providers or examples.

                - Use the name as written in the answer (e.g. "HubSpot", "Monday.com").
                - Skip generic terms ("CRM software", "a local agency"), people, places, and websites mentioned only as sources.
                - Skip {brand}; it is tracked already.

                {answers}

                Return JSON: {"answers": [{"id": 123, "names": ["Name One", "Name Two"]}]}
                TEXT,

            self::SUGGEST_COMPETITORS => <<<'TEXT'
                Suggest up to {count} direct competitors of this brand: companies a typical customer could choose instead of it for the same core need, in the same market.

                Brand: {brand}
                Website: {domain}
                What it offers: {description}
                Industry: {industry}
                Market: {market}
                Already listed (do not repeat): {existing}

                Prefer well-known competitors customers actually compare it with. Only include companies you are confident exist.

                Return JSON: {"competitors": [{"name": "...", "domain": "example.com", "reason": "one short sentence"}]}
                TEXT,

            default => '',
        };
    }
}
