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
            'limit'   => 'nullable|integer|min:1|max:20',
        ]);

        $domain  = strtolower(trim($request->input('domain')));
        $sources = $request->input('sources');
        $limit   = (int) ($request->input('limit') ?? config('apify.max_reviews', 20));

        /* ======================================================
        1) Try DB cache
        ====================================================== */
        $search = Search::where('domain', $domain)->first();

        if ($search) {
            $reviews = $search->reviews()
                ->whereIn('source', $sources)
                ->orderByDesc('date')
                ->limit($limit * count($sources))
                ->get();

            if ($reviews->isNotEmpty()) {
                return response()->json([
                    'domain'        => $domain,
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

        /* ======================================================
        2) Fetch from Apify
        ====================================================== */
        $allReviews   = [];
        $ratings      = [];
        $totalReviews = 0;

        if (in_array('google', $sources)) {
            $google = $this->fetchFromGoogle($domain, $limit);

            if (!empty($google['summary'])) {
                $ratings['google'] = $google['summary'];
            }

            $allReviews   = array_merge($allReviews, $google['reviews'] ?? []);
            $totalReviews += count($google['reviews'] ?? []);
        }

        // if (in_array('trustpilot', $sources)) {
        //     $trust = $this->fetchFromTrustpilot($domain, $limit);

        //     if (!empty($trust['summary'])) {
        //         $ratings['trustpilot'] = $trust['summary'];
        //     }

        //     $allReviews   = array_merge($allReviews, $trust['reviews'] ?? []);
        //     $totalReviews += count($trust['reviews'] ?? []);
        // }
        
        
        if (in_array('trustpilot', $sources)) {
            $trustResult = $this->fetchFromTrustpilot($domain, $limit);
            $runId = $trustResult['run_id'] ?? null;

            if ($runId) {
                // Dispatch background job
                TrustpilotFetchJob::dispatch($runId, $limit);

                $ratings['trustpilot'] = ['rating' => 0, 'total' => 0]; // temporary placeholder
            }
        }

        /* ======================================================
        3) Store cache
        ====================================================== */
        DB::transaction(function () use ($domain, $sources, $ratings, $totalReviews, $allReviews) {
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
        });

        /* ======================================================
        4) Final response (matches DB response exactly)
        ====================================================== */
                
            return response()->json([
            'domain'        => $domain,
            'sources'       => $sources,
            'ratings'       => $ratings,
            'total_reviews' => $totalReviews,
            'limit'         => $limit,

            // Google reviews are already formatted
            'reviews'       => collect($allReviews)->map(fn ($r) => [
                'source' => $r['source'],
                'rating' => $r['rating'],
                'text'   => $r['text'],
                'date'   => !empty($r['date'])
                    ? \Carbon\Carbon::parse($r['date'])->format('d-m-Y')
                    : null,
                'author' => $r['author'] ?? null,
            ])->values(),

            // 👇 VERY IMPORTANT
            'trustpilot_run_id' => $runId ?? null
        ]);

        
        
    }

    protected function fetchFromGoogle(string $domain, int $limit): array
    {
        $token   = config('apify.token');
        $actorId = config('apify.google_actor');

        $run = Http::post(
            "https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}",
            [
                'searchStringsArray' => [$domain],
                'language'           => 'en',
                'maxReviews'         => $limit,
                'reviewsSort'        => 'newest',
                'includeReviews'     => true,
            ]
        );

        if (!$run->successful()) {
            return ['reviews' => [], 'summary' => null];
        }

        $runId = $run->json('data.id');
        if (!$runId) {
            return ['reviews' => [], 'summary' => null];
        }

        $datasetId = null;
        for ($i = 0; $i < 60; $i++) {
            sleep(2);
            $status = Http::get(
                "https://api.apify.com/v2/acts/{$actorId}/runs/{$runId}?token={$token}"
            );

            $datasetId = $status->json('data.defaultDatasetId');
            if ($status->json('data.status') === 'SUCCEEDED') {
                break;
            }
        }

        if (!$datasetId) {
            return ['reviews' => [], 'summary' => null];
        }

        $items = Http::get(
            "https://api.apify.com/v2/datasets/{$datasetId}/items",
            ['token' => $token, 'format' => 'json', 'clean' => true]
        )->json();

        if (empty($items[0])) {
            return ['reviews' => [], 'summary' => null];
        }

        $place = $items[0];

        $summary = null;
        if (!empty($place['totalScore']) && !empty($place['reviewsCount'])) {
            $summary = [
                'rating' => (float) $place['totalScore'],
                'total'  => (int) $place['reviewsCount'],
            ];
        }

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

        usort($reviews, fn ($a, $b) =>
            strcmp((string) $b['date'], (string) $a['date'])
        );

        return [
            'reviews' => array_slice($reviews, 0, $limit),
            'summary' => $summary,
        ];
    }
    
    protected function fetchFromTrustpilot(string $domain, int $limit = 20): array
    {
        $token   = config('apify.token');
        $actorId = 'nikita-sviridenko~trustpilot-reviews-scraper';
        $companyDomain = str_replace(['https://', 'http://', 'www.'], '', $domain);

        $runRes = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}", [
            "companyDomain" => $companyDomain,
            "contentToExtract" => "reviews",
            "sortBy" => "recency",
            "filterByVerified" => true,
            "startFromPageNumber" => 1,
            "endAtPageNumber" => 1,
            "proxyConfiguration" => [
                "useApifyProxy" => true
            ]
        ]);

        if (!$runRes->successful()) {
            \Log::error('Trustpilot run failed', ['body' => $runRes->body()]);
            return ['reviews' => [], 'summary' => null, 'run_id' => null];
        }

        $run = $runRes->json('data');
        $runId = $run['id'] ?? null;

        // Return immediately with run ID
        return ['reviews' => [], 'summary' => null, 'run_id' => $runId];
    }
    
    //php artisan make:job TrustpilotFetchJob



