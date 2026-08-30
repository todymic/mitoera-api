import { test, expect, Page, Locator } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

// Intercept mitoera-render.js to serve the local branch version
async function useLocalRenderJs(page: Page) {
  const localJs = fs.readFileSync(
    path.join(__dirname, '../../public/mitoera-render.js'), 'utf-8'
  );
  await page.route('**/mitoera-render.js', route =>
    route.fulfill({ contentType: 'application/javascript', body: localJs })
  );
}

const SANDBOX_RENDER_URL = 'https://api.mitoera.com/sandbox-render'
  + '?key=pk_test_a435782e'
  + '&event=ff62c6c1-cf6e-4bcf-adad-e82da7f77813';

async function waitForChart(page: Page) {
  await page.waitForSelector('#chart.chart-ready', { timeout: 20_000 });
}

async function getZoom(page: Page): Promise<number> {
  return page.evaluate(() => (window as any).__renderer__?._zoom ?? 0);
}

// Desktop: step 0 → 2 (section zoom direct) → _onSeatClick — 2 clicks
async function clickSeatToSelect(page: Page, seat: Locator) {
  await seat.click(); await page.waitForTimeout(700); // step 0 → 2 (section zoom)
  await seat.click(); await page.waitForTimeout(400); // step 2 → _onSeatClick
}

// Mobile: step 0 → 1 (section zoom) → 2 (modal) — tap section wrapper then seat
async function mobileTapSeatToSelect(page: Page, seat: Locator) {
  // step 0 → 1: tap on section wrapper
  const card = await seat.evaluateHandle(el =>
    el.closest('[data-section]') || el.closest('[data-plancat]') || el
  );
  await (card as Locator).tap(); await page.waitForTimeout(700); // step 0 → 1
  await seat.tap();              await page.waitForTimeout(700); // step 1 → modal
}

// ── Chart loading ─────────────────────────────────────────────────────────────

test('chart loads and becomes visible', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);
  await expect(page.locator('#chart')).toBeVisible();
});

test('legend visible by default', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);
  await expect(page.locator('.pr-legend')).toBeVisible();
});

test('legend hidden with ?legend=0', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL + '&legend=0');
  await waitForChart(page);
  await expect(page.locator('.pr-legend')).toHaveCount(0);
});

// ── Seat selection ────────────────────────────────────────────────────────────

test('clicking an available seat selects it', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const seat = page.locator('[data-sk][data-ps="enabled"]').first();
  if (await seat.count() === 0) test.skip();

  await clickSeatToSelect(page, seat);

  const boxShadow = await seat.evaluate(el => (el as HTMLElement).style.boxShadow);
  // Selected seats get a colored ring box-shadow, not 'none'
  expect(boxShadow !== 'none' && boxShadow.length > 4).toBeTruthy();
});

test('clicking a selected seat deselects it', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const seat = page.locator('[data-sk][data-ps="enabled"]').first();
  if (await seat.count() === 0) test.skip();

  await clickSeatToSelect(page, seat);      // select (3 clicks)
  await seat.click(); await page.waitForTimeout(400); // deselect (4th click)

  const boxShadow = await seat.evaluate(el => (el as HTMLElement).style.boxShadow);
  expect(boxShadow === '' || boxShadow === 'none').toBeTruthy();
});

test('resume footer appears after seat selection', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL + '&resume=1');
  await waitForChart(page);

  const seat = page.locator('[data-sk][data-ps="enabled"]').first();
  if (await seat.count() === 0) test.skip();

  await clickSeatToSelect(page, seat);

  // Resume bar shows "N siège" or "N sièges" (use first() to avoid strict-mode on duplicate elements)
  await expect(page.locator('text=/siège/').first()).toBeVisible({ timeout: 5_000 });
});

// ── Tooltip ───────────────────────────────────────────────────────────────────

test('tooltip has white background (not transparent)', async ({ page }) => {
  // test-widget.html embed le chart dans un iframe — on cible le frame render.html
  await page.goto('https://api.mitoera.com/test-widget.html');

  const frameLocator = page.frameLocator('iframe');
  await frameLocator.locator('#chart.chart-ready').waitFor({ timeout: 30_000 });

  // .mr-tooltip est créé avec background:#fff dès le chargement du chart.
  // On vérifie que le CSS !important n'a pas remis transparent dessus.
  const tooltip = frameLocator.locator('.mr-tooltip');
  await expect(tooltip).toHaveCount(1);

  const bg = await tooltip.evaluate(el => getComputedStyle(el).backgroundColor);
  // Doit être blanc opaque — rgb(255, 255, 255) — pas transparent (rgba(0,0,0,0))
  expect(bg).toBe('rgb(255, 255, 255)');
});

// ── Drag / pan ────────────────────────────────────────────────────────────────

test('dragging pans the canvas', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const box = (await page.locator('#chart').boundingBox())!;
  const cx = box.x + box.width / 2;
  const cy = box.y + box.height / 2;

  // The canvas viewport has a translate(...) transform — record it before drag
  const transformBefore = await page.evaluate(() => {
    const el = Array.from(document.querySelectorAll('#chart [style]'))
      .find(e => (e as HTMLElement).style.transform.includes('translate'));
    return (el as HTMLElement | undefined)?.style.transform ?? '';
  });

  await page.mouse.move(cx, cy);
  await page.mouse.down();
  await page.mouse.move(cx + 100, cy + 60, { steps: 15 });
  await page.mouse.up();
  await page.waitForTimeout(200);

  const transformAfter = await page.evaluate(() => {
    const el = Array.from(document.querySelectorAll('#chart [style]'))
      .find(e => (e as HTMLElement).style.transform.includes('translate'));
    return (el as HTMLElement | undefined)?.style.transform ?? '';
  });

  expect(transformAfter).not.toBe(transformBefore);
});

