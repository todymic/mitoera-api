import { defineConfig } from 'vite';
import path from 'path';

const __dirname = import.meta.dirname;

// Deux builds séparés : widget et render (IIFE autonomes, pas de code splitting)
function makeConfig(name) {
  return defineConfig({
    publicDir: false,
    build: {
      outDir: 'public',
      emptyOutDir: false,
      minify: 'terser',
      terserOptions: {
        compress: { drop_console: false, passes: 2 },
        mangle: true,
        format: { comments: false },
      },
      lib: {
        entry: path.resolve(__dirname, `js-src/${name}.js`),
        name: name.replace(/-/g, '_'),
        fileName: () => `${name}.js`,
        formats: ['iife'],
      },
    },
  });
}

export default process.env.BUILD_TARGET === 'render'
  ? makeConfig('mitoera-render')
  : makeConfig('mitoera-widget');
