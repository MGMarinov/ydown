import { chromium } from 'playwright';

const URL_A = 'https://www.youtube.com/watch?v=vj7pRom9eDk';
const URL_B = 'https://www.youtube.com/watch?v=oTXxBmsbwo0';
const APP_URL = 'http://localhost/tools/ydown/';

const MAX_TOTAL_MS = 15 * 60 * 1000;
const POLL_MS = 1000;
const MAX_STALL_MS = 20000;

function parsePercent(text) {
  const raw = String(text || '').trim().replace('%', '');
  const value = Number.parseInt(raw, 10);
  return Number.isFinite(value) ? value : -1;
}

function nowMs() {
  return Date.now();
}

function pickOptionIndexByBitrate(optionTexts, mode) {
  let bestIndex = -1;
  let bestValue = mode === 'max' ? -1 : Number.MAX_SAFE_INTEGER;

  for (let i = 0; i < optionTexts.length; i += 1) {
    const text = String(optionTexts[i] || '');
    const match = text.match(/(\d+)\s*kbps/i);
    if (!match) {
      continue;
    }
    const bitrate = Number.parseInt(match[1], 10);
    if (!Number.isFinite(bitrate)) {
      continue;
    }

    if (mode === 'max' && bitrate > bestValue) {
      bestValue = bitrate;
      bestIndex = i;
    }
    if (mode === 'min' && bitrate < bestValue) {
      bestValue = bitrate;
      bestIndex = i;
    }
  }

  if (bestIndex >= 0) {
    return bestIndex;
  }

  return optionTexts.length > 1 ? 1 : 0;
}

async function waitForScanReady(page, slotSuffix, timeoutMs = 180000) {
  const titleSelector = `#slot-${slotSuffix}-title`;
  const statusTextSelector = `#prozess-text-${slotSuffix}`;
  const rowSelector = `#prozess-zeile-${slotSuffix}`;
  const start = nowMs();
  while (nowMs() - start < timeoutMs) {
    const titleText = await page.$eval(titleSelector, (el) => String(el.textContent || '').trim());
    const statusText = await page.$eval(statusTextSelector, (el) => String(el.textContent || '').trim().toLowerCase());
    const rowClass = await page.$eval(rowSelector, (el) => String(el.className || ''));

    const headerReady = titleText.startsWith('Download - ');
    const statusReady = statusText.includes('quality list ready') || statusText.includes('scan completed');
    const successRow = rowClass.includes('status-success');

    if (headerReady || statusReady || successRow) {
      return;
    }
    await page.waitForTimeout(400);
  }
  throw new Error(`Timed out waiting for scan completion on slot ${slotSuffix.toUpperCase()}`);
}