// ── Mobile flow — bottom sheet (local branch) ─────────────────────────────────

test.describe('mobile', () => {
  test.use({ viewport: { width: 375, height: 812 }, hasTouch: true });

  test('mobile: chart loads', async ({ page }) => {
    await useLocalRenderJs(page);
    await page.goto(SANDBOX_RENDER_URL);
    await waitForChart(page);
    await expect(page.locator('#chart')).toBeVisible();
  });

  test('mobile: step 0 → 2 (section zoom) on section tap', async ({ page }) => {
    await useLocalRenderJs(page);
    await page.goto(SANDBOX_RENDER_URL);
    await waitForChart(page);

    const section = page.locator('[data-section],[data-plancat]').first();
    if (await section.count() === 0) test.skip();

    const zoomBefore = await page.evaluate(() => (window as any).__renderer__?._zoom ?? 0);
    await section.tap();
    await page.waitForTimeout(700);

    const stepAfter = await page.evaluate(() => (window as any).__renderer__?._mobileStep ?? -1);
    const zoomAfter = await page.evaluate(() => (window as any).__renderer__?._zoom ?? 0);
    expect(stepAfter).toBe(2);
    expect(zoomAfter).toBeGreaterThan(zoomBefore);
  });

  // Helper: tap first available seat in zoomed section via JS (bypasses viewport check)
  async function tapFirstAvailableSeat(page: Page) {
    return page.evaluate(() => {
      const seat = document.querySelector('[data-sk][data-ps="enabled"]') as HTMLElement | null;
      if (!seat) return false;
      seat.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, isPrimary: true }));
      seat.dispatchEvent(new PointerEvent('pointerup',   { bubbles: true, cancelable: true, isPrimary: true }));
      return true;
    });
  }

  test('mobile: step 2 → bottom sheet visible after seat tap', async ({ page }) => {
    await useLocalRenderJs(page);
    await page.goto(SANDBOX_RENDER_URL);
    await waitForChart(page);

    if (await page.locator('[data-sk][data-ps="enabled"]').count() === 0) test.skip();

    // step 0 → 2 (section zoom)
    await page.locator('[data-section],[data-plancat]').first().tap();
    await page.waitForTimeout(700);

    // step 2 → select seat via JS (seat may be transformed off-screen for Playwright)
    await tapFirstAvailableSeat(page);
    await page.waitForTimeout(500);

    const debug = await page.evaluate(() => {
      const r = (window as any).__renderer__;
      const seat = document.querySelector('[data-sk][data-ps="enabled"]') as HTMLElement | null;
      return {
        hasSheet: !!r?._mobileSheet,
        transform: r?._mobileSheet?.style.transform,
        mobileStep: r?._mobileStep,
        zoom: r?._zoom,
        isMobile: r?._isMobile?.(),
        selectedSize: r?._selected?.size,
        selectedSeats: r?.getSelectedSeats?.()?.length,
        seatKey: seat?.dataset?.sk,
      };
    });
    console.log('DEBUG sheet:', JSON.stringify(debug));

    // transform may be 'translateY(0)' or 'translateY(0px)' depending on browser
    const sheetVisible = debug.transform === 'translateY(0)' || debug.transform === 'translateY(0px)';
    expect(sheetVisible).toBe(true);
  });

  test('mobile: bottom sheet shows Continuer button after selection', async ({ page }) => {
    await useLocalRenderJs(page);
    await page.goto(SANDBOX_RENDER_URL);
    await waitForChart(page);

    if (await page.locator('[data-sk][data-ps="enabled"]').count() === 0) test.skip();

    await page.locator('[data-section],[data-plancat]').first().tap();
    await page.waitForTimeout(700);
    await tapFirstAvailableSeat(page);
    await page.waitForTimeout(500);

    const hasCta = await page.evaluate(() => !!document.getElementById('_mm-cta'));
    expect(hasCta).toBe(true);
  });

  test('mobile: remove button in sheet deselects seat', async ({ page }) => {
    await useLocalRenderJs(page);
    await page.goto(SANDBOX_RENDER_URL);
    await waitForChart(page);

    if (await page.locator('[data-sk][data-ps="enabled"]').count() === 0) test.skip();

    await page.locator('[data-section],[data-plancat]').first().tap();
    await page.waitForTimeout(700);
    await tapFirstAvailableSeat(page);
    await page.waitForTimeout(500);

    // Click remove button (×) in the sheet via JS
    await page.evaluate(() => {
      const btn = document.querySelector('#_mm-cta')
        ?.closest('div')?.parentElement
        ?.querySelector('[data-sk]') as HTMLElement | null;
      btn?.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, isPrimary: true }));
    });
    await page.waitForTimeout(400);

    const sheetHidden = await page.evaluate(() => {
      const r = (window as any).__renderer__;
      const t = r?._mobileSheet?.style.transform ?? '';
      return t === 'translateY(100%)' || t.includes('100%');
    });
    expect(sheetHidden).toBe(true);
  });
});
