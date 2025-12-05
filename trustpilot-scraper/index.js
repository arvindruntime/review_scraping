import express from "express";
import { chromium } from "playwright";

const app = express();
app.use(express.json());

app.post("/scrape-trustpilot", async (req, res) => {
    const { url } = req.body;
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();

    try {
        await page.goto("https://www.trustpilot.com/review/" + url, { timeout: 60000 });

        const reviews = await page.$$eval('.review-card', cards =>
            cards.map(card => ({
                title: card.querySelector('.review-content__title')?.innerText || "",
                text: card.querySelector('.review-content__text')?.innerText || "",
                rating: card.querySelector('.star-rating img')?.getAttribute('alt') || "",
                date: card.querySelector('time')?.getAttribute('datetime') || ""
            }))
        );

        await browser.close();

        res.json({ reviews });
    } catch (error) {
        await browser.close();
        res.json({ error: error.message });
    }
});

app.listen(4000, () => console.log("Trustpilot scraper running on 4000"));
