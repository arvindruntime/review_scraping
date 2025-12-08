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

        // Try each Trustpilot URL
        for (const baseUrl of TRUSTPILOT_URLS) {
            const url = baseUrl + domain;
            console.log(`Trying Trustpilot URL: ${url}`);
            try {
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
                
                // Set a realistic user agent to avoid bot detection
                await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
                
                // Extract overall rating and total reviews
                try {
                    // Wait for page to fully load - Trustpilot uses heavy JavaScript
                    console.log('Waiting for page to fully load...');
                    await page.waitForLoadState('networkidle', { timeout: 30000 });
                    await page.waitForTimeout(5000); // Extra wait for JavaScript execution
                    
                    // Scroll to trigger lazy loading
                    console.log('Scrolling to trigger content loading...');
                    await page.evaluate(() => {
                        window.scrollTo(0, document.body.scrollHeight);
                    });
                    await page.waitForTimeout(3000);
                    
                    // Scroll back up
                    await page.evaluate(() => {
                        window.scrollTo(0, 0);
                    });
                    await page.waitForTimeout(2000);
                    
                    // Debug: Check what's actually on the page
                    const pageContent = await page.content();
                    console.log(`Page content length: ${pageContent.length} characters`);
                    
                    // Try to find any review-related elements
                    const allArticles = await page.$$eval('article', articles => articles.length);
                    const allDivsWithData = await page.$$eval('[data-review-id], [data-review], [data-service-review-id]', divs => divs.length);
                    const allSections = await page.$$eval('section', sections => sections.length);
                    console.log(`Found ${allArticles} article elements, ${allDivsWithData} data-review elements, ${allSections} sections`);
                    
                    // Try multiple selectors for overall rating
                    const ratingSelectors = [
                        '[data-star-rating]',
                        '.star-rating',
                        '[data-service-review-rating]',
                        '.styles_trustScore__3l6qP',
                        'div[data-star-rating]'
                    ];
                    
                    for (const selector of ratingSelectors) {
                        const ratingElement = await page.$(selector).catch(() => null);
                        if (ratingElement) {
                            const ratingValue = await ratingElement.getAttribute('data-star-rating') ||
                                              await ratingElement.getAttribute('data-service-review-rating');
                            if (ratingValue) {
                                overallRating = parseFloat(ratingValue);
                                break;
                            }
                        }
                    }
                    
                    // Try to extract from text if not found
                    if (!overallRating) {
                        const ratingText = await page.textContent('body').catch(() => '');
                        const match = ratingText.match(/TrustScore[:\s]+(\d+\.?\d*)/i) || 
                                   ratingText.match(/(\d+\.?\d*)\s+out of 5/i);
                        if (match) {
                            overallRating = parseFloat(match[1]);
                        }
                    }

                    // Get total reviews count with multiple selectors
                    const totalSelectors = [
                        '[data-total-reviews]',
                        '.styles_totalReviews__3l6qP',
                        '[data-service-review-count]'
                    ];
                    
                    for (const selector of totalSelectors) {
                        const totalEl = await page.$(selector).catch(() => null);
                        if (totalEl) {
                            const totalText = await totalEl.getAttribute('data-total-reviews') ||
                                            await totalEl.getAttribute('data-service-review-count') ||
                                            await totalEl.textContent();
                            if (totalText) {
                                const match = totalText.toString().match(/(\d+)/);
                                if (match) {
                                    totalReviews = parseInt(match[1]);
                                    break;
                                }
                            }
                        }
                    }
                    
                    // Try extracting from page text
                    if (!totalReviews) {
                        const pageText = await page.textContent('body').catch(() => '');
                        const match = pageText.match(/(\d+)\s+reviews?/i);
                        if (match) {
                            totalReviews = parseInt(match[1]);
                        }
                    }
                } catch (e) {
                    console.log('Could not extract rating/total:', e.message);
                }

                // Scroll and load more reviews
                let previousCount = 0;
                let scrollAttempts = 0;
                const maxScrollAttempts = 5;

                while (reviews.length < limit && scrollAttempts < maxScrollAttempts) {
                    // Wait for reviews to load
                    await page.waitForTimeout(3000);
                    
                    // Scroll down to load more content
                    await page.evaluate(() => {
                        window.scrollBy(0, 1000);
                    });
                    await page.waitForTimeout(2000);

                    // Try multiple selectors - Trustpilot uses different structures
                    const reviewSelectors = [
                        'article[data-review-id]',
                        'article[data-service-review-id]',
                        '[data-review-id]',
                        '[data-service-review-id]',
                        '[data-review]',
                        '.review-card',
                        '.paper_paper__1PY90',
                        'section[data-review-id]',
                        'div[data-review-id]',
                        '.styles_reviewCard__',
                        'article',
                        'div[class*="review"]',
                        'section[class*="review"]',
                    ];
                    
                    let foundSelector = null;
                    let maxCount = 0;
                    
                    // Try all selectors and find the one with most matches
                    for (const selector of reviewSelectors) {
                        try {
                            const count = await page.$$eval(selector, els => els.length);
                            if (count > maxCount) {
                                maxCount = count;
                                foundSelector = selector;
                                console.log(`Found ${count} elements with selector: ${selector}`);
                            }
                        } catch (e) {
                            // Try next selector
                        }
                    }
                    
                    if (!foundSelector || maxCount === 0) {
                        console.log(`No review selectors matched. Trying to find any review-like content...`);
                        // Try to find any article or div that might contain reviews
                        const anyArticles = await page.$$eval('article', articles => articles.length);
                        const anyDivs = await page.$$eval('div', divs => divs.length);
                        console.log(`Found ${anyArticles} article elements and ${anyDivs} div elements total`);
                        
                        // Try a very generic approach - look for any element with review-like text
                        foundSelector = 'article, div[class*="review"], section[class*="review"]';
                    }
                    
                    const reviewSelector = foundSelector || 'article, [data-review-id], .review-card';
                    console.log(`Using selector: ${reviewSelector}`);

                    // Extract reviews with multiple selector strategies
                    console.log(`Extracting reviews with selector: ${reviewSelector}`);
                    
                    // First, try to get all potential review containers
                    const reviewCards = await page.$$eval(
                        reviewSelector, 
                        cards => {
                            return cards.map(card => {
                                // Try multiple selectors for text - Trustpilot uses various class names
                                const textEl = card.querySelector(
                                    '.review-content__text, ' +
                                    '.typography_body-l__KUYBe, ' +
                                    '[data-review-content-text], ' +
                                    '.styles_reviewContent__8HJqX, ' +
                                    'p.review-content__text, ' +
                                    '.review-text, ' +
                                    'p[data-review-content-text], ' +
                                    '.styles_reviewText__, ' +
                                    '[class*="reviewText"], ' +
                                    '[class*="review-content"], ' +
                                    'p'
                                );
                                const titleEl = card.querySelector(
                                    '.review-content__title, ' +
                                    '.typography_heading-s__f7029, ' +
                                    'h2.review-content__title, ' +
                                    'h2, h3, [class*="title"]'
                                );
                                
                                // Try multiple selectors for rating
                                const ratingEl = card.querySelector(
                                    '[data-star-rating], ' +
                                    '[data-service-review-rating], ' +
                                    '[data-rating], ' +
                                    '.star-rating, ' +
                                    '.styles_starRating__3l6qP, ' +
                                    'div[data-star-rating], ' +
                                    '[class*="star"], ' +
                                    '[class*="rating"], ' +
                                    'img[alt*="star"], ' +
                                    'img[alt*="Star"]'
                                );
                                
                                // Try multiple selectors for date
                                const dateEl = card.querySelector(
                                    'time, ' +
                                    '[datetime], ' +
                                    '.review-content__dates, ' +
                                    '.typography_body-m__xgxZ_, ' +
                                    'time[datetime]'
                                );
                                
                                // Try multiple selectors for author
                                const authorEl = card.querySelector(
                                    '.consumer-information__name, ' +
                                    '.typography_heading-xs__qUOHe, ' +
                                    '.styles_consumerName__dP8Um, ' +
                                    '[data-consumer-name], ' +
                                    '.reviewer-name'
                                );

                                // Extract rating
                                let rating = null;
                                if (ratingEl) {
                                    // Try data-star-rating attribute first
                                    const dataRating = ratingEl.getAttribute('data-star-rating') || 
                                                      ratingEl.getAttribute('data-service-review-rating');
                                    if (dataRating) {
                                        rating = parseInt(dataRating);
                                    } else {
                                        // Try to extract from img alt
                                        const img = ratingEl.querySelector('img');
                                        if (img) {
                                            const alt = img.getAttribute('alt') || '';
                                            const match = alt.match(/(\d+)/);
                                            if (match) rating = parseInt(match[1]);
                                        }
                                        // Try class name patterns
                                        if (!rating) {
                                            const classes = ratingEl.className || '';
                                            const classStr = classes.toString();
                                            if (classStr.includes('star-rating-5') || classStr.includes('5-star') || classStr.includes('five')) rating = 5;
                                            else if (classStr.includes('star-rating-4') || classStr.includes('4-star') || classStr.includes('four')) rating = 4;
                                            else if (classStr.includes('star-rating-3') || classStr.includes('3-star') || classStr.includes('three')) rating = 3;
                                            else if (classStr.includes('star-rating-2') || classStr.includes('2-star') || classStr.includes('two')) rating = 2;
                                            else if (classStr.includes('star-rating-1') || classStr.includes('1-star') || classStr.includes('one')) rating = 1;
                                        }
                                        // Try counting stars
                                        if (!rating) {
                                            const stars = ratingEl.querySelectorAll('img[alt*="star"], svg, .star');
                                            if (stars.length > 0) {
                                                rating = stars.length;
                                            }
                                        }
                                    }
                                }

                                // Extract date
                                let date = null;
                                if (dateEl) {
                                    date = dateEl.getAttribute('datetime') || 
                                           dateEl.getAttribute('title') ||
                                           dateEl.getAttribute('data-date') ||
                                           dateEl.textContent?.trim();
                                }

                                // Extract author
                                let author = null;
                                if (authorEl) {
                                    author = authorEl.textContent?.trim() || 
                                            authorEl.innerText?.trim() ||
                                            authorEl.getAttribute('data-consumer-name');
                                }

                                // Extract text
                                const text = (textEl?.textContent || textEl?.innerText || titleEl?.textContent || titleEl?.innerText || '').trim();

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
                        console.log('Stack trace:', e.stack);
                        return [];
                    });
                    
                    console.log(`Extracted ${reviewCards.length} review cards`);
                    
                    // If no cards found with primary selector, try alternative approach
                    if (reviewCards.length === 0) {
                        console.log('No cards found with primary selector, trying alternative extraction...');
                        
                        // Try to find reviews by looking for common Trustpilot patterns
                        const articleCount = await page.$$eval('article', articles => articles.length);
                        console.log(`Found ${articleCount} article elements for alternative extraction`);
                        
                        const alternativeCards = await page.evaluate(() => {
                            const reviews = [];
                            
                            // Look for all article elements
                            const articles = document.querySelectorAll('article');
                            
                            articles.forEach((article, index) => {
                                if (reviews.length >= 20) return; // Limit to 20
                                
                                // Get all text content
                                const text = article.textContent || article.innerText || '';
                                
                                // Skip if text is too short or looks like navigation/header
                                if (text.length < 50 || text.length > 5000) return;
                                
                                // Try to find rating - look for star images or rating indicators
                                let rating = null;
                                
                                // Method 1: Look for img with star in alt
                                const ratingImgs = article.querySelectorAll('img[alt*="star"], img[alt*="Star"], img[alt*="Star"]');
                                if (ratingImgs.length > 0) {
                                    ratingImgs.forEach(img => {
                                        const alt = img.getAttribute('alt') || '';
                                        const match = alt.match(/(\d+)/);
                                        if (match) {
                                            const num = parseInt(match[1]);
                                            if (num >= 1 && num <= 5) rating = num;
                                        }
                                    });
                                }
                                
                                // Method 2: Look for data attributes
                                if (!rating) {
                                    const dataRating = article.getAttribute('data-star-rating') || 
                                                     article.getAttribute('data-rating') ||
                                                     article.getAttribute('data-service-review-rating');
                                    if (dataRating) {
                                        const num = parseInt(dataRating);
                                        if (num >= 1 && num <= 5) rating = num;
                                    }
                                }
                                
                                // Method 3: Count filled stars
                                if (!rating) {
                                    const filledStars = article.querySelectorAll('svg[class*="filled"], img[alt*="filled"], [class*="star"][class*="filled"]');
                                    if (filledStars.length > 0 && filledStars.length <= 5) {
                                        rating = filledStars.length;
                                    }
                                }
                                
                                // Try to find author - look for links or spans with names
                                let author = null;
                                const authorEl = article.querySelector('a[href*="/users/"], [class*="consumer"], [class*="author"], [class*="name"]');
                                if (authorEl) {
                                    author = (authorEl.textContent || authorEl.innerText || '').trim().substring(0, 100);
                                }
                                
                                // Try to find date
                                let date = null;
                                const dateEl = article.querySelector('time, [datetime], [class*="date"]');
                                if (dateEl) {
                                    date = dateEl.getAttribute('datetime') || 
                                           dateEl.getAttribute('title') ||
                                           dateEl.textContent?.trim();
                                }
                                
                                // Only add if we have substantial text
                                if (text.trim().length > 20) {
                                    reviews.push({
                                        text: text.trim().substring(0, 2000), // Limit text length
                                        rating: rating || 3, // Default to 3 if can't determine
                                        date: date,
                                        author: author,
                                    });
                                }
                            });
                            
                            return reviews;
                        });
                        
                        console.log(`Alternative extraction found ${alternativeCards.length} potential reviews`);
                        if (alternativeCards.length > 0) {
                            reviewCards.push(...alternativeCards);
                        }
                    }
                    
                    // Process and add reviews
                    console.log(`Processing ${reviewCards.length} review cards...`);
                    for (const card of reviewCards) {
                        if (reviews.length >= limit) break;
                        
                        // More lenient check - allow reviews with text even if rating is missing
                        if (card.text && card.text.length > 10) {
                            // If no rating found, try to infer from text (look for "5 star", "1 star", etc.)
                            let rating = card.rating;
                            if (!rating) {
                                const textLower = card.text.toLowerCase();
                                if (textLower.includes('5 star') || textLower.includes('five star')) rating = 5;
                                else if (textLower.includes('4 star') || textLower.includes('four star')) rating = 4;
                                else if (textLower.includes('3 star') || textLower.includes('three star')) rating = 3;
                                else if (textLower.includes('2 star') || textLower.includes('two star')) rating = 2;
                                else if (textLower.includes('1 star') || textLower.includes('one star')) rating = 1;
                                else rating = 3; // Default to 3 if can't determine
                            }
                            
                            console.log(`Adding review: rating=${rating}, text length=${card.text.length}`);
                            reviews.push({
                                source: 'trustpilot',
                                rating: rating,
                                text: card.text,
                                date: card.date ? card.date.split('T')[0] : null,
                                author: card.author || null,
                            });
                        } else {
                            console.log(`Skipping card - rating: ${card.rating}, text: ${card.text ? 'yes (' + card.text.length + ' chars)' : 'no'}`);
                        }
                    }
                    console.log(`Total reviews collected so far: ${reviews.length}`);

                    // Remove duplicates
                    const uniqueReviews = [];
                    const seen = new Set();
                    for (const review of reviews) {
                        const key = review.text.substring(0, 50);
                        if (!seen.has(key)) {
                            seen.add(key);
                            uniqueReviews.push(review);
                        }
                    }
                    reviews = uniqueReviews;

                    // Check if we got new reviews
                    if (reviews.length === previousCount) {
                        scrollAttempts++;
                    } else {
                        scrollAttempts = 0;
                    }
                    previousCount = reviews.length;

                    // Try to click "Load more" button
                    try {
                        const loadMoreButton = await page.$('button[data-pagination-button-next], .pagination-link--next');
                        if (loadMoreButton) {
                            await loadMoreButton.click();
                            await page.waitForTimeout(2000);
                        } else {
                            // Scroll to bottom to load more
                            await page.evaluate(() => {
                                window.scrollTo(0, document.body.scrollHeight);
                            });
                            await page.waitForTimeout(2000);
                        }
                    } catch (e) {
                        // No load more button, just scroll
                        await page.evaluate(() => {
                            window.scrollTo(0, document.body.scrollHeight);
                        });
                        await page.waitForTimeout(2000);
                    }

                    if (reviews.length >= limit) break;
                }

                // Limit reviews
                reviews = reviews.slice(0, limit);

                if (reviews.length > 0) {
                    break; // Found working URL and reviews
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
                    error: "Found Trustpilot page but could not extract reviews. The page structure might have changed."
                });
            }
        }

        // Log results for debugging
        console.log(`Found ${reviews.length} reviews for ${domain}`);
        if (reviews.length > 0) {
            console.log('Sample review:', reviews[0]);
        }

        res.json({
            rating: overallRating,
            total_reviews: totalReviews,
            reviews: reviews,
        });
    } catch (error) {
        await browser.close();
        res.status(500).json({ error: error.message });
    }
});

// Health check endpoint
app.get("/health", (req, res) => {
    res.json({ status: "ok", service: "trustpilot-scraper", port: 4000 });
});

app.listen(4000, () => console.log("Trustpilot scraper running on 4000"));
