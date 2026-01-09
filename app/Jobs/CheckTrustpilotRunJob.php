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

    public int $timeout = 180;
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

        /** ------------------------------
         * 1️⃣ Run status
         * ------------------------------ */
        $status = Http::get(
            "https://api.apify.com/v2/actor-runs/{$this->runId}",
            ['token' => $token]
        )->json('data.status');

        \Log::info('Trustpilot run status', compact('status'));

        if ($status === 'FAILED') {
            Search::where('domain', $this->domain)->update(['status' => 'failed']);
            return;
        }

        /** ------------------------------
         * 2️⃣ Progressive fetch
         * ------------------------------ */
        if (in_array($status, ['RUNNING', 'SUCCEEDED'])) {

            $items = Http::get(
                "https://api.apify.com/v2/datasets/{$this->datasetId}/items",
                [
                    'token' => $token,
                    'limit' => $this->limit,
                    'desc'  => true,
                    'clean' => true,
                ]
            )->json() ?? [];

            if ($items) {
                $this->saveTrustpilotReviews($items);
            }

            if ($status !== 'SUCCEEDED') {
                self::dispatch(
                    $this->runId,
                    $this->datasetId,
                    $this->domain,
                    $this->limit
                )->delay(now()->addSeconds(10));
                return;
            }
        }

        /** ------------------------------
         * 3️⃣ FINALIZE (REAL TOTALS)
         * ------------------------------ */
        $search = Search::where('domain', $this->domain)->first();
        if (!$search || $search->status === 'completed') {
            return;
        }

        // 🔥 REAL Trustpilot data
        [$tpTotal, $tpAvg] = $this->fetchTrustpilotMeta($this->domain);

        $googleCount = $search->google_reviews ?? 0;

        $search->update([
            'ratings' => [
                'trustpilot' => [
                    'rating' => $tpAvg,
                    'total'  => $tpTotal,
                ],
            ],
            'trustpilot_reviews' => $tpTotal,
            'total_reviews'      => $googleCount + $tpTotal,
            'status'             => 'completed',
        ]);

        \Log::info('Trustpilot finalized CORRECTLY', [
            'domain' => $this->domain,
            'total'  => $tpTotal,
            'avg'    => $tpAvg,
        ]);
    }

    /** ------------------------------
     * Save reviews
     * ------------------------------ */
    private function saveTrustpilotReviews(array $items): void
    {
        $search = Search::where('domain', $this->domain)->first();
        if (!$search) return;

        foreach ($items as $r) {
            Review::updateOrCreate(
                [
                    'search_id' => $search->id,
                    'source'    => 'trustpilot',
                    'author'    => $r['authorName'] ?? 'Anonymous',
                    'date'      => substr($r['datePublished'] ?? '', 0, 10),
                ],
                [
                    'rating' => (int)($r['ratingValue'] ?? 0),
                    'text'   => $r['reviewBody'] ?? '',
                ]
            );
        }

        $search->update(['status' => 'processing']);
    }

    /** ------------------------------
     * REAL Trustpilot totals
     * ------------------------------ */
    private function fetchTrustpilotMeta(string $domain): array
    {
        $url = "https://www.trustpilot.com/review/" . $domain;

        $html = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0',
        ])->get($url)->body();

        if (!preg_match('/<script id="__NEXT_DATA__".*?>(.*?)<\/script>/s', $html, $m)) {
            return [0, 0];
        }

        $json = json_decode($m[1], true);

        $business = $json['props']['pageProps']['businessUnit'] ?? [];

        return [
            (int) ($business['numberOfReviews'] ?? 0),
            round((float) ($business['trustScore'] ?? 0), 1),
        ];
    }
}
