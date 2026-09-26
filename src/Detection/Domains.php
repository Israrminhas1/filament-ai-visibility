<?php

namespace IsrarMinhas\FilamentAiVisibility\Detection;

class Domains
{
    /**
     * Two-part public suffixes, so "shop.acme.co.uk" resolves to "acme.co.uk".
     * Not the full Public Suffix List, but covers the common country domains
     * and the hosting platforms that give each customer a subdomain.
     */
    protected const MULTI_PART_SUFFIXES = [
        'co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'ltd.uk', 'plc.uk', 'me.uk', 'net.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
        'co.nz', 'org.nz', 'net.nz', 'govt.nz',
        'co.jp', 'ne.jp', 'or.jp', 'ac.jp', 'go.jp',
        'co.kr', 'or.kr', 'go.kr',
        'co.in', 'net.in', 'org.in', 'gov.in', 'ac.in',
        'co.za', 'org.za', 'gov.za',
        'com.br', 'net.br', 'org.br', 'gov.br',
        'com.mx', 'org.mx', 'gob.mx',
        'com.ar', 'com.co', 'com.pe', 'com.tr', 'com.sg', 'com.hk', 'com.tw', 'com.cn', 'com.my', 'com.ph', 'com.pk', 'com.ng', 'com.eg', 'com.sa',
        'co.id', 'co.il', 'co.th', 'co.ke',
        'com.de', 'co.at', 'or.at', 'com.es', 'com.pl', 'com.ua', 'com.vn', 'com.gr', 'co.ve', 'com.uy', 'com.ec', 'co.cr',
        // Hosting platforms where each subdomain is a separate site ("acme.github.io").
        'github.io', 'gitlab.io', 'blogspot.com', 'netlify.app', 'vercel.app', 'pages.dev', 'workers.dev', 'herokuapp.com',
        'wordpress.com', 'substack.com', 'web.app', 'firebaseapp.com', 'azurewebsites.net', 'myshopify.com', 'wixsite.com',
        'webflow.io', 'framer.website', 'notion.site', 'glitch.me', 'fly.dev', 'onrender.com', 'readthedocs.io', 'tumblr.com',
    ];

    public static function host(string $url): ?string
    {
        $url = trim($url);

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! str_contains($host, '.')) {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower(rtrim($host, '.')));
    }

    /**
     * "blog.shop.acme.co.uk" → "acme.co.uk"
     */
    public static function registrable(string $urlOrHost): ?string
    {
        $host = static::host($urlOrHost);

        if (! $host) {
            return null;
        }

        $parts = explode('.', $host);
        $count = count($parts);

        if ($count <= 2) {
            return $host;
        }

        $lastTwo = $parts[$count - 2] . '.' . $parts[$count - 1];

        return in_array($lastTwo, static::MULTI_PART_SUFFIXES, true)
            ? implode('.', array_slice($parts, -3))
            : $lastTwo;
    }

    /**
     * Whether a URL belongs to one of the domains (including their subdomains).
     *
     * @param  array<string>  $domains
     */
    public static function matches(string $url, array $domains): bool
    {
        $host = static::host($url);

        if (! $host) {
            return false;
        }

        foreach ($domains as $domain) {
            $domain = static::host($domain);

            if ($domain && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }

        return false;
    }
}
