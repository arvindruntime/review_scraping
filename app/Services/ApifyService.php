<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApifyService
{
    protected string $token;

    public function __construct()
    {
        $this->token = config('services.apify.token') ?? env('APIFY_API_KEY');
    }

    /**
     * Run an Apify actor synchronously.
     * actorId should be like "username/actor-name" or "owner~actor-name" depending on Apify.
     * Returns array: ['ok'=>bool,'status'=>$httpStatus,'body'=>string,'json'=>array|null]
     */
    public function runSync(string $actorId, array $input = [], array $options = [])
    {
        $url = "https://api.apify.com/v2/acts/{$actorId}/run-sync?token={$this->token}";
        Log::debug("Apify run-sync url: {$url}");
        try {
            $response = Http::timeout($options['timeout'] ?? 180)->post($url, $input);
            $body = $response->body();
            Log::debug("Apify response status: {$response->status()}");
            Log::debug("Apify response body (truncated 2000 chars): " . substr($body, 0, 2000));
            $json = null;
            try {
                $json = $response->json();
            } catch (\Exception $e) {
                Log::debug("Apify response json decode failed: " . $e->getMessage());
            }
            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => $body,
                'json' => $json,
            ];
        } catch (\Exception $e) {
            Log::error('Apify runSync error: ' . $e->getMessage());
            return [
                'ok' => false,
                'status' => 0,
                'body' => $e->getMessage(),
                'json' => null,
            ];
        }
    }
}