<?php

namespace IsrarMinhas\FilamentAiVisibility\Competitors;

use Closure;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Models\DomainProfile;
use IsrarMinhas\FilamentAiVisibility\Support\Text;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Reads what a website says about itself (title, description, headings and
 * opening text of the home and about pages), so classification is based on
 * evidence rather than a guess from the domain name.
 */
class EvidenceFetcher
{
    /**
     * Pages are read up to this size.
     */
    public const MAX_BYTES = 800_000;

    public const MAX_REDIRECTS = 3;

    protected const BLOCKED_IPV4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    protected const BLOCKED_IPV6 = [
        '::/128', '::1/128', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    protected ?Closure $resolver = null;

    public function profile(string $domain): DomainProfile
    {
        $domain = strtolower(trim($domain));
        $profile = DomainProfile::query()->firstOrNew(['domain' => $domain]);

        if ($profile->exists && $profile->isFresh()) {
            return $profile;
        }

        $home = $this->page("https://{$domain}/");
        $about = ($home === null || mb_strlen($home['excerpt']) < 400) ? $this->page("https://{$domain}/about") : null;

        if ($home === null && $about === null) {
            $profile->fill(['status' => 'unreachable', 'fetched_at' => now()])->save();

            return $profile;
        }

        $page = $home ?? $about;

        $profile->fill([
            'title' => $page['title'],
            'description' => $page['description'],
            'headings' => array_slice(array_values(array_unique([...($home['headings'] ?? []), ...($about['headings'] ?? [])])), 0, 15),
            'excerpt' => mb_substr(trim(($home['excerpt'] ?? '') . ' ' . ($about['excerpt'] ?? '')), 0, 2000),
            'status' => 'ok',
            'fetched_at' => now(),
        ])->save();

        return $profile;
    }

    /**
     * Use a custom DNS lookup (tests, or a resolver that goes through a proxy).
     *
     * @param  (Closure(string): array<string>)|null  $resolver  Host => its IP addresses.
     */
    public function resolveUsing(?Closure $resolver): static
    {
        $this->resolver = $resolver;

        return $this;
    }

    /**
     * @return array{title: ?string, description: ?string, headings: array<string>, excerpt: string}|null
     */
    protected function page(string $url): ?array
    {
        $html = $this->fetch($url);

        return $html === null ? null : $this->parse($html);
    }

    /**
     * Fetch an HTML page safely: only public hosts, redirects followed by hand
     * (each hop checked again) and the body read up to a size limit.
     */
    public function fetch(string $url): ?string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $url = $this->asciiUrl($url);
            $ip = $url === null ? null : $this->safeAddress($url);

            if ($ip === null) {
                return null;
            }

            $sink = fopen('php://temp', 'w+b');
            $meta = ['status' => null, 'type' => null, 'truncated' => false];

            try {
                try {
                    $response = $this->request($url, $ip, $sink, $meta);
                } catch (Throwable) {
                    return $this->truncated($sink, $meta);
                }

                if ($response->redirect()) {
                    $url = $this->resolveLocation($url, (string) $response->header('Location'));

                    if ($url === null) {
                        return null;
                    }

                    continue;
                }

                if (! $response->successful()) {
                    return null;
                }

                $html = $this->readBody($response);

                return $this->looksLikeHtml((string) $response->header('Content-Type'), $html) ? $html : null;
            } finally {
                if (is_resource($sink)) {
                    fclose($sink);
                }
            }
        }

