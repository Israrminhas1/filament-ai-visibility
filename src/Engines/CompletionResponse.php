<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

final class CompletionResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly int $searches = 0,
    ) {}

    /**
     * The first JSON object or array in the text, tolerating code fences and
     * surrounding prose. Returns null when there is none.
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        $text = trim($this->text);
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $start = strpos($text, $open);
            $end = strrpos($text, $close);

            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }
}
