#!/usr/bin/env node
"use strict";

const { spawn, spawnSync } = require("child_process");
const crypto = require("crypto");
const fs = require("fs");
const net = require("net");
const os = require("os");
const path = require("path");

const baseUrl = (process.argv[2] || "http://127.0.0.1:8765").replace(/\/+$/, "");
const pages = [
  ["landing", "/index.html"],
  ["docs", "/docs.html"],
  ["demo", "/vercel-demo/index.html"],
  ["offline", "/offline.html"],
  ["app-register", "/litegig.php?action=register"],
];
const viewports = [
  { name: "mobile", width: 390, height: 844, mobile: true },
  { name: "desktop", width: 1280, height: 900, mobile: false },
];
const performanceBudgets = {
  loadMs: 2500,
  domInteractiveMs: 1800,
  resources: 28,
  encodedBytes: 900 * 1024,
};

function fail(message) {
  console.error(`FAIL: ${message}`);
  process.exit(1);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function terminateProcess(child) {
  if (!child || child.exitCode !== null || child.signalCode !== null) return;
  await new Promise((resolve) => {
    const timer = setTimeout(() => {
      child.kill("SIGKILL");
      resolve();
    }, 2500);
    child.once("exit", () => {
      clearTimeout(timer);
      resolve();
    });
    child.kill("SIGTERM");
  });
}

async function removeTreeWithRetry(dir) {
  for (let attempt = 0; attempt < 8; attempt++) {
    try {
      fs.rmSync(dir, { recursive: true, force: true });
      return;
    } catch (error) {
      if (!["ENOTEMPTY", "EBUSY", "EPERM"].includes(error.code) || attempt === 7) throw error;
      await sleep(250);
    }
  }
}

function findChrome() {
  const candidates = [
    process.env.CHROME_BIN,
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    "/Applications/Chromium.app/Contents/MacOS/Chromium",
    "google-chrome",
    "google-chrome-stable",
    "chromium",
    "chromium-browser",
    "chrome",
  ].filter(Boolean);

  for (const candidate of candidates) {
    if (candidate.includes(path.sep) && fs.existsSync(candidate)) return candidate;
    const found = spawnSync("which", [candidate], { encoding: "utf8" });
    if (found.status === 0 && found.stdout.trim()) return found.stdout.trim();
  }
  fail("Chrome/Chromium is required for render smoke checks. Set CHROME_BIN to the browser binary.");
}

class CDP {
  constructor(url) {
    const parsed = new URL(url);
    this.host = parsed.hostname;
    this.port = Number(parsed.port);
    this.path = parsed.pathname + parsed.search;
    this.socket = null;
    this.buffer = Buffer.alloc(0);
    this.handshake = false;
    this.nextId = 1;
    this.pending = new Map();
    this.listeners = new Map();
  }

  connect() {
    return new Promise((resolve, reject) => {
      const key = crypto.randomBytes(16).toString("base64");
      const socket = net.createConnection({ host: this.host, port: this.port }, () => {
        socket.write([
          `GET ${this.path} HTTP/1.1`,
          `Host: ${this.host}:${this.port}`,
          "Upgrade: websocket",
          "Connection: Upgrade",
          `Sec-WebSocket-Key: ${key}`,
          "Sec-WebSocket-Version: 13",
          "",
          "",
        ].join("\r\n"));
      });
      this.socket = socket;
      socket.on("data", (chunk) => {
        this.buffer = Buffer.concat([this.buffer, chunk]);
        if (!this.handshake) {
          const end = this.buffer.indexOf("\r\n\r\n");
          if (end === -1) return;
          const header = this.buffer.subarray(0, end).toString("utf8");
          if (!header.includes(" 101 ")) {
            reject(new Error(`DevTools WebSocket handshake failed: ${header.split("\r\n")[0]}`));
            return;
          }
          this.handshake = true;
          this.buffer = this.buffer.subarray(end + 4);
          resolve();
        }
        this.drainFrames();
      });
      socket.on("error", reject);
      socket.on("close", () => {
        for (const [, pending] of this.pending) pending.reject(new Error("DevTools WebSocket closed"));
        this.pending.clear();
      });
    });
  }

  on(method, callback) {
    if (!this.listeners.has(method)) this.listeners.set(method, []);
    this.listeners.get(method).push(callback);
  }

  waitFor(method, timeoutMs = 10000) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error(`Timed out waiting for ${method}`)), timeoutMs);
      this.on(method, (params) => {
        clearTimeout(timer);
        resolve(params);
      });
    });
  }

  send(method, params = {}) {
    const id = this.nextId++;
    const payload = JSON.stringify({ id, method, params });
    this.writeFrame(Buffer.from(payload, "utf8"));
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject, method });
      setTimeout(() => {
        if (this.pending.has(id)) {
          this.pending.delete(id);
          reject(new Error(`Timed out calling ${method}`));
        }
      }, 10000);
    });
  }

  close() {
    if (this.socket && !this.socket.destroyed) this.socket.destroy();
  }

  drainFrames() {
    while (this.buffer.length >= 2) {
      const first = this.buffer[0];
      const second = this.buffer[1];
      const opcode = first & 0x0f;
      let length = second & 0x7f;
      let offset = 2;
      if (length === 126) {
        if (this.buffer.length < 4) return;
        length = this.buffer.readUInt16BE(2);
        offset = 4;
      } else if (length === 127) {
        if (this.buffer.length < 10) return;
        const high = this.buffer.readUInt32BE(2);
        const low = this.buffer.readUInt32BE(6);
        length = high * 2 ** 32 + low;
        offset = 10;
      }
      const masked = (second & 0x80) !== 0;
      const maskOffset = masked ? 4 : 0;
      if (this.buffer.length < offset + maskOffset + length) return;
      let payload = this.buffer.subarray(offset + maskOffset, offset + maskOffset + length);
      if (masked) {
        const mask = this.buffer.subarray(offset, offset + 4);
        payload = Buffer.from(payload.map((byte, index) => byte ^ mask[index % 4]));
      }
      this.buffer = this.buffer.subarray(offset + maskOffset + length);
      if (opcode === 1) this.handleMessage(payload.toString("utf8"));
      if (opcode === 8) this.close();
      if (opcode === 9) this.writeFrame(payload, 0x0a);
    }
  }

  writeFrame(payload, opcode = 0x01) {
    const mask = crypto.randomBytes(4);
    let header;
    if (payload.length < 126) {
      header = Buffer.alloc(2);
      header[1] = 0x80 | payload.length;
    } else if (payload.length < 65536) {
      header = Buffer.alloc(4);
      header[1] = 0x80 | 126;
      header.writeUInt16BE(payload.length, 2);
    } else {
      header = Buffer.alloc(10);
      header[1] = 0x80 | 127;
      header.writeUInt32BE(0, 2);
      header.writeUInt32BE(payload.length, 6);
    }
    header[0] = 0x80 | opcode;
    const masked = Buffer.from(payload.map((byte, index) => byte ^ mask[index % 4]));
    this.socket.write(Buffer.concat([header, mask, masked]));
  }

  handleMessage(text) {
    const message = JSON.parse(text);
    if (message.id && this.pending.has(message.id)) {
      const pending = this.pending.get(message.id);
      this.pending.delete(message.id);
      if (message.error) pending.reject(new Error(`${pending.method}: ${message.error.message}`));
      else pending.resolve(message.result || {});
      return;
    }
    if (message.method && this.listeners.has(message.method)) {
      for (const callback of this.listeners.get(message.method)) callback(message.params || {});
    }
  }
}