async function collectSlotState(page, slot) {
  const suffix = slot.toLowerCase();
  const state = await page.evaluate((s) => {
    const row = document.getElementById(`prozess-zeile-${s}`);
    const textEl = document.getElementById(`prozess-text-${s}`);
    const percentEl = document.getElementById(`prozess-prozent-${s}`);
    const fillEl = document.getElementById(`prozess-fuellung-${s}`);
    const rowClass = row ? row.className : '';
    return {
      text: textEl ? textEl.textContent : '',
      percent: percentEl ? percentEl.textContent : '0%',
      fillPercent: fillEl ? fillEl.style.width : '0%',
      rowClass,
    };
  }, suffix);

  const percentLabel = parsePercent(state.percent);
  const percentFill = parsePercent(state.fillPercent);
  const percent = percentLabel >= 0 ? percentLabel : percentFill;
  const status = state.rowClass.includes('status-error')
    ? 'error'
    : state.rowClass.includes('status-success')
      ? 'done'
      : 'running';

  return {
    ts: nowMs(),
    slot,
    percent,
    text: String(state.text || '').trim(),
    status,
  };
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ acceptDownloads: true });
  const page = await context.newPage();

  const timeline = [];
  const slotData = {
    A: { lastChangeMs: nowMs(), lastSnapshot: null, done: false, events: 0 },
    B: { lastChangeMs: nowMs(), lastSnapshot: null, done: false, events: 0 },
  };

  try {
    await page.goto(APP_URL, { waitUntil: 'domcontentloaded', timeout: 120000 });

    await page.fill('#seiten_url', URL_A);
    await page.fill('#seiten_url_2', URL_B);
    await page.press('#seiten_url', 'Enter');
    await page.press('#seiten_url_2', 'Enter');

    await page.selectOption('#ziel_format_a', 'mp3');
    await page.selectOption('#ziel_format_b', 'mp3');

    await waitForScanReady(page, 'a');
    await waitForScanReady(page, 'b');

    const optionTextsA = await page.$eval('#qualitaet_index_a', (el) => Array.from(el.options).map((o) => o.textContent || ''));
    const optionTextsB = await page.$eval('#qualitaet_index_b', (el) => Array.from(el.options).map((o) => o.textContent || ''));

    const pickA = pickOptionIndexByBitrate(optionTextsA, 'max');
    const pickB = pickOptionIndexByBitrate(optionTextsB, 'min');

    await page.$eval('#qualitaet_index_a', (el, idx) => {
      if (idx >= 0 && idx < el.options.length) {
        const opt = el.options[idx];
        el.value = String(opt.value || '');
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, pickA);

    await page.$eval('#qualitaet_index_b', (el, idx) => {
      if (idx >= 0 && idx < el.options.length) {
        const opt = el.options[idx];
        el.value = String(opt.value || '');
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, pickB);

    await page.click('#start-url-1');
    await page.click('#start-url-2');

    const startedAt = nowMs();
    while (nowMs() - startedAt < MAX_TOTAL_MS) {
      for (const slot of ['A', 'B']) {
        const snap = await collectSlotState(page, slot);
        const current = slotData[slot];

        const changed = !current.lastSnapshot
          || current.lastSnapshot.percent !== snap.percent
          || current.lastSnapshot.status !== snap.status
          || current.lastSnapshot.text !== snap.text;

        if (changed) {
          current.lastSnapshot = snap;
          current.lastChangeMs = snap.ts;
          current.events += 1;
          timeline.push({
            time: new Date(snap.ts).toISOString(),
            slot,
            status: snap.status,
            percent: snap.percent,
            text: snap.text,
          });
        }

        if (!current.done && snap.status === 'done') {
          current.done = true;
        }

        if (snap.status === 'error') {
          throw new Error(`Slot ${slot} reached error state: ${snap.text}`);
        }

        if (!current.done) {
          const stallMs = nowMs() - current.lastChangeMs;
          const inDecodingPhase = String(snap.text || '').toLowerCase().includes('decoding audio');
          if (!inDecodingPhase && stallMs > MAX_STALL_MS) {
            throw new Error(`Slot ${slot} stalled for ${Math.round(stallMs / 1000)}s at ${snap.percent}% (${snap.text})`);
          }
        }
      }

      if (slotData.A.done && slotData.B.done) {
        break;
      }

      await page.waitForTimeout(POLL_MS);
    }

    if (!slotData.A.done || !slotData.B.done) {
      throw new Error('Downloads did not finish in allowed time window.');
    }

    if (slotData.A.events < 10 || slotData.B.events < 10) {
      throw new Error(`Insufficient realtime events. A=${slotData.A.events}, B=${slotData.B.events}`);
    }

    const summary = {
      ok: true,
      slotAEvents: slotData.A.events,
      slotBEvents: slotData.B.events,
      totalEvents: timeline.length,
      firstEvents: timeline.slice(0, 10),
      lastEvents: timeline.slice(-12),
    };

    console.log(JSON.stringify(summary, null, 2));
  } finally {
    await context.close();
    await browser.close();
  }
}

main().catch((error) => {
  const out = {
    ok: false,
    error: error && error.message ? error.message : String(error),
  };
  console.error(JSON.stringify(out, null, 2));
  process.exit(1);
});
