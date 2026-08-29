import { test, expect, Page, Locator } from '@playwright/test';

const SANDBOX_RENDER_URL = 'https://api.mitoera.com/sandbox-render'
  + '?key=pk_test_a435782e'
  + '&event=ff62c6c1-cf6e-4bcf-adad-e82da7f77813';

async function waitForChart(page: Page) {
  await page.waitForSelector('#chart.chart-ready', { timeout: 20_000 });
}

async function getZoom(page: Page): Promise<number> {
  return page.evaluate(() => (window as any).__renderer__?._zoom ?? 0);
}

// The seat pointerup handler uses _mobileStep (even on desktop):
//   step 0 → section zoom, step 1 → seat zoom, step 2 → _onSeatClick (select)
// So 3 clicks are needed to reach selection from a fresh page.
async function clickSeatToSelect(page: Page, seat: Locator) {
  await seat.click(); await page.waitForTimeout(700); // step 0 → 1 (section zoom)
  await seat.click(); await page.waitForTimeout(700); // step 1 → 2 (seat zoom)
  await seat.click(); await page.waitForTimeout(400); // step 2 → _onSeatClick
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
