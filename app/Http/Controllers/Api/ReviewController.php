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

        if (in_array('trustpilot', $sources)) {
            $trust = $this->fetchFromTrustpilot($domain, $limit);

            if (!empty($trust['summary'])) {
                $ratings['trustpilot'] = $trust['summary'];
            }

            $allReviews   = array_merge($allReviews, $trust['reviews'] ?? []);
            $totalReviews += count($trust['reviews'] ?? []);
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
            'reviews'       => collect($allReviews)->map(fn ($r) => [
                'source' => $r['source'],
                'rating' => $r['rating'],
                'text'   => $r['text'],
                'date'   => !empty($r['date'])
                ? \Carbon\Carbon::parse($r['date'])->format('d-m-Y')
                : null,
                'author' => $r['author'] ?? null,
            ])->values(),
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

protected function fetchFromTrustpilot(string $domain, int $limit): array
{
    $token   = config('apify.token');              // e.g. env('APIFY_TOKEN')
    $actorId = config('apify.trustpilot_actor');   // e.g. 'nikita-sviridenko~trustpilot-reviews-scraper'

    if (!$actorId || !$token) {
        \Log::error('Trustpilot actor ID or token missing', [
            'actorId'  => $actorId,
            'hasToken' => !empty($token),
        ]);
        return ['reviews' => [], 'summary' => null];
    }

    // 1) Start actor run – this actor expects "companyDomain" and "maxReviews"
    $run = Http::withToken($token)->post(
        "https://api.apify.com/v2/acts/{$actorId}/runs",
        [
            'companyDomain' => $domain,   // <- required by this actor
            'maxReviews'    => $limit,
        ]
    );

    if (!$run->successful()) {
        \Log::error('Trustpilot run start failed', [
            'status' => $run->status(),
            'body'   => $run->body(),
        ]);
        return ['reviews' => [], 'summary' => null];
    }

    $runId = data_get($run->json(), 'data.id');
    if (!$runId) {
        \Log::error('Trustpilot run id missing', ['response' => $run->json()]);
        return ['reviews' => [], 'summary' => null];
    }

    // 2) Poll run status
    $status    = 'RUNNING';
    $datasetId = null;

    for ($i = 0; $i < 60; $i++) {
        sleep(2);

        $statusRes = Http::withToken($token)
            ->get("https://api.apify.com/v2/actor-runs/{$runId}");

        if (!$statusRes->successful()) {
            \Log::warning('Trustpilot status check failed', [
                'status' => $statusRes->status(),
                'body'   => $statusRes->body(),
            ]);
            break;
        }

        $data      = $statusRes->json('data');
        $status    = $data['status'] ?? $status;
        $datasetId = $data['defaultDatasetId'] ?? $datasetId;

        if (in_array($status, ['SUCCEEDED', 'FAILED', 'ABORTED'], true)) {
            break;
        }
    }

    if ($status !== 'SUCCEEDED' || !$datasetId) {
        \Log::warning('Trustpilot run not completed', [
            'domain'    => $domain,
            'status'    => $status,
            'datasetId' => $datasetId,
        ]);
        return ['reviews' => [], 'summary' => null];
    }

    // 3) Fetch dataset items (flat list of reviews)
    $itemsRes = Http::withToken($token)->get(
        "https://api.apify.com/v2/datasets/{$datasetId}/items",
        [
            'format' => 'json',
            'clean'  => true,
            'desc'   => true,
            'limit'  => $limit,   // ask API to cap at limit
        ]
    );

    if (!$itemsRes->successful()) {
        \Log::warning('Trustpilot dataset fetch failed', [
            'status' => $itemsRes->status(),
            'body'   => $itemsRes->body(),
        ]);
        return ['reviews' => [], 'summary' => null];
    }

    $items = $itemsRes->json();
    if (empty($items)) {
        return ['reviews' => [], 'summary' => null];
    }

    // 4) Map actor items => internal review format
    $reviews = [];
    foreach ($items as $r) {
        $reviews[] = [
            'source' => 'trustpilot',
            'rating' => (int)($r['ratingValue'] ?? 0),
            'text'   => trim($r['reviewBody'] ?? ''),
            'date'   => $r['datePublished'] ?? null,
            'author' => $r['authorName'] ?? null,
        ];
    }

    // Newest first by date
    usort($reviews, static function ($a, $b) {
        return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
    });

    // Build a simple summary
    $summary = null;
    if ($reviews) {
        $count = count($reviews);
        $avg   = $count ? array_sum(array_column($reviews, 'rating')) / $count : 0;
        $summary = [
            'rating' => round($avg, 2),
            'total'  => $count,
        ];
    }

    return [
        'reviews' => array_slice($reviews, 0, $limit),
        'summary' => $summary,
    ];
}


}

