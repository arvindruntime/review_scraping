<?php

namespace App\Jobs;

use App\Models\Search;
use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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
            Search::updateOrCreate(
            ['domain' => $this->domain],
            ['status' => 'pending']
            );
        try {
                $allReviews   = [];
                $ratings      = [];
                $totalReviews = 0;

                if (in_array('google', $this->sources)) {
                    $google = app(\App\Http\Controllers\Api\ReviewController::class)
                        ->fetchFromGoogle($this->domain, $this->limit);

                    if (!empty($google['summary'])) {
                        $ratings['google'] = $google['summary'];
                    }

                    $allReviews   = array_merge($allReviews, $google['reviews'] ?? []);
                    $totalReviews += count($google['reviews'] ?? []);
                }

                if (in_array('trustpilot', $this->sources)) {
                    $trust = app(\App\Http\Controllers\Api\ReviewController::class)
                        ->fetchFromTrustpilot($this->domain, $this->limit);

                    if (!empty($trust['summary'])) {
                        $ratings['trustpilot'] = $trust['summary'];
                    }

                    $allReviews   = array_merge($allReviews, $trust['reviews'] ?? []);
                    $totalReviews += count($trust['reviews'] ?? []);
                }

                DB::transaction(function () use ($ratings, $totalReviews, $allReviews) {
                    $search = Search::updateOrCreate(
                        ['domain' => $this->domain],
                        [
                            'sources'       => $this->sources,
                            'ratings'       => $ratings,
                            'total_reviews' => $totalReviews,
                            'status'        => 'completed',
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
            } catch (\Throwable $e) {
                Search::where('domain', $this->domain)
                    ->update(['status' => 'failed']);

                throw $e;
            }
    }
}
