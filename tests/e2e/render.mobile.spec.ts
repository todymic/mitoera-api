import { test, expect, devices, Page } from '@playwright/test';

test.use({ ...devices['iPhone 14'] });

const SANDBOX_RENDER_URL = 'https://mitoera.com/sandbox-render'
  + '?key=pk_pub_e66dc0a5'
  + '&event=ff62c6c1-cf6e-4bcf-adad-e82da7f77813';

async function waitForChart(page: Page) {
  await page.waitForSelector('#chart.chart-ready', { timeout: 20_000 });
}

async function getMobileStep(page: Page): Promise<number> {
  return page.evaluate(() => (window as any).__renderer__?._mobileStep ?? -1);
}

async function getZoom(page: Page): Promise<number> {
  return page.evaluate(() => (window as any).__renderer__?._zoom ?? 0);
}

test('chart loads on mobile', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);
  await expect(page.locator('#chart')).toBeVisible();
});

test('tap section zooms in — step 0 → 1', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const section = page.locator('[data-section]').first();
  if (await section.count() === 0) test.skip();

  const zoomBefore = await getZoom(page);
  await section.tap();
  await page.waitForTimeout(500);

  expect(await getZoom(page)).toBeGreaterThan(zoomBefore);
  expect(await getMobileStep(page)).toBe(1);
});

test('tap seat at step 1 → step 2', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const section = page.locator('[data-section]').first();
  if (await section.count() === 0) test.skip();
  await section.tap();
  await page.waitForTimeout(500);

  const seat = page.locator('[data-sk][data-ps="available"]').first();
  if (await seat.count() === 0) test.skip();
  await seat.tap();
  await page.waitForTimeout(500);

  expect(await getMobileStep(page)).toBe(2);
});

test('tap another seat at step 2 stays at step 2 (no re-zoom)', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const section = page.locator('[data-section]').first();
  if (await section.count() === 0) test.skip();
  await section.tap();
  await page.waitForTimeout(500);

  const seats = page.locator('[data-sk][data-ps="available"]');
  if (await seats.count() < 2) test.skip();
  await seats.first().tap();
  await page.waitForTimeout(500);

  const zoomAtStep2 = await getZoom(page);

  // Tap a different seat — must NOT go back to step 1 then 2
  await seats.nth(1).tap();
  await page.waitForTimeout(400);

  expect(await getMobileStep(page)).toBe(2);
  // Zoom should stay comparable (within 20%)
  expect(await getZoom(page)).toBeGreaterThan(zoomAtStep2 * 0.8);
});

test('drag at step 1 pans — does not trigger zoom', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const section = page.locator('[data-section]').first();
  if (await section.count() === 0) test.skip();
  await section.tap();
  await page.waitForTimeout(500);

  const zoomAtStep1 = await getZoom(page);

  const box = (await page.locator('#chart').boundingBox())!;
  const cx = box.x + box.width / 2;
  const cy = box.y + box.height / 2;

  // Simulate drag via mouse (Playwright translates to touch on mobile viewport)
  await page.mouse.move(cx, cy);
  await page.mouse.down();
  await page.mouse.move(cx + 80, cy, { steps: 15 });
  await page.mouse.up();
  await page.waitForTimeout(300);

  expect(await getMobileStep(page)).toBe(1);
  expect(await getZoom(page)).toBeCloseTo(zoomAtStep1, 1);
});

test('drag at step 2 pans — does not revert to step 1', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const section = page.locator('[data-section]').first();
  if (await section.count() === 0) test.skip();
  await section.tap();
  await page.waitForTimeout(500);

  const seat = page.locator('[data-sk][data-ps="available"]').first();
  if (await seat.count() === 0) test.skip();
  await seat.tap();
  await page.waitForTimeout(500);

  const box = (await page.locator('#chart').boundingBox())!;
  const cx = box.x + box.width / 2;
  const cy = box.y + box.height / 2;

  await page.mouse.move(cx, cy);
  await page.mouse.down();
  await page.mouse.move(cx + 80, cy, { steps: 15 });
  await page.mouse.up();
  await page.waitForTimeout(300);

  expect(await getMobileStep(page)).toBe(2);
});
