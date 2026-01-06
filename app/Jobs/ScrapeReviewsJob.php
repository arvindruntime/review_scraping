<?php

namespace App\Jobs;

use App\Models\Review;
use App\Models\Search;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use App\Jobs\CheckTrustpilotRunJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ScrapeReviewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 2;

    protected string $domain;
    protected array $sources;
    protected int $limit;
    protected ?string $google_place_id = null;

    public function __construct(string $domain, array $sources, int $limit, ?string $google_place_id = null)
    {
        $this->domain  = $domain;
        $this->sources = $sources;
        $this->limit   = $limit;
        $this->google_place_id = $google_place_id;
    }

    public function handle(): void
    {
        \Log::info('Jobs handle called');
                    
        $search = Search::updateOrCreate(
            ['domain' => $this->domain],
            ['status' => 'processing']
        );
                     
        try {
                $allReviews   = [];
                $ratings      = [];
                // $totalReviews = 0;
                
                    \Log::info('Entered try block', [
                    'domain'  => $this->domain,
                    'sources' => $this->sources,
                    'limit'   => $this->limit,
                ]);

                if (in_array('google', $this->sources)) {
                    $google = app(\App\Http\Controllers\Api\ReviewController::class)
                        ->fetchFromGoogle($this->domain, $this->limit, $this->google_place_id);

                    $googleReviews = $google['reviews'] ?? [];

                    if (!empty($google['summary'])) {
                        $ratings['google'] = $google['summary'];
                    }

                    // $googleCount = count($googleReviews);    
                    $googleCount = $google['summary']['total'] ?? count($googleReviews);

                    // Save Google results even if zero were found (delete old ones, update counts)
                    DB::transaction(function () use ($search, $googleReviews, $ratings, $googleCount) {

                        // Delete old Google reviews (safe even if none exist)
                        Review::where('search_id', $search->id)
                            ->where('source', 'google')
                            ->delete();

                        foreach ($googleReviews as $r) {
                            Review::create([
                                'search_id' => $search->id,
                                'source'    => $r['source'],
                                'rating'    => $r['rating'],
                                'text'      => $r['text'],
                                'date'      => $r['date'] ?? null,
                                'author'    => $r['author'] ?? null,
                            ]);
                        }

                        // Merge ratings into the existing JSON (preserve trustpilot rating if present)
                        $existingRatings = $search->ratings ?? [];
                        if (!empty($ratings['google'])) {
                            $existingRatings['google'] = $ratings['google'];
                        } else {
                            // explicit null to indicate no google summary
                            $existingRatings['google'] = $existingRatings['google'] ?? null;
                        }

                        $trustpilotCount = $search->trustpilot_reviews ?? 0;
                        $totalReviews = $trustpilotCount + $googleCount;

                        $status = 'completed';
                        if (in_array('trustpilot', $this->sources) && $trustpilotCount === 0) {
                            $status = 'partial';
                        }

                        $search->update([
                            'sources' => $this->sources,
                            'ratings' => $existingRatings,
                            'google_reviews' => $googleCount,
                            'total_reviews' => $totalReviews,
                            'status' => $status,
                        ]);

                        \Log::info('Google results saved to search', [
                            'domain' => $this->domain,
                            'google_reviews' => $googleCount,
                            'total_reviews' => $totalReviews,
                        ]);

                    });
                }
                
                // * ---------------- TRUSTPILOT ---------------- */
                
                if (in_array('trustpilot', $this->sources)) {
                                        
                    $run = app(\App\Http\Controllers\Api\ReviewController::class)
                        ->startTrustpilotRun($this->domain);

                    CheckTrustpilotRunJob::dispatch(
                        $run['run_id'],
                        $run['dataset_id'],
                        $this->domain,
                        $this->limit
                    )->delay(now()->addSeconds(30));
                }               

                \Log::info('ScrapeReviewsJob completed successfully', [
                'domain' => $this->domain
                ]);
            
            } catch (\Throwable $e) {
                                
                $search->update(['status' => 'failed']);
                
                \Log::error('ScrapeReviewsJob failed', [
                'domain'  => $this->domain,
                'error'  => $e->getMessage()
                ]);
                
                throw $e;
            }
    }
}
