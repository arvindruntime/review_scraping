<?php
return [
    'token'          => env('APIFY_TOKEN'),
    'google_actor'   => env('APIFY_GOOGLE_ACTOR_ID'),
    'trustpilot_actor' => env('APIFY_TRUSTPILOT_ACTOR_ID'),
    'max_reviews'    => env('APIFY_MAX_REVIEWS', 20),
];