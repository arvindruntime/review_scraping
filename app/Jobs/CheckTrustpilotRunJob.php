<?php

namespace App\Jobs;

use App\Models\Review;
use App\Models\Search;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CheckTrustpilotRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 10;

    public function __construct(
        public string $runId,
        public string $datasetId,
        public string $domain,
        public int $limit
    ) {}

    public function handle()
    {
        $token = config('apify.token');

        $response = Http::get(
            "https://api.apify.com/v2/actor-runs/{$this->runId}?token={$token}"
        );

        $status = $response->json('data.status');

        \Log::info('Trustpilot run status', [
            'run_id' => $this->runId,
            'status' => $status
        ]);
        
        
        if ($status === 'FAILED') {
            Search::where('domain', $this->domain)
                ->update(['status' => 'failed']);

            \Log::error('Trustpilot run failed', [
                'run_id' => $this->runId
            ]);

            return;
        }


        if ($status !== 'SUCCEEDED') {
            
            
            if ($this->attempts() >= 10) {
                Search::where('domain', $this->domain)
                    ->update(['status' => 'failed']);

                \Log::error('Trustpilot run timed out', [
                    'run_id' => $this->runId
                ]);

                return;
            }
    
    
            // recheck after 20 sec
            self::dispatch(
                $this->runId,
                $this->datasetId,
                $this->domain,
                $this->limit
            )->delay(now()->addSeconds(20));

            return;
        }

        // fetch dataset metadata (may include itemCount)
        $datasetMeta = [];
        try {
            $datasetMeta = Http::get("https://api.apify.com/v2/datasets/{$this->datasetId}?token={$token}")->json('data') ?? [];
        } catch (\Throwable $e) {
            \Log::warning('Failed to fetch dataset metadata: ' . $e->getMessage());
        }

        // prefer using a sample (up to 200) to compute average rating, but use dataset itemCount if present for totals
        //$sampleLimit = min(max(50, $this->limit), 200);

        $items = Http::get(
            "https://api.apify.com/v2/datasets/{$this->datasetId}/items",
            ['token' => $token, 'limit' => $this->limit, 'desc'=> true, 'format' => 'json', 'clean' => true]
        )->json() ?? [];

        $reviews = collect($items)->map(fn ($r) => [
            'source' => 'trustpilot',
            'rating' => (int) ($r['ratingValue'] ?? 0),
            'text'   => $r['reviewBody'] ?? '',
            'date'   => $r['datePublished'] ?? null,
            'author' => $r['authorName'] ?? 'Anonymous',
        ])->toArray();

        // save reviews
        $search = Search::where('domain', $this->domain)->first();
            
        if (!$search) {
            \Log::error('Search not found for domain', [
                'domain' => $this->domain
            ]);
            return;
        }
        
        if ($search->status === 'completed') {
            return;
        }

        Review::where('search_id', $search->id)
        ->where('source', 'trustpilot')
        ->delete();
    
        foreach ($reviews as $r) {
            Review::create([
                'search_id' => $search->id,
                'source'    => 'trustpilot',
                'rating'    => $r['rating'],
                'text'      => $r['text'],
                'date'      => $r['date'],
                'author'    => $r['author'],
            ]);
        }
                
            // $status = $search->reviews()->where('source', 'google')->exists()
            // ? 'completed'
            // : 'partial';
            $search->refresh();
            $googleCount = $search->google_reviews ?? 0;

            // Prefer dataset itemCount if available, otherwise fallback to number of sampled reviews
            $datasetItemCount = $datasetMeta['itemCount'] ?? null;
            $trustpilotCount = is_null($datasetItemCount) ? count($items) : (int) $datasetItemCount;

            // Average rating computed from sampled items; if the dataset metadata includes an average use it
            $avgRating = null;
            if (!empty($items)) {
                $avgRating = collect($items)->avg('ratingValue');
                $avgRating = is_null($avgRating) ? null : round($avgRating, 1);
            } elseif (isset($datasetMeta['avgRating'])) {
                $avgRating = round((float) $datasetMeta['avgRating'], 1);
            }

            // Build final ratings while preserving any existing google rating
            $existingRatings = $search->ratings ?? [];
            $existingRatings['trustpilot'] = [
                'rating' => $avgRating ?? 0,
                'total'  => $trustpilotCount,
            ];

            $status = ($googleCount > 0 || $trustpilotCount > 0) ? 'completed' : 'partial';

            $search->update([
                'ratings'             => $existingRatings,
                'trustpilot_reviews'  => $trustpilotCount,
                'total_reviews'       => $googleCount + $trustpilotCount,
                'status'              => $status,
            ]);

            \Log::info('Trustpilot reviews saved & search finalized', [
                'domain' => $this->domain,
                'google_reviews' => $googleCount,
                'trustpilot_reviews' => $trustpilotCount,
                'dataset_item_count' => $datasetItemCount,
                'total_reviews' => $googleCount + $trustpilotCount,
            ]);
        
            // $search->update([
            //     'ratings->trustpilot' => [
            //         'rating' => collect($items)->avg('ratingValue'),
            //         'total'  => count($items),
            //     ],
            //     'total_reviews' => Review::where('search_id', $search->id)->count(),
            //     'status' => $status,
            // ]);


        \Log::info('Trustpilot reviews saved', [
            'domain' => $this->domain,
            'count'  => count($reviews)
        ]);
    }
}