//     protected function fetchFromTrustpilot(string $domain, int $limit = 20): array
// {
//     set_time_limit(180);

//     $token   = config('apify.token');
//     $actorId = 'nikita-sviridenko~trustpilot-reviews-scraper';

//     $companyDomain = str_replace(['https://', 'http://', 'www.'], '', $domain);

//     $runRes = Http::withHeaders([
//             'Content-Type' => 'application/json',
//         ])
//         ->post("https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}", [
//             "companyDomain" => $companyDomain,
//             "contentToExtract" => "reviews",
//             "sortBy" => "recency",
//             "filterByVerified" => true,
//             "startFromPageNumber" => 1,
//             "endAtPageNumber" => 1,
//             "proxyConfiguration" => [
//                 "useApifyProxy" => true
//             ]
//         ]);

//     if (!$runRes->successful()) {
//         \Log::error('Trustpilot run failed', [
//             'body' => $runRes->body()
//         ]);
//         return ['reviews' => [], 'summary' => null];
//     }

//     $run = $runRes->json('data');
//     $get_run_id = $run['id'] ?? null;
//     $datasetId = $run['defaultDatasetId'];
    
//     if (!empty($get_run_id) && !empty($datasetId))
//     {
//         // cheeck for status 
        
//         do {
//             $response = Http::get("https://api.apify.com/v2/actor-runs/{$get_run_id}?token={$token}");
//             $status = $response->json('data.status');

//             if ($status === 'SUCCEEDED') {
//                 $datasetId = $response->json('data.defaultDatasetId');
//                 // Fetch dataset
//                 break;
//             }

//             sleep(10); // wait 10 seconds before next check
//         } while ($status !== 'FAILED');
                
//     }
//     else
//     {
//         \Log::error('Trustpilot run has no ID', [
//             'run' => $run
//         ]);
//         return ['reviews' => [], 'summary' => null];
//     }
    
//    \Log::info('Trustpilot run succeeded', [
//         'run_id' => $get_run_id,
//         'dataset_id' => $datasetId,
//         'data'=> $response->json('data'),        
//     ]);
    
//     $itemsRes = Http::timeout(60)->get(
//         "https://api.apify.com/v2/datasets/{$datasetId}/items?token={$token}&limit={$limit}"
//     );

//     $items = $itemsRes->json() ?? [];

//     $reviews = collect($items)->map(fn ($r) => [
//         'source' => 'trustpilot',
//         'rating' => (int) ($r['ratingValue'] ?? 0),
//         'text'   => $r['reviewBody'] ?? '',
//         'date'   => $r['datePublished'] ?? null,
//         'author' => $r['authorName'] ?? 'Anonymous',
//     ])->toArray();

//     return [
//         'reviews' => $reviews,
//         'summary' => [
//             'rating' => count($reviews)
//                 ? round(collect($reviews)->avg('rating'), 1)
//                 : 0,
//             'total' => count($reviews),
//         ],
//     ];
// }


///////////////////////////////

// protected function fetchFromTrustpilot(string $domain, int $limit = 20): array
// {
//     set_time_limit(180);

//     $token   = config('apify.token');
//     $actorId = 'nikita-sviridenko~trustpilot-reviews-scraper';

//     $companyDomain = str_replace(['https://', 'http://', 'www.'], '', $domain);

//     // ✅ RUN ACTOR (INPUT AT ROOT LEVEL)
//     $runRes = Http::timeout(60)->post(
//         "https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}&waitForFinish=120",
//         [
//             // ❗ NO "input" KEY HERE
//             'companyDomain' => $companyDomain,
//             'contentToExtract' => 'reviews',
//             'sortBy' => 'recency',
//             'filterByVerified' => true,
//             'startFromPageNumber' => 1,
//             'endAtPageNumber' => ceil($limit / 20),
//             'proxyConfiguration' => [
//                 'useApifyProxy' => true,
//             ],
//         ]
//     );

//     if (!$runRes->successful()) {
//         \Log::error('Trustpilot run failed', [
//             'body' => $runRes->body()
//         ]);
//         return ['reviews' => [], 'summary' => null];
//     }

//     $run = $runRes->json('data');
//     $datasetId = $run['defaultDatasetId'];

//     // ✅ FETCH REVIEWS
//     $itemsRes = Http::timeout(60)->get(
//         "https://api.apify.com/v2/datasets/{$datasetId}/items?token={$token}&limit={$limit}"
//     );

//     $items = $itemsRes->json() ?? [];

//     $reviews = collect($items)->map(fn ($r) => [
//         'source' => 'trustpilot',
//         'rating' => (int) ($r['ratingValue'] ?? 0),
//         'text'   => $r['reviewBody'] ?? '',
//         'date'   => $r['datePublished'] ?? null,
//         'author' => $r['authorName'] ?? 'Anonymous',
//     ])->toArray();

//     return [
//         'reviews' => $reviews,
//         'summary' => [
//             'rating' => count($reviews)
//                 ? round(collect($reviews)->avg('rating'), 1)
//                 : 0,
//             'total' => count($reviews),
//         ],
//     ];
// }


public function getTrustpilotReviews(Request $request)
{
    $request->validate([
        'run_id' => 'required|string',
    ]);

    $runId = $request->input('run_id');

    $reviews = Cache::get("trustpilot_reviews_{$runId}");

    if (!$reviews) {
        return response()->json([
            'status' => 'processing',
            'message' => 'Reviews are still being fetched. Please wait.'
        ]);
    }

    return response()->json([
        'status' => 'completed',
        'reviews' => $reviews
    ]);
}





}

