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
     * (e.g. a cURL error quoting the full URL with "?api_key=…", a request
     * header such as "X-Api-Key: …", or a JSON body with "access_token").
     */
    public static function redact(string $message): string
    {
        $names = 'api[_-]?key|apikey|key|access[_-]?token|refresh[_-]?token|id[_-]?token|token|password|passwd|secret|client[_-]?secret|signature|sig|auth';

        // Query parameters, plain ("api_key=…") or URL-encoded ("api_key%3D…", ending at "%26").
        $message = (string) preg_replace('/(?:\b|(?<=%3F)|(?<=%26)|(?<=%253F)|(?<=%2526))((?:' . $names . ')(?:=|%(?:25)?3D))(?:(?!%(?:25)?26)[^&\s"\'<>])+/i', '$1[hidden]', $message);
        // Request headers.
        $message = (string) preg_replace('/\b((?:X-)?(?:Api-?Key|Auth-?Token|Access-?Token|Goog-Api-Key|Subscription-Key)|Ocp-Apim-Subscription-Key|Private-Token)(\s*:\s*)[^\s,;"\'<>]+/i', '$1$2[hidden]', $message);
        // JSON fields ("access_token": "…").
        $message = (string) preg_replace('/("[\w-]*(?:' . $names . ')"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i', '$1"[hidden]"', $message);
        $message = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [hidden]', $message);
        $message = (string) preg_replace('~(\w+://)[^/\s:@]+:[^/\s@]+@~', '$1[hidden]@', $message);

        return (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s', '[hidden private key]', $message);
    }
}
