<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers\Concerns;

use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;

/**
 * Parses the OpenAI-style Responses API output (used by OpenAI and xAI):
 * `message` items hold `output_text` parts with `url_citation` annotations.
 */
trait ParsesResponsesApi
{
    protected function parseAnswer(Response $response, EngineRequest $request): EngineResponse
    {
        $text = [];
        $citations = [];
        $searches = 0;

        foreach ($response->json('output', []) as $item) {
            if (($item['type'] ?? null) === 'web_search_call') {
                $searches++;
            }

            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) !== 'output_text') {
                    continue;
                }

                $text[] = $part['text'] ?? '';

                foreach ($part['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? null) === 'url_citation') {
                        $citations[] = $annotation;
                    }
                }
            }
        }

        // xAI can also return a top-level list of citation URLs.
        foreach ((array) $response->json('citations', []) as $citation) {
            $citations[] = $citation;
        }

        return new EngineResponse(
            answer: trim(implode("\n\n", $text)),
            citations: EngineResponse::uniqueCitations($citations),
            model: $response->json('model') ?? $request->model,
            inputTokens: $response->json('usage.input_tokens'),
            outputTokens: $response->json('usage.output_tokens'),
            // xAI reports sources used rather than separate search calls.
            searches: $searches ?: ((int) $response->json('usage.num_sources_used', 0) > 0 ? 1 : 0),
        );
    }
}
