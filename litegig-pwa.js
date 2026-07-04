(function () {
  const DB_NAME = "litegig-offline-actions";
  const STORE = "actions";
  let replaying = false;

  function showNotice(message, tone) {
    const wrap = document.querySelector(".wrap") || document.body;
    const notice = document.createElement("div");
    notice.className = "flash " + (tone === "error" ? "error" : "ok");
    notice.setAttribute("role", tone === "error" ? "alert" : "status");
    notice.textContent = message;
    const top = document.querySelector(".top");
    if (top && top.parentNode === wrap) {
      wrap.insertBefore(notice, top.nextSibling);
    } else {
      wrap.insertBefore(notice, wrap.firstChild);
    }
  }

  function openDb() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, 1);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(STORE)) {
          db.createObjectStore(STORE, { keyPath: "id" });
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }

  async function txStore(mode) {
    const db = await openDb();
    return db.transaction(STORE, mode).objectStore(STORE);
  }

  async function serializeForm(form) {
    const data = new FormData(form);
    const entries = [];
    for (const pair of data.entries()) {
      const name = pair[0];
      const value = pair[1];
      if (value instanceof File) {
        if (value.size > 0) {
          entries.push({ name, kind: "file", file: value, filename: value.name });
        }
      } else {
        entries.push({ name, kind: "text", value: String(value) });
      }
    }
    return entries;
  }

  async function queueForm(form) {
    const store = await txStore("readwrite");
    const row = {
      id: String(Date.now()) + "-" + Math.random().toString(16).slice(2),
      action: new URL(form.getAttribute("action") || location.href, location.href).toString(),
      method: (form.getAttribute("method") || "POST").toUpperCase(),
      label: form.dataset.offlineLabel || "Runner action",
      entries: await serializeForm(form),
      createdAt: new Date().toISOString()
    };
    await new Promise((resolve, reject) => {
      const request = store.put(row);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
    return row;
  }

  async function allQueued() {
    const store = await txStore("readonly");
    return new Promise((resolve, reject) => {
      const request = store.getAll();
      request.onsuccess = () => resolve(request.result || []);
      request.onerror = () => reject(request.error);
    });
  }

  async function deleteQueued(id) {
    const store = await txStore("readwrite");
    return new Promise((resolve, reject) => {
      const request = store.delete(id);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }

  function formDataFromRow(row) {
    const data = new FormData();
    for (const entry of row.entries || []) {
      if (entry.kind === "file" && entry.file) {
        data.append(entry.name, entry.file, entry.filename || "proof");
      } else if (entry.kind === "text") {
        data.append(entry.name, entry.value || "");
      }
    }
    return data;
  }

  async function replayQueue() {
    if (replaying || !navigator.onLine) return;
    replaying = true;
    try {
      const rows = await allQueued();
      let sent = 0;
      for (const row of rows) {
        const response = await fetch(row.action, {
          method: row.method || "POST",
          body: formDataFromRow(row),
          credentials: "same-origin"
        });
        if (!response.ok) {
          throw new Error("Replay failed with HTTP " + response.status);
        }
        await deleteQueued(row.id);
        sent += 1;
      }
      if (sent > 0) {
        showNotice("Synced " + sent + " queued runner action" + (sent === 1 ? "." : "s."), "ok");
      }
    } catch (error) {
      showNotice("Queued runner actions are still waiting to sync. Open the job sheet when you are back online.", "error");
    } finally {
      replaying = false;
    }
  }

  document.addEventListener("submit", async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.offlineQueue !== "runner") return;
    if (navigator.onLine) return;
    event.preventDefault();
    try {
      const row = await queueForm(form);
      showNotice(row.label + " queued. It will sync when this device is online.", "ok");
      form.reset();
    } catch (error) {
      showNotice("Could not queue this action offline. Keep this page open and try again.", "error");
    }
  });

  window.addEventListener("online", replayQueue);
  document.addEventListener("DOMContentLoaded", replayQueue);

  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("litegig-sw.js").catch(() => {});
    });
  }
})();
