<?php

namespace App\Helpers;

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
}
