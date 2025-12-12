<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Scraper</title>
<div class="container">
    <h1>Reviews for {{ $domain }}</h1>

    @if ($search)
        <p>
            Sources:
            {{ implode(', ', $search->sources ?? []) }}
            <br>
            Total reviews stored: {{ $search->total_reviews }}
        </p>
    @endif

    @if ($reviews->isEmpty())
        <p>No reviews found.</p>
    @else
        <ul class="list-group">
            @foreach ($reviews as $review)
                <li class="list-group-item mb-2">
                    <span class="badge bg-secondary">{{ ucfirst($review->source) }}</span>
                    @if($review->author)
                        <strong> {{ $review->author }}</strong>
                    @endif
                    @if($review->rating)
                        <span> - {{ $review->rating }}★</span>
                    @endif
                    @if($review->date)
                        <span class="text-muted"> ({{ $review->date->format('Y-m-d') }})</span>
                    @endif
                    <div class="mt-1">
                        {{ $review->text }}
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <a href="{{ route('reviews.form') }}" class="btn btn-link mt-3">Search another domain</a>
</div>

