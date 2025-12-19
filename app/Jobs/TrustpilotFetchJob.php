<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class TrustpilotFetchJob implements ShouldQueue
{
    
     use InteractsWithQueue, Queueable, SerializesModels;

    protected $runId;
    protected $limit;

    /**
     * Create a new job instance.
     */
    public function __construct(string $runId, int $limit = 20)
    {
        $this->runId = $runId;
        $this->limit = $limit;
    }

    /**
     * Execute the job.
     */
        public function handle()
        {
            $token = config('apify.token');
            $datasetId = null;

            // Poll for run completion
            for ($i = 0; $i < 60; $i++) { // max 10 min
                sleep(10);

                $statusRes = Http::get("https://api.apify.com/v2/actor-runs/{$this->runId}?token={$token}");
                $status = $statusRes->json('data.status');

                if ($status === 'SUCCEEDED') {
                    $datasetId = $statusRes->json('data.defaultDatasetId');
                    break;
                } elseif ($status === 'FAILED') {
                    \Log::error("Trustpilot run failed: {$this->runId}");
                    return;
                }
            }

            if (!$datasetId) return;

            // Fetch dataset items
            $items = Http::get(
                "https://api.apify.com/v2/datasets/{$datasetId}/items",
                ['token' => $token, 'limit' => $this->limit, 'clean' => 1, 'format' => 'json']
            )->json() ?? [];

            // Map reviews
                      
            
            $reviews = collect($items)->map(fn ($r) => [
            'source' => 'trustpilot',
            'rating' => (int) ($r['ratingValue'] ?? 0),
            'text'   => $r['reviewBody'] ?? '',
            'date'   => !empty($r['datePublished'])
                ? \Carbon\Carbon::parse($r['datePublished'])->format('d-m-Y')
                : null,
            'author' => $r['authorName'] ?? 'Anonymous',
        ])->toArray();

            // Store temporarily in cache (or DB)
            Cache::put("trustpilot_reviews_{$this->runId}", $reviews, 3600);
        }
    }
