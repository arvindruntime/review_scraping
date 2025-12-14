<?php

namespace App\Http\Controllers\Api;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class ReviewController extends Controller
{
    public function __construct()
    {
    }
    public function fetchReviews(Request $request)
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

            if (isset($googleResult['error'])) {
                \Log::warning('Google fetch failed with Apify error: ' . json_encode($googleResult['error']));
                // Continue with Trustpilot or return cached/empty
            }

            $allReviews   = array_merge($allReviews, $googleResult['reviews']);
            if ($googleResult['summary']) {
                $ratings['google'] = $googleResult['summary'];
            }
            $totalReviews += count($googleResult['reviews']);
        }

        if (in_array('trustpilot', $sources)) {
            $trustResult  = $this->fetchFromTrustpilot($domain, $limit);

            if (isset($trustResult['error'])) {
                \Log::warning('Trustpilot fetch failed with Apify error: ' . json_encode($trustResult['error']));
            }

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

        $searchString = $domain;

        $payload = [
            'searchStringsArray' => [$searchString],
            'language'           => 'en',
            'maxReviews'         => $limit * 2, // Request more to ensure we get $limit after filtering
            'reviewsSort'        => 'newest',
            'includeReviews'     => true,
        ];

        $run = Http::post("https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}", $payload);

        // LOG RAW RESPONSE - CRITICAL for debugging
        \Log::info('Apify Raw Response', [
            'domain' => $domain,
            'status' => $run->status(),
            'body_preview' => substr($run->body(), 0, 2000),
            'full_headers' => $run->headers()
        ]);

        // HANDLE APIFY JSON ERROR (even if HTTP 200/403/500)
        $body = $run->body();
        $bodyJson = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($bodyJson['error'])) {
            \Log::error('Apify API Error Detected', [
                'domain' => $domain,
                'error_type' => $bodyJson['error']['type'] ?? 'unknown',
                'error_message' => $bodyJson['error']['message'] ?? 'unknown'
            ]);
            
            // Return ADMIN-FRIENDLY error to frontend
            return [
                'reviews' => [],
                'summary' => null,
                'error' => [
                    'type' => $bodyJson['error']['type'] ?? 'apify_error',
                    'message' => $bodyJson['error']['message'] ?? 'Apify API error',
                    'admin_note' => 'Check Laravel logs for full details'
                ]
            ];
        }


        // THEN check HTTP status
        if (!$run->successful()) {
            \Log::error('Apify HTTP Error', ['status' => $run->status(), 'body' => $body]);
            return ['reviews' => [], 'summary' => null];
        }

        if (!$run->successful()) {
            return ['reviews' => [], 'summary' => null];
        }

        $runData = $run->json('data');
        if (!$runData || !isset($runData['id'])) {
            return ['reviews' => [], 'summary' => null];
        }

        $runId     = $runData['id'];
        $status    = $runData['status'] ?? 'READY';
        $datasetId = $runData['defaultDatasetId'] ?? null;

        // Extended polling for longer scrapes
        $attempt = 0;
        while ($attempt < 60 && $status !== 'SUCCEEDED' && $status !== 'FAILED') { // Increased from 15 to 60
            sleep(2); // Increased from 1 to 2 seconds
            $statusRes = Http::get("https://api.apify.com/v2/acts/{$actorId}/runs/{$runId}?token={$token}");
            if (!$statusRes->successful()) {
                break;
            }
            $data      = $statusRes->json('data');
            $status    = $data['status'] ?? $status;
            $datasetId = $data['defaultDatasetId'] ?? $datasetId;
            $attempt++;
        }

        if ($status !== 'SUCCEEDED' || !$datasetId) {
            return ['reviews' => [], 'summary' => null];
        }

        // Fetch ALL available reviews (remove limit slicing)
        $itemsRes = Http::get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
            'token'   => $token,
            'format'  => 'json',
            'clean'   => 'true',
            'desc'    => 'true', // Newest first
            // Removed 'limit' to get all items
        ]);


        // SAME error handling
        $itemsBody = $itemsRes->body();
        $itemsJson = json_decode($itemsBody, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($itemsJson['error'])) {
            \Log::error('Apify Dataset Error', [
                'domain' => $domain,
                'datasetId' => $datasetId,
                'error' => $itemsJson['error']
            ]);
            return [
                'reviews' => [],
                'summary' => null,
                'error' => [
                    'type' => $itemsJson['error']['type'],
                    'message' => $itemsJson['error']['message'],
                    'admin_note' => 'Monthly plan limit reached - upgrade required'
                ]
            ];
        }

        if (!$itemsRes->successful()) {
            return ['reviews' => [], 'summary' => null];
        }

        $items = $itemsRes->json();
        $reviews = [];
        $summaryRatingSum = 0;
        $summaryRatingCnt = 0;

        foreach ($items as $place) {
            if (isset($place['rating']) && isset($place['userRatingsTotal'])) {
                $summaryRatingSum += $place['rating'] * $place['userRatingsTotal'];
                $summaryRatingCnt += $place['userRatingsTotal'];
            }

            if (!isset($place['reviews']) || !is_array($place['reviews'])) {
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

        // REMOVED: usort and array_slice - keep all reviews
        // Sort by date descending (newest first)
        usort($reviews, fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));

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
    $token = config('apify.token');
    $actorId = config('apify.trustpilot_actor');
    
    $payload = [
        'startUrls' => [['url' => "https://www.trustpilot.com/review/{$domain}"]],
        'maxReviews' => $limit * 2,
    ];
    
    $run = Http::post("https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}", $payload);

    // LOG RAW RESPONSE - CRITICAL for debugging
        \Log::info('Apify Raw Response', [
            'domain' => $domain,
            'status' => $run->status(),
            'body_preview' => substr($run->body(), 0, 2000),
            'full_headers' => $run->headers()
        ]);

        // HANDLE APIFY JSON ERROR (even if HTTP 200/403/500)
        $body = $run->body();
        $bodyJson = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($bodyJson['error'])) {
            \Log::error('Apify API Error Detected', [
                'domain' => $domain,
                'error_type' => $bodyJson['error']['type'] ?? 'unknown',
                'error_message' => $bodyJson['error']['message'] ?? 'unknown'
            ]);
            
            // Return ADMIN-FRIENDLY error to frontend
            return [
                'reviews' => [],
                'summary' => null,
                'error' => [
                    'type' => $bodyJson['error']['type'] ?? 'apify_error',
                    'message' => $bodyJson['error']['message'] ?? 'Apify API error',
                    'admin_note' => 'Check Laravel logs for full details'
                ]
            ];
        }

        // THEN check HTTP status
        if (!$run->successful()) {
            \Log::error('Apify HTTP Error', ['status' => $run->status(), 'body' => $body]);
            return ['reviews' => [], 'summary' => null];
        }
    
    // SAME EXACT polling + dataset logic as fetchFromGoogle()
    if (!$run->successful()) return ['reviews' => [], 'summary' => null];
    
    $runData = $run->json('data');
    $runId = $runData['id'] ?? null;
    
    // Poll loop (copy from Google method exactly)
    $attempt = 0;
    $status = 'READY';
    $datasetId = null;
    while ($attempt < 60 && $status !== 'SUCCEEDED' && $status !== 'FAILED') {
        sleep(2);
        $statusRes = Http::get("https://api.apify.com/v2/acts/{$actorId}/runs/{$runId}?token={$token}");
        $data = $statusRes->json('data');
        $status = $data['status'] ?? $status;
        $datasetId = $data['defaultDatasetId'] ?? $datasetId;
        $attempt++;
    }
    
    if ($status !== 'SUCCEEDED' || !$datasetId) {
        return ['reviews' => [], 'summary' => null];
    }
    
    // Fetch items (same as Google)
    $itemsRes = Http::get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
        'token' => $token, 'format' => 'json', 'clean' => 'true', 'desc' => 'true'
    ]);

    // SAME error handling
    $itemsBody = $itemsRes->body();
    $itemsJson = json_decode($itemsBody, true);

    if (json_last_error() === JSON_ERROR_NONE && isset($itemsJson['error'])) {
        \Log::error('Apify Dataset Error', [
            'domain' => $domain,
            'datasetId' => $datasetId,
            'error' => $itemsJson['error']
        ]);
        return [
            'reviews' => [],
            'summary' => null,
            'error' => [
                'type' => $itemsJson['error']['type'],
                'message' => $itemsJson['error']['message'],
                'admin_note' => 'Monthly plan limit reached - upgrade required'
            ]
        ];
    }
    
    if (!$itemsRes->successful()) return ['reviews' => [], 'summary' => null];
    
    $items = $itemsRes->json();
    $reviews = [];
    
    foreach ($items as $item) {
        $reviews[] = [
            'source' => 'trustpilot',
            'rating' => $item['stars'] ?? $item['rating'] ?? 0,
            'text' => $item['text'] ?? '',
            'date' => $item['datePublished'] ?? null,
            'author' => $item['authorName'] ?? null,
        ];
    }
    
    usort($reviews, fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
    
    return ['reviews' => $reviews, 'summary' => null]; // Trustpilot summary logic if needed
}


}

