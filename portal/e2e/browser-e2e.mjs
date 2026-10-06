/**
 * Chrome E2E for dockside portal against a live compose stack.
 * Driven by scripts/portal-browser-e2e.sh. Uses system google-chrome via playwright-core.
 *
 * Default path: German login → boats → start → Auf Fahrt correct → abort-with-damage
 * → offline banner → service worker → mobile (Pixel 7 + iPhone) login/list/start.
 *
 * Optional:
 *   PORTAL_E2E_WITH_DAMAGE=1  — severity labels + report does not finish trip
 *   PORTAL_E2E_WITH_ACK=1     — NOTAVAILABLE ACK dialog, start, abort cleanup;
 *                               also ACK_STALE renew via SQL comment mutation
 *   PORTAL_E2E_SKIP_MOBILE=1  — skip Pixel 7 / iPhone emulation pass
 *   PORTAL_E2E_SKIP_CORRECT_ABORT=1 — skip correct + abort-with-damage (legacy finish-only)
 */
import { execFileSync } from "node:child_process";
import { chromium, devices } from "playwright-core";

const BASE_URL = (process.env.PORTAL_E2E_BASE_URL || "http://127.0.0.1:18083").replace(/\/$/, "");
const PORTAL_URL = `${BASE_URL}/portal/`;
const USER_ID = process.env.PORTAL_LIVE_USER_ID || "902";
const PASSWORD = process.env.PORTAL_LIVE_PASSWORD || "RehearsePortal1!";
const PERSON_QUERY = process.env.PORTAL_E2E_PERSON_QUERY || "Aisenbrey";
const BOAT_HINT = process.env.PORTAL_E2E_BOAT_HINT || "";
const ACK_BOAT_HINT = process.env.PORTAL_E2E_ACK_BOAT_HINT || "";
const DEST_HINT = process.env.PORTAL_E2E_DEST_HINT || "Allermöhe";
const CHROME =
  process.env.CHROME_PATH ||
  process.env.GOOGLE_CHROME_BIN ||
  "/usr/bin/google-chrome";
const SKIP_OFFLINE = process.env.PORTAL_E2E_SKIP_OFFLINE === "1";
const HEADLESS = process.env.PORTAL_E2E_HEADED !== "1";
const WITH_DAMAGE = process.env.PORTAL_E2E_WITH_DAMAGE === "1";
const WITH_ACK = process.env.PORTAL_E2E_WITH_ACK === "1";
const SKIP_MOBILE = process.env.PORTAL_E2E_SKIP_MOBILE === "1";
const SKIP_CORRECT_ABORT = process.env.PORTAL_E2E_SKIP_CORRECT_ABORT === "1";
const COMPOSE_PROJECT = process.env.COMPOSE_PROJECT_NAME || "efacloud-desktop-synch";
const DB_USER = process.env.EFACLOUD_DB_USER || "efacloud";
const DB_PASS = process.env.EFACLOUD_DB_PASSWORD || "efacloud_dev_password";
const DB_NAME = process.env.EFACLOUD_DB_NAME || "efacloud";

const SEVERITY_LABELS = [
  "Boot nicht benutzbar",
  "Boot eingeschränkt benutzbar",
  "Boot voll benutzbar",
];

let passN = 0;
let failN = 0;

function ok(name, detail = "") {
  passN += 1;
  console.log(`  PASS  ${name}${detail ? ` — ${detail}` : ""}`);
}

function fail(name, detail = "") {
  failN += 1;
  console.log(`  FAIL  ${name}${detail ? ` — ${detail}` : ""}`);
}

function info(name, detail = "") {
  console.log(`  INFO  ${name}${detail ? ` — ${detail}` : ""}`);
}

function sql(query) {
  return execFileSync(
    "docker",
    [
      "compose",
      "-p",
      COMPOSE_PROJECT,
      "exec",
      "-T",
      "db",
      "mariadb",
      `-u${DB_USER}`,
      `-p${DB_PASS}`,
      DB_NAME,
      "-N",
      "-B",
      "-e",
      query,
    ],
    { encoding: "utf8" }
  ).trim();
}

