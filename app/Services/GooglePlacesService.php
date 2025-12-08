<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GooglePlacesService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = env('GOOGLE_PLACES_API_KEY');
    }

    /**
     * Find business by domain using TextSearch
     */
    public function findBusinessByDomain(string $domain): ?array
    {
        try {
            // Remove protocol and www
            $cleanDomain = preg_replace('/^https?:\/\/(www\.)?/', '', $domain);
            $cleanDomain = rtrim($cleanDomain, '/');

            // Try searching with the domain
            $response = Http::get('https://maps.googleapis.com/maps/api/place/textsearch/json', [
                'query' => $cleanDomain,
                'key' => $this->apiKey,
            ]);

            $data = $response->json();

            if ($data['status'] === 'OK' && !empty($data['results'])) {
                // Return the first result
                return $data['results'][0];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Google Places TextSearch error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get place details including reviews
     */
    public function getPlaceDetails(string $placeId, int $limit = 20): array
    {
        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/place/details/json', [
                'place_id' => $placeId,
                'fields' => 'rating,user_ratings_total,reviews',
                'key' => $this->apiKey,
            ]);

            $data = $response->json();

            if ($data['status'] !== 'OK' || !isset($data['result'])) {
                return [
                    'rating' => null,
                    'total_reviews' => 0,
                    'reviews' => [],
                ];
            }

            $result = $data['result'];
            $reviews = $result['reviews'] ?? [];
            
            // Limit reviews
            $reviews = array_slice($reviews, 0, $limit);

            // Map reviews to standard format
            $mappedReviews = array_map(function ($review) {
                return [
                    'source' => 'google',
                    'rating' => (int) $review['rating'],
                    'text' => $review['text'] ?? '',
                    'date' => isset($review['time']) ? date('Y-m-d', $review['time']) : null,
                    'author' => $review['author_name'] ?? null,
                ];
            }, $reviews);

            return [
                'rating' => $result['rating'] ?? null,
                'total_reviews' => $result['user_ratings_total'] ?? 0,
                'reviews' => $mappedReviews,
            ];
        } catch (\Exception $e) {
            Log::error('Google Places Details error: ' . $e->getMessage());
            return [
                'rating' => null,
                'total_reviews' => 0,
                'reviews' => [],
            ];
        }
    }

    /**
     * Get reviews for a domain
     */
    public function getReviewsForDomain(string $domain, int $limit = 20): array
    {
        $business = $this->findBusinessByDomain($domain);
        
        if (!$business) {
            return [
                'rating' => null,
                'total_reviews' => 0,
                'reviews' => [],
            ];
        }

        return $this->getPlaceDetails($business['place_id'], $limit);
    }
}

