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
            // recheck after 20 sec
            self::dispatch(
                $this->runId,
                $this->datasetId,
                $this->domain,
                $this->limit
            )->delay(now()->addSeconds(20));

            return;
        }

        // fetch dataset
        $items = Http::get(
            "https://api.apify.com/v2/datasets/{$this->datasetId}/items",
            ['token' => $token, 'limit' => $this->limit]
        )->json();

        $reviews = collect($items)->map(fn ($r) => [
            'source' => 'trustpilot',
            'rating' => (int) ($r['ratingValue'] ?? 0),
            'text'   => $r['reviewBody'] ?? '',
            'date'   => $r['datePublished'] ?? null,
            'author' => $r['authorName'] ?? 'Anonymous',
        ])->toArray();

        // save reviews
        $search = \App\Models\Search::where('domain', $this->domain)->first();
            
        if (!$search) {
            \Log::error('Search not found for domain', [
                'domain' => $this->domain
            ]);
            return;
        }

        Review::where('search_id', $search->id)
        ->where('source', 'trustpilot')
        ->delete();
    
        foreach ($reviews as $r) {
            \App\Models\Review::create([
                'search_id' => $search->id,
                'source'    => 'trustpilot',
                'rating'    => $r['rating'],
                'text'      => $r['text'],
                'date'      => $r['date'],
                'author'    => $r['author'],
            ]);
        }
                
            $status = $search->reviews()->where('source', 'google')->exists()
            ? 'completed'
            : 'partial';
    
            $search->update([
                'ratings->trustpilot' => [
                    'rating' => collect($items)->avg('ratingValue'),
                    'total'  => count($items),
                ],
                'total_reviews' => Review::where('search_id', $search->id)->count(),
                'status' => $status,
            ]);


        \Log::info('Trustpilot reviews saved', [
            'domain' => $this->domain,
            'count'  => count($reviews)
        ]);
    }
}

