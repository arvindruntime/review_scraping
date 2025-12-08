<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Search;
use App\Services\GooglePlacesService;
use App\Services\TrustpilotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    private GooglePlacesService $googlePlacesService;
    private TrustpilotService $trustpilotService;

    public function __construct(GooglePlacesService $googlePlacesService, TrustpilotService $trustpilotService)
    {
        $this->googlePlacesService = $googlePlacesService;
        $this->trustpilotService = $trustpilotService;
    }

    /**
     * Fetch reviews for a domain
     */
    public function fetchReviews(Request $request)
    {
        try {
            // Increase execution time for Trustpilot scraping
            set_time_limit(180);
            
            $validator = Validator::make($request->all(), [
            'domain' => 'required|string',
            'sources' => 'array',
            'sources.*' => 'in:google,trustpilot',
            'limit' => 'integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $domain = $request->input('domain');
        $sources = $request->input('sources', ['google', 'trustpilot']); // Default both selected
        $limit = $request->input('limit', 20);

        // Clean domain (remove protocol, www, trailing slash)
        $domain = preg_replace('/^https?:\/\/(www\.)?/', '', $domain);
        $domain = rtrim($domain, '/');

        $allReviews = [];
        $ratings = [];

        // Fetch Google reviews
        if (in_array('google', $sources)) {
            $googleData = $this->googlePlacesService->getReviewsForDomain($domain, $limit);
            $allReviews = array_merge($allReviews, $googleData['reviews']);
            
            if ($googleData['rating'] !== null) {
                $ratings['google'] = [
                    'rating' => $googleData['rating'],
                    'total' => $googleData['total_reviews'],
                ];
            }
        }

        // Fetch Trustpilot reviews
        if (in_array('trustpilot', $sources)) {
            $trustpilotData = $this->trustpilotService->getReviewsForDomain($domain, $limit);
            
            // Log Trustpilot response for debugging
            \Log::info('Trustpilot response for ' . $domain, [
                'rating' => $trustpilotData['rating'] ?? null,
                'total_reviews' => $trustpilotData['total_reviews'] ?? 0,
                'reviews_count' => count($trustpilotData['reviews'] ?? []),
            ]);
            
            if (!empty($trustpilotData['reviews'])) {
                $allReviews = array_merge($allReviews, $trustpilotData['reviews']);
            }
            
            if ($trustpilotData['rating'] !== null) {
                $ratings['trustpilot'] = [
                    'rating' => $trustpilotData['rating'],
                    'total' => $trustpilotData['total_reviews'],
                ];
            }
        }

        // Check if search already exists for this domain (within last 24 hours)
        // If exists, update it; otherwise create new
        $search = Search::where('domain', $domain)
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('created_at', 'desc')
            ->first();

        if ($search) {
            // Update existing search
            $search->update([
                'sources' => $sources,
                'ratings' => $ratings,
                'total_reviews' => count($allReviews),
            ]);
        } else {
            // Create new search
            $search = Search::create([
                'domain' => $domain,
                'sources' => $sources,
                'ratings' => $ratings,
                'total_reviews' => count($allReviews),
            ]);
        }

        // Store reviews (avoid duplicates by checking text + source + domain)
        $newReviewsCount = 0;
        $duplicateCount = 0;
        
        // Get all existing reviews for this domain to check against
        $existingReviews = Review::whereHas('search', function($query) use ($domain) {
            $query->where('domain', $domain);
        })->get()->keyBy(function($review) {
            // Create a unique key: source + first 100 chars of normalized text
            $normalized = trim(preg_replace('/\s+/', ' ', $review->text));
            return $review->source . '|' . substr($normalized, 0, 100);
        });
        
        foreach ($allReviews as $reviewData) {
            // Normalize text for comparison (trim and remove extra whitespace)
            $normalizedText = trim(preg_replace('/\s+/', ' ', $reviewData['text']));
            $checkKey = $reviewData['source'] . '|' . substr($normalizedText, 0, 100);
            
            // Check if review already exists
            if ($existingReviews->has($checkKey)) {
                $duplicateCount++;
                continue;
            }
            
            // Also do exact text match for same source
            $exactMatch = Review::where('source', $reviewData['source'])
                ->where('text', $reviewData['text'])
                ->whereHas('search', function($query) use ($domain) {
                    $query->where('domain', $domain);
                })
                ->exists();

            // Only create if it doesn't exist
            if (!$exactMatch) {
                Review::create([
                    'search_id' => $search->id,
                    'source' => $reviewData['source'],
                    'rating' => $reviewData['rating'],
                    'text' => $reviewData['text'],
                    'date' => $reviewData['date'] ?? null,
                    'author' => $reviewData['author'] ?? null,
                ]);
                $newReviewsCount++;
                
                // Add to existing reviews cache to prevent duplicates in same batch
                $existingReviews->put($checkKey, true);
            } else {
                $duplicateCount++;
            }
        }

        \Log::info('Stored reviews for ' . $domain, [
            'total_fetched' => count($allReviews),
            'new_reviews' => $newReviewsCount,
            'duplicates_skipped' => $duplicateCount,
            'search_id' => $search->id,
        ]);

        // Return unified response
        return response()->json([
            'domain' => $domain,
            'ratings' => $ratings,
            'total_reviews' => count($allReviews),
            'reviews' => $allReviews,
            'limit' => $limit,
        ]);
        
        } catch (\Exception $e) {
            \Log::error('Error in fetchReviews: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'error' => 'An error occurred while processing your request: ' . $e->getMessage(),
                'domain' => $request->input('domain', 'unknown'),
                'ratings' => [],
                'total_reviews' => 0,
                'reviews' => [],
            ], 500);
        }
    }

    /**
     * Get all reviews (for admin or frontend)
     */
    public function getReviews(Request $request)
    {
        $query = Review::with('search');

        // Filter by source if provided
        if ($request->has('source')) {
            $query->where('source', $request->input('source'));
        }

        // Filter by search_id if provided
        if ($request->has('search_id')) {
            $query->where('search_id', $request->input('search_id'));
        }

        $reviews = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'reviews' => $reviews->map(function ($review) {
                return [
                    'source' => $review->source,
                    'rating' => $review->rating,
                    'text' => $review->text,
                    'date' => $review->date ? $review->date->format('Y-m-d') : null,
                    'author' => $review->author,
                ];
            }),
        ]);
    }
}