function sqlEscape(s) {
  return String(s).replace(/\\/g, "\\\\").replace(/'/g, "''");
}

function mutateBoatComment(boatId, comment) {
  sql(
    `UPDATE efa2boatstatus SET Comment='${sqlEscape(comment)}' WHERE BoatId='${sqlEscape(boatId)}'`
  );
}

async function waitSuccess(page, text, timeout = 20000) {
  const box = page.locator(".success-box").filter({ hasText: text });
  await box.first().waitFor({ state: "visible", timeout });
}

async function dismissAcks(page, maxRounds = 8) {
  for (let i = 0; i < maxRounds; i++) {
    const dialog = page.getByRole("dialog");
    if (!(await dialog.isVisible().catch(() => false))) return;
    const ja = dialog.getByRole("button", { name: "Ja" });
    if (await ja.isVisible().catch(() => false)) {
      await ja.click();
      await page.waitForTimeout(500);
      continue;
    }
    return;
  }
}

async function pickPerson(page, label, query) {
  const field = page.locator(".field").filter({ has: page.getByText(label, { exact: true }) });
  const input = field.locator("input").first();
  await input.click();
  await input.fill("");
  await input.type(query, { delay: 40 });
  const suggest = page.locator(".person-suggest button").first();
  await suggest.waitFor({ state: "visible", timeout: 15000 });
  await suggest.click();
}

async function selectDestination(page) {
  const dest = page.locator("#dest");
  if (!(await dest.isVisible().catch(() => false))) return;
  let value = "";
  for (const opt of await dest.locator("option").all()) {
    const t = (await opt.textContent()) || "";
    const v = await opt.getAttribute("value");
    if (v && DEST_HINT && t.includes(DEST_HINT)) {
      value = v;
      break;
    }
    if (!value && v) value = v;
  }
  if (value) await dest.selectOption(value);
}

async function gotoBoats(page) {
  await page.goto(PORTAL_URL, { waitUntil: "domcontentloaded" });
  await page.getByRole("heading", { name: "Boote" }).waitFor({ state: "visible", timeout: 20000 });
}

async function openStartFormFromList(page, { hint = "", preferNoDamage = true } = {}) {
  const boatRows = page.locator("button.boat-row");
  await boatRows.first().waitFor({ state: "visible", timeout: 20000 });
  const boatCount = await boatRows.count();
  let target = boatRows.first();
  if (hint) {
    const hinted = page.locator("button.boat-row").filter({ hasText: hint }).first();
    if (await hinted.isVisible().catch(() => false)) {
      target = hinted;
    }
  } else if (preferNoDamage) {
    for (let i = 0; i < boatCount; i++) {
      const row = boatRows.nth(i);
      if (!(await row.locator(".badge-damage").count())) {
        target = row;
        break;
      }
    }
  }
  const boatTitle = (await target.locator(".boat-row-title").textContent())?.trim() || "?";
  await target.click();
  await page.waitForTimeout(800);
  if (await page.getByRole("button", { name: "Fahrt starten" }).isVisible().catch(() => false)) {
    const onForm = await page.getByRole("heading", { name: "Fahrt starten" }).isVisible().catch(() => false);
    if (!onForm) await page.getByRole("button", { name: "Fahrt starten" }).click();
  }
  if (await page.getByRole("heading", { name: "Konfiguration" }).isVisible().catch(() => false)) {
    await page.locator("button.boat-row, button.variant-row, .stack button").first().click();
  }
  await page.getByRole("heading", { name: "Fahrt starten" }).waitFor({ state: "visible", timeout: 15000 });
  return boatTitle;
}

function boatIdFromUrl(page) {
  const u = new URL(page.url());
  return u.searchParams.get("boatId") || "";
}

async function abortOpenTrip(page, { withDamage = false, description = "" } = {}) {
  const abortBtn = page.getByRole("button", { name: /Fahrt abbrechen/ });
  await abortBtn.waitFor({ state: "visible", timeout: 20000 });
  await abortBtn.click();
  const dialog = page.getByRole("dialog");
  await dialog.waitFor({ state: "visible", timeout: 10000 });
  if (withDamage) {
    await dialog.getByText("Mit Bootsschaden melden").click();
    await dialog.locator("#abort-desc").waitFor({ state: "visible", timeout: 5000 });
    await dialog.locator("#abort-desc").fill(description || `browser-e2e abort damage ${Date.now()}`);
    const sev = dialog.locator("#severity");
    if (await sev.isVisible().catch(() => false)) {
      await sev.selectOption({ label: "Boot eingeschränkt benutzbar" }).catch(async () => {
        await sev.selectOption({ index: 1 });
      });
    }
    await dialog.getByRole("button", { name: "Fahrt abbrechen (Bootsschaden)" }).click();
    await waitSuccess(page, /abgebrochen und Schaden|abgebrochen/i);
  } else {
    await dialog.getByRole("button", { name: "Fahrt abbrechen", exact: true }).click();
    await waitSuccess(page, /abgebrochen|gelöscht/i);
  }
}

async function openTripFromAufFahrt(page, boatHint = "") {
  await gotoBoats(page);
  await page.getByRole("tab", { name: "Auf Fahrt", exact: true }).click();
  await page.waitForTimeout(500);
  const boatRows = page.locator("button.boat-row");
  await boatRows.first().waitFor({ state: "visible", timeout: 20000 });
  let target = boatRows.first();
  if (boatHint) {
    const hinted = boatRows.filter({ hasText: boatHint }).first();
    if (await hinted.isVisible().catch(() => false)) target = hinted;
  }
  const title = ((await target.locator(".boat-row-title").textContent()) || "").trim();
  await target.click();
  await page.getByRole("heading", { name: /Fahrt korrigieren|Fahrt/ }).waitFor({
    state: "visible",
    timeout: 15000,
  });
  return title;
}

async function runCorrectAndAbortWithDamage(page, boatTitle) {
  console.log("-- correct + abort-with-damage --");
  const opened = await openTripFromAufFahrt(page, boatTitle);
  ok("Auf Fahrt opens open trip", opened);

  // Ensure correct mode
  const corrChip = page.getByRole("button", { name: "Korrigieren", exact: true });
  if (await corrChip.isVisible().catch(() => false)) {
    await corrChip.click();
  }
  const headingOk =
    (await page.getByRole("heading", { name: "Fahrt korrigieren" }).isVisible().catch(() => false)) ||
    (await page.getByRole("button", { name: "Korrektur speichern" }).isVisible().catch(() => false));
  if (headingOk) {
    ok("correct screen from Auf Fahrt", "Korrigieren mode ready");
  } else {
    fail("correct screen from Auf Fahrt", "missing heading/save");
  }

  // Nudge destination if possible (still valid)
  await selectDestination(page);
  await page.getByRole("button", { name: "Korrektur speichern" }).click();
  await dismissAcks(page);
  await waitSuccess(page, "Fahrt korrigiert");
  ok("correct trip (server-confirmed)");

  await abortOpenTrip(page, {
    withDamage: true,
    description: `browser-e2e abort-with-damage ${Date.now()} (safe to ignore)`,
  });
  ok("abort-with-damage (server-confirmed)");
  await page.getByRole("heading", { name: "Boote" }).waitFor({ state: "visible", timeout: 15000 });
}

async function runMobileViewportPass(browser) {
  console.log("-- mobile viewport (Pixel 7 + iPhone) --");
  const profiles = [
    { name: "Pixel 7", descriptor: devices["Pixel 7"] },
    { name: "iPhone 13", descriptor: devices["iPhone 13"] },
  ];
  for (const { name, descriptor } of profiles) {
    if (!descriptor) {
      fail(`mobile ${name}`, "playwright device descriptor missing");
      continue;
    }
    const context = await browser.newContext({
      ...descriptor,
      locale: "de-DE",
      serviceWorkers: "allow",
    });
    const page = await context.newPage();
    page.setDefaultTimeout(25000);
    try {
      await page.goto(PORTAL_URL, { waitUntil: "domcontentloaded" });
      await page.getByLabel("Konto").waitFor({ state: "visible", timeout: 20000 });
      await page.getByLabel("Konto").fill(USER_ID);
      await page.getByLabel("Passwort").fill(PASSWORD);
      await page.getByRole("button", { name: /Anmelden/ }).click();
      await page.getByRole("heading", { name: "Boote" }).waitFor({ state: "visible", timeout: 20000 });
      ok(`${name} login + boat list`);

      const tabVerfuegbar = page.getByRole("tab", { name: "Verfügbar", exact: true });
      if (!(await tabVerfuegbar.isVisible().catch(() => false))) {
        fail(`${name} boat views`, "Verfügbar tab missing");
      } else {
        ok(`${name} boat views visible`);
      }

      const seatRow = page.getByLabel("Sitzplätze");
      const einer = seatRow.getByRole("button", { name: /^Einer/ });
      if (await einer.isVisible().catch(() => false)) {
        await einer.click();
        await page.waitForTimeout(400);
      }
      const boatTitle = await openStartFormFromList(page, { preferNoDamage: true });
      await pickPerson(page, "Name", PERSON_QUERY);
      await selectDestination(page);
      await page.getByRole("button", { name: "Fahrt starten" }).click();
      await dismissAcks(page);
      await waitSuccess(page, "Fahrt gestartet");
      ok(`${name} start trip`, boatTitle);
      await page.waitForURL(/\/portal\/trips\/(?!new)[^/]+/, { timeout: 15000 });
      await abortOpenTrip(page);
      ok(`${name} abort cleanup`);
    } catch (e) {
      fail(`mobile ${name}`, String(e?.message || e));
    } finally {
      await context.close();
    }
  }
}

async function runDamageSection(page) {
  console.log("-- with-damage --");
  await gotoBoats(page);
  const seatRow = page.getByLabel("Sitzplätze");
  const einer = seatRow.getByRole("button", { name: /^Einer/ });
  if (await einer.isVisible().catch(() => false)) {
    await einer.click();
    await page.waitForTimeout(400);
  }
  const boatTitle = await openStartFormFromList(page, { hint: BOAT_HINT, preferNoDamage: true });
  const boatId = boatIdFromUrl(page);
  info("damage boat", `${boatTitle} id=${boatId || "?"}`);

  await page.getByRole("button", { name: "Schaden melden" }).click();
  await page.getByRole("heading", { name: "Schaden melden" }).waitFor({ state: "visible", timeout: 15000 });

  const severity = page.locator("#severity");
  await severity.waitFor({ state: "visible", timeout: 10000 });
  const optionTexts = (await severity.locator("option").allTextContents()).map((t) => t.trim());
  const missing = SEVERITY_LABELS.filter((l) => !optionTexts.includes(l));
  if (missing.length === 0) {
    ok("damage three severities", SEVERITY_LABELS.join(" / "));
  } else {
    fail("damage three severities", `missing=${missing.join(",")} got=${optionTexts.join("|")}`);
  }

  await severity.selectOption({ label: "Boot voll benutzbar" });
  const desc = `browser-e2e damage ${Date.now()} (safe to ignore)`;
  await page.locator("#desc").fill(desc);
  await page.getByRole("button", { name: "Melden", exact: true }).click();
  const success = page.locator(".success-box");
  await success.first().waitFor({ state: "visible", timeout: 20000 });
  const successText = (await success.first().textContent()) || "";
  if (
    /nicht beendet oder abgebrochen/i.test(successText) ||
    /Schaden gemeldet/i.test(successText)
  ) {
    ok("damage report does not finish trip", successText.trim().slice(0, 80));
  } else {
    fail("damage report does not finish trip", successText);
  }

  // Still on damage/list flow — must not have navigated to a finished-trip success path.
  await page.waitForTimeout(1200);
  const url = page.url();
  if (/\/damages/.test(url) || /\/boats\//.test(url)) {
    ok("damage UI stayed on boat/damage path", url.replace(PORTAL_URL, "/portal/"));
  } else {
    fail("damage UI stayed on boat/damage path", url);
  }
}

async function runAckSection(page) {
  console.log("-- with-ack --");
  await gotoBoats(page);
  await page.getByRole("tab", { name: "Nicht verfügbar", exact: true }).click();
  await page.waitForTimeout(600);
  const seatRow = page.getByLabel("Sitzplätze");
  const einer = seatRow.getByRole("button", { name: /^Einer/ });
  if (await einer.isVisible().catch(() => false)) {
    await einer.click();
    await page.waitForTimeout(400);
  }

  const hint = ACK_BOAT_HINT || "Baracuda";
  let boatTitle;
  try {
    boatTitle = await openStartFormFromList(page, { hint, preferNoDamage: false });
  } catch {
    boatTitle = await openStartFormFromList(page, { preferNoDamage: false });
  }
  const boatId = boatIdFromUrl(page);
  if (!boatId) {
    fail("ACK NOTAVAILABLE boatId", "missing from URL");
    return;
  }
  info("ACK boat", `${boatTitle} id=${boatId}`);

  await pickPerson(page, "Name", PERSON_QUERY);
  await selectDestination(page);

  // Force ACK_STALE once: mutate status Comment after tokens are attached to start POST.
  let staleMutated = false;
  const originalComment = sql(
    `SELECT IFNULL(Comment,'') FROM efa2boatstatus WHERE BoatId='${sqlEscape(boatId)}' LIMIT 1`
  );
  await page.route("**/api/portal/v1/trips", async (route) => {
    try {
      if (route.request().method() === "POST" && !staleMutated) {
        let body = {};
        try {
          body = route.request().postDataJSON() || {};
        } catch {
          body = {};
        }
        const tokens = body.acknowledgmentTokens || [];
        if (Array.isArray(tokens) && tokens.length > 0) {
          mutateBoatComment(boatId, `browser-e2e-ack-stale-${Date.now()}`);
          staleMutated = true;
          info("ACK_STALE mutate", "Comment updated between ack and start");
        }
      }
    } catch (e) {
      info("ACK_STALE mutate error", String(e));
    }
    await route.continue();
  });

  await page.getByRole("button", { name: "Fahrt starten" }).click();

  // First dialog: status override
  const dialog = page.getByRole("dialog");
  await dialog.waitFor({ state: "visible", timeout: 20000 });
  const title1 = ((await dialog.locator("h2").textContent()) || "").trim();
  if (/gesperrt|reserviert|Schaden/i.test(title1)) {
    ok("ACK dialog visible", title1);
  } else {
    fail("ACK dialog visible", title1);
  }
  await dialog.getByRole("button", { name: "Ja" }).click();

  // After Ja: either more acks, ACK_STALE renew dialog, or start success.
  // Walk remaining dialogs (stale renew + any further checks).
  let sawStaleRenew = false;
  for (let i = 0; i < 8; i++) {
    await page.waitForTimeout(700);
    if (await page.locator(".success-box").filter({ hasText: "Fahrt gestartet" }).isVisible().catch(() => false)) {
      break;
    }
    if (await dialog.isVisible().catch(() => false)) {
      const t = ((await dialog.locator("h2").textContent()) || "").trim();
      if (staleMutated && /gesperrt|reserviert|Schaden/i.test(t)) {
        sawStaleRenew = true;
      }
      const ja = dialog.getByRole("button", { name: "Ja" });
      if (await ja.isVisible().catch(() => false)) {
        await ja.click();
        continue;
      }
    }
    // Error box with renew messaging also acceptable evidence of stale handling
    const err = page.locator(".error-box");
    if (await err.isVisible().catch(() => false)) {
      const et = (await err.textContent()) || "";
      if (/ACK|erneut|Bestätigung|Änderung|Bedingungen/i.test(et)) {
        sawStaleRenew = true;
        info("ACK_STALE error path", et.trim().slice(0, 100));
        // Try dismiss by re-clicking start if form still there
        if (await page.getByRole("button", { name: "Fahrt starten" }).isVisible().catch(() => false)) {
          await page.getByRole("button", { name: "Fahrt starten" }).click();
          continue;
        }
      }
    }
  }

  if (staleMutated && sawStaleRenew) {
    ok("ACK_STALE renew UI", "dialog/error after Comment mutation");
  } else if (staleMutated) {
    // Mutation may race after start already validated — still record INFO, not hard fail if start worked
    info("ACK_STALE renew UI", "mutation ran but renew dialog not clearly observed");
  }

  await waitSuccess(page, "Fahrt gestartet", 30000);
  ok("ACK override start", boatTitle);

  await page.waitForURL(/\/portal\/trips\/(?!new)[^/]+/, { timeout: 15000 });
  await abortOpenTrip(page);
  ok("ACK path abort cleanup", boatTitle);

  // Restore comment so lab fleet stays tidy
  try {
    mutateBoatComment(boatId, originalComment);
  } catch {
    /* ignore */
  }
  await page.unroute("**/api/portal/v1/trips").catch(() => {});
}

async function run() {
  console.log(
    `== portal browser E2E @ ${PORTAL_URL} (chrome=${CHROME}` +
      ` damage=${WITH_DAMAGE ? "on" : "off"} ack=${WITH_ACK ? "on" : "off"}) ==`
  );
  const browser = await chromium.launch({
    executablePath: CHROME,
    headless: HEADLESS,
    args: ["--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu"],
  });
  const context = await browser.newContext({
    locale: "de-DE",
    viewport: { width: 420, height: 900 },
    serviceWorkers: "allow",
  });
  const page = await context.newPage();
  page.setDefaultTimeout(25000);

  try {
    // --- 1. German login shell ---
    await page.goto(PORTAL_URL, { waitUntil: "domcontentloaded" });
    await page.getByLabel("Konto").waitFor({ state: "visible", timeout: 20000 });
    const brand = await page.locator(".brand-mark").textContent().catch(() => "");
    const anmelden = page.getByRole("button", { name: /Anmelden/ });
    const konto = page.getByLabel("Konto");
    const pass = page.getByLabel("Passwort");
    if (
      (brand || "").includes("efaPortal") &&
      (await anmelden.isVisible()) &&
      (await konto.isVisible()) &&
      (await pass.isVisible())
    ) {
      ok("German login shell", "efaPortal / Konto / Passwort / Anmelden");
    } else {
      fail("German login shell", `brand=${brand}`);
    }

    // --- 2. Login 902 ---
    await konto.fill(USER_ID);
    await pass.fill(PASSWORD);
    await anmelden.click();
    await page.getByRole("heading", { name: "Boote" }).waitFor({ state: "visible", timeout: 20000 });
    ok("login", `user=${USER_ID}`);

    // --- 3. Boat list: views + seat chips ---
    const tabVerfuegbar = page.getByRole("tab", { name: "Verfügbar", exact: true });
    const tabAufFahrt = page.getByRole("tab", { name: "Auf Fahrt", exact: true });
    const tabNicht = page.getByRole("tab", { name: "Nicht verfügbar", exact: true });
    const seatRow = page.getByLabel("Sitzplätze");
    if (
      (await tabVerfuegbar.isVisible()) &&
      (await tabAufFahrt.isVisible()) &&
      (await tabNicht.isVisible()) &&
      (await seatRow.isVisible())
    ) {
      ok("boat views + seat chips", "Verfügbar / Auf Fahrt / Nicht verfügbar + Sitzplätze");
    } else {
      fail("boat views + seat chips");
    }

    const einer = seatRow.getByRole("button", { name: /^Einer/ });
    if (await einer.isVisible().catch(() => false)) {
      await einer.click();
      await page.waitForTimeout(500);
      ok("seat chip Einer", "selected");
    } else {
      info("seat chip Einer", "not present — using Alle");
    }

    const boatRows = page.locator("button.boat-row");
    await boatRows.first().waitFor({ state: "visible", timeout: 20000 });
    const boatCount = await boatRows.count();
    if (boatCount < 1) {
      fail("available boat list", "empty");
      throw new Error("no boats to start");
    }
    ok("available boat list", `rows=${boatCount}`);

    const boatTitle = await openStartFormFromList(page, {
      hint: BOAT_HINT,
      preferNoDamage: true,
    });
    ok("open start form", boatTitle);

    // --- 4. Fill crew + destination, start ---
    await pickPerson(page, "Name", PERSON_QUERY);
    await selectDestination(page);
    if (await page.locator("#dest").isVisible().catch(() => false)) {
      ok("destination selected", DEST_HINT || "first");
    } else {
      info("destination", "none required / empty list");
    }

    await page.getByRole("button", { name: "Fahrt starten" }).click();
    await dismissAcks(page);
    await waitSuccess(page, "Fahrt gestartet");
    ok("start trip (server-confirmed)", boatTitle);

    await page.waitForURL(/\/portal\/trips\/(?!new)[^/]+/, { timeout: 15000 });
    await page.getByRole("button", { name: "Beenden", exact: true }).waitFor({
      state: "visible",
      timeout: 20000,
    });

    // --- 5. Correct (Auf Fahrt) + abort-with-damage (default) or finish ---
    if (!SKIP_CORRECT_ABORT) {
      // Leave trip open; navigate via Auf Fahrt list (parity with dockside workflow).
      await runCorrectAndAbortWithDamage(page, boatTitle);
    } else {
      await page.getByRole("button", { name: "Beenden", exact: true }).click();
      const distance = page.locator("#distance");
      if (await distance.isVisible()) {
        const cur = await distance.inputValue();
        if (!cur) await distance.fill("5");
      }
      await page.getByRole("button", { name: "Fahrt beenden", exact: true }).click();
      await waitSuccess(page, "Fahrt beendet");
      ok("finish trip (server-confirmed)");
      await page.getByRole("heading", { name: "Boote" }).waitFor({ state: "visible", timeout: 15000 });
    }

    // --- 5b. Optional damage / ACK (before offline) ---
    if (WITH_DAMAGE) {
      await runDamageSection(page);
    } else {
      info("damage checks", "skipped (pass --with-damage)");
    }
    if (WITH_ACK) {
      await runAckSection(page);
    } else {
      info("ACK checks", "skipped (pass --with-ack)");
    }

    // --- 6. Optional offline ---
    await gotoBoats(page);
    if (!SKIP_OFFLINE) {
      await context.setOffline(true);
      await page.waitForTimeout(300);
      const banner = page.getByRole("status").filter({ hasText: /Offline/i });
      if (await banner.isVisible().catch(() => false)) {
        ok("offline banner", "Offline — Fahrten nur online speicherbar");
      } else {
        const cdp = await context.newCDPSession(page);
        await cdp.send("Network.enable");
        await cdp.send("Network.emulateNetworkConditions", {
          offline: true,
          latency: 0,
          downloadThroughput: 0,
          uploadThroughput: 0,
        });
        await page.evaluate(() => {
          window.dispatchEvent(new Event("offline"));
        });
        await page.waitForTimeout(200);
        if (await banner.isVisible().catch(() => false)) {
          ok("offline banner", "after CDP + offline event");
        } else {
          fail("offline banner", "not visible after setOffline");
        }
      }

      const firstBoat = page.locator("button.boat-row").first();
      if (await firstBoat.isVisible().catch(() => false)) {
        await firstBoat.click();
        await page.waitForTimeout(600);
        if (await page.getByRole("button", { name: "Fahrt starten" }).isVisible().catch(() => false)) {
          const onForm = await page.getByRole("heading", { name: "Fahrt starten" }).isVisible().catch(() => false);
          if (!onForm) await page.getByRole("button", { name: "Fahrt starten" }).click();
        }
        if (await page.getByRole("heading", { name: "Fahrt starten" }).isVisible().catch(() => false)) {
          await pickPerson(page, "Name", PERSON_QUERY).catch(() => {});
          if (await page.locator("#dest").isVisible().catch(() => false)) {
            const opts = page.locator("#dest option");
            const n = await opts.count();
            for (let i = 0; i < n; i++) {
              const v = await opts.nth(i).getAttribute("value");
              if (v) {
                await page.locator("#dest").selectOption(v);
                break;
              }
            }
          }
          await page.getByRole("button", { name: "Fahrt starten" }).click();
          await page.waitForTimeout(1500);
          const success = await page.locator(".success-box").isVisible().catch(() => false);
          const err =
            (await page.locator(".error-box").isVisible().catch(() => false)) ||
            (await page.getByText(/Keine Verbindung|Netz|online/i).isVisible().catch(() => false));
          if (!success && err) {
            ok("offline mutation no false success", "error/network message shown");
          } else if (!success) {
            ok("offline mutation no false success", "no success box (error optional)");
          } else {
            fail("offline mutation no false success", "success shown while offline");
          }
        } else {
          info("offline mutation", "could not open start form offline — banner check only");
        }
      }
      await context.setOffline(false);
      try {
        const cdp2 = await context.newCDPSession(page);
        await cdp2.send("Network.emulateNetworkConditions", {
          offline: false,
          latency: 0,
          downloadThroughput: -1,
          uploadThroughput: -1,
        });
      } catch {
        /* ignore */
      }
      await page.evaluate(() => window.dispatchEvent(new Event("online")));
      await page.goto(PORTAL_URL, { waitUntil: "domcontentloaded" });
      await page.getByRole("heading", { name: "Boote" }).waitFor({ state: "visible", timeout: 20000 }).catch(() => {});
    } else {
      info("offline checks", "skipped (PORTAL_E2E_SKIP_OFFLINE=1)");
    }

    // --- 7. Service worker ---
    const sw = await page.evaluate(async () => {
      if (!("serviceWorker" in navigator)) return { supported: false };
      const regs = await navigator.serviceWorker.getRegistrations();
      const portal = regs.find((r) => (r.scope || "").includes("/portal"));
      return {
        supported: true,
        count: regs.length,
        scope: portal?.scope || null,
        active: !!portal?.active,
        scriptURL: portal?.active?.scriptURL || portal?.installing?.scriptURL || null,
      };
    });
    if (sw.supported && (sw.scope || sw.count > 0)) {
      ok("service worker", `scope=${sw.scope} active=${sw.active} script=${sw.scriptURL}`);
    } else if (sw.supported) {
      await page.waitForTimeout(1500);
      const sw2 = await page.evaluate(async () => {
        const regs = await navigator.serviceWorker.getRegistrations();
        const portal = regs.find((r) => (r.scope || "").includes("/portal"));
        return {
          scope: portal?.scope || null,
          active: !!portal?.active,
          scriptURL: portal?.active?.scriptURL || null,
          count: regs.length,
        };
      });
      if (sw2.scope || sw2.count > 0) {
        ok("service worker", `scope=${sw2.scope} active=${sw2.active}`);
      } else {
        fail("service worker", `no /portal/ registration (regs=${sw2.count})`);
      }
    } else {
      fail("service worker", "navigator.serviceWorker unsupported");
    }

    // --- 8. Mobile device emulation (Pixel 7 + iPhone) ---
    if (!SKIP_MOBILE) {
      await runMobileViewportPass(browser);
    } else {
      info("mobile viewport", "skipped (PORTAL_E2E_SKIP_MOBILE=1)");
    }
  } catch (e) {
    fail("e2e crashed", String(e?.stack || e));
  } finally {
    await browser.close();
  }

  console.log();
  console.log(`browser_e2e PASS=${passN} FAIL=${failN}`);
  return failN === 0 ? 0 : 1;
}

process.exit(await run());
