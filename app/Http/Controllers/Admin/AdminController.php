<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Search;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * Get all searches with reviews
     */
    public function index(Request $request)
    {
        $query = Search::with('reviews');

        // Filter by domain if provided
        if ($request->has('domain')) {
            $query->where('domain', 'like', '%' . $request->input('domain') . '%');
        }

        // Filter by source if provided
        if ($request->has('source')) {
            $query->whereJsonContains('sources', $request->input('source'));
        }

        $searches = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($searches);
    }

    /**
     * Get a specific search with all reviews
     */
    public function show($id)
    {
        $search = Search::with('reviews')->findOrFail($id);

        return response()->json([
            'search' => $search,
            'reviews' => $search->reviews->map(function ($review) {
                return [
                    'source' => $review->source,
                    'rating' => $review->rating,
                    'text' => $review->text,
                    'date' => $review->date ? $review->date->format('Y-m-d') : null,
                    'author' => $review->author,
                ];
            }),
        ]);
    }

    /**
     * Get statistics
     */
    public function stats()
    {
        $totalSearches = Search::count();
        $totalReviews = Review::count();
        $googleReviews = Review::where('source', 'google')->count();
        $trustpilotReviews = Review::where('source', 'trustpilot')->count();

        return response()->json([
            'total_searches' => $totalSearches,
            'total_reviews' => $totalReviews,
            'google_reviews' => $googleReviews,
            'trustpilot_reviews' => $trustpilotReviews,
        ]);
    }
}

