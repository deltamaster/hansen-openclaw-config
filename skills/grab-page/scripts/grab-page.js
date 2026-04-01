#!/usr/bin/env node
/**
 * grab-page - Fetch a web page using Playwright with stealth anti-detection
 * Usage: node grab-page.js <url> [--screenshot] [--html]
 */

const { chromium } = require('playwright');

const args = process.argv.slice(2);

if (args.length === 0) {
  console.error('Usage: node grab-page.js <url> [--screenshot] [--html]');
  console.error('  <url>       - The URL to fetch');
  console.error('  --screenshot - Take a screenshot instead of extracting content');
  console.error('  --html      - Output HTML content (default: text content)');
  process.exit(1);
}

const url = args[0];
const takeScreenshot = args.includes('--screenshot');
const outputHtml = args.includes('--html');

async function applyStealth(page) {
  // Evade bot detection by masking automation fingerprints
  await page.addInitScript(() => {
    // Mock webdriver property
    Object.defineProperty(navigator, 'webdriver', {
      get: () => false,
    });

    // Mock plugins
    Object.defineProperty(navigator, 'plugins', {
      get: () => [
        {
          name: 'Chrome PDF Plugin',
          description: 'Portable Document Format',
          filename: 'internal-pdf-viewer',
        },
        {
          name: 'Chrome PDF Viewer',
          description: '',
          filename: 'mhjfbmdgcfjbbpaeojofohoefgiehjai',
        },
        {
          name: 'Native Client',
          description: '',
          filename: 'internal-nacl-plugin',
        },
      ],
    });

    // Mock languages
    Object.defineProperty(navigator, 'languages', {
      get: () => ['en-US', 'en', 'zh-CN', 'zh'],
    });

    // Mock permissions
    const originalQuery = window.navigator.permissions.query;
    window.navigator.permissions.query = (parameters) =>
      parameters.name === 'notifications'
        ? Promise.resolve({ state: Notification.permission })
        : originalQuery(parameters);

    // Remove automation indicators
    window.navigator.chrome = window.navigator.chrome || {};
    window.navigator.chrome.app = {};
    window.navigator.chrome.csi = () => {};
    window.navigator.chrome.loadTimes = () => {};

    // Mock hardware concurrency
    Object.defineProperty(navigator, 'hardwareConcurrency', {
      get: () => 8,
    });

    // Mock device memory
    Object.defineProperty(navigator, 'deviceMemory', {
      get: () => 8,
    });

    // Clear performance entries
    if (window.performance) {
      window.performance.clearResourceTimings = () => {};
      window.performance.getEntriesByType = () => [];
      window.performance.getEntriesByName = () => [];
    }

    // Mock battery
    if ('getBattery' in navigator) {
      navigator.getBattery = () => Promise.resolve({
        charging: true,
        chargingTime: 0,
        dischargingTime: Infinity,
        level: 1.0,
      });
    }

    // Mock media devices
    if (navigator.mediaDevices) {
      navigator.mediaDevices.enumerateDevices = () => Promise.resolve([
        { kind: 'audioinput', deviceId: 'default', label: '', groupId: 'default' },
        { kind: 'videoinput', deviceId: 'default', label: '', groupId: 'default' },
      ]);
    }

    // Mock screen
    Object.defineProperty(screen, 'colorDepth', { get: () => 24 });
    Object.defineProperty(screen, 'pixelDepth', { get: () => 24 });

    // Mock canvas fingerprint
    const originalGetContext = HTMLCanvasElement.prototype.getContext;
    HTMLCanvasElement.prototype.getContext = function(type, ...args) {
      const context = originalGetContext.call(this, type, ...args);
      if (type === '2d') {
        const originalFillText = context.fillText;
        context.fillText = (...textArgs) => {
          textArgs[1] += 0.5;
          textArgs[2] += 0.5;
          return originalFillText.apply(context, textArgs);
        };
      }
      return context;
    };
  });
}

async function grabPage() {
  const browser = await chromium.launch({
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-blink-features=AutomationControlled',
      '--disable-dev-shm-usage',
      '--disable-gpu',
    ]
  });

  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    locale: 'en-US',
  });

  const page = await context.newPage();

  // Apply stealth evasions
  await applyStealth(page);

  try {
    console.error(`Fetching: ${url}`);
    await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });

    if (takeScreenshot) {
      const screenshotPath = '/tmp/grab-page-screenshot.png';
      await page.screenshot({ path: screenshotPath, fullPage: true });
      console.log(screenshotPath);
    } else if (outputHtml) {
      const html = await page.content();
      console.log(html);
    } else {
      // Extract text content
      const content = await page.evaluate(() => {
        const body = document.body;
        // Remove script and style elements
        const clone = body.cloneNode(true);
        clone.querySelectorAll('script, style, noscript, iframe, nav, header, footer, [role="navigation"], [role="banner"], [role="contentinfo"]').forEach(el => el.remove());
        return clone.innerText || clone.textContent || '';
      });
      console.log(content.trim());
    }
  } catch (error) {
    console.error(`Error: ${error.message}`);
    process.exit(1);
  } finally {
    await browser.close();
  }
}

grabPage();
