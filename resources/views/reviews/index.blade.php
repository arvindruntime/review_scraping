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
            
            /* /////// reviews in tabular formate css */
            .reviews-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .reviews-table th,
        .reviews-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }

        .reviews-table th {
            background: #f4f6f8;
            font-weight: 700;
            color: #2c3e50;
            font-size: 14px;
            text-transform: uppercase;
        }

        .reviews-table td {
            font-size: 14px;
            color: #34495e;
        }

        .reviews-table tr:hover {
            background: #f9fbfc;
        }

        .rating-stars {
            color: #FFB800;
            font-size: 16px;
        }

        .source-badge-table {
            padding: 6px 12px;
            border-radius: 14px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .source-badge-table.google {
            background: #4285F4;
            color: #fff;
        }

        .source-badge-table.trustpilot {
            background: #00B67A;
            color: #fff;
        }


        /* /////////////// 02-01-2026// */

        /* ===== Dark Hero Section (Screenshot Style) ===== */
.hero-dark {
    min-height: 85vh;
    background: radial-gradient(circle at top left, #3b1d5a, #0b0c10 60%);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    padding: 60px 20px;
    color: #fff;
}

.pill {
    background: rgba(255,255,255,0.1);
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 13px;
    margin-bottom: 20px;
    color: #c7c7ff;
}

.hero-title-dark {
    font-size: 56px;
    font-weight: 800;
    margin-bottom: 20px;
}

.hero-title-dark span {
    background: linear-gradient(90deg, #6a5cff, #ff4d8d);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.hero-subtitle-dark {
    max-width: 750px;
    font-size: 18px;
    line-height: 1.6;
    color: #b5b5c3;
    margin-bottom: 40px;
}

.search-dark {
    display: flex;
    gap: 14px;
    background: #15161a;
    padding: 12px;
    border-radius: 16px;
    width: 100%;
    max-width: 720px;
    box-shadow: 0 0 0 1px rgba(255,255,255,0.08);
}

.search-input-dark {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 10px;
    background: #0f1014;
    border-radius: 12px;
    padding: 0 14px;
}

.search-input-dark input {
    background: transparent;
    border: none;
    color: #fff;
    font-size: 16px;
    width: 100%;
    padding: 14px 0;
}

.search-input-dark input:focus {
    outline: none;
}

.link-icon {
    opacity: 0.6;
}

.search-dark button {
    background: linear-gradient(90deg, #6a5cff, #ff4d8d);
    border: none;
    border-radius: 12px;
    padding: 0 28px;
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    transition: all 0.3s;
}

.search-dark button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(106,92,255,0.4);
}

.source-dark {
    margin-top: 26px;
    display: flex;
    gap: 20px;
}

.source-dark label {
    background: rgba(255,255,255,0.08);
    padding: 10px 18px;
    border-radius: 12px;
    cursor: pointer;
    font-weight: 600;
}

.source-dark input {
    margin-right: 6px;
}

/* Mobile */
@media (max-width: 768px) {
    .hero-title-dark {
        font-size: 36px;
    }

    .search-dark {
        flex-direction: column;
    }

    .search-dark button {
        width: 100%;
        padding: 14px;
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
        
    <!-- replaced hero section -->
     <div class="hero-dark">
    <span class="pill">● New : AI COMPETITOR ANALYSIS</span>

    <h1 class="hero-title-dark">
        Find reviews you can <span>trust.</span>
    </h1>

    <p class="hero-subtitle-dark">
        AI-powered analysis aggregating thousands of reviews into actionable intelligence.
        Understand sentiment, reliability and trust in seconds.
    </p>

    <div class="search-dark">
        <div class="search-input-dark">
            <span class="link-icon">🔗</span>
            <input
                type="text"
                id="domain"
                placeholder="avis.com.au"
            >
        </div>

        <button id="search-btn" onclick="scarpeReviews()">
            ⚡ Generate Report
        </button>
    </div>

    <div class="source-dark">
        <label>
            <input type="checkbox" id="filter-trustpilot" checked>
            ⭐ Trustpilot
        </label>

        <label>
            <input type="checkbox" id="filter-google" checked>
            🌐 Google Reviews
        </label>
    </div>
</div>


        <div id="error-message" class="error" style="display: none;"></div>
        <div id="loading" class="loading" style="display: none;">Loading reviews</div>

        <div id="results" style="display: none;">
            <div class="stats-section">
                <h2 class="stats-title">Stats</h2>
                <div class="stats-grid" id="stats-grid"></div>
            </div>

            <div class="source-filters">
                <button class="source-filter-btn active" onclick="filterAndFetch('all')">All Reviews</button>
                <button class="source-filter-btn" onclick="filterAndFetch('google')">Google</button>
                <button class="source-filter-btn" onclick="filterAndFetch('trustpilot')">Trustpilot</button>
            </div>

            <div id="reviews-container">
                <div style="overflow-x:auto;">
                    <table class="reviews-table" id="reviews-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Source</th>
                                <th>Reviewer Name</th>
                                <th>Rating (Stars)</th>
                                <th>Date of review</th>
                                <th>Review Description</th>
                            </tr>
                        </thead>
                        <tbody id="reviews-table-body">
                            <!-- Rows injected by JS -->
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script>
        
        
    function renderStars(rating) {
        rating = Math.round(rating * 2) / 2; // allow .5
        let stars = '';

        for (let i = 1; i <= 5; i++) {
            if (rating >= i) {
                stars += '★';
            } else if (rating >= i - 0.5) {
                stars += '☆'; // half star fallback
            } else {
                stars += '☆';
            }
        }
        return stars;
    }
        let allReviews = [];
        let currentFilter = 'all';

    async function scarpeReviews() {
        const userInput = document.getElementById('domain').value.trim();
        const domain = sanitizeDomain(userInput);
        if (!domain) {
            showError('Please enter a valid domain (example: doman.com)');
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

        // UI start
        document.getElementById('error-message').style.display = 'none';
        document.getElementById('loading').style.display = 'block';
        document.getElementById('loading').innerText = 'Fetching reviews, please wait...';
        document.getElementById('results').style.display = 'none';
        document.getElementById('search-btn').disabled = true;

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 90000);

            const response = await fetch('/api/reviews/scrape', {
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

            const contentType = response.headers.get('content-type');
            let data;

            if (contentType && contentType.includes('application/json')) {
                data = await response.json();
            } else {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error('Invalid server response');
            }

            console.log('Scrape response data here:', data);
            
          
            if (data.status === 'processing') {
                document.getElementById('loading').innerText =
                    'Scraping reviews in background… Please wait.';

                // if the server returned partial results/counts include them immediately
                if (data.reviews?.length > 0 || (data.google_reviews || 0) > 0 || (data.trustpilot_reviews || 0) > 0) {
                    allReviews = data.reviews || [];
                    displayResults(data);
                }

                schedulePoll(15000);

                return;
            }
           

            if (!response.ok) {
                throw new Error(data.error || 'Failed to fetch reviews');
            }
            // Success
            allReviews = data.reviews || [];
            console.log('All reviews after scrape:', data);
            displayResults(data);

        } catch (error) {
            if (error.name === 'AbortError') {
                showError(
                    'Request timed out. Reviews are still being fetched in background. Please wait...'
                );
            } else {
                showError(error.message || 'An unexpected error occurred.');
            }
            console.error('Error fetching reviews:', error);
        } finally {
            /*
            ⚠️ IMPORTANT:
            Do NOT hide loader or enable button here
            because when status=processing we returned early
            */
            document.getElementById('search-btn').disabled = false;
        }
    }
    
    
    async function getReviews() {
        const get_domain = document.getElementById('domain').value.trim();
        if (!get_domain) {
            showError('Please enter a domain');
            return;
        }

        const get_sources = [];
        if (document.getElementById('filter-google').checked) {
            get_sources.push('google');
        }
        if (document.getElementById('filter-trustpilot').checked) {
            get_sources.push('trustpilot');
        }

        if (get_sources.length === 0) {
            showError('Please select at least one source');
            return;
        }

        // UI start
        document.getElementById('error-message').style.display = 'none';
        document.getElementById('loading').style.display = 'block';
        document.getElementById('loading').innerText = 'Fetching reviews, please wait...';
        document.getElementById('results').style.display = 'none';
        document.getElementById('search-btn').disabled = true;

        try {
            const get_controller = new AbortController();
            const timeoutId = setTimeout(() => get_controller.abort(), 90000);

            const get_response = await fetch('/api/get-reviews', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    domain: get_domain,
                    sources: get_sources,
                    limit: 20
                }),
                signal: get_controller.signal
            });

            clearTimeout(timeoutId);

            const contentType = get_response.headers.get('content-type');
            let get_data;

            if (contentType && contentType.includes('application/json')) {
                get_data = await get_response.json();
            } else {
                const text = await get_response.text();
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error('Invalid server response');
            }

            if (get_data.status === 'processing') {
                document.getElementById('loading').innerText =
                    'Scraping reviews in background… this may take up to 1–2 minutes.';

                // if server returned partial results/counts include them immediately
                if (get_data.reviews?.length > 0 || (get_data.google_reviews || 0) > 0 || (get_data.trustpilot_reviews || 0) > 0) {
                    allReviews = get_data.reviews || [];
                    displayResults(get_data);
                }

                // schedule next poll
                schedulePoll(15000);

                return;
            }

            // PARTIAL: some sources complete, others still processing (show current data and keep polling)
            if (get_data.status === 'partial') {
                document.getElementById('loading').innerText =
                    'Partial results available — other sources still scraping...';
                // show current results immediately
                allReviews = get_data.reviews || [];
                displayResults(get_data);
                // continue polling for final results
                schedulePoll(15000);
                return;
            }

            if (get_data.status === 'completed') {
                document.getElementById('loading').style.display = 'none';
                clearPoll();
            }

            if (!get_response.ok) {
                throw new Error(get_data.error || 'Failed to fetch reviews');
            }
            // Success
            allReviews = get_data.reviews || [];
            displayResults(get_data);

        } catch (error) {
            if (error.name === 'AbortError') {
                showError(
                    'Request timed out. Reviews are still being fetched in background. Please wait...'
                );
            } else {
                showError(error.message || 'An unexpected error occurred.');
            }
            console.error('Error fetching reviews:', error);
        } finally {
            document.getElementById('search-btn').disabled = false;
        }
    }

        let _pollTimeoutId = null;
        function schedulePoll(ms = 15000) {
            console.debug('schedulePoll:', ms);
            clearPoll();
            _pollTimeoutId = setTimeout(() => {
                console.debug('polling getReviews');
                getReviews();
            }, ms);
        }
        function clearPoll() {
            if (_pollTimeoutId) {
                clearTimeout(_pollTimeoutId);
                _pollTimeoutId = null;
            }
        }

        // On page load, if domain is present, try to fetch results (useful after reload)
        document.addEventListener('DOMContentLoaded', () => {
            const d = document.getElementById('domain').value.trim();
            if (d) {
                // don't auto-show errors; simply try to fetch existing completed/processing search
                getReviews();
            }
        });

        function displayResults(data) {
            
            console.log('Displaying results with data:', data);

            // keep a local copy of fetched reviews so filter works immediately
            allReviews = data.reviews || allReviews;
            
            if (data.status === 'completed') {
                document.getElementById('loading').style.display = 'none';
                clearPoll();
            }
            
            
            const limit = data.limit || 20;
            // Display stats
            const statsGrid = document.getElementById('stats-grid');
            statsGrid.innerHTML = '';
            
            statsGrid.innerHTML = `
                <div style="grid-column:1/-1;font-weight:700;font-size:18px;">
                    Reviews for: ${data.domain}
                </div>
            `;

            // Google card: show if we have rating info or a count > 0
            if ((data.ratings && data.ratings.google) || (data.google_reviews > 0)) {
                const g = (data.ratings && data.ratings.google) ? data.ratings.google : { rating: 0, total: data.google_reviews };
                statsGrid.innerHTML += `
                    <div class="stat-card">
                        <div class="stat-label">Google Reviews</div>
                        <div class="stat-value">
                            ${g.rating ?? '-'}
                            <span style="color:#FFB800; font-size:18px;">
                                ${renderStars(g.rating || 0)}
                            </span>
                            (${g.total ?? data.google_reviews})
                        </div>
                        <div class="stat-note">Google Reviews</div>
                    </div>
                `;
            }

            // Trustpilot card: show if we have rating info or a count > 0
            if ((data.ratings && data.ratings.trustpilot) || (data.trustpilot_reviews > 0)) {
                const t = (data.ratings && data.ratings.trustpilot) ? data.ratings.trustpilot : { rating: 0, total: data.trustpilot_reviews };
                statsGrid.innerHTML += `
                    <div class="stat-card">
                        <div class="stat-label">Trustpilot Reviews</div>
                        <div class="stat-value">
                            ${t.rating ?? '-'}
                            <span style="color:#FFB800; font-size:18px;">
                                ${renderStars(t.rating || 0)}
                            </span>
                            (${t.total ?? data.trustpilot_reviews})
                        </div>
                        <div class="stat-note">Trustpilot Reviews</div>
                    </div>
                `;
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

        document.querySelectorAll('.source-filter-btn').forEach(btn => {
            btn.classList.remove('active');
            if (
                (source === 'all' && btn.textContent.includes('All')) ||
                (source === 'google' && btn.textContent === 'Google') ||
                (source === 'trustpilot' && btn.textContent === 'Trustpilot')
            ) {
                btn.classList.add('active');
            }
        });

        const filteredReviews = source === 'all'
            ? allReviews
            : allReviews.filter(r => r.source === source);

        const tbody = document.getElementById('reviews-table-body');
        tbody.innerHTML = '';

        if (filteredReviews.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align:center; padding:30px;">
                        No reviews found
                    </td>
                </tr>
            `;
            return;
        }

        filteredReviews.forEach((review, index) => {
            const stars = '⭐'.repeat(review.rating || 0);

            tbody.innerHTML += `
                <tr>
                    <td>${index + 1}</td>
                    <td>
                        <span class="source-badge-table ${review.source}">
                            ${review.source}
                        </span>
                    </td>
                    <td>${escapeHtml(review.author || 'Anonymous')}</td>
                    <td class="rating-stars">${stars}</td>
                    <td>${review.date || '-'}</td>
                    <td>${escapeHtml(review.text || '')}</td>
                    
                </tr>
            `;
        });
    }

        // When user clicks a source filter button, update the checkbox state and re-fetch latest data for that source(s)
        function filterAndFetch(source) {
            // set appropriate checkboxes so getReviews sends correct sources
            if (source === 'all') {
                document.getElementById('filter-google').checked = true;
                document.getElementById('filter-trustpilot').checked = true;
            } else if (source === 'google') {
                document.getElementById('filter-google').checked = true;
                document.getElementById('filter-trustpilot').checked = false;
            } else if (source === 'trustpilot') {
                document.getElementById('filter-google').checked = false;
                document.getElementById('filter-trustpilot').checked = true;
            }

            // visually apply filter immediately (so UI is responsive)
            filterReviews(source);

            // fetch latest reviews for the selected source(s) and update stats
            getReviews();
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
                scarpeReviews();
            }
        });


    function sanitizeDomain(input) {
        if (!input) return '';

        let value = input
            .trim()
            .toLowerCase();

        // Remove protocol
        value = value.replace(/^https?:\/\//, '');

        // Remove everything after /
        value = value.split('/')[0];

        // Remove www.
        value = value.replace(/^www\./, '');

        // If user entered only "www" or invalid
        if (!value || value === 'www') {
            return '';
        }

        // Reduce subdomains → root domain
        const parts = value.split('.');
        if (parts.length > 2) {
            value = parts.slice(-2).join('.');
        }

       return value;
    }

    </script>
</body>
</html>
