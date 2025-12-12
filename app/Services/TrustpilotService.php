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
            
            if (!$response->successful()) {
                return false;
            }
            
            // Check if response is JSON
            $contentType = $response->header('Content-Type', '');
            $body = $response->body();
            
            if (stripos($contentType, 'text/html') !== false || 
                (substr(trim($body), 0, 1) === '<' && stripos($body, '<html') !== false)) {
                Log::debug('Trustpilot scraper health check returned HTML instead of JSON');
                return false;
            }
            
            try {
                $data = $response->json();
                return isset($data['status']);
            } catch (\Exception $e) {
                Log::debug('Trustpilot scraper health check JSON parse failed: ' . $e->getMessage());
                return false;
            }
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
            
            // Reduced timeout to 60 seconds to avoid Nginx/PHP-FPM gateway timeouts
            // If scraping takes longer, consider optimizing the scraper or using async processing
            $response = Http::timeout(60)->post($this->scraperUrl . '/scrape-trustpilot', [
                'domain' => $domain,
                'limit' => $limit,
            ]);

            if ($response->successful()) {
                // Check if response is JSON before parsing
                $contentType = $response->header('Content-Type', '');
                $body = $response->body();
                
                // Check if response is HTML (error page) instead of JSON
                if (stripos($contentType, 'text/html') !== false || 
                    (substr(trim($body), 0, 1) === '<' && stripos($body, '<html') !== false)) {
                    Log::error('Trustpilot scraper returned HTML instead of JSON', [
                        'url' => $this->scraperUrl,
                        'content_type' => $contentType,
                        'body_preview' => substr($body, 0, 500),
                    ]);
                    
                    return [
                        'rating' => null,
                        'total_reviews' => 0,
                        'reviews' => [],
                        'error' => 'Trustpilot scraper service returned an HTML error page. Please ensure the scraper service is running on ' . $this->scraperUrl,
                    ];
                }
                
                // Try to parse JSON, handle errors gracefully
                try {
                    $data = $response->json();
                } catch (\Exception $jsonException) {
                    Log::error('Failed to parse Trustpilot scraper JSON response', [
                        'url' => $this->scraperUrl,
                        'content_type' => $contentType,
                        'body_preview' => substr($body, 0, 500),
                        'error' => $jsonException->getMessage(),
                    ]);
                    
                    return [
                        'rating' => null,
                        'total_reviews' => 0,
                        'reviews' => [],
                        'error' => 'Trustpilot scraper service returned invalid JSON. Response may be an error page. Please check if the scraper service is running correctly.',
                    ];
                }
                
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
                $errorBody = $response->body();
                $statusCode = $response->status();
                
                Log::error('Trustpilot scraper returned non-success status', [
                    'status' => $statusCode,
                    'url' => $this->scraperUrl,
                    'content_type' => $response->header('Content-Type', ''),
                    'body_preview' => substr($errorBody, 0, 500),
                ]);
                
                return [
                    'rating' => null,
                    'total_reviews' => 0,
                    'reviews' => [],
                    'error' => 'Failed to connect to Trustpilot scraper. HTTP Status: ' . $statusCode . '. Please ensure the scraper service is running on ' . $this->scraperUrl,
                ];
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Trustpilot scraper connection exception for domain ' . $domain . ': ' . $e->getMessage());
            return [
                'rating' => null,
                'total_reviews' => 0,
                'reviews' => [],
                'error' => 'Cannot connect to Trustpilot scraper. Make sure it is running on ' . $this->scraperUrl,
            ];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Handle timeout exceptions specifically
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'timeout') !== false || strpos($errorMessage, 'timed out') !== false) {
                Log::error('Trustpilot scraper timeout for domain ' . $domain . ': ' . $errorMessage);
                return [
                    'rating' => null,
                    'total_reviews' => 0,
                    'reviews' => [],
                    'error' => 'Trustpilot scraper request timed out. The scraping process is taking longer than expected. Please try again or contact support if the issue persists.',
                ];
            }
            Log::error('Trustpilot scraper request exception for domain ' . $domain . ': ' . $errorMessage);
            return [
                'rating' => null,
                'total_reviews' => 0,
                'reviews' => [],
                'error' => 'Trustpilot scraper request failed: ' . $errorMessage,
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