async function requestJson(url, options = {}) {
  const response = await fetch(url, options);
  if (!response.ok) throw new Error(`${url} returned ${response.status}`);
  return response.json();
}

function pngSize(base64) {
  const png = Buffer.from(base64, "base64");
  if (png.length < 24 || png.toString("ascii", 1, 4) !== "PNG") {
    throw new Error("Screenshot is not a PNG.");
  }
  return { bytes: png.length, width: png.readUInt32BE(16), height: png.readUInt32BE(20) };
}

async function openTarget(devtoolsOrigin) {
  try {
    return await requestJson(`${devtoolsOrigin}/json/new?${encodeURIComponent("about:blank")}`, { method: "PUT" });
  } catch {
    return requestJson(`${devtoolsOrigin}/json/new?${encodeURIComponent("about:blank")}`);
  }
}

async function auditPage(devtoolsOrigin, label, url, viewport) {
  const target = await openTarget(devtoolsOrigin);
  const client = new CDP(target.webSocketDebuggerUrl);
  const errors = [];
  try {
    await client.connect();
    client.on("Runtime.exceptionThrown", (params) => {
      errors.push(params.exceptionDetails?.text || "Runtime exception");
    });
    client.on("Log.entryAdded", (params) => {
      if (["error", "warning"].includes(params.entry?.level)) errors.push(params.entry.text || params.entry.level);
    });
    await client.send("Page.enable");
    await client.send("Runtime.enable");
    await client.send("Log.enable");
    await client.send("Emulation.setDeviceMetricsOverride", {
      width: viewport.width,
      height: viewport.height,
      deviceScaleFactor: 1,
      mobile: viewport.mobile,
    });
    await client.send("Emulation.setTouchEmulationEnabled", { enabled: viewport.mobile });
    const load = client.waitFor("Page.loadEventFired", 15000).catch(() => null);
    const nav = await client.send("Page.navigate", { url });
    if (nav.errorText) throw new Error(nav.errorText);
    await load;
    await sleep(500);

    const evaluation = await client.send("Runtime.evaluate", {
      returnByValue: true,
      expression: `(() => {
        const doc = document.documentElement;
        const body = document.body;
        const meta = document.querySelector('meta[name="viewport"]');
        const text = (body?.innerText || '').replace(/\\s+/g, ' ').trim();
        const touchSelector = 'button,input:not([type="hidden"]),select,textarea,.btn,.tab,.nav a,.actions a,[role="button"]';
        const visible = (el) => {
          const rect = el.getBoundingClientRect();
          const style = getComputedStyle(el);
          return rect.width > 0 && rect.height > 0 && style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity) !== 0 && el.getAttribute('aria-hidden') !== 'true';
        };
        const descriptor = (el) => {
          const rect = el.getBoundingClientRect();
          const cls = typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().replace(/\\s+/g, '.') : '';
          return el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + cls + ' ' + Math.round(rect.width) + 'x' + Math.round(rect.height);
        };
        const textByIds = (value) => String(value || '').split(/\\s+/).map((id) => document.getElementById(id)?.textContent || '').join(' ').trim();
        const controlName = (el) => {
          if (el.getAttribute('aria-label')) return el.getAttribute('aria-label').trim();
          if (el.getAttribute('aria-labelledby')) return textByIds(el.getAttribute('aria-labelledby'));
          if (el.labels && el.labels.length) return Array.from(el.labels).map((label) => label.textContent || '').join(' ').trim();
          if (el.getAttribute('title')) return el.getAttribute('title').trim();
          return '';
        };
        const commandName = (el) => {
          if (el.getAttribute('aria-label')) return el.getAttribute('aria-label').trim();
          if (el.getAttribute('aria-labelledby')) return textByIds(el.getAttribute('aria-labelledby'));
          if (el.getAttribute('title')) return el.getAttribute('title').trim();
          return (el.textContent || '').trim();
        };
        const tinyTargets = Array.from(document.querySelectorAll(touchSelector))
          .filter(visible)
          .filter((el) => {
            const rect = el.getBoundingClientRect();
            return rect.width < 44 || rect.height < 44;
          })
          .slice(0, 8)
          .map(descriptor);
        const unnamedControls = Array.from(document.querySelectorAll('input:not([type="hidden"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="image"]),select,textarea'))
          .filter(visible)
          .filter((el) => controlName(el) === '')
          .slice(0, 8)
          .map(descriptor);
        const unnamedCommands = Array.from(document.querySelectorAll('a[href],button,[role="button"]'))
          .filter(visible)
          .filter((el) => commandName(el) === '')
          .slice(0, 8)
          .map(descriptor);
        const imageAltIssues = Array.from(document.querySelectorAll('img'))
          .filter(visible)
          .filter((el) => !el.hasAttribute('alt'))
          .slice(0, 8)
          .map(descriptor);
        const navigation = performance.getEntriesByType('navigation')[0];
        const resources = performance.getEntriesByType('resource');
        const encodedBytes = resources.reduce((sum, entry) => sum + (entry.encodedBodySize || 0), navigation?.encodedBodySize || 0);
        return {
          title: document.title,
          textLength: text.length,
          viewportMeta: meta ? meta.getAttribute('content') || '' : '',
          lang: document.documentElement.getAttribute('lang') || '',
          hasMain: !!document.querySelector('main'),
          clientWidth: doc.clientWidth || window.innerWidth,
          scrollWidth: Math.max(doc.scrollWidth, body ? body.scrollWidth : 0),
          timing: navigation ? {
            duration: Math.round(navigation.duration || 0),
            domInteractive: Math.round(navigation.domInteractive || 0),
            loadEventEnd: Math.round(navigation.loadEventEnd || 0),
            resourceCount: resources.length,
            encodedBytes: Math.round(encodedBytes),
          } : null,
          tinyTargets,
          unnamedControls,
          unnamedCommands,
          imageAltIssues,
        };
      })()`,
    });
    const value = evaluation.result?.value || {};
    if ((value.textLength || 0) < 80) throw new Error("rendered text is unexpectedly sparse");
    if (!String(value.viewportMeta || "").includes("width=device-width")) throw new Error("missing mobile viewport metadata");
    if (!String(value.lang || "").trim()) throw new Error("document language is missing");
    if (!value.hasMain) throw new Error("main landmark is missing");
    if ((value.scrollWidth || 0) > (value.clientWidth || viewport.width) + 1) {
      throw new Error(`horizontal overflow ${value.scrollWidth}px > ${value.clientWidth}px`);
    }
    if (value.tinyTargets && value.tinyTargets.length > 0) {
      throw new Error(`undersized touch targets: ${value.tinyTargets.join(", ")}`);
    }
    if (value.unnamedControls && value.unnamedControls.length > 0) {
      throw new Error(`form controls without accessible names: ${value.unnamedControls.join(", ")}`);
    }
    if (value.unnamedCommands && value.unnamedCommands.length > 0) {
      throw new Error(`commands without accessible names: ${value.unnamedCommands.join(", ")}`);
    }
    if (value.imageAltIssues && value.imageAltIssues.length > 0) {
      throw new Error(`images missing alt attributes: ${value.imageAltIssues.join(", ")}`);
    }
    if (!value.timing) throw new Error("navigation timing is unavailable");
    if ((value.timing.duration || 0) > performanceBudgets.loadMs) {
      throw new Error(`load duration ${value.timing.duration}ms exceeds ${performanceBudgets.loadMs}ms budget`);
    }
    if ((value.timing.domInteractive || 0) > performanceBudgets.domInteractiveMs) {
      throw new Error(`DOM interactive ${value.timing.domInteractive}ms exceeds ${performanceBudgets.domInteractiveMs}ms budget`);
    }
    if ((value.timing.resourceCount || 0) > performanceBudgets.resources) {
      throw new Error(`resource count ${value.timing.resourceCount} exceeds ${performanceBudgets.resources} budget`);
    }
    if ((value.timing.encodedBytes || 0) > performanceBudgets.encodedBytes) {
      throw new Error(`encoded transfer ${value.timing.encodedBytes} bytes exceeds ${performanceBudgets.encodedBytes} byte budget`);
    }
    if (errors.length > 0) throw new Error(`browser console/runtime errors: ${errors.join(" | ")}`);

    const shot = await client.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: false });
    const size = pngSize(shot.data || "");
    if (size.width !== viewport.width || size.height !== viewport.height) {
      throw new Error(`screenshot is ${size.width}x${size.height}, expected ${viewport.width}x${viewport.height}`);
    }
    if (size.bytes < 3000) throw new Error(`screenshot is too small (${size.bytes} bytes)`);
  } finally {
    client.close();
    if (target.id) {
      await fetch(`${devtoolsOrigin}/json/close/${encodeURIComponent(target.id)}`).catch(() => null);
    }
  }
  return `${label} ${viewport.name}`;
}

