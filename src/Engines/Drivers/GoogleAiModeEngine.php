<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;

/**
 * Google's conversational AI Mode.
 */
class GoogleAiModeEngine extends SerpApiGoogleEngine
{
    public function key(): string
    {
        return 'google_ai_mode';
    }

    public function label(): string
    {
        return 'Google AI Mode';
    }

    public function ask(EngineRequest $request): EngineResponse
    {
        $response = $this->search($request->apiKey, [
            'engine' => 'google_ai_mode',
            'q' => $request->prompt,
            'gl' => $request->country ? strtolower($request->country) : null,
            'hl' => 'en',
        ]);

        $text = trim((string) $response->json('reconstructed_markdown'))
            ?: $this->blocksToText((array) $response->json('text_blocks', []));

        return $this->answer($text, (array) $response->json('references', []), $request, 1);
    }
}
