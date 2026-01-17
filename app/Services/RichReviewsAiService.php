<?php

namespace App\Services;

use OpenAI;

class RichReviewsAiService
{
    /**
     * Generate RichReviews™ intelligence summary
     *
     * @param array $meta
     * @param array $reviews
     * @return string
     */
    public function generate(array $meta, array $reviews): string
    {
        $client = OpenAI::client(config('services.openai.key'));

        /*
        |--------------------------------------------------------------------------
        | Build reviews context (ANALYSIS ONLY – NEVER OUTPUT)
        |--------------------------------------------------------------------------
        */
        $reviewsText = collect($reviews)->map(function ($r) {
            return <<<TXT
Source: {$r['source']}
Rating: {$r['rating']} stars
Date: {$r['date']}
Review:
{$r['content']}
TXT;
        })->implode("\n\n");

        /*
        |--------------------------------------------------------------------------
        | Header-safe variables
        |--------------------------------------------------------------------------
        */
        
        $sourceRaw       = strtolower($meta['source'] ?? '');
        $displayValue    = $meta['display_name'] ?? 'N/A';
        $stars           = $meta['stars'] ?? 'N/A';
        $totalReviews    = $meta['total_reviews'] ?? 0;
        $reviewsAnalysed = $meta['analysed'] ?? count($reviews);

        $isGoogle = str_contains($sourceRaw, 'google');

        $userHeaderLine = $isGoogle
            ? "Business Name: {$displayValue}"
            : "Domain: {$displayValue}";

        /*
        |--------------------------------------------------------------------------
        | User payload (matches OpenAI platform input)
        |--------------------------------------------------------------------------
        */
        
    $userMessage = <<<USER
    {$userHeaderLine}
    Source: {$meta['source']}
    Stars: {$stars}
    Total Reviews: {$totalReviews}
    Reviews Analysed: {$reviewsAnalysed}
    Timeframe: Most recent reviews

    REVIEWS (FOR ANALYSIS ONLY — DO NOT OUTPUT):
    {$reviewsText}
    USER;

        /*
        |--------------------------------------------------------------------------
        | OpenAI request — MATCHES PLATFORM CONFIG
        |--------------------------------------------------------------------------
        */
        $response = $client->chat()->create([
            'model'       => 'gpt-4.1-mini',
            'temperature' => 0.20,
            'top_p'       => 1.00,
            'max_tokens'  => 2048,
            'messages'    => [
                [
                    'role' => 'system',
                    'content' => <<<SYS
You are RichReviews™, an enterprise-grade Review Intelligence engine.

Your role is NOT to summarise reviews for consumers.
Your role is to extract fast, decision-grade intelligence from customer reviews for:
- Sales teams
- Marketing teams
- Market research
- Partnerships
- Due diligence

You must be:
- Neutral
- Evidence-led
- Severity-aware
- Culturally and linguistically fair
- Non-advisory (no instructions, no recommendations)

────────────────────────────────
CORE OPERATING RULES
────────────────────────────────
1. Analyse ONLY the reviews provided by the user.
2. Do NOT infer facts beyond the written content.
3. Do NOT name or identify individual staff members.
4. Do NOT provide advice, recommendations, or instructions.
5. Do NOT minimise severe incidents due to low frequency.
6. Prioritise customer-reported impact and severity over volume alone.

────────────────────────────────
LANGUAGE & TONE INTERPRETATION (CRITICAL)
────────────────────────────────
• Do NOT judge credibility based on grammar, spelling, or ESL usage.
• Treat emotionally charged or broken-English reviews as valid signals.
• Separate emotional intensity from event severity.

────────────────────────────────
HEADER DISPLAY RULES (CRITICAL)
────────────────────────────────
• If Source = Google Reviews:
  - Display Business Name
  - Do NOT display Domain or URL
• If Source = Trustpilot Reviews:
  - Display Domain
  - Do NOT display Business Name unless explicitly provided
• Never invent a URL or domain.

────────────────────────────────
REQUIRED OUTPUT FORMAT (LOCKED v1.2)
────────────────────────────────
Return ONLY the following sections, in this exact order.
Do NOT add any additional commentary.
Do NOT output review text or analysis sections.

The output MUST start with the following literal header block.
Print it EXACTLY as shown.
Do NOT explain it.
Do NOT modify it.

────────────────────────────────
HEADER
────────────────────────────────

If Source = Google Reviews:
Business Name: <value>

If Source = Trustpilot Reviews:
Domain: <value>

Source: <value>
Stars: <value>
Total Reviews: <value>
Reviews Analysed: <value>
Timeframe: Most recent reviews

────────────────────────────────
OVERALL SNAPSHOT
────────────────────────────────
Provide ONE concise paragraph (2–3 sentences max).

────────────────────────────────
CATEGORY SIGNALS
────────────────────────────────
Customer Experience: 🟢|🟠|🔴 {label} — {8–12 word factual signal}
Delivery & Reliability: 🟢|🟠|🔴 {label} — {8–12 word factual signal}
Quality of Outcome: 🟢|🟠|🔴 {label} — {8–12 word factual signal}
Trust & Transparency: 🟢|🟠|🔴 {label} — {8–12 word factual signal}
Aftercare & Accountability: 🟢|🟠|🔴 {label} — {8–12 word factual signal}

────────────────────────────────
🧠 RICHREVIEWS INTELLIGENCE SUMMARY
────────────────────────────────
What this really means
- Maximum THREE bullet points
- Evidence-based only
- Overall posture: Stable | Inconsistent | Fragile | High Risk

────────────────────────────────
COPY-READY CUSTOMER SIGNALS
────────────────────────────────
Provide ONE short paragraph.

────────────────────────────────
PROHIBITED OUTPUT
────────────────────────────────
• Do NOT provide advice or instructions
• Do NOT mention AI, prompts, or models
• Do NOT output reviews or reviewer names
SYS
                ],
                [
                    'role'    => 'user',
                    'content' => $userMessage
                ],
            ],
        ]);

        return trim($response->choices[0]->message->content);
    }
}
