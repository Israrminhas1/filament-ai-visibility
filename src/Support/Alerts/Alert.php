<?php

namespace IsrarMinhas\FilamentAiVisibility\Support\Alerts;

/**
 * A message to deliver through the configured alert channels.
 */
final class Alert
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $level = 'warning',
        public readonly ?string $url = null,
        public readonly ?string $urlLabel = null,
        public readonly string $type = 'system',
        public readonly ?int $brandId = null,
        public readonly ?int $ruleId = null,
        public readonly array $payload = [],
    ) {}
}
