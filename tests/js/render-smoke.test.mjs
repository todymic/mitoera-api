/**
 * Test de fumée du renderer acheteur : est-ce que rendre un plan va au bout
 * sans lever ?
 *
 * `npm run build` ne détecte pas un identifiant non défini. Le jour où un
 * helper a été supprimé par erreur en refactorisant, le widget s'est arrêté
 * sur « cls is not defined » et seul le chargeur restait à l'écran — sans
 * qu'aucune vérification ne le voie. Ce test pose la question manquante.
 *
 *   npm run test:contract
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { installDom, makeContainer } from './dom-stub.mjs';

installDom();
// L'IIFE s'exécute à l'import et pose global.PlaceRender sur notre faux window
await import('../../js-src/mitoera-render.js');
const PlaceRender = globalThis.window.PlaceRender;

const categories = [{ id: 'c1', name: 'GOLD', color: '#22c55e', price: 50000, currency: 'MGA' }];

function chart(objects) {
  return { chartObjects: objects, categories, seats: [] };
}

function render(objects) {
  const container = makeContainer();
  const r = new PlaceRender({ container, data: chart(objects), readOnly: true });
  r.render();
  return r;
}

test('PlaceRender est exposé', () => {
  assert.equal(typeof PlaceRender, 'function');
});

test('un bloc de sièges avec libellés de rangée se rend sans lever', () => {
  // Le cas exact qui cassait : rangée non groupée, sièges >= 12px, donc
  // libellés affichés — le chemin qui appelait le helper manquant.
  render([{
    _type: 'seatRow', id: 'sr1', section: 'PARTERRE',
    top: 0, left: 0, rows: 3, cols: 6, seatSize: 22, shape: 'round',
    categoryId: 'c1', rowFormat: 'A-Z', rowDirection: 'normal',
    colFormat: '1-9', colDirection: 'normal',
  }]);
});

test('un bloc avec réglages par rangée se rend sans lever', () => {
  render([{
    _type: 'seatRow', id: 'sr2', section: 'RESA',
    top: 0, left: 0, rows: 3, cols: 8, seatSize: 20, shape: 'round',
    categoryId: 'c1', rowFormat: 'A-Z', rowDirection: 'normal',
    colFormat: '1-9', colDirection: 'normal',
    rowOverrides: { 0: { cols: 4, colStartAt: 3 }, 1: { label: 'Z', colOffset: 2 } },
    rowOrder: [2, 0, 1],
    disabledSeats: ['0-1'], deletedSeats: ['1-2'],
  }]);
});

test('un groupe de rangées rattaché se rend sans lever', () => {
  render([
    { _type: 'seatRow', id: 'sr3', section: 'GOLD', top: 0, left: 0,
      rows: 2, cols: 6, seatSize: 22, shape: 'round', categoryId: 'c1' },
    { _type: 'seatRow', id: 'grp1', section: 'GOLD', isGroup: true,
      parentRowId: 'sr3', top: 0, left: 200, rows: 1, cols: 3,
      seatSize: 22, shape: 'round', categoryId: 'c1',
      rowOverrides: { 0: { label: 'A', colStartAt: 6 } } },
  ]);
});

test('sièges trop petits pour porter un numéro : rendu sans lever', () => {
  render([{
    _type: 'seatRow', id: 'sr4', section: 'MINI', top: 0, left: 0,
    rows: 2, cols: 4, seatSize: 8, shape: 'square', categoryId: 'c1',
  }]);
});

test('tous les types d\'objets du plan se rendent sans lever', () => {
  render([
    { _type: 'zone', id: 'z1', label: 'SCENE', top: 0, left: 0, width: 200, height: 60, categoryId: 'c1' },
    { _type: 'freeZone', id: 'f1', label: 'BAR', top: 100, left: 0, width: 80, height: 40 },
    { _type: 'seatRow', id: 'sr5', section: 'S', top: 200, left: 0, rows: 2, cols: 4, seatSize: 22, categoryId: 'c1' },
    { _type: 'tableZone', id: 't1', section: 'T', top: 300, left: 0, seatCount: 6, categoryId: 'c1' },
    { _type: 'tableSection', id: 'ts1', section: 'TS', top: 400, left: 0, tableCount: 2, tableRows: 1, seatsPerTable: 4, categoryId: 'c1' },
  ]);
});

test('un plan vide se rend sans lever', () => {
  render([]);
});
