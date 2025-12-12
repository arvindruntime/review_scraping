<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- <meta name="csrf-token" content="{{ csrf_token() }}"> -->
    <title>Review Scraper</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
            min-height: 100vh;
            padding: 0;
        }

        .header {
            background: #2c3e50;
            color: white;
            padding: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo::before {
            content: '⭐';
            font-size: 28px;
            color: #00B67A;
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        /* Trustpilot-style Search Section */
        .hero-section {
            text-align: center;
            margin-bottom: 60px;
        }

        .hero-title {
            font-size: 48px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 15px;
            line-height: 1.2;
        }

        .hero-subtitle {
            font-size: 20px;
            color: #7f8c8d;
            margin-bottom: 40px;
        }

        .search-box {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            max-width: 800px;
            margin: 0 auto;
        }

        .search-input-wrapper {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .search-input {
            flex: 1;
            padding: 18px 24px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: #00B67A;
            box-shadow: 0 0 0 3px rgba(0, 182, 122, 0.1);
        }

        .search-btn {
            background: #00B67A;
            color: white;
            border: none;
            padding: 18px 36px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-btn:hover:not(:disabled) {
            background: #00a066;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 182, 122, 0.3);
        }

        .search-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .search-btn::before {
            content: '🔍';
            font-size: 18px;
        }

        .source-filters-inline {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .filter-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            background: #f8f9fa;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .filter-checkbox:hover {
            background: #e9ecef;
        }

        .filter-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #00B67A;
        }

        .filter-checkbox label {
            margin: 0;
            cursor: pointer;
            font-weight: 500;
            color: #2c3e50;
        }

        .error {
            background: #ffebee;
            color: #c62828;
            padding: 20px;
            border-radius: 12px;
            margin: 20px auto;
            max-width: 800px;
            border-left: 4px solid #c62828;
        }

        .loading {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
            font-size: 18px;
        }

        .loading::after {
            content: '...';
            animation: dots 1.5s steps(4, end) infinite;
        }

        @keyframes dots {
            0%, 20% { content: '.'; }
            40% { content: '..'; }
            60%, 100% { content: '...'; }
        }

        /* Stats Section */
        .stats-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .stats-title {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 25px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px;
            border-radius: 12px;
            border-left: 5px solid #00B67A;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .stat-label {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-note {
            margin-top: 10px;
            font-size: 13px;
            color: #7f8c8d;
        }

        /* Source Filter Buttons */
        .source-filters {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .source-filter-btn {
            padding: 12px 24px;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 15px;
            font-weight: 600;
            color: #2c3e50;
        }

        .source-filter-btn:hover {
            border-color: #00B67A;
            color: #00B67A;
            transform: translateY(-2px);
        }

        .source-filter-btn.active {
            background: #00B67A;
            color: white;
            border-color: #00B67A;
        }

        /* Review Cards - Trustpilot Style */
        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .review-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border: 1px solid #e9ecef;
        }

        .review-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }

        .review-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .reviewer-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #00B67A 0%, #00a066 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .reviewer-info {
            flex: 1;
        }

        .reviewer-name {
            font-weight: 600;
            color: #2c3e50;
            font-size: 16px;
            margin-bottom: 5px;
        }

        .review-rating {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .star {
            color: #FFB800;
            font-size: 18px;
        }

        .star.empty {
            color: #e0e0e0;
        }

        .review-text {
            color: #34495e;
            line-height: 1.6;
            margin-bottom: 15px;
            font-size: 15px;
        }

        .review-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }

        .review-date {
            color: #7f8c8d;
            font-size: 13px;
        }

        .source-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .source-badge.google {
            background: #4285F4;
            color: white;
        }

        .source-badge.trustpilot {
            background: #00B67A;
            color: white;
        }

        .no-reviews {
            text-align: center;
            padding: 80px 20px;
            color: #7f8c8d;
        }

        .no-reviews-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .no-reviews-text {
            font-size: 18px;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .hero-title {
                font-size: 32px;
            }

            .hero-subtitle {
                font-size: 16px;
            }

            .search-box {
                padding: 25px;
            }

            .search-input-wrapper {
                flex-direction: column;
            }

            .search-btn {
                width: 100%;
                justify-content: center;
            }

            .reviews-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="logo">Review Scraper</div>
        </div>
    </div>

    <div class="main-container">
        <div class="hero-section">
            <h1 class="hero-title">Find reviews you can trust</h1>
            <p class="hero-subtitle">Discover, read, and analyze reviews from multiple sources</p>

            <div class="search-box">
                <div class="search-input-wrapper">
                    <input 
                        type="text" 
                        id="domain" 
                        class="search-input" 
                        placeholder="Search company or domain (e.g., ubereats.com)" 
                        value=""
                    >
                    <button id="search-btn" class="search-btn" onclick="fetchReviews()">Search</button>
                </div>

                <div class="source-filters-inline">
                    <div class="filter-checkbox">
                        <input type="checkbox" id="filter-google" checked>
                        <label for="filter-google">Google Reviews</label>
                    </div>
                    <div class="filter-checkbox">
                        <input type="checkbox" id="filter-trustpilot" checked>
                        <label for="filter-trustpilot">Trustpilot</label>
                    </div>
                </div>
            </div>
        </div>

        <div id="error-message" class="error" style="display: none;"></div>
        <div id="loading" class="loading" style="display: none;">Loading reviews</div>

        <div id="results" style="display: none;">
            <div class="stats-section">
                <h2 class="stats-title">Statistics</h2>
                <div class="stats-grid" id="stats-grid"></div>
            </div>

            <div class="source-filters">
                <button class="source-filter-btn active" onclick="filterReviews('all')">All Reviews</button>
                <button class="source-filter-btn" onclick="filterReviews('google')">Google</button>
                <button class="source-filter-btn" onclick="filterReviews('trustpilot')">Trustpilot</button>
            </div>

            <div id="reviews-container">
                <div class="reviews-grid" id="reviews-grid"></div>
            </div>
        </div>
    </div>

    <script>
        let allReviews = [];
        let currentFilter = 'all';

        async function fetchReviews() {
            const domain = document.getElementById('domain').value.trim();
            if (!domain) {
                showError('Please enter a domain');
                return;
            }

            const sources = [];
            if (document.getElementById('filter-google').checked) {
                sources.push('google');
            }
            if (document.getElementById('filter-trustpilot').checked) {
                sources.push('trustpilot');
            }

            if (sources.length === 0) {
                showError('Please select at least one source');
                return;
            }

            document.getElementById('error-message').style.display = 'none';
            document.getElementById('loading').style.display = 'block';
            document.getElementById('results').style.display = 'none';
            document.getElementById('search-btn').disabled = true;

            try {
                // Set a longer timeout for the fetch request (90 seconds)
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 90000);
                
                const response = await fetch('/api/reviews/fetch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({
                        domain: domain,
                        sources: sources,
                        limit: 20
                    }),
                    signal: controller.signal
                });
                
                clearTimeout(timeoutId);

                // Check content type before parsing JSON
                const contentType = response.headers.get('content-type');
                let data;
                
                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    // If response is not JSON, get text to see what we got
                    const text = await response.text();
                    console.error('Non-JSON response received:', text.substring(0, 500));
                    throw new Error('Server returned an invalid response. Please check server logs or contact support.');
                }

                if (!response.ok) {
                    throw new Error(data.error || 'Failed to fetch reviews');
                }

                allReviews = data.reviews || [];
                displayResults(data);
            } catch (error) {
                // Handle different types of errors
                if (error.name === 'AbortError' || error.message.includes('timeout')) {
                    showError('Request timed out. The scraping process is taking longer than expected. Please try again or contact support.');
                } else if (error instanceof SyntaxError && error.message.includes('JSON')) {
                    showError('Server returned an invalid response. This may indicate a server configuration issue (504 Gateway Timeout). Please check that the Trustpilot scraper service is running and that server timeouts are configured correctly.');
                } else {
                    showError(error.message || 'An unexpected error occurred. Please try again.');
                }
                console.error('Error fetching reviews:', error);
            } finally {
                document.getElementById('loading').style.display = 'none';
                document.getElementById('search-btn').disabled = false;
            }
        }

        function displayResults(data) {
            const limit = data.limit || 20;
            // Display stats
            const statsGrid = document.getElementById('stats-grid');
            statsGrid.innerHTML = '';

            if (data.ratings) {
                if (data.ratings.google) {
                    const googleTotal = data.ratings.google.total || 0;
                    statsGrid.innerHTML += `
                        <div class="stat-card">
                            <div class="stat-label">Google Rating</div>
                            <div class="stat-value">⭐ ${data.ratings.google.rating} (${googleTotal.toLocaleString()} reviews)</div>
                            ${googleTotal > limit ? `<div class="stat-note">Showing latest ${limit} reviews from Google</div>` : ''}
                        </div>
                    `;
                }
                if (data.ratings.trustpilot) {
                    const trustpilotTotal = data.ratings.trustpilot.total || 0;
                    statsGrid.innerHTML += `
                        <div class="stat-card">
                            <div class="stat-label">Trustpilot Rating</div>
                            <div class="stat-value">⭐ ${data.ratings.trustpilot.rating} (${trustpilotTotal.toLocaleString()} reviews)</div>
                            ${trustpilotTotal > limit ? `<div class="stat-note">Showing latest ${limit} reviews from Trustpilot</div>` : ''}
                        </div>
                    `;
                }
            }

            statsGrid.innerHTML += `
                <div class="stat-card">
                    <div class="stat-label">Total Reviews Found</div>
                    <div class="stat-value">${data.total_reviews || 0}</div>
                    <div class="stat-note">Showing up to ${limit} recent reviews per source.</div>
                </div>
            `;

            // Display reviews
            filterReviews(currentFilter);
            document.getElementById('results').style.display = 'block';
        }

        function filterReviews(source) {
            currentFilter = source;

            // Update active button
            document.querySelectorAll('.source-filter-btn').forEach(btn => {
                btn.classList.remove('active');
                const btnText = btn.textContent.toLowerCase().trim();
                if ((source === 'all' && btnText.includes('all')) ||
                    (source === 'google' && btnText === 'google') ||
                    (source === 'trustpilot' && btnText === 'trustpilot')) {
                    btn.classList.add('active');
                }
            });

            // Filter reviews
            const filteredReviews = source === 'all' 
                ? allReviews 
                : allReviews.filter(r => r.source === source);

            // Display filtered reviews
            const reviewsGrid = document.getElementById('reviews-grid');
            if (filteredReviews.length === 0) {
                reviewsGrid.innerHTML = `
                    <div class="no-reviews" style="grid-column: 1 / -1;">
                        <div class="no-reviews-icon">🔍</div>
                        <div class="no-reviews-text">No reviews found</div>
                    </div>
                `;
            } else {
                reviewsGrid.innerHTML = filteredReviews.map(review => {
                    const authorInitial = review.author ? review.author.charAt(0).toUpperCase() : '?';
                    const stars = Array.from({ length: 5 }, (_, i) => 
                        i < review.rating 
                            ? '<span class="star">⭐</span>' 
                            : '<span class="star empty">☆</span>'
                    ).join('');

                    return `
                        <div class="review-card">
                            <div class="review-header">
                                <div class="reviewer-avatar">${authorInitial}</div>
                                <div class="reviewer-info">
                                    <div class="reviewer-name">${review.author || 'Anonymous'}</div>
                                    <div class="review-rating">${stars}</div>
                                </div>
                            </div>
                            <div class="review-text">${escapeHtml(review.text)}</div>
                            <div class="review-footer">
                                <div class="review-date">${review.date || 'Date not available'}</div>
                                <span class="source-badge ${review.source}">${review.source}</span>
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function showError(message) {
            const errorDiv = document.getElementById('error-message');
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
        }

        // Allow Enter key to trigger search
        document.getElementById('domain').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                fetchReviews();
            }
        });
    </script>
</body>
</html>
