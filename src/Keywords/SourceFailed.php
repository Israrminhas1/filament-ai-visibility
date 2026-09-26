<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords;

use RuntimeException;
use Throwable;

/**
 * A keyword source could not be synced. The message is stored on the
 * connection and shown to users, so secrets are stripped from it.
 */
class SourceFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $credentialsRejected = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct(static::redact($message), 0, $previous);
    }

    /**
     * Removes API keys, tokens and passwords that HTTP errors can echo back
     * (e.g. a cURL error quoting the full URL with "?api_key=…").
     */
    public static function redact(string $message): string
    {
        $message = (string) preg_replace('/\b((?:api_?key|key|access_token|refresh_token|token|password|passwd|secret|client_secret|signature|sig|auth)=)[^&\s"\'<>]+/i', '$1[hidden]', $message);
        $message = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [hidden]', $message);
        $message = (string) preg_replace('~(\w+://)[^/\s:@]+:[^/\s@]+@~', '$1[hidden]@', $message);

        return (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s', '[hidden private key]', $message);
    }
}
