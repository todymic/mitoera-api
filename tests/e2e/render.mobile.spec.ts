import { test, expect, devices, Page } from '@playwright/test';

test.use({ ...devices['iPhone 14'] });

const SANDBOX_RENDER_URL = 'https://api.mitoera.com/sandbox-render'
  + '?key=pk_pub_e66dc0a5'
  + '&event=ff62c6c1-cf6e-4bcf-adad-e82da7f77813';

async function waitForChart(page: Page) {
  await page.waitForSelector('#chart.chart-ready', { timeout: 20_000 });
}

// Read zoom scale from the viewport's transform style
async function getScale(page: Page): Promise<number> {
  return page.evaluate(() => {
    // Try __renderer__ first (if deployed), fall back to parsing DOM transform
    const r = (window as any).__renderer__;
    if (r && typeof r._zoom === 'number') return r._zoom;
    const el = Array.from(document.querySelectorAll('#chart [style]'))
      .find(e => (e as HTMLElement).style.transform.includes('scale'));
    if (!el) return 0;
    const m = (el as HTMLElement).style.transform.match(/scale\(([\d.]+)\)/);
    return m ? parseFloat(m[1]) : 0;
  });
}

async function getMobileStep(page: Page): Promise<number> {
  return page.evaluate(() => {
    const r = (window as any).__renderer__;
    return r && typeof r._mobileStep === 'number' ? r._mobileStep : -1;
  });
}

test('chart loads on mobile', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);
  await expect(page.locator('#chart')).toBeVisible();
});

test('tap section zooms in', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const section = page.locator('[data-section]').first();
  if (await section.count() === 0) test.skip();

  const scaleBefore = await getScale(page);
  await section.tap();
  await page.waitForTimeout(600);

  const scaleAfter = await getScale(page);
  expect(scaleAfter).toBeGreaterThan(scaleBefore);
});

test('tap seat at step 1 zooms further — step 1 → 2', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const section = page.locator('[data-section]').first();
  if (await section.count() === 0) test.skip();
  await section.tap();
  await page.waitForTimeout(600);

  const scaleAtStep1 = await getScale(page);

  const seat = page.locator('[data-sk][data-ps="enabled"]').first();
  if (await seat.count() === 0) test.skip();
  await seat.tap();
  await page.waitForTimeout(600);

  // Step 1→2 zooms further into the seat
  const scaleAtStep2 = await getScale(page);
  expect(scaleAtStep2).toBeGreaterThan(scaleAtStep1);
});

test('tap another seat at step 2 stays zoomed (no re-zoom to step 1)', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const section = page.locator('[data-section]').first();
  if (await section.count() === 0) test.skip();
  await section.tap();
  await page.waitForTimeout(600);

  const seats = page.locator('[data-sk][data-ps="enabled"]');
  if (await seats.count() < 2) test.skip();
  await seats.first().tap();
  await page.waitForTimeout(600);

  const scaleAtStep2 = await getScale(page);

  // Tap a different seat — scale must NOT drop back to step-1 level
  await seats.nth(1).tap();
  await page.waitForTimeout(500);

  const scaleAfter = await getScale(page);
  // Zoom should stay within 20% of step-2 zoom (not reset to overview)
  expect(scaleAfter).toBeGreaterThan(scaleAtStep2 * 0.8);

  // If __renderer__ is available, also verify mobileStep stays at 2
  const step = await getMobileStep(page);
  if (step !== -1) expect(step).toBe(2);
});

test('drag at step 1 pans — does not zoom further', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const section = page.locator('[data-section]').first();
  if (await section.count() === 0) test.skip();
  await section.tap();
  await page.waitForTimeout(600);

  const scaleAtStep1 = await getScale(page);

  const box = (await page.locator('#chart').boundingBox())!;
  const cx = box.x + box.width / 2;
  const cy = box.y + box.height / 2;

  await page.mouse.move(cx, cy);
  await page.mouse.down();
  await page.mouse.move(cx + 80, cy, { steps: 15 });
  await page.mouse.up();
  await page.waitForTimeout(400);

  const scaleAfterDrag = await getScale(page);
  // Scale must not have jumped to a much higher value (no unintended seat-zoom)
  expect(scaleAfterDrag).toBeCloseTo(scaleAtStep1, 0);

  // If __renderer__ is available, verify step stays at 1
  const step = await getMobileStep(page);
  if (step !== -1) expect(step).toBe(1);
});

test('drag at step 2 pans — does not revert to overview', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const section = page.locator('[data-section]').first();
  if (await section.count() === 0) test.skip();
  await section.tap();
  await page.waitForTimeout(600);

  const seat = page.locator('[data-sk][data-ps="enabled"]').first();
  if (await seat.count() === 0) test.skip();
  await seat.tap();
  await page.waitForTimeout(600);

  const scaleAtStep2 = await getScale(page);

  const box = (await page.locator('#chart').boundingBox())!;
  const cx = box.x + box.width / 2;
  const cy = box.y + box.height / 2;

  await page.mouse.move(cx, cy);
  await page.mouse.down();
  await page.mouse.move(cx + 80, cy, { steps: 15 });
  await page.mouse.up();
  await page.waitForTimeout(400);

  const scaleAfterDrag = await getScale(page);
  // Scale must stay close to step-2 zoom level (not reset to overview)
  expect(scaleAfterDrag).toBeGreaterThan(scaleAtStep2 * 0.8);

  // If __renderer__ is available, verify step stays at 2
  const step = await getMobileStep(page);
  if (step !== -1) expect(step).toBe(2);
});
