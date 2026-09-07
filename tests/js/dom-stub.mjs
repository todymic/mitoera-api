/**
 * DOM minimal, juste assez pour instancier et rendre PlaceRender hors navigateur.
 *
 * Sans dépendance : jsdom simulerait bien plus que ce dont le renderer a besoin,
 * pour un test dont le seul rôle est de répondre à « est-ce que le rendu va au
 * bout sans lever ? ». C'est la question qui n'était posée nulle part le jour où
 * un helper supprimé par erreur a fait échouer le widget sur `cls is not defined`.
 */

class Style {
  constructor() { this._p = {}; }
  setProperty(k, v) { this._p[k] = v; }
  getPropertyValue(k) { return this._p[k] ?? ''; }
  removeProperty(k) { delete this._p[k]; }
}

// Les styles sont posés par affectation directe (el.style.width = '10px'),
// donc un Proxy sur un objet nu suffit — sauf pour setProperty, gardé ci-dessus.
function makeStyle() {
  const s = new Style();
  return new Proxy(s, {
    get: (t, k) => (k in t ? t[k] : t._p[k]),
    set: (t, k, v) => { t._p[k] = v; return true; },
  });
}

class ClassList {
  constructor() { this._s = new Set(); }
  add(...c) { c.forEach((x) => this._s.add(x)); }
  remove(...c) { c.forEach((x) => this._s.delete(x)); }
  toggle(c, on) { (on ?? !this._s.has(c)) ? this._s.add(c) : this._s.delete(c); }
  contains(c) { return this._s.has(c); }
  get value() { return [...this._s].join(' '); }
}

class El {
  constructor(tag) {
    this.tagName = String(tag).toUpperCase();
    this.style = makeStyle();
    this.classList = new ClassList();
    this.dataset = {};
    this.children = [];
    this.parentNode = null;
    this.textContent = '';
    this._html = '';
    this._listeners = {};
    this.id = '';
  }
  set className(v) { this.classList = new ClassList(); if (v) this.classList.add(...String(v).split(/\s+/)); }
  get className() { return this.classList.value; }
  set innerHTML(v) { this._html = String(v); this.children = []; }
  get innerHTML() { return this._html; }
  appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
  removeChild(c) { this.children = this.children.filter((x) => x !== c); return c; }
  remove() { this.parentNode?.removeChild(this); }
  addEventListener(t, fn) { (this._listeners[t] ||= []).push(fn); }
  removeEventListener(t, fn) { this._listeners[t] = (this._listeners[t] || []).filter((f) => f !== fn); }
  dispatch(t, ev = {}) { (this._listeners[t] || []).forEach((fn) => fn({ target: this, preventDefault() {}, stopPropagation() {}, ...ev })); }
  getBoundingClientRect() { return { left: 0, top: 0, right: 800, bottom: 600, width: 800, height: 600, x: 0, y: 0 }; }
  setAttribute(k, v) { this._attrs = this._attrs || {}; this._attrs[k] = String(v); if (k === 'id') this.id = String(v); }
  getAttribute(k) { return (this._attrs || {})[k] ?? null; }
  removeAttribute(k) { delete (this._attrs || {})[k]; }
  hasAttribute(k) { return (this._attrs || {})[k] !== undefined; }
  matches() { return false; }
  closest(sel) {
    const attr = sel.replace(/^\[|\]$/g, '');
    for (let n = this; n; n = n.parentNode) {
      if (attr === 'data-section' && n.dataset.section !== undefined) return n;
      if (attr === 'data-plancat' && n.dataset.plancat !== undefined) return n;
    }
    return null;
  }
  _all() { return this.children.flatMap((c) => [c, ...c._all()]); }
  querySelectorAll(sel) {
    const attr = sel.replace(/^\[|\]$/g, '');
    return this._all().filter((n) => n.dataset[attr.replace(/^data-/, '')] !== undefined);
  }
  querySelector(sel) { return this.querySelectorAll(sel)[0] ?? null; }
  get clientWidth() { return 800; }
  get clientHeight() { return 600; }
  get offsetWidth() { return 160; }
  get offsetHeight() { return 44; }
  focus() {}
  scrollTo() {}
}

export function installDom() {
  const doc = new El('document');
  doc.head = new El('head');
  doc.body = new El('body');
  doc.createElement = (t) => new El(t);
  doc.createElementNS = (_ns, t) => new El(t);
  doc.getElementById = () => null;
  doc.addEventListener = () => {};
  doc.removeEventListener = () => {};

  const win = {
    innerWidth: 1280, innerHeight: 800, devicePixelRatio: 1,
    document: doc,
    addEventListener: () => {}, removeEventListener: () => {},
    requestAnimationFrame: (fn) => { fn(0); return 1; },
    cancelAnimationFrame: () => {},
    matchMedia: () => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }),
    getComputedStyle: () => ({ getPropertyValue: () => '' }),
    parent: null,
    postMessage: () => {},
    setTimeout: (fn) => { return 0; },   // pas d'exécution différée dans un test
    clearTimeout: () => {},
  };
  win.top = win;
  win.parent = win;

  globalThis.window = win;
  globalThis.document = doc;
  globalThis.Element = El;
  // Le renderer appelle ces API sans les préfixer par window.
  // rAF ne déclenche pas le callback : les boucles d'animation tourneraient
  // indéfiniment avec une horloge figée, et ce test ne juge que le rendu initial.
  globalThis.requestAnimationFrame = () => 1;
  globalThis.cancelAnimationFrame = () => {};
  globalThis.getComputedStyle = win.getComputedStyle;
  globalThis.matchMedia = win.matchMedia;
  globalThis.performance ??= { now: () => 0 };
  return { win, doc, El };
}

export function makeContainer() {
  return new El('div');
}
