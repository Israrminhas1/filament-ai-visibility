<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;

/**
 * The AI Overview at the top of Google search results.
 */
class GoogleAiOverviewEngine extends SerpApiGoogleEngine
{
    public function key(): string
    {
        return 'google_ai_overview';
    }

    public function label(): string
    {
        return 'Google AI Overviews';
    }

    public function ask(EngineRequest $request): EngineResponse
    {
        $response = $this->search($request->apiKey, [
            'engine' => 'google',
            'q' => $request->prompt,
            'gl' => $request->country ? strtolower($request->country) : null,
            'hl' => 'en',
        ]);

        $overview = (array) $response->json('ai_overview', []);
        $searches = 1;

        // Some overviews load separately and need a second request, straight away (tokens expire in minutes).
        if (filled($overview['page_token'] ?? null) && empty($overview['text_blocks'])) {
            $overview = (array) $this->search($request->apiKey, [
                'engine' => 'google_ai_overview',
                'page_token' => $overview['page_token'],
            ])->json('ai_overview', []);
            $searches++;
        }

        return $this->answer(
            $this->blocksToText((array) ($overview['text_blocks'] ?? [])),
            (array) ($overview['references'] ?? []),
            $request,
            $searches,
        );
    }
}