        return null;
    }

    /**
     * @param  resource  $sink
     * @param  array{status: ?int, type: ?string, truncated: bool}  $meta
     */
    protected function request(string $url, string $ip, $sink, array &$meta): Response
    {
        return Http::timeout(config('ai-visibility.http.website_fetch_timeout', 10))
            ->withUserAgent(config('ai-visibility.http.user_agent'))
            ->accept('text/html')
            ->withoutRedirecting()
            ->withOptions($this->requestOptions($url, $ip, $sink, $meta))
            ->get($url);
    }

    /**
     * Guzzle options for one request: connect to the checked address, and stop
     * the transfer once MAX_BYTES are read or the page is clearly not HTML.
     *
     * Never "stream": Guzzle's stream handler refuses the "curl" option, and
     * the cURL handler is what pins the address (CURLOPT_RESOLVE).
     *
     * @param  resource  $sink
     * @param  array{status: ?int, type: ?string, truncated: bool}  $meta
     * @return array<string, mixed>
     */
    protected function requestOptions(string $url, string $ip, $sink, array &$meta): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $pinned = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return [
            'sink' => $sink,
            // Connect to the address that was checked, so DNS cannot change in between.
            'curl' => defined('CURLOPT_RESOLVE') ? [CURLOPT_RESOLVE => ["{$host}:443:{$pinned}", "{$host}:80:{$pinned}"]] : [],
            'on_headers' => function (ResponseInterface $response) use (&$meta) {
                $meta['status'] = $response->getStatusCode();
                $meta['type'] = $response->getHeaderLine('Content-Type');

                if ($meta['status'] < 200 || $meta['status'] >= 300) {
                    return;
                }

                if ($meta['type'] !== '' && ! str_contains(strtolower($meta['type']), 'html')) {
                    throw new RuntimeException("Not an HTML page ({$meta['type']}).");
                }
            },
            // Returning true stops the transfer; what was read so far stays in the sink.
            'progress' => function ($total, $downloaded) use (&$meta) {
                if ($downloaded > self::MAX_BYTES) {
                    $meta['truncated'] = true;

                    return true;
                }

                return false;
            },
        ];
    }

    /**
     * The start of a page whose transfer was stopped at the size limit; null
     * when the request failed for any other reason.
     *
     * @param  resource  $sink
     * @param  array{status: ?int, type: ?string, truncated: bool}  $meta
     */
    protected function truncated($sink, array $meta): ?string
    {
        if (! $meta['truncated'] || $meta['status'] < 200 || $meta['status'] >= 300 || ! is_resource($sink)) {
            return null;
        }

        rewind($sink);
        $html = (string) stream_get_contents($sink, self::MAX_BYTES);

        return $html !== '' && $this->looksLikeHtml((string) $meta['type'], $html) ? $html : null;
    }

    /**
     * The body, read up to MAX_BYTES (a larger page is cut off there).
     */
    protected function readBody(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $html = '';

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        while (! $stream->eof() && strlen($html) < self::MAX_BYTES) {
            $chunk = $stream->read(min(65_536, self::MAX_BYTES - strlen($html)));

            if ($chunk === '') {
                break;
            }

            $html .= $chunk;
        }

        return $html;
    }

    /**
     * Served as HTML, or served without a content type but starting like an HTML document.
     */
    protected function looksLikeHtml(string $contentType, string $body): bool
    {
        if (trim($contentType) !== '') {
            return str_contains(strtolower($contentType), 'html');
        }

        $start = strtolower(ltrim(substr($body, 0, 512), "\xEF\xBB\xBF \t\r\n"));

        return str_starts_with($start, '<!doctype html') || str_starts_with($start, '<html');
    }

    /**
     * The absolute URL a redirect points to (relative paths and query-only
     * locations included), or null when there is none.
     */
    protected function resolveLocation(string $current, string $location): ?string
    {
        $location = trim($location);

        if ($location === '') {
            return null;
        }

        try {
            return (string) UriResolver::resolve(new Uri($current), new Uri($location));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The URL with an internationalised host in its ASCII form ("münchen.de"
     * → "xn--mnchen-3ya.de"); null when it cannot be converted.
     */
    protected function asciiUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! preg_match('/[^\x00-\x7F]/', $host)) {
            return $url;
        }

        if (! function_exists('idn_to_ascii')) {
            return null;
        }

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        $position = strpos($url, $host);

        return $ascii === false || $position === false ? null : substr_replace($url, strtolower($ascii), $position, strlen($host));
    }

    /**
     * The public IP address to connect to for this URL, or null when the URL
     * is not safe to fetch: not http(s), an IP address, an unusual port, or a
     * host that points at a private network.
     */
    public function safeAddress(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            return null;
        }

        if (! $this->isAllowedHost($host)) {
            return null;
        }

        $ips = $this->resolve($host);

        if ($ips === []) {
            return null;
        }

        // Every address must be public: a host may list a private one next to a public one.
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    /**
     * A plain hostname: no IP literals, numeric hosts or local names.
     */
    protected function isAllowedHost(string $host): bool
    {
        if ($host === '' || str_contains($host, '[') || str_contains($host, ':') || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
            return false;
        }

        // A numeric last part ("0x7f.1", "127.1") means an IP address in disguise; real TLDs are never numeric.
        if (preg_match('/(^|\.)(0x[0-9a-f]*|[0-9]+)$/', $host)) {
            return false;
        }

        return ! preg_match('/(^|\.)(localhost|local|localdomain|internal|intranet|lan|home|arpa)$/', $host);
    }

    /**
     * @return array<string>
     */
    protected function resolve(string $host): array
    {
        if ($this->resolver) {
            return array_values(array_filter((array) ($this->resolver)($host), 'is_string'));
        }

        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Whether an address is on the public internet (not private, loopback,
     * link-local, shared, multicast or reserved), for IPv4 and IPv6.
     */
    public function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]');
        $packed = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : false;

        if ($packed === false) {
            return false;
        }

        // IPv4 inside IPv6 (::ffff:10.0.0.1, ::10.0.0.1, 64:ff9b::10.0.0.1) is checked as IPv4.
        if (strlen($packed) === 16) {
            $prefix = substr($packed, 0, 12);

            if (in_array($prefix, [str_repeat("\0", 10) . "\xff\xff", str_repeat("\0", 12), "\x00\x64\xff\x9b" . str_repeat("\0", 8)], true)
                && substr($packed, 12) !== "\0\0\0\0" && substr($packed, 12) !== "\0\0\0\1") {
                $packed = substr($packed, 12);
                $ip = (string) inet_ntop($packed);
            }
        }

        if (defined('FILTER_FLAG_GLOBAL_RANGE') && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        foreach (strlen($packed) === 4 ? self::BLOCKED_IPV4 : self::BLOCKED_IPV6 as $cidr) {
            if ($this->inRange($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    protected function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $network = inet_pton($network);

        if ($network === false || strlen($network) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        if ($bits % 8 === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits % 8)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /**
     * @return array{title: ?string, description: ?string, headings: array<string>, excerpt: string}
     */
    public function parse(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 800_000));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//footer|//header//nav|//form') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $meta = fn (string $query) => ($node = $xpath->query($query)->item(0)) ? (Text::squish($node->getAttribute('content')) ?: null) : null;

        $headings = [];

        foreach ($xpath->query('//h1|//h2|//h3') as $node) {
            $text = Text::squish($node->textContent);

            if ($text !== '' && mb_strlen($text) < 150) {
                $headings[] = $text;
            }

            if (count($headings) >= 12) {
                break;
            }
        }

        $body = $xpath->query('//body')->item(0);
        $title = $xpath->query('//title')->item(0);

        return [
            'title' => $title ? (Text::squish($title->textContent) ?: null) : null,
            'description' => $meta('//meta[@name="description"]') ?? $meta('//meta[@property="og:description"]'),
            'headings' => $headings,
            'excerpt' => mb_substr(Text::squish($body?->textContent ?? ''), 0, 1500),
        ];
    }
}
