import express from "express";
import { chromium } from "playwright";
import cors from "cors";

const app = express();
app.use(express.json());
app.use(cors());

// Trustpilot URLs to try
const TRUSTPILOT_URLS = [
    "https://www.trustpilot.com/review/",
    "https://www.trustpilot.co.uk/review/",
    "https://www.trustpilot.com.au/review/",
];

// Extract rating from star rating element
function extractRating(ratingElement) {
    if (!ratingElement) return null;

    // Try different selectors
    const img = ratingElement.querySelector('img');
    if (img) {
        const alt = img.getAttribute('alt') || '';
        const match = alt.match(/(\d+)/);
        if (match) return parseInt(match[1]);
    }

    // Try data-rating attribute
    const dataRating = ratingElement.getAttribute('data-rating');
    if (dataRating) return parseInt(dataRating);

    // Try class names
    const classes = ratingElement.className || '';
    if (classes.includes('star-rating-5')) return 5;
    if (classes.includes('star-rating-4')) return 4;
    if (classes.includes('star-rating-3')) return 3;
    if (classes.includes('star-rating-2')) return 2;
    if (classes.includes('star-rating-1')) return 1;

    return null;
}

app.post("/scrape-trustpilot", async (req, res) => {
    const { domain, limit = 20 } = req.body;

    if (!domain) {
        return res.status(400).json({ error: "Domain is required" });
    }

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();

    try {
        let workingUrl = null;
        let reviews = [];
        let overallRating = null;
        let totalReviews = 0;
        console.log(`Starting scrape for domain: ${domain}`);

        // Try each Trustpilot URL
        for (const baseUrl of TRUSTPILOT_URLS) {
            const url = baseUrl + domain;
            console.log(`Trying Trustpilot URL: ${url}`);

            try {
                // Set a realistic user agent to avoid bot detection
                await page.setExtraHTTPHeaders({
                    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept-Language': 'en-US,en;q=0.9',
                });

                await page.goto(url, { timeout: 60000, waitUntil: 'networkidle' });

                // Check if page loaded successfully (not 404)
                const pageTitle = await page.title();
                console.log(`Page title: ${pageTitle}`);

                if (pageTitle.includes('404') || pageTitle.includes('Not Found')) {
                    console.log(`404 page detected, trying next URL...`);
                    continue;
                }

                workingUrl = url;
                console.log(`Working URL found: ${url}`);

                // Wait for page to fully load - Trustpilot uses heavy JavaScript
                console.log('Waiting for page to fully load...');
                await page.waitForLoadState('networkidle', { timeout: 30000 });
                await page.waitForTimeout(3000); // Extra wait for JavaScript execution

                // Extract overall rating and total reviews
                try {
                    // Try to extract overall rating from TrustScore
                    const ratingText = await page.textContent('body').catch(() => '');
                    const ratingMatch = ratingText.match(/TrustScore[:\s]+(\d+\.?\d*)/i) ||
                        ratingText.match(/(\d+\.?\d*)\s+out of 5/i);
                    if (ratingMatch) {
                        overallRating = parseFloat(ratingMatch[1]);
                        console.log(`Found overall rating: ${overallRating}`);
                    }

                    // Try extracting total reviews from page text
                    const totalMatch = ratingText.match(/([\d,]+)\s+reviews?/i);
                    if (totalMatch) {
                        totalReviews = parseInt(totalMatch[1].replace(/,/g, ''));
                        console.log(`Found total reviews: ${totalReviews}`);
                    }
                } catch (e) {
                    console.log('Could not extract rating/total:', e.message);
                }

                // Scroll to trigger lazy loading of reviews
                console.log('Scrolling to trigger content loading...');
                await page.evaluate(() => {
                    window.scrollTo(0, document.body.scrollHeight / 2);
                });
                await page.waitForTimeout(2000);

                // Scroll back up
                await page.evaluate(() => {
                    window.scrollTo(0, 0);
                });
                await page.waitForTimeout(1000);

                // Now extract reviews using the correct selectors based on the HTML structure
                console.log('Extracting reviews...');

                // Use the specific selector for Trustpilot review cards
                const reviewSelector = 'article[data-service-review-card-paper="true"]';

                // Check if reviews exist
                const reviewCount = await page.$$eval(reviewSelector, articles => articles.length).catch(() => 0);
                console.log(`Found ${reviewCount} review cards with selector: ${reviewSelector}`);

                if (reviewCount === 0) {
                    console.log('No reviews found with primary selector, trying alternative...');
                    // Try generic article selector
                    const altCount = await page.$$eval('article', articles => articles.length).catch(() => 0);
                    console.log(`Found ${altCount} article elements total`);
                }

                // Scroll and load more reviews
                let scrollAttempts = 0;
                const maxScrollAttempts = 3;

                while (reviews.length < limit && scrollAttempts < maxScrollAttempts) {
                    // Extract reviews from current page state
                    const reviewCards = await page.$$eval(
                        reviewSelector,
                        (cards) => {
                            return cards.map(card => {
                                // Extract rating from img alt attribute
                                let rating = null;
                                const ratingImg = card.querySelector('img.CDS_StarRating_starRating__614d2e, img[alt*="Rated"], img[alt*="star"]');
                                if (ratingImg) {
                                    const alt = ratingImg.getAttribute('alt') || '';
                                    // Match "Rated X out of 5 stars"
                                    const match = alt.match(/Rated\s+(\d+)\s+out\s+of\s+5/i) || alt.match(/(\d+)\s+star/i);
                                    if (match) {
                                        rating = parseInt(match[1]);
                                    }
                                }

                                // Extract review text
                                const textEl = card.querySelector('p[data-relevant-review-text-typography="true"]');
                                let text = '';
                                if (textEl) {
                                    // Get text content, excluding "See more" span
                                    const seeMoreSpan = textEl.querySelector('span.styles_seeMore__J_tOL');
                                    if (seeMoreSpan) {
                                        // Clone the element and remove the "See more" span
                                        const clonedEl = textEl.cloneNode(true);
                                        const clonedSeeMore = clonedEl.querySelector('span.styles_seeMore__J_tOL');
                                        if (clonedSeeMore) {
                                            clonedSeeMore.remove();
                                        }
                                        text = (clonedEl.textContent || '').trim();
                                    } else {
                                        text = (textEl.textContent || '').trim();
                                    }
                                }

                                // Extract author
                                let author = null;
                                const authorEl = card.querySelector('span[data-consumer-name-typography="true"]');
                                if (authorEl) {
                                    author = (authorEl.textContent || '').trim();
                                }

                                // Extract date
                                let date = null;
                                const dateEl = card.querySelector('time[data-service-review-date-time-ago="true"]');
                                if (dateEl) {
                                    date = dateEl.getAttribute('datetime') || dateEl.getAttribute('title');
                                }

                                return {
                                    text: text,
                                    rating: rating,
                                    date: date,
                                    author: author,
                                };
                            });
                        }
                    ).catch((e) => {
                        console.log('Error extracting reviews:', e.message);
                        return [];
                    });

                    console.log(`Extracted ${reviewCards.length} review cards in this iteration`);

                    // Process and add reviews
                    for (const card of reviewCards) {
                        if (reviews.length >= limit) break;

                        // Only add reviews with valid text and rating
                        if (card.text && card.text.length > 10 && card.rating) {
                            // Check for duplicates
                            const isDuplicate = reviews.some(r => r.text === card.text);
                            if (!isDuplicate) {
                                console.log(`Adding review: rating=${card.rating}, author=${card.author}, text length=${card.text.length}`);
                                reviews.push({
                                    source: 'trustpilot',
                                    rating: card.rating,
                                    text: card.text,
                                    date: card.date ? card.date.split('T')[0] : null,
                                    author: card.author || null,
                                });
                            }
                        }
                    }

                    console.log(`Total reviews collected so far: ${reviews.length}`);

                    if (reviews.length >= limit) break;

                    // Scroll down to load more reviews
                    scrollAttempts++;
                    console.log(`Scrolling to load more reviews (attempt ${scrollAttempts}/${maxScrollAttempts})...`);
                    await page.evaluate(() => {
                        window.scrollBy(0, 1000);
                    });
                    await page.waitForTimeout(2000);
                }

                // Limit reviews to requested amount
                reviews = reviews.slice(0, limit);

                if (reviews.length > 0) {
                    console.log(`Successfully extracted ${reviews.length} reviews`);
                    break; // Found working URL and reviews
                } else {
                    console.log('No reviews extracted, trying next URL...');
                }
            } catch (error) {
                console.log(`Failed to load ${url}:`, error.message);
                continue;
            }
        }

        await browser.close();

        if (reviews.length === 0) {
            if (!workingUrl) {
                console.log(`No working URL found for domain: ${domain}`);
                return res.json({
                    rating: null,
                    total_reviews: 0,
                    reviews: [],
                    error: "Could not find Trustpilot page for this domain. Please check if the domain has a Trustpilot profile."
                });
            } else {
                console.log(`Found working URL but no reviews extracted for domain: ${domain}`);
                return res.json({
                    rating: overallRating,
                    total_reviews: totalReviews,
                    reviews: [],
                    error: "Found Trustpilot page but could not extract reviews. The page structure might have changed or there are no reviews available."
                });
            }
        }

        // Log results for debugging
        console.log(`Successfully scraped ${reviews.length} reviews for ${domain}`);
        if (reviews.length > 0) {
            console.log('Sample review:', JSON.stringify(reviews[0], null, 2));
        }

        res.json({
            rating: overallRating,
            total_reviews: totalReviews,
            reviews: reviews,
        });
    } catch (error) {
        console.error('Error in scrape-trustpilot:', error);
        await browser.close();
        res.status(500).json({ error: error.message });
    }
});

// Health check endpoint
app.get("/health", (req, res) => {
    res.json({ status: "ok", service: "trustpilot-scraper", port: 4000 });
});

app.listen(4000, "0.0.0.0", () => console.log("Trustpilot scraper running on 4000"));
