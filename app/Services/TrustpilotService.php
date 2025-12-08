<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrustpilotService
{
    private string $scraperUrl;

    public function __construct()
    {
        $this->scraperUrl = env('TRUSTPILOT_SCRAPER_URL', 'http://localhost:4000');
    }

    /**
     * Check if Trustpilot scraper is running
     */
    public function isScraperRunning(): bool
    {
        try {
            $response = Http::timeout(5)->get($this->scraperUrl . '/health');
            return $response->successful() && isset($response->json()['status']);
        } catch (\Exception $e) {
            Log::debug('Trustpilot scraper health check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get reviews from Trustpilot
     */
    public function getReviewsForDomain(string $domain, int $limit = 20): array
    {
        try {
            Log::info('Fetching Trustpilot reviews for domain: ' . $domain);
            
            $response = Http::timeout(180)->post($this->scraperUrl . '/scrape-trustpilot', [
                'domain' => $domain,
                'limit' => $limit,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Log full response for debugging
                Log::info('Trustpilot response for ' . $domain, [
                    'has_rating' => isset($data['rating']),
                    'rating' => $data['rating'] ?? null,
                    'total_reviews' => $data['total_reviews'] ?? 0,
                    'reviews_count' => count($data['reviews'] ?? []),
                    'has_error' => isset($data['error']),
                    'error_message' => $data['error'] ?? null,
                ]);
                
                // Check if there's an error in the response
                if (isset($data['error'])) {
                    Log::warning('Trustpilot scraper returned error for domain ' . $domain . ': ' . $data['error']);
                }
                
                $reviews = $data['reviews'] ?? [];
                
                if (count($reviews) > 0) {
                    Log::info('Trustpilot: Successfully found ' . count($reviews) . ' reviews for domain ' . $domain);
                    // Log first review as sample
                    if (!empty($reviews[0])) {
                        Log::info('Sample Trustpilot review: ' . json_encode($reviews[0]));
                    }
                } else {
                    Log::warning('Trustpilot: No reviews found for domain ' . $domain . '. Error: ' . ($data['error'] ?? 'No error message'));
                }
                
                return [
                    'rating' => $data['rating'] ?? null,
                    'total_reviews' => $data['total_reviews'] ?? 0,
                    'reviews' => $reviews,
                    'error' => $data['error'] ?? null,
                ];
            } else {
                // Log the failed response
                Log::error('Trustpilot scraper returned non-success status', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            $errorBody = $response->body();
            $statusCode = $response->status();
            Log::error('Trustpilot scraper HTTP error for domain ' . $domain, [
                'status' => $statusCode,
                'body' => $errorBody,
            ]);
            
            return [
                'rating' => null,
                'total_reviews' => 0,
                'reviews' => [],
                'error' => 'Failed to connect to Trustpilot scraper. HTTP Status: ' . $statusCode,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Trustpilot scraper connection exception for domain ' . $domain . ': ' . $e->getMessage());
            return [
                'rating' => null,
                'total_reviews' => 0,
                'reviews' => [],
                'error' => 'Cannot connect to Trustpilot scraper. Make sure it is running on ' . $this->scraperUrl,
            ];
        } catch (\Exception $e) {
            Log::error('Trustpilot service exception for domain ' . $domain . ': ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'rating' => null,
                'total_reviews' => 0,
                'reviews' => [],
                'error' => 'Trustpilot service error: ' . $e->getMessage(),
            ];
        }
    }
}

