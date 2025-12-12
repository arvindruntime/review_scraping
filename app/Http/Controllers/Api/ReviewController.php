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
public function fetch(Request $request)
    {
        $request->validate([
            'domain'  => 'required|string',
            'sources' => 'required|array|min:1',
            'limit'   => 'nullable|integer|min:1|max:100',
        ]);

        $domain  = strtolower(trim($request->input('domain')));
        $sources = $request->input('sources');
        $limit   = (int) ($request->input('limit') ?? config('apify.max_reviews', 20));

        // 1) Try from DB cache
        $search = Search::where('domain', $domain)->first();

        if ($search) {
            $reviewsQuery = $search->reviews()->whereIn('source', $sources);
            $reviews = $reviewsQuery->orderByDesc('date')->limit($limit * count($sources))->get();

            if ($reviews->isNotEmpty()) {
                return response()->json([
                    'domain'        => $domain,
                    'sources'       => $sources,
                    'ratings'       => $search->ratings ?? [],
                    'total_reviews' => $search->total_reviews ?? 0,
                    'limit'         => $limit,
                    'reviews'       => $reviews->map(function (Review $r) {
                        return [
                            'source' => $r->source,
                            'rating' => $r->rating,
                            'text'   => $r->text,
                            'date'   => optional($r->date)->format('Y-m-d'),
                            'author' => $r->author,
                        ];
                    })->values(),
                ]);
            }
        }

        // 2) Not cached or empty → fetch from Apify
        $allReviews   = [];
        $ratings      = [];
        $totalReviews = 0;

        if (in_array('google', $sources)) {
            $googleResult = $this->fetchFromGoogle($domain, $limit);
            $allReviews   = array_merge($allReviews, $googleResult['reviews']);
            if ($googleResult['summary']) {
                $ratings['google'] = $googleResult['summary'];
            }
            $totalReviews += count($googleResult['reviews']);
        }

        if (in_array('trustpilot', $sources)) {
            $trustResult  = $this->fetchFromTrustpilot($domain, $limit);
            $allReviews   = array_merge($allReviews, $trustResult['reviews']);
            if ($trustResult['summary']) {
                $ratings['trustpilot'] = $trustResult['summary'];
            }
            $totalReviews += count($trustResult['reviews']);
        }

        // 3) Store in DB for future use
        DB::beginTransaction();

        try {
            $search = Search::updateOrCreate(
                ['domain' => $domain],
                [
                    'sources'       => $sources,
                    'ratings'       => $ratings,
                    'total_reviews' => $totalReviews,
                ]
            );

            $search->reviews()->delete();

            foreach ($allReviews as $r) {
                Review::create([
                    'search_id' => $search->id,
                    'source'    => $r['source'],
                    'rating'    => $r['rating'],
                    'text'      => $r['text'],
                    'date'      => $r['date'] ?? null,
                    'author'    => $r['author'] ?? null,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Error saving reviews.',
            ], 500);
        }

        // 4) Response for front-end JS (matches your HTML logic)
        return response()->json([
            'domain'        => $domain,
            'sources'       => $sources,
            'ratings'       => $ratings,
            'total_reviews' => $totalReviews,
            'limit'         => $limit,
            'reviews'       => collect($allReviews)->map(function ($r) {
                return [
                    'source' => $r['source'],
                    'rating' => $r['rating'],
                    'text'   => $r['text'],
                    'date'   => $r['date'] ?? null,
                    'author' => $r['author'] ?? null,
                ];
            })->values(),
        ]);
    }

    protected function fetchFromGoogle(string $domain, int $limit): array
    {
        $token   = config('apify.token');
        $actorId = config('apify.google_actor');

        // TODO: improve mapping domain → business + location
        $searchString = $domain;

        $payload = [
            'searchStringsArray' => [$searchString],
            'language'           => 'en',
            'maxReviews'         => $limit,
            'reviewsSort'        => 'newest',
            'includeReviews'     => true,
        ];

        $run = Http::post(
            "https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}",
            $payload
        );

        if (! $run->successful()) {
            return ['reviews' => [], 'summary' => null];
        }

        $runData = $run->json('data');
        if (! $runData || ! isset($runData['id'])) {
            return ['reviews' => [], 'summary' => null];
        }

        $runId     = $runData['id'];
        $status    = $runData['status'] ?? 'READY';
        $datasetId = $runData['defaultDatasetId'] ?? null;

        $attempt = 0;
        while ($attempt < 15 && $status !== 'SUCCEEDED' && $status !== 'FAILED') {
            sleep(1);
            $statusRes = Http::get(
                "https://api.apify.com/v2/acts/{$actorId}/runs/{$runId}?token={$token}"
            );
            if (! $statusRes->successful()) {
                break;
            }
            $data      = $statusRes->json('data');
            $status    = $data['status'] ?? $status;
            $datasetId = $data['defaultDatasetId'] ?? $datasetId;
            $attempt++;
        }

        if ($status !== 'SUCCEEDED' || ! $datasetId) {
            return ['reviews' => [], 'summary' => null];
        }

        $itemsRes = Http::get(
            "https://api.apify.com/v2/datasets/{$datasetId}/items",
            [
                'token'  => $token,
                'format' => 'json',
                'clean'  => 'true',
                'limit'  => $limit,
                'desc'   => 'true',
            ]
        );
        // dd($itemsRes);

        if (! $itemsRes->successful()) {
            return ['reviews' => [], 'summary' => null];
        }

        $items   = $itemsRes->json();
        $reviews = [];
        $summaryRatingSum = 0;
        $summaryRatingCnt = 0;
        

        foreach ($items as $place) {
            if (isset($place['rating']) && isset($place['userRatingsTotal'])) {
                $summaryRatingSum += $place['rating'] * $place['userRatingsTotal'];
                $summaryRatingCnt += $place['userRatingsTotal'];
            }

            if (! isset($place['reviews']) || ! is_array($place['reviews'])) {
                continue;
            }

            foreach ($place['reviews'] as $r) {
                $reviews[] = [
                    'source' => 'google',
                    'rating' => (int) ($r['rating'] ?? 0),
                    'text'   => $r['text'] ?? $r['reviewText'] ?? '',
                    'date'   => $r['publishedAtDate'] ?? $r['reviewDate'] ?? null,
                    'author' => $r['reviewerName'] ?? $r['authorName'] ?? null,
                ];
            }
        }

        usort($reviews, fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
        $reviews = array_slice($reviews, 0, $limit);

        $summary = null;
        if ($summaryRatingCnt > 0) {
            $summary = [
                'rating' => round($summaryRatingSum / $summaryRatingCnt, 2),
                'total'  => $summaryRatingCnt,
            ];
        }

        return ['reviews' => $reviews, 'summary' => $summary];
    }

    protected function fetchFromTrustpilot(string $domain, int $limit): array
    {
        $token   = config('apify.token');
        $actorId = config('apify.trustpilot_actor');

        $payload = [
            'startUrls' => [
                ['url' => "https://www.trustpilot.com/review/{$domain}"],
            ],
            'maxReviews' => $limit,
        ];

        $run = Http::post(
            "https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}",
            $payload
        );

        if (! $run->successful()) {
            return ['reviews' => [], 'summary' => null];
        }

        $runData = $run->json('data');
        if (! $runData || ! isset($runData['id'])) {
            return ['reviews' => [], 'summary' => null];
        }

        $runId     = $runData['id'];
        $status    = $runData['status'] ?? 'READY';
        $datasetId = $runData['defaultDatasetId'] ?? null;

        $attempt = 0;
        while ($attempt < 15 && $status !== 'SUCCEEDED' && $status !== 'FAILED') {
            sleep(1);
            $statusRes = Http::get(
                "https://api.apify.com/v2/acts/{$actorId}/runs/{$runId}?token={$token}"
            );
            if (! $statusRes->successful()) {
                break;
            }
            $data      = $statusRes->json('data');
            $status    = $data['status'] ?? $status;
            $datasetId = $data['defaultDatasetId'] ?? $datasetId;
            $attempt++;
        }

        if ($status !== 'SUCCEEDED' || ! $datasetId) {
            return ['reviews' => [], 'summary' => null];
        }

        $itemsRes = Http::get(
            "https://api.apify.com/v2/datasets/{$datasetId}/items",
            [
                'token'  => $token,
                'format' => 'json',
                'clean'  => 'true',
                'limit'  => $limit,
                'desc'   => 'true',
            ]
        );

        if (! $itemsRes->successful()) {
            return ['reviews' => [], 'summary' => null];
        }

        $items   = $itemsRes->json();
        $reviews = [];
        $summaryRatingSum = 0;
        $summaryRatingCnt = 0;

        foreach ($items as $item) {
            if (isset($item['rating']) && isset($item['totalReviews'])) {
                $summaryRatingSum += $item['rating'] * $item['totalReviews'];
                $summaryRatingCnt += $item['totalReviews'];
            }

            if (! isset($item['reviews']) || ! is_array($item['reviews'])) {
                continue;
            }

            foreach ($item['reviews'] as $r) {
                $reviews[] = [
                    'source' => 'trustpilot',
                    'rating' => (int) ($r['rating'] ?? 0),
                    'text'   => $r['text'] ?? $r['reviewText'] ?? '',
                    'date'   => $r['date'] ?? null,
                    'author' => $r['author'] ?? null,
                ];
            }
        }

        usort($reviews, fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
        $reviews = array_slice($reviews, 0, $limit);

        $summary = null;
        if ($summaryRatingCnt > 0) {
            $summary = [
                'rating' => round($summaryRatingSum / $summaryRatingCnt, 2),
                'total'  => $summaryRatingCnt,
            ];
        }

        return ['reviews' => $reviews, 'summary' => $summary];
    }

    /**
     * Fetch reviews for a domain
     */
    public function fetchReviews(Request $request)
    {
        try {
            // Increase execution time for Trustpilot scraping
            // Reduced to 90 seconds to avoid Nginx/PHP-FPM gateway timeouts
            // Note: Server configuration (Nginx proxy_read_timeout, PHP-FPM request_terminate_timeout)
            // should also be increased if scraping consistently takes longer
            set_time_limit(90);
            
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

