// Rejoue le contrat partagé (tests/fixtures/seat-plan.json) contre le module
// du renderer acheteur. Le back-office et EventService.php rejouent le MÊME
// fichier : c'est ce qui empêche les trois implémentations de diverger.
//
//   node --test tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { seatRowKeys, displayOrder, seatRowMaxCols, axisLabel } from '../../js-src/seat-plan.js';

const here = dirname(fileURLToPath(import.meta.url));
const fixture = JSON.parse(readFileSync(join(here, '../fixtures/seat-plan.json'), 'utf8'));

test('les clés produites correspondent au contrat partagé', async (t) => {
  assert.ok(fixture.cases.length > 0);
  for (const c of fixture.cases) {
    await t.test(c.name, () => {
      assert.deepEqual(seatRowKeys(c.row), c.keys);
    });
  }
});

test("rowOrder ne change que l'ordre d'affichage", () => {
  const c = fixture.cases.find((x) => x.displayOrder);
  assert.deepEqual(displayOrder(c.row), c.displayOrder);
  assert.deepEqual(seatRowKeys(c.row), c.keys);
});

test('largeur utile prise sur la rangée la plus large', () => {
  for (const g of fixture.geometry) {
    const expectedCols = Math.round((g.size.w - 2 * (g.row.isGroup ? 0 : 7) + 6) / ((g.row.seatSize || 22) + 6));
    assert.equal(seatRowMaxCols(g.row), expectedCols, g.name);
  }
});

test('startAt s\'applique après le sens', () => {
  assert.equal(axisLabel(0, 1, 'A-Z', 'normal', 5), 'F');
  assert.equal(axisLabel(0, 3, 'A-Z', 'reversed', 2), 'E');
  assert.equal(axisLabel(26, 30, 'A-Z', 'normal', 0), 'AA');
});
