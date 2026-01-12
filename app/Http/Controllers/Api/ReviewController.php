<?php

namespace App\Http\Controllers\Api;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use App\Jobs\ScrapeReviewsJob;
use App\Helpers\DomainHelper;



class ReviewController extends Controller
{
    public function __construct()
    {
    }
    public function scrapeReviews(Request $request)
    {
        \Log::info('ScrapeReviews function called');

        $request->validate([
            'domain'  => 'required|string',
            'sources' => 'required|array|min:1',
            'limit'   => 'nullable|integer|min:1|max:20',

        ]);

        \Log::info('ScrapeReviews validation called');

        $domain = $request->input('domain');
        $sources = $request->input('sources');
        $google_place_id = $request->input('google_place_id') ?? '';
        $business_name = $request->input('business_name') ?? '';
        $limit   = (int) ($request->input('limit') ?? config('apify.max_reviews', 20));  
        
       
        
        $search = Search::where(function ($query) use ($domain, $google_place_id, $sources) {
        if (!empty($domain)) {
             
            $query->where('domain', $domain);
        }

        if (!empty($sources)) {
            $query->where(function ($q) use ($sources) {
                foreach ($sources as $source) {
                    $q->orWhereJsonContains('sources', $source);
                }
            });
        }

        if (!empty($google_place_id)) {
            $query->orWhere('google_place_id', $google_place_id);
        }
        })->first();
                
        if ($search && in_array($search->status, ['completed', 'partial'])) {
                                    
            \Log::info('Found completed search in DB', [
                'domain'  => $domain,
                'sources' => $sources,
                'limit'   => $limit,
            ]);

            $reviews = $search->reviews()
                ->whereIn('source', $sources)
                ->orderByDesc('date')
                ->limit($limit * count($sources))
                ->get();

            if ($reviews->isNotEmpty()) {

                \Log::info('Reviews fetched successfully from db');

                return response()->json([
                    'status'  => 'completed',
                    'domain'        => $domain,
                    'message' => 'Reviews fetched successfully!',
                    'sources'       => $sources,
                    'ratings'       => $search->ratings ?? [],
                    'total_reviews' => $search->total_reviews ?? 0,
                    'limit'         => $limit,
                    'reviews'       => $reviews->map(fn (Review $r) => [
                        'source' => $r->source,
                        'rating' => $r->rating,
                        'text'   => $r->text,
                        'date'   => optional($r->date)->format('d-m-Y'),
                        'author' => $r->author,
                    ])->values(),
                ]);
            }
        }
        
                
        \Log::info('Starting Dispatch ScrapeReviewsJob', [
            'domain'  => $domain,
            'sources' => $sources,
            'limit'   => $limit,
        ]);
        
        // dd($domain, $sources, $limit, $google_place_id, $business_name);
        
        ScrapeReviewsJob::dispatch($domain, $sources, $limit, $google_place_id, $business_name);   
        
        \Log::info('After Start Dispatched exicuted next line');
            
        // show processing response but only include counts/ratings for requested sources
        $allRatings = $search->ratings ?? [];
        $filteredRatings = [];
        foreach ($sources as $s) {
            $filteredRatings[$s] = $allRatings[$s] ?? null;
        }
        $googleCount = in_array('google', $sources) ? ($search->google_reviews ?? 0) : 0;
        $trustCount = in_array('trustpilot', $sources) ? ($search->trustpilot_reviews ?? 0) : 0;

        return response()->json([
            'status'  => 'processing',
            'domain'  => $domain,
            'business_name' => $business_name,
            'message' => 'Reviews are being fetched.',
            'sources' => $sources,
            'ratings' => $filteredRatings,
            'google_reviews' => $googleCount,
            'trustpilot_reviews' => $trustCount,
            'total_reviews' => $googleCount + $trustCount,
            'limit'         => $limit,
            'reviews'       => [],
        ], 202);
    }

