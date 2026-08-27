import { test, expect, Page } from '@playwright/test';

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
  const seat = page.locator('[data-sk][data-ps="available"]').first();
  await seat.click();
  const boxShadow = await seat.evaluate(el => (el as HTMLElement).style.boxShadow);
  expect(boxShadow.length).toBeGreaterThan(4); // selected style adds a colored ring
});

test('clicking a selected seat deselects it', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);
  const seat = page.locator('[data-sk][data-ps="available"]').first();
  await seat.click(); // select
  await seat.click(); // deselect
  const boxShadow = await seat.evaluate(el => (el as HTMLElement).style.boxShadow);
  expect(boxShadow === '' || boxShadow === 'none').toBeTruthy();
});

test('resume footer appears after seat selection', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL + '&resume=1');
  await waitForChart(page);
  const seat = page.locator('[data-sk][data-ps="available"]').first();
  await seat.click();
  await expect(page.locator('#chart').locator('text=/siège/')).toBeVisible({ timeout: 5_000 });
});

// ── Drag / pan ────────────────────────────────────────────────────────────────

test('dragging pans the canvas', async ({ page }) => {
  await page.goto(SANDBOX_RENDER_URL);
  await waitForChart(page);

  const box = (await page.locator('#chart').boundingBox())!;
  const cx = box.x + box.width / 2;
  const cy = box.y + box.height / 2;

  const transformBefore = await page.evaluate(() => {
    const el = document.querySelector<HTMLElement>('#chart [style*="transformOrigin"]');
    return el?.style.transform ?? '';
  });

  await page.mouse.move(cx, cy);
  await page.mouse.down();
  await page.mouse.move(cx + 100, cy + 60, { steps: 15 });
  await page.mouse.up();

  const transformAfter = await page.evaluate(() => {
    const el = document.querySelector<HTMLElement>('#chart [style*="transformOrigin"]');
    return el?.style.transform ?? '';
  });

  expect(transformAfter).not.toBe(transformBefore);
});
