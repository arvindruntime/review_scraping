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

    public int $timeout = 300;
    public int $tries = 3;

    protected string $domain;
    protected array $sources;
    protected int $limit;

    public function __construct(string $domain, array $sources, int $limit)
    {
        $this->domain  = $domain;
        $this->sources = $sources;
        $this->limit   = $limit;
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
                        ->fetchFromGoogle($this->domain, $this->limit);

                    if (!empty($google['summary'])) {
                        $ratings['google'] = $google['summary'];
                    }

                    $allReviews = $google['reviews'] ?? [];
                    
                    // $totalReviews += count($google['reviews'] ?? []);
                    
                    if (!empty($allReviews)) {

                        DB::transaction(function () use ($search, $allReviews, $ratings) {
                            
                            // Delete old Google reviews only
                            Review::where('search_id', $search->id)
                                ->where('source', 'google')
                                ->delete();

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

                            $search->update([
                                'sources' => $this->sources,
                                'ratings->google' => $ratings['google'] ?? null,
                                'total_reviews' => Review::where('search_id', $search->id)->count(),
                                'status' => in_array('trustpilot', $this->sources) ? 'partial' : 'completed',
                            ]);

                        });
                    }    
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