async function main() {
  const chrome = findChrome();
  const userDataDir = fs.mkdtempSync(path.join(os.tmpdir(), "litegig-render-chrome-"));
  let child;
  try {
    const args = [
      "--headless=new",
      "--disable-gpu",
      "--disable-dev-shm-usage",
      "--no-sandbox",
      "--no-first-run",
      "--no-default-browser-check",
      "--remote-debugging-address=127.0.0.1",
      "--remote-debugging-port=0",
      `--user-data-dir=${userDataDir}`,
      "about:blank",
    ];
    child = spawn(chrome, args, { stdio: ["ignore", "pipe", "pipe"] });
    let output = "";
    const devtoolsUrl = await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error("Timed out waiting for Chrome DevTools endpoint.")), 10000);
      const capture = (chunk) => {
        output += chunk.toString();
        const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/);
        if (match) {
          clearTimeout(timer);
          resolve(match[1]);
        }
      };
      child.stdout.on("data", capture);
      child.stderr.on("data", capture);
      child.on("exit", (code) => reject(new Error(`Chrome exited before startup (${code}). ${output}`)));
    });
    const parsed = new URL(devtoolsUrl);
    const devtoolsOrigin = `http://${parsed.hostname}:${parsed.port}`;
    let checks = 0;
    for (const [name, pagePath] of pages) {
      for (const viewport of viewports) {
        const label = await auditPage(devtoolsOrigin, name, baseUrl + pagePath, viewport);
        checks++;
        console.log(`ok ${checks} - ${label}`);
      }
    }
    console.log(`Render smoke passed (${checks} viewport checks).`);
  } finally {
    await terminateProcess(child);
    await removeTreeWithRetry(userDataDir);
  }
}

main().catch((error) => fail(error.message));
