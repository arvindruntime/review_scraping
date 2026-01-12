<?php

namespace App\Helpers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DomainHelper
{
    public static function normalizeForTrustpilot(string $domain): string
    {
        // Remove protocol
        $domain = preg_replace('#^https?://#', '', trim($domain));

        // Remove leading dots
        $domain = ltrim($domain, '.');

        // Remove trailing slash
        $domain = rtrim($domain, '/');

        // Validate domain
        if (!filter_var('http://' . $domain, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid domain name');
        }

        // Ensure www.
        if (!str_starts_with($domain, 'www.')) {
            $domain = 'www.' . $domain;
        }

        return $domain;
    }

    public static function looksLikeDomain(string $value): bool
    {
        $value = trim($value);

        // must contain at least one dot
        if (!str_contains($value, '.')) {
            return false;
        }

        // remove protocol for validation
        $value = preg_replace('#^https?://#', '', $value);

        return filter_var('http://' . $value, FILTER_VALIDATE_URL) !== false;
    }


    public static function fetchTrustpilotMeta(string $domain): array
    {
        $url = "https://www.trustpilot.com/review/" . $domain;

        $html = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0',
        ])->get($url)->body();

        if (!preg_match('/<script id="__NEXT_DATA__".*?>(.*?)<\/script>/s', $html, $m)) {
            return [0, 0];
        }

        $json = json_decode($m[1], true);

        $business = $json['props']['pageProps']['businessUnit'] ?? [];

        return [
            (int) ($business['numberOfReviews'] ?? 0),
            round((float) ($business['trustScore'] ?? 0), 1),
        ];
    }

    public static function fetchMeta(string $domain): array
    {
        return Cache::remember(
            'tp_meta_' . md5($domain),
            now()->addHours(12),
            function () use ($domain) {

                try {
                    $url = "https://www.trustpilot.com/review/" . $domain;

                    $response = Http::timeout(15)       // ⏱ reduce wait
                        ->retry(2, 1000)                // 🔁 retry twice
                        ->withHeaders([
                            'User-Agent' =>
                                'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                            'Accept-Language' => 'en-US,en;q=0.9',
                        ])
                        ->get($url);

                    if (!$response->successful()) {
                        Log::warning('Trustpilot HTTP failed', [
                            'domain' => $domain,
                            'status' => $response->status(),
                        ]);
                        return [0, 0];
                    }

                    $html = $response->body();

                    if (!preg_match(
                        '/<script id="__NEXT_DATA__".*?>(.*?)<\/script>/s',
                        $html,
                        $m
                    )) {
                        return [0, 0];
                    }

                    $json = json_decode($m[1], true);
                    $business = $json['props']['pageProps']['businessUnit'] ?? [];

                    return [
                        (int) ($business['numberOfReviews'] ?? 0),
                        round((float) ($business['trustScore'] ?? 0), 1),
                    ];

                } catch (\Throwable $e) {

                    Log::error('Trustpilot meta fetch failed', [
                        'domain' => $domain,
                        'error'  => $e->getMessage(),
                    ]);

                    // ⛑ Fail safe (NO 500 error)
                    return [0, 0];
                }
            }
        );
    }

}
