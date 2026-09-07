/**
 * Formules de libellés et de clés d'un bloc de sièges, côté acheteur.
 *
 * Ce fichier est le pendant de mitoera-bo/src/services/seatPlan.js et de
 * EventService::seatRowKeys(). Les trois implémentations rejouent le même
 * jeu d'essai (tests/fixtures/seat-plan.json) : en modifier une sans les
 * autres casse au moins un test.
 *
 * Elles restent séparées faute de runtime commun — le back-office est en
 * Vue, le widget en IIFE autonome, la génération des sièges en PHP — mais
 * le contrat partagé les empêche de diverger silencieusement, ce qui s'est
 * produit plusieurs fois : réglages par rangée ignorés, clés en base ne
 * correspondant pas au plan affiché.
 */

export function letters(n, upper) {
  let s = '', x = n;
  do { s = String.fromCharCode((upper ? 65 : 97) + (x % 26)) + s; x = Math.floor(x / 26) - 1; } while (x >= 0);
  return s;
}

const ROMAN = [[1000,'M'],[900,'CM'],[500,'D'],[400,'CD'],[100,'C'],[90,'XC'],[50,'L'],[40,'XL'],[10,'X'],[9,'IX'],[5,'V'],[4,'IV'],[1,'I']];
export function roman(n) {
  let v = n + 1, r = '';
  for (const [a, b] of ROMAN) { while (v >= a) { r += b; v -= a; } }
  return r || String(n + 1);
}

/** `startAt` s'applique APRÈS le sens, comme sequenceValue côté back-office. */
export function axisLabel(idx, total, fmt, dir, startAt) {
  const i = (dir === 'reversed' ? Math.max(0, total - 1 - idx) : idx) + (startAt || 0);
  return fmt === 'A-Z' ? letters(i, true)
       : fmt === 'a-z' ? letters(i, false)
       : fmt === 'I-X' ? roman(i)
       : String(i + 1);
}

// ---------- Réglages par rangée ----------
export function rowOver(row, ri) { return (row.rowOverrides || {})[ri] || {}; }

export function rowColCount(row, ri) {
  const o = rowOver(row, ri);
  return o.cols != null ? o.cols : (row.cols || 1);
}

export function rowStartAt(row, ri) {
  const o = rowOver(row, ri);
  return o.colStartAt != null ? o.colStartAt : 0;
}

export function rowColOffset(row, ri) { return rowOver(row, ri).colOffset || 0; }

export function rowLabelOf(row, ri) {
  const o = rowOver(row, ri);
  return (o.label != null && o.label !== '')
    ? String(o.label)
    : axisLabel(ri, row.rows, row.rowFormat || 'A-Z', row.rowDirection || 'normal');
}

export function colLabelOf(row, ri, ci) {
  return axisLabel(ci, rowColCount(row, ri), row.colFormat || '1-9', row.colDirection || 'normal', rowStartAt(row, ri));
}

export function sectionOf(row) { return row.section || row.label || row.id || 'S'; }

export function seatRowKey(row, ri, ci) {
  return `${sectionOf(row)}-${rowLabelOf(row, ri)}-${colLabelOf(row, ri, ci)}`;
}

/** rowOrder est une permutation d'affichage : l'index de données reste la clé. */
export function displayOrder(row) {
  const n = row.rows || 1;
  return (Array.isArray(row.rowOrder) && row.rowOrder.length === n)
    ? row.rowOrder
    : Array.from({ length: n }, (_, i) => i);
}

/** Largeur utile d'un bloc : la rangée la plus large, décalage compris. */
export function seatRowMaxCols(row) {
  let max = 0;
  for (let r = 0; r < (row.rows || 1); r++) {
    max = Math.max(max, rowColCount(row, r) + rowColOffset(row, r));
  }
  return max || (row.cols || 1);
}

/** Les clés d'un bloc, sièges désactivés et supprimés exclus. */
export function seatRowKeys(row) {
  const disabled = row.disabledSeats || [];
  const deleted  = row.deletedSeats  || [];
  const keys = [];
  for (let r = 0; r < (row.rows || 1); r++) {
    for (let c = 0; c < rowColCount(row, r); c++) {
      const pk = `${r}-${c}`;
      if (disabled.includes(pk) || deleted.includes(pk)) continue;
      keys.push(seatRowKey(row, r, c));
    }
  }
  return keys;
}
