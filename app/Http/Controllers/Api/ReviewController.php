<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\ApifyService;

class ReviewController extends Controller
{
    protected ApifyService $apify;

    public function __construct(ApifyService $apify)
    {
        $this->apify = $apify;
    }

    public function fetchReviews(Request $request)
    {
        $domain = $request->input('domain');
        $limit = (int) $request->input('limit', 20);
        if (! $domain) {
            return response()->json(['error'=>'domain required'], 400);
        }

        // Example actor IDs — confirm the public actor IDs you want to run.
        $googleActor = 'compass/crawler-google-places';      // replace if different
        $trustpilotActor = 'casper11515/trustpilot-reviews-scraper'; // replace if different

        $resultAll = [];
        $ratings = [
            'google' => ['rating'=>0,'total'=>0],
            'trustpilot' => ['rating'=>0,'total'=>0],
        ];

        // GOOGLE via Apify
        $googleInput = [
            // actor-specific input; adjust according to actor docs
            'searchStringsArray' => [$domain],
            'maxReviews' => $limit,
            'language' => 'en',
        ];
        $g = $this->apify->runSync($googleActor, $googleInput);
        Log::debug('Google actor run result status: '.$g['status']);
        if ($g['ok'] && is_array($g['json'])) {
            // inspect actual shape — common places: output.places or output
            $json = $g['json'];
            $items = $json['output']['places'] ?? $json['output'] ?? $json['defaultDatasetItems'] ?? $json['items'] ?? [];
            // normalize if single place
            if (isset($items['reviews'])) {
                $items = [$items];
            }
            $collected = [];
            foreach ($items as $place) {
                $avg = $place['averageRating'] ?? $place['rating'] ?? null;
                $count = $place['reviewCount'] ?? $place['reviewsCount'] ?? null;
                if ($avg) $ratings['google']['rating'] = $avg;
                if ($count) $ratings['google']['total'] = $count;
                $reviews = $place['reviews'] ?? $place['placeReviews'] ?? $place['reviewsList'] ?? [];
                foreach ($reviews as $r) {
                    $collected[] = [
                        'source' => 'google',
                        'author' => $r['reviewerName'] ?? $r['author'] ?? null,
                        'rating' => $r['rating'] ?? $r['stars'] ?? 0,
                        'text' => $r['reviewText'] ?? $r['text'] ?? '',
                        'date' => $r['publishedAtDate'] ?? $r['date'] ?? null,
                    ];
                    if (count($collected) >= $limit) break;
                }
                if (count($collected) >= $limit) break;
            }
            $resultAll = array_merge($resultAll, $collected);
        } else {
            Log::error('Google actor failed: '.$g['body']);
        }

        // TRUSTPILOT via Apify
        $trustInput = [
            'startUrls' => [['url' => "https://www.trustpilot.com/review/{$domain}"]],
            'maxRequestsPerCrawl' => 10,
        ];
        $t = $this->apify->runSync($trustpilotActor, $trustInput);
        Log::debug('Trustpilot actor run result status: '.$t['status']);
        if ($t['ok'] && is_array($t['json'])) {
            $json = $t['json'];
            $items = $json['output']['results'] ?? $json['output'] ?? $json['defaultDatasetItems'] ?? $json['items'] ?? [];
            if (isset($items['reviews'])) $items = [$items];
            $collected = [];
            foreach ($items as $page) {
                $reviews = $page['reviews'] ?? $page['items'] ?? [];
                foreach ($reviews as $r) {
                    $collected[] = [
                        'source' => 'trustpilot',
                        'author' => $r['authorName'] ?? $r['author'] ?? null,
                        'rating' => $r['rating'] ?? 0,
                        'text' => $r['text'] ?? $r['reviewText'] ?? '',
                        'date' => $r['publishedDate'] ?? $r['date'] ?? null,
                    ];
                    if (count($collected) >= $limit) break;
                }
                if (count($collected) >= $limit) break;
            }
            $resultAll = array_merge($resultAll, $collected);
        } else {
            Log::error('Trustpilot actor failed: '.$t['body']);
        }

        // Ensure deterministic sort by date if present
        usort($resultAll, function($a,$b){
            $da = $a['date'] ? strtotime($a['date']) : 0;
            $db = $b['date'] ? strtotime($b['date']) : 0;
            return $db <=> $da; // newest first
        });

        return response()->json([
            'domain' => $domain,
            'ratings' => $ratings,
            'reviews' => array_slice($resultAll, 0, $limit),
            'total_reviews' => count($resultAll),
        ]);
    }
}