    function fetchFromGoogle(string $domain, int $limit, string $google_place_id = null): array
    { 
        // Prefer Google Places API (more authoritative for rating/total) when API key is configured
        // try {
        //     $apiKey = env('GOOGLE_PLACES_API_KEY');
        //     if (!empty($apiKey)) {
        //         $service = app(\App\Services\GooglePlacesService::class);
        //         $res = $service->getReviewsForDomain($domain, $limit);

        //         return [
        //             'reviews' => $res['reviews'] ?? [],
        //             'summary' => [
        //                 'rating' => isset($res['rating']) ? (float) $res['rating'] : null,
        //                 'total'  => isset($res['total_reviews']) ? (int) $res['total_reviews'] : null,
        //             ],
        //         ];
        //     }
        // } catch (\Throwable $e) {
        //     \Log::warning('GooglePlacesService failed, falling back to Apify actor: ' . $e->getMessage());
        // }

        // Fallback: use configured Apify Google actor
        $token   = config('apify.token');
        $actorId = config('apify.google_actor');

        \Log::info('fetchFromGoogle started', [
            'domain' => $domain,
            'limit' => $limit,
            'google_place_id' => $google_place_id
        ]);

        $payload = [
            'maxReviews'     => $limit,
            'reviewsSort'    => 'newest',
            'includeReviews' => true,
        ];

        if (!empty($google_place_id)) {
            $payload['placeIds'] = [$google_place_id];

            \Log::info('Apify Google actor working with placeid: ', [
                    'google_place_id' => $google_place_id
                ]);

        } else {
            $cleanName = preg_replace('#^https?://#', '', $domain);
            $cleanName = preg_replace('#^www\.#', '', $cleanName);
            $cleanName = preg_replace('#\..*$#', '', $cleanName);

            $payload['searchStringsArray'] = [
                "{$cleanName} Australia",
                "{$cleanName} Sydney",
            ];

            \Log::info('Apify Google actor working with domain name: ', [
                    'domain name' => $cleanName
                ]);
        }

        $run = Http::post(
            "https://api.apify.com/v2/acts/{$actorId}/run-sync-get-dataset-items?token={$token}",
            $payload
        );

        if (!$run->successful()) {
            \Log::error('Apify Google actor failed', [
                'status' => $run->status(),
                'body'   => $run->body(),
            ]);
            return ['reviews' => [], 'summary' => null];
        }

        $items = $run->json();
        
        if (empty($items[0])) {
            return ['reviews' => [], 'summary' => null];
        }

        $place = $items[0];

        $reviews = [];
        foreach ($place['reviews'] ?? [] as $r) {
            $reviews[] = [
                'source' => 'google',
                'rating' => (int) ($r['stars'] ?? 0),
                'author' => $r['name'] ?? null,
                'date'   => $r['publishedAtDate'] ?? null,
                'text'   => trim($r['text'] ?? ''),
            ];
        }

            \Log::info('Google Reviews Scraped');

            usort($reviews, fn ($a, $b) =>
                strcmp((string) $b['date'], (string) $a['date'])
            );

            return [
        'reviews' => array_slice($reviews, 0, $limit),
        'summary' => [
            'rating' => isset($place['totalScore']) ? (float) $place['totalScore'] : null,
            'total'  => isset($place['reviewsCount']) ? (int) $place['reviewsCount'] : null,
            ],
        ];       
    }
    
    
    public function startTrustpilotRun(string $domain): array
    {
        $token   = config('apify.token');
        // $actorId = 'nikita-sviridenko~trustpilot-reviews-scraper';
        $actorId = config('apify.trustpilot_actor');

        //$companyDomain = str_replace(['https://', 'http://', 'www.'], '', $domain);
        try {
        $companyDomain = DomainHelper::normalizeForTrustpilot($domain);
        } catch (\Throwable $e) {
            \Log::error('Invalid domain for Trustpilot', [
                'domain' => $domain,
                'error'  => $e->getMessage()
            ]);

            throw new \InvalidArgumentException('Invalid domain');
        }

        $response = Http::post(
            "https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}&memory=4096",
            [
                "companyDomain" => $companyDomain,
                "contentToExtract" => "reviews",
                // "sortBy" => "recency",
                "sort" => "recency",
                "filterByVerified" => false,
                "startFromPageNumber" => 1,
                "endAtPageNumber" => 1,
                'count' => 20,
                "proxyConfiguration" => ["useApifyProxy" => true]
            ]
        );

        if (!$response->successful()) {
            throw new \Exception('Failed to start Trustpilot actor');
        }

        \Log::info('TP sync called and response is',[
            'run' => $response->json(),
            'passed_domain' => $domain,
            'normlizeddomain' =>$companyDomain,
        ]);

       
        $run = $response->json('data');

        return [
            'run_id' => $run['id'],
            'dataset_id' => $run['defaultDatasetId']
        ];
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);
        return rtrim($domain, '/');
    }

    
    public function getReviews(Request $request)
    {
        $domain = $request->input('domain');
        $sources = $request->input('sources', []);
        $limit   = (int) ($request->input('limit') ?? 20);
        
        if (empty($domain)) {
            return response()->json([
                'message' => 'Domain parameter is required.',
            ], 400);
        }

        $search = Search::where('domain', $domain)->first();
               
        // if (!$search || !in_array($search->status, ['completed','partial'])) {
        //     return response()->json([
        //         'status' => 'processing',
        //         'message' => 'No completed reviews found for the specified domain.',
        //     ], 202);
        // }
        
        if (!$search) {
            return response()->json([
                'status' => 'processing',
                'message' => 'Search not started yet',
            ], 202);
        }

                    
        $reviews = $search->reviews()
            ->whereIn('source', $sources)
            ->orderByDesc('date')
            ->limit(max(1, $limit * max(1, count($sources))))
            ->get();

        // Prepare ratings and counts only for requested sources so UI shows relevant cards
        $allRatings = $search->ratings ?? [];
        $filteredRatings = [];
        foreach ($sources as $s) {
            $filteredRatings[$s] = $allRatings[$s] ?? null;
        }

        $googleCount = in_array('google', $sources) ? ($search->google_reviews ?? 0) : 0;
        $trustpilotCount = in_array('trustpilot', $sources) ? ($search->trustpilot_reviews ?? 0) : 0;

        return response()->json([
            'status'           => $search->status,
            'domain'           => $domain,
            'message'          => $reviews->isNotEmpty() ? 'Reviews fetched successfully!' : 'No reviews found for the selected source(s).',
            'sources'          => $sources,
            'ratings'          => $filteredRatings,
            'google_reviews'   => $googleCount,
            'trustpilot_reviews' => $trustpilotCount,
            'total_reviews'    => $googleCount + $trustpilotCount,
            'limit'            => $limit,
            'reviews'          => $reviews->map(fn (Review $r) => [
                'source' => $r->source,
                'rating' => $r->rating,
                'text'   => $r->text,
                'date'   => optional($r->date)->format('d-m-Y'),
                'author' => $r->author,
            ])->values(),
        ]);

    }

    public function googlePlaceSuggestions(Request $request)
    {
        $input = trim($request->get('domain'));

        if (!$input) {
            return response()->json(['predictions' => []]);
        }

        // Normalize domain → keyword
        $keyword = preg_replace('#^https?://#', '', $input);
        $keyword = preg_replace('#^www\.#', '', $keyword);
        $keyword = preg_replace('#\.(com|in|au|co|net|org|io|ai|uk)(/.*)?$#i', '', $keyword);

        // ✅ Autocomplete API
        $autoResponse = Http::get(
            'https://maps.googleapis.com/maps/api/place/autocomplete/json',
            [
                'input'    => $keyword,
                'language' => 'en',
                'locationbias' => 'circle:50000@20.5937,78.9629', // India bias
                // Australia bias (soft, not restrictive)
                // 'location' => '-25.2744,133.7751',
                'radius'   => 2000000,

                'key'      => config('services.google.places_key'),
            ]
        )->json();

        // ✅ Proper status handling
        if (($autoResponse['status'] ?? '') !== 'OK') {
            return response()->json([
                'predictions' => [],
                'status'      => $autoResponse['status'] ?? 'UNKNOWN',
                'error'       => $autoResponse['error_message'] ?? null,
            ]);
        }

        if (empty($autoResponse['predictions'])) {
            return response()->json(['predictions' => []]);
        }

        // Limit to top 5 (cost control)
        $predictions = array_slice($autoResponse['predictions'], 0, 5);
        $results = [];

        foreach ($predictions as $item) {

            // Place Details API (rating + total reviews)
            $details = Http::get(
                'https://maps.googleapis.com/maps/api/place/details/json',
                [
                    'place_id' => $item['place_id'],
                    'fields'   => 'rating,user_ratings_total',
                    'key'      => config('services.google.places_key'),
                ]
            )->json();

            $results[] = [
                'place_id'       => $item['place_id'],
                'main_text'      => $item['structured_formatting']['main_text'],
                'secondary_text' => $item['structured_formatting']['secondary_text'] ?? '',
                'rating'         => $details['result']['rating'] ?? null,
                'total_reviews'  => $details['result']['user_ratings_total'] ?? 0,
            ];
        }

        return response()->json([
            'predictions' => $results
        ]);
    }

    public function trustpilotSuggestions(Request $request)
    {
        $query = trim($request->get('q'));

        if (strlen($query) < 3) {
            return response()->json([]);
        }

        // 1️⃣ typing-safe validation
        if (!DomainHelper::looksLikeDomain($query)) {
            return response()->json([]);
        }

        try {
            // You can improve this later using Trustpilot search endpoint
            $domain = DomainHelper::normalizeForTrustpilot($query);

            [$total, $rating] = DomainHelper::fetchMeta($domain);

            return response()->json([
                [
                    'domain' => $domain,
                    'rating' => $rating,
                    'total_reviews' => $total,
                ]
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Trustpilot suggestion failed', [
                'input' => $query,
                'error' => $e->getMessage(),
            ]);

            return response()->json([]);
        }
    }
}

