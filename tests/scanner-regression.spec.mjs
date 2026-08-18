import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const fixtureRoot = path.resolve('tests/fixtures');
const imageExtensions = new Set(['.png','.jpg','.jpeg','.webp']);

function fixtureNames() {
  return fs.readdirSync(fixtureRoot,{withFileTypes:true})
    .filter(d=>d.isDirectory() && fs.existsSync(path.join(fixtureRoot,d.name,'expected.json')))
    .map(d=>d.name)
    .sort();
}

function parseDebug(text) {
  const cards = new Map();
  let pages = null;
  let usernameLine = null;

  for (const line of text.split(/\r?\n/)) {
    let m = line.match(/^SCAN_COMPLETE \| pages=(\d+)\/5/);
    if (m) pages = Number(m[1]);

    if (line.startsWith('USERNAME |')) usernameLine = line;

    m = line.match(/^(?:DETECT|FILL) \| page=\d+ \| slot=\d+ \| ([^|]+) \| ([^|]+) \| qty=(\d+)/);
    if (m) {
      const category=m[1].trim();
      const name=m[2].trim();
      cards.set(`${category}|${name}`, Number(m[3]));
    }
  }
  return {cards,pages,usernameLine};
}

for (const fixtureName of fixtureNames()) {
  test(`scanner regression: ${fixtureName}`, async ({ page }) => {
    test.setTimeout(360_000);

    const dir=path.join(fixtureRoot,fixtureName);
    const expected=JSON.parse(fs.readFileSync(path.join(dir,'expected.json'),'utf8'));

    const images=fs.readdirSync(dir)
      .filter(name=>imageExtensions.has(path.extname(name).toLowerCase()))
      .sort()
      .map(name=>path.join(dir,name));

    expect(images.length,'fixture should contain screenshots').toBeGreaterThan(0);

    // The harness's previous-inventory request is intentionally answered with
    // an empty dataset so partial-scan behavior remains deterministic.
    await page.route('**/?ajax_player_inventory=*', route => route.fulfill({
      status:200,
      contentType:'application/json',
      body:JSON.stringify({found:false,display_name:null,quantities:{}})
    }));

    await page.goto('/tests/scanner-harness.html');
    await page.locator('#screenshots').setInputFiles(images);
    await page.locator('#analyzeScreenshots').click();

    await expect(page.locator('#rawOcrText')).toContainText(
      '===== END FULL DETECTED COUNTS =====',
      {timeout:330_000}
    );

    const debug=await page.locator('#rawOcrText').innerText();
    const actual=parseDebug(debug);

    expect(actual.pages,'all card pages should parse').toBe(expected.expected_pages);

    const aliases=expected.aliases || {};
    const mismatches=[];

    for (const [key,actualQty] of actual.cards) {
      const [category,name]=key.split('|');
      const expectedName=aliases[key] || name;

      if (!(expectedName in expected.cards)) {
        mismatches.push(`Unexpected scanner result: ${key}=${actualQty}`);
        continue;
      }

      const expectedQty=Number(expected.cards[expectedName]);
      if (actualQty!==expectedQty) {
        mismatches.push(
          `${category} | ${name}: expected ${expectedQty}, detected ${actualQty}`
        );
      }
    }

    // Also catch cards that vanished from output.
    for (const [expectedName,expectedQty] of Object.entries(expected.cards)) {
      let found=false;
      for (const key of actual.cards.keys()) {
        const [,name]=key.split('|');
        const mapped=aliases[key] || name;
        if (mapped===expectedName) { found=true; break; }
      }
      if (!found) mismatches.push(`${expectedName}: missing from scanner output`);
    }

    if (mismatches.length) {
      console.error('\n--- Scanner debug ---\n'+debug);
      console.error('\n--- Mismatches ---\n'+mismatches.join('\n'));
    }

    expect(mismatches, 'scanner quantity mismatches').toEqual([]);
  });
}
