import { createServer, build } from 'vite';
import path from 'path';
import fs from 'fs';

const THEMES_DIR = path.resolve('themes');

if (process.argv[2] === 'dev') {
  async function dev() {
    const themeName = process.argv[3] || 'default';
    const configPath = path.join(THEMES_DIR, themeName, 'vite.config.js');

    if (!fs.existsSync(configPath)) {
      console.error(`❌ vite.config.js not found for theme: ${themeName}`);
      process.exit(1);
    }

    const server = await createServer({
      configFile: configPath,
    });
    await server.listen();
    console.log(`✅ Dev server running for theme "${themeName}"`);
  }

  dev();
} else {
  async function buildAll() {
    const themes = fs.readdirSync(THEMES_DIR, { withFileTypes: true })
      .filter(dirent => dirent.isDirectory())
      .map(dirent => dirent.name);

    console.log(`🧱 Building ${themes.length} theme(s)...\n`);

    for (const theme of themes) {
      const configPath = path.join(THEMES_DIR, theme, 'vite.config.js');

      if (!fs.existsSync(configPath)) {
        console.warn(`⚠️  Skipping theme "${theme}" (no vite.config.js found)`);
        continue;
      }

      console.log(`📦 Building theme: ${theme}`);
      await build({ configFile: configPath });
      console.log(`✅ Finished building: ${theme}\n`);
    }

    console.log('🎉 All themes built successfully!');
  }

  buildAll();
}
