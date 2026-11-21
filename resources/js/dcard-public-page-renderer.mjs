import { chromium } from 'playwright';

const [, , targetUrl, expectedHost, pinnedIp, timeoutValue, browserChannel] = process.argv;
const timeoutMilliseconds = Math.max(1, Math.min(15_000, Number.parseInt(timeoutValue ?? '10000', 10)));
const startedAt = Date.now();

function isValidTarget() {
    try {
        const url = new URL(targetUrl);
        const validHost = expectedHost === 'dcard.tw' || expectedHost.endsWith('.dcard.tw');
        const validIpv4 = /^(?:\d{1,3}\.){3}\d{1,3}$/.test(pinnedIp);

        return validHost && validIpv4 && url.protocol === 'https:' && url.hostname === expectedHost && url.port === '';
    } catch {
        return false;
    }
}

function writeResult(result) {
    process.stdout.write(`${JSON.stringify(result)}\n`);
}

if (!isValidTarget()) {
    writeResult({ error: 'invalid_target' });
    process.exit(0);
}

let browser;

try {
    const args = [
        '--no-first-run',
        '--disable-background-networking',
        '--disable-component-update',
        '--disable-default-apps',
        '--disable-sync',
        `--host-resolver-rules=MAP ${expectedHost} ${pinnedIp},EXCLUDE localhost`,
    ];
    const launchOptions = { headless: true, args };

    if (browserChannel === 'chromium') {
        browser = await chromium.launch(launchOptions);
    } else {
        browser = await chromium.launch({ ...launchOptions, channel: 'chrome' });
    }

    const context = await browser.newContext({ serviceWorkers: 'block' });
    await context.routeWebSocket('**/*', (webSocket) => webSocket.close({ code: 1008, reason: 'Monitoring does not use WebSockets.' }));
    const page = await context.newPage();

    await page.route('**/*', async (route) => {
        try {
            const requestUrl = new URL(route.request().url());

            if (['GET', 'HEAD'].includes(route.request().method())
                && requestUrl.protocol === 'https:'
                && requestUrl.hostname === expectedHost
                && requestUrl.port === '') {
                await route.continue();

                return;
            }

            await route.abort('blockedbyclient');
        } catch {
            await route.abort('blockedbyclient');
        }
    });

    page.setDefaultNavigationTimeout(timeoutMilliseconds);
    page.setDefaultTimeout(timeoutMilliseconds);
    const response = await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: timeoutMilliseconds });
    await page.waitForTimeout(Math.min(1_200, Math.max(0, timeoutMilliseconds - 250)));

    const [html, visibleText, contentType, finalUrl] = await Promise.all([
        page.content(),
        page.locator('body').innerText().catch(() => ''),
        response?.headerValue('content-type') ?? Promise.resolve(null),
        Promise.resolve(page.url()),
    ]);

    writeResult({
        status: response?.status() ?? null,
        final_url: finalUrl,
        content_type: contentType,
        html: html.slice(0, 250_000),
        visible_text: visibleText.slice(0, 80_000),
        elapsed_ms: Date.now() - startedAt,
    });
} catch {
    writeResult({ error: 'browser_navigation_failed' });
} finally {
    if (browser) {
        await browser.close().catch(() => {});
    }
}
