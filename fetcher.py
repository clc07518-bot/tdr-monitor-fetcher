#!/usr/bin/env python3
"""
TDR Monitor external fetcher.

Playwright with real Google Chrome + playwright-stealth is used for
tokyodisneyresort.jp / olc.co.jp because Akamai Bot Manager blocks
plain requests and bundled Chromium via TLS/JA3 fingerprint.

PRTIMES is fetched via the per-company RDF feed
(https://prtimes.jp/companyrdf.php?company_id=119340) because the HTML page
is a React SPA that doesn't hydrate under headless automation.

Runs on GitHub Actions every 3 min, POSTs normalized items to
/wp-json/tdr-mon/v1/ingest.
"""
from __future__ import annotations
import hashlib
import json
import os
import sys
import time
from typing import Any, Callable
from urllib.parse import urljoin

# Force JST: GHA runs in UTC, but X auto-post hour window + park-hours date are JST.
os.environ.setdefault("TZ", "Asia/Tokyo")
try:
    time.tzset()
except AttributeError:
    pass

import requests
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout
try:
    from playwright_stealth import stealth_sync
except ImportError:
    stealth_sync = None

DRY_RUN = os.environ.get("DRY_RUN", "0") == "1"
WP_BASE = os.environ.get("WP_BASE", "").rstrip("/")
TOKEN   = os.environ.get("WP_INGEST_TOKEN", "")
INGEST  = f"{WP_BASE}/wp-json/tdr-mon/v1/ingest" if WP_BASE else ""

# X (Twitter) auto-poster credentials. Optional — auto-post is skipped when absent.
X_CK  = os.environ.get("X_CONSUMER_KEY", "")
X_CS  = os.environ.get("X_CONSUMER_SECRET", "")
X_AT  = os.environ.get("X_ACCESS_TOKEN", "")
X_ATS = os.environ.get("X_ACCESS_TOKEN_SECRET", "")
X_ENABLED = bool(X_CK and X_CS and X_AT and X_ATS) and os.environ.get("X_AUTO_POST", "1") != "0"

# Auto-post safety constraints
X_DAILY_LIMIT = int(os.environ.get("X_DAILY_LIMIT", "15"))
X_HOUR_START  = int(os.environ.get("X_HOUR_START", "7"))
X_HOUR_END    = int(os.environ.get("X_HOUR_END", "23"))
X_ALLOWED_SOURCES = {"prtimes", "update", "urgent", "olc_tdr"}  # exclude stop_*
X_STATE_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".x_state.json")
if not DRY_RUN and (not WP_BASE or not TOKEN):
    raise SystemExit("WP_BASE and WP_INGEST_TOKEN required (or set DRY_RUN=1)")

UA = ("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36")

SOURCES = {
    "update":   ("https://www.tokyodisneyresort.jp/tdr/news/update.html", "li .iconTag"),
    "urgent":   ("https://www.tokyodisneyresort.jp/tdr/update.html",       "main"),
    "stop_tdl": ("https://www.tokyodisneyresort.jp/tdl/monthly/stop.html", "li .heading3"),
    "stop_tds": ("https://www.tokyodisneyresort.jp/tds/monthly/stop.html", "li .heading3"),
    "olc_tdr":  ("https://www.olc.co.jp/ja/news/news_tdr.html",            "main, .newsList, body"),
}

# PRTIMES uses RDF feed instead of SPA scraping (much more reliable).
PRTIMES_RDF = "https://prtimes.jp/companyrdf.php?company_id=119340"

# Official park calendar (Akamai-protected → Playwright required).
PARK_CALENDAR_URL = "https://www.tokyodisneyresort.jp/tdr/calendar.html"
PARK_HOURS_INGEST = f"{WP_BASE}/wp-json/tdr-today/v1/park-hours" if WP_BASE else ""

# Show / parade daily schedules (per-park).
SHOW_SCHEDULE_URLS = {
    "tdl": "https://www.tokyodisneyresort.jp/tdl/daily/calendar.html",
    "tds": "https://www.tokyodisneyresort.jp/tds/daily/calendar.html",
}
SHOWS_INGEST = f"{WP_BASE}/wp-json/tdr-today/v1/shows" if WP_BASE else ""

# Character greeting realtime wait times (per-park).
# 公式 HTML には現在ほぼデータが無い。/_/realtime/<park>_greeting.json を XHR で取る。
# Akamai 越え用に親 HTML を踏んでセッション確立してから fetch する。
GREETING_PARENT = {
    "tdl": "https://www.tokyodisneyresort.jp/tdl/realtime/greeting.html",
    "tds": "https://www.tokyodisneyresort.jp/tds/realtime/greeting.html",
}
GREETING_URLS = GREETING_PARENT  # legacy alias
GREETINGS_INGEST = f"{WP_BASE}/wp-json/tdr-today/v1/greetings" if WP_BASE else ""

# Snapshot trigger (15-min reliable cron without depending on WP-Cron).
SNAPSHOT_TRIGGER = f"{WP_BASE}/wp-json/tdr-today/v1/snapshot" if WP_BASE else ""

# Realtime: TDR が公開している内部 JSON API。
# 2026-04-26 現在、HTML 版の /<park>/realtime/ には待ち時間が一切埋め込まれず、
# /_/realtime/<park>_attraction.json (Akamai 越し XHR) のみが正解の rate-data 源。
# 親ページ <park>/realtime/attraction.html を踏んでセッションを確立してから JSON を fetch する。
REALTIME_PARENT = {
    "tdl": "https://www.tokyodisneyresort.jp/tdl/realtime/attraction.html",
    "tds": "https://www.tokyodisneyresort.jp/tds/realtime/attraction.html",
}
# 旧 HTML エンドポイント（互換のため定義は残す。利用は廃止。）
REALTIME_URLS = REALTIME_PARENT
REALTIME_INGEST = f"{WP_BASE}/wp-json/tdr-today/v1/realtime" if WP_BASE else ""


def stable_id(source: str, url: str, title: str = "") -> str:
    return hashlib.sha1(f"{source}|{url}|{title}".encode()).hexdigest()[:16]


def render(page, url: str, wait_selector: str, timeout_ms: int = 30000) -> str:
    page.goto(url, wait_until="domcontentloaded", timeout=timeout_ms)
    try:
        page.wait_for_selector(wait_selector, timeout=10000)
    except PWTimeout:
        pass
    # Let lazy content settle
    page.wait_for_timeout(800)
    return page.content()


def render_with_retry(page, url: str, wait_selector: str, attempts: int = 2) -> str:
    last_exc: Exception | None = None
    for i in range(attempts):
        try:
            return render(page, url, wait_selector)
        except Exception as e:
            last_exc = e
            print(f"[warn] {url} attempt {i+1}/{attempts} failed: {e}", file=sys.stderr)
            if i + 1 < attempts:
                time.sleep(2)
    raise last_exc if last_exc else RuntimeError("render failed")


def parse_tdr_update(html: str, base_url: str) -> list[dict]:
    soup = BeautifulSoup(html, "lxml")
    items = []
    for li in soup.select("li"):
        a = li.find("a", href=True)
        if not a:
            continue
        tag = li.select_one("span.iconTag")
        date = li.select_one("p.date, .date")
        title_el = li.select_one("p.txt, .txt")
        if not (tag and title_el):
            continue
        title = title_el.get_text(" ", strip=True)
        if not title:
            continue
        url = urljoin(base_url, a["href"])
        category = tag.get_text(strip=True)
        pubdate = date.get_text(strip=True) if date else ""
        img_el = li.find("img")
        image_url = urljoin(base_url, img_el["src"]) if img_el and img_el.get("src") else ""
        items.append({
            "id": stable_id("update", url, title),
            "title": f"[{category}] {title}" if category else title,
            "url": url,
            "pubdate": pubdate,
            "category": category,
            "image_url": image_url,
        })
    return items


def parse_tdr_urgent(html: str, base_url: str) -> list[dict]:
    soup = BeautifulSoup(html, "lxml")
    items = []
    # Only items within explicit notice containers, with a txt/title/heading and a date-bearing element.
    containers = soup.select(
        ".infoList li, .newsList li, .mainContents li, "
        "section.info li, [class*='notice'] li, [class*='update'] li"
    )
    for li in containers:
        a = li.find("a", href=True)
        if not a:
            continue
        title_el = li.select_one("p.txt, .txt, .title, p.heading3, .heading3")
        date_el = li.select_one("p.date, .date, time")
        if not (title_el and date_el):
            continue
        title = title_el.get_text(" ", strip=True)
        if not title or len(title) < 5 or len(title) > 300:
            continue
        url = urljoin(base_url, a["href"])
        pubdate = date_el.get_text(" ", strip=True)
        items.append({
            "id": stable_id("urgent", url, title),
            "title": title,
            "url": url,
            "pubdate": pubdate,
            "category": "重要",
            "image_url": "",
        })
    return items


def parse_tdr_stop(html: str, base_url: str, source_key: str) -> list[dict]:
    soup = BeautifulSoup(html, "lxml")
    items = []
    # Structure: li > a > img + div.listTextArea > p.heading3 (name) + p (period)
    for li in soup.find_all("li"):
        name_el = li.select_one("p.heading3, .heading3")
        if not name_el:
            continue
        name = name_el.get_text(" ", strip=True)
        if len(name) < 2 or len(name) > 200:
            continue
        # Period is the last <p> inside listTextArea, i.e. the sibling of p.heading3
        period = ""
        text_area = li.select_one(".listTextArea")
        if text_area:
            ps = text_area.find_all("p")
            if len(ps) >= 2:
                period = ps[-1].get_text(" ", strip=True)
        period = " ".join(period.split())  # collapse whitespace
        title = f"{name}｜{period}" if period else name
        url = base_url
        img_el = li.find("img")
        image_url = urljoin(base_url, img_el["src"]) if img_el and img_el.get("src") else ""
        a = li.find("a", href=True)
        if a:
            url = urljoin(base_url, a["href"])
        items.append({
            "id": stable_id(source_key, name, period),
            "title": title,
            "url": url,
            "pubdate": period,
            "category": "休止",
            "image_url": image_url,
        })
    return items


def parse_olc_tdr(html: str, base_url: str) -> list[dict]:
    soup = BeautifulSoup(html, "lxml")
    items = []
    # OLC release list items have class "category_jaElm release_jaElm" and a single link.
    for li in soup.select("li.category_jaElm.release_jaElm, li.release_jaElm"):
        a = li.find("a", href=True)
        if not a:
            continue
        title_el = li.select_one("span.news_tx")
        if not title_el:
            continue
        title = " ".join(title_el.get_text(" ", strip=True).split())
        if not title or len(title) < 5 or len(title) > 400:
            continue
        date_el = li.select_one("span.date")
        pubdate = date_el.get_text(strip=True) if date_el else li.get("entrydate", "")
        # category: find tdr_ja, resort-line_ja, ikspiari_ja etc.
        cat_el = li.select_one("span[data-category-group='renkei'][data-category-level='2']")
        category = cat_el.get_text(strip=True) if cat_el else "プレスリリース"
        url = urljoin(base_url, a["href"])
        items.append({
            "id": stable_id("olc_tdr", url, title),
            "title": title,
            "url": url,
            "pubdate": pubdate,
            "category": category,
            "image_url": "",
        })
    return items


def fetch_prtimes_rdf() -> list[dict]:
    """PRTIMES company RDF feed — server-rendered XML, no browser required."""
    r = requests.get(PRTIMES_RDF, headers={"User-Agent": UA}, timeout=20)
    r.raise_for_status()
    soup = BeautifulSoup(r.text, "xml")
    items: list[dict] = []
    for it in soup.find_all("item"):
        title_el = it.find("title")
        link_el = it.find("link")
        date_el = it.find("date") or it.find("pubDate")
        if not (title_el and link_el):
            continue
        title = title_el.get_text(strip=True)
        url = link_el.get_text(strip=True)
        if not title or not url:
            continue
        pubdate = date_el.get_text(strip=True) if date_el else ""
        items.append({
            "id": stable_id("prtimes", url, title),
            "title": title,
            "url": url,
            "pubdate": pubdate,
            "category": "プレスリリース",
            "image_url": "",
        })
    return items


PARSERS: dict[str, Callable[[str, str], list[dict]]] = {
    "update":   parse_tdr_update,
    "urgent":   parse_tdr_urgent,
    "stop_tdl": lambda h, u: parse_tdr_stop(h, u, "stop_tdl"),
    "stop_tds": lambda h, u: parse_tdr_stop(h, u, "stop_tds"),
    "olc_tdr":  parse_olc_tdr,
}


def x_load_state() -> dict:
    try:
        with open(X_STATE_FILE) as f:
            return json.load(f)
    except Exception:
        return {"posted_ids": [], "by_date": {}, "seeded": False}


def x_save_state(state: dict) -> None:
    try:
        with open(X_STATE_FILE, "w") as f:
            json.dump(state, f, ensure_ascii=False)
    except Exception as e:
        print(f"[warn] x_save_state: {e}", file=sys.stderr)


def x_seed_if_first_run(all_items_by_source: dict[str, list[dict]]) -> dict:
    """初回実行時 (state.seeded != True) は posted_ids に既存全 ID を入れて
    過去記事の連投を防ぐ。次回以降に検出された 'new' のみがポスト対象になる。
    Returns: {"seeded": bool, "seeded_count": int}
    """
    state = x_load_state()
    if state.get("seeded"):
        return {"seeded": False}
    seeded_count = 0
    posted_ids = set(state.get("posted_ids", []))
    for src, items in all_items_by_source.items():
        if src not in X_ALLOWED_SOURCES:
            continue
        for it in items:
            iid = it.get("id")
            if iid and iid not in posted_ids:
                posted_ids.add(iid)
                seeded_count += 1
    state["posted_ids"] = list(posted_ids)
    state["seeded"] = True
    x_save_state(state)
    return {"seeded": True, "seeded_count": seeded_count}


def post_to_x(item: dict) -> dict:
    """Post a single item to X with safety constraints.

    Returns: {"posted": bool, "reason": str|None, "tweet_id": str|None}
    """
    if not X_ENABLED:
        return {"posted": False, "reason": "x_disabled"}
    src = item.get("source")
    if src not in X_ALLOWED_SOURCES:
        return {"posted": False, "reason": f"source_filtered:{src}"}
    now = time.localtime()
    if not (X_HOUR_START <= now.tm_hour < X_HOUR_END):
        return {"posted": False, "reason": f"out_of_hours:{now.tm_hour}"}

    state = x_load_state()
    today_key = time.strftime("%Y%m%d", now)
    today_count = state.get("by_date", {}).get(today_key, 0)
    if today_count >= X_DAILY_LIMIT:
        return {"posted": False, "reason": f"daily_limit:{today_count}"}

    # 初回実行（posted_ids が空）は過去記事を一気にポストしないようシード扱い。
    # 呼び出し側で先に x_seed_if_first_run() するためここでは記録のみ。
    item_id = item.get("id", "")
    if item_id and item_id in state.get("posted_ids", []):
        return {"posted": False, "reason": "already_posted"}

    title = (item.get("title") or "").strip()
    url = item.get("url") or ""
    if not title or not url:
        return {"posted": False, "reason": "missing_title_or_url"}

    # Compose 280-char-safe text
    body = f"🆕 TDRニュース速報\n\n{title}\n\n詳細はこちら👇\n{url}\n\n#東京ディズニーリゾート #TDR"
    if len(body) > 270:
        # Trim title to fit
        overflow = len(body) - 270
        new_title = title[: max(20, len(title) - overflow - 3)] + "…"
        body = f"🆕 TDRニュース速報\n\n{new_title}\n\n詳細はこちら👇\n{url}\n\n#東京ディズニーリゾート #TDR"

    if DRY_RUN:
        print(f"[x dry-run] would post: {body[:100]}…", file=sys.stderr)
        return {"posted": False, "reason": "dry_run", "preview": body}

    try:
        import tweepy  # imported here so DRY_RUN doesn't require it
    except ImportError as e:
        return {"posted": False, "reason": f"tweepy_missing:{e}"}

    try:
        client = tweepy.Client(
            consumer_key=X_CK,
            consumer_secret=X_CS,
            access_token=X_AT,
            access_token_secret=X_ATS,
        )
        resp = client.create_tweet(text=body)
        tid = str(resp.data.get("id")) if resp and resp.data else None
        # Persist state
        state.setdefault("posted_ids", []).append(item_id)
        # Cap posted_ids list at 1000
        if len(state["posted_ids"]) > 1000:
            state["posted_ids"] = state["posted_ids"][-1000:]
        state.setdefault("by_date", {})
        state["by_date"][today_key] = today_count + 1
        # Prune by_date older than 7 days
        cutoff = time.strftime("%Y%m%d", time.localtime(time.time() - 7*86400))
        state["by_date"] = {k: v for k, v in state["by_date"].items() if k >= cutoff}
        x_save_state(state)
        return {"posted": True, "tweet_id": tid, "title": title[:60]}
    except Exception as e:
        return {"posted": False, "reason": f"api_error:{e}"}


def parse_park_calendar(html: str) -> dict[str, dict[str, str]]:
    """Parse /tdr/calendar.html for today's TDL/TDS open/close hours.

    Returns: {"tdl": {"open": "9:00", "close": "21:00"}, "tds": {...}}
    """
    today_ymd = time.strftime("%Y%m%d", time.localtime())
    result: dict[str, dict[str, str]] = {}
    import re as _re
    for park in ("tdl", "tds"):
        pat = (
            r'<a href="/' + park + r'/daily/calendar/' + today_ymd + r'/"[^>]*>'
            r'\s*<p class="openTime">\s*'
            r'(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})'
        )
        m = _re.search(pat, html)
        if m:
            result[park] = {
                "open":  f"{int(m.group(1))}:{m.group(2)}",
                "close": f"{int(m.group(3))}:{m.group(4)}",
            }
    return result


def parse_show_schedule(html: str) -> list[dict]:
    """Parse /{tdl|tds}/daily/calendar.html for today's show/parade times.

    Returns: [{"name": ..., "times": ["15:00", "20:35"], "category": "show|parade"}, ...]
    Excludes character greetings (lines whose name contains グリーティング etc.).
    """
    soup = BeautifulSoup(html, "html.parser")
    out: list[dict] = []
    for li in soup.select(".linkList33 li"):
        name_el = li.select_one(".heading3")
        if not name_el:
            continue
        name = name_el.get_text(strip=True)
        # Filter character greetings
        if any(k in name for k in ("グリーティング", "ハウス前", "ガジェット", "アリス", "プルート", "ミニーの家")):
            continue
        # Collect timetables — each .timetable may have multiple times separated by "/"
        # Skip entries whose timetable looks like "9:00 - 21:00" (operating-hour range, not show times)
        times: list[str] = []
        is_range = False
        import re as _re
        for tt in li.select(".timetable"):
            txt = tt.get_text(" ", strip=True)
            # Detect range separator (greeting attractions have "HH:MM - HH:MM")
            if _re.search(r"\d{1,2}:\d{2}\s*[-〜～]\s*\d{1,2}:\d{2}", txt):
                is_range = True
                break
            for part in txt.replace("／", "/").split("/"):
                part = part.strip()
                for m in _re.findall(r"(\d{1,2}:\d{2})", part):
                    times.append(m)
        if is_range or not times:
            continue
        # Categorize
        if "パレード" in name or "ドリームライツ" in name:
            cat = "parade"
        else:
            cat = "show"
        out.append({"name": name, "times": times, "category": cat})
    return out


def fetch_greeting_json(page, park: str) -> dict:
    """Fetch /_/realtime/<park>_greeting.json via Playwright session."""
    parent = GREETING_PARENT[park]
    page.goto(parent, wait_until="domcontentloaded", timeout=60000)
    try:
        page.wait_for_load_state("networkidle", timeout=15000)
    except Exception:
        pass
    page.wait_for_timeout(800)
    result = page.evaluate(
        """async (park) => {
            const r = await fetch('/_/realtime/' + park + '_greeting.json?' + Date.now(), {credentials: 'include'});
            const t = await r.text();
            return {status: r.status, text: t};
        }""",
        park,
    )
    if result.get("status") != 200:
        raise RuntimeError(f"greeting json HTTP {result.get('status')} for {park}")
    return json.loads(result["text"])


def parse_greetings(data) -> list[dict]:
    """Normalize greeting JSON.

    Input: dict[area_key, {AreaJName, Facility:[{greeting:{...}}]}] from /_/realtime/<park>_greeting.json
    Output: list of {name, location, wait_min, status}

    Backwards-compat: returns [] if a string (legacy HTML) is passed.
    """
    if isinstance(data, str):
        return []
    if not isinstance(data, dict):
        return []
    out: list[dict] = []
    seen: set[str] = set()
    for area_key, area in data.items():
        if not isinstance(area, dict):
            continue
        location = (area.get("AreaJName") or "").strip()
        for fac_wrapper in area.get("Facility", []) or []:
            if not isinstance(fac_wrapper, dict):
                continue
            g = fac_wrapper.get("greeting") or {}
            name = (g.get("FacilityName") or "").strip()
            if not name or name in seen:
                continue
            seen.add(name)
            # Wait time
            st = g.get("StandbyTime")
            wait_min = None
            if isinstance(st, str) and st.strip().isdigit():
                v = int(st)
                if 0 <= v <= 480:
                    wait_min = v
            # Status: 案内中 (001) → operating; その他 → closed
            status = "closed"
            for slot in g.get("operatinghours", []) or []:
                if isinstance(slot, dict) and slot.get("OperatingStatusCD") == "001":
                    status = "operating"
                    break
            out.append({
                "name": name,
                "location": location,
                "wait_min": wait_min,
                "status": status,
            })
    return out


def ingest_greetings(per_park: dict[str, list[dict]]) -> dict[str, Any]:
    if not per_park or not any(per_park.values()):
        return {"endpoint": "greetings", "skipped": "no greetings parsed"}
    if DRY_RUN:
        return {"endpoint": "greetings", "dry_run": True,
                "counts": {k: len(v) for k, v in per_park.items()}}
    payload = dict(per_park)
    r = requests.post(
        GREETINGS_INGEST,
        headers={"X-TDR-Token": TOKEN, "Content-Type": "application/json"},
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        timeout=15,
    )
    try:
        body = r.json()
    except Exception:
        body = {"raw": r.text[:300]}
    return {"endpoint": "greetings", "status": r.status_code, **body}


def fetch_realtime_json(page, park: str) -> list[dict]:
    """Fetch /_/realtime/<park>_attraction.json via Playwright session.

    Akamai/WAF blocks naked curl; we need to first navigate to the parent
    HTML page to establish session cookies, then fetch the JSON via XHR
    from the same origin.
    """
    parent = REALTIME_PARENT[park]
    page.goto(parent, wait_until="domcontentloaded", timeout=60000)
    try:
        page.wait_for_load_state("networkidle", timeout=15000)
    except Exception:
        pass
    page.wait_for_timeout(800)
    result = page.evaluate(
        """async (park) => {
            const r = await fetch('/_/realtime/' + park + '_attraction.json?' + Date.now(), {credentials: 'include'});
            const t = await r.text();
            return {status: r.status, text: t};
        }""",
        park,
    )
    status = result.get("status")
    if status != 200:
        raise RuntimeError(f"realtime json HTTP {status} for {park}")
    return json.loads(result["text"])


def parse_realtime(items_or_html) -> list[dict]:
    """Normalize the realtime JSON list (or skip if HTML is passed in).

    Input: list[dict] from /_/realtime/<park>_attraction.json
    Output: list of {name, has_pp, has_dpa, has_sbp, wait_min, status}

    Backwards-compat: if a string is passed (legacy HTML callers), return
    [] — the HTML page no longer exposes wait times.
    """
    if isinstance(items_or_html, str):
        return []
    items = items_or_html or []
    out: list[dict] = []
    seen: set[str] = set()
    for it in items:
        name = (it.get("FacilityName") or "").strip()
        if not name or name in seen:
            continue
        seen.add(name)
        # Wait time: StandbyTime is "<int>" str, False, or None
        st = it.get("StandbyTime")
        wait_min: int | None = None
        if isinstance(st, str) and st.strip().isdigit():
            v = int(st)
            if 0 <= v <= 480:
                wait_min = v
        # Status
        op_cd = it.get("OperatingStatusCD")
        if op_cd == "001":
            status = "operating"
        elif op_cd == "004":  # 一時運営中止
            status = "closed"
            wait_min = None
        else:
            status = "operating"  # null = treat as default-operating
        # Badges: only consider "currently issuing" (CD == '1')
        has_dpa = (it.get("DPAStatusCD") == "1")
        has_pp  = (it.get("PPStatusCD")  == "1")
        # SBP: Fsflg true and not flagged-off via FsStatusflg/FsStatus
        fsflg = bool(it.get("Fsflg"))
        fs_status_flg = it.get("FsStatusflg")
        has_sbp = fsflg and (fs_status_flg is None)
        out.append({
            "name": name,
            "has_pp": has_pp,
            "has_dpa": has_dpa,
            "has_sbp": has_sbp,
            "wait_min": wait_min,
            "status": status,
        })
    return out


def ingest_realtime(per_park: dict[str, list[dict]]) -> dict[str, Any]:
    if not per_park or not any(per_park.values()):
        return {"endpoint": "realtime", "skipped": "no realtime parsed"}
    if DRY_RUN:
        return {"endpoint": "realtime", "dry_run": True,
                "counts": {k: len(v) for k, v in per_park.items()}}
    payload = dict(per_park)
    r = requests.post(
        REALTIME_INGEST,
        headers={"X-TDR-Token": TOKEN, "Content-Type": "application/json"},
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        timeout=20,
    )
    try:
        body = r.json()
    except Exception:
        body = {"raw": r.text[:300]}
    return {"endpoint": "realtime", "status": r.status_code, **body}


def trigger_snapshot() -> dict[str, Any]:
    """確実な15分間隔のためにスナップショットを明示的に呼ぶ。"""
    if not SNAPSHOT_TRIGGER or DRY_RUN:
        return {"endpoint": "snapshot", "skipped": "no WP_BASE or dry-run"}
    try:
        r = requests.post(
            SNAPSHOT_TRIGGER,
            headers={"X-TDR-Token": TOKEN, "Content-Type": "application/json"},
            data=b"{}",
            timeout=15,
        )
        try:
            body = r.json()
        except Exception:
            body = {"raw": r.text[:300]}
        return {"endpoint": "snapshot", "status": r.status_code, **body}
    except Exception as e:
        return {"endpoint": "snapshot", "error": str(e)}


def ingest_show_schedule(per_park: dict[str, list[dict]]) -> dict[str, Any]:
    if not per_park or not any(per_park.values()):
        return {"endpoint": "shows", "skipped": "no shows parsed"}
    if DRY_RUN:
        return {"endpoint": "shows", "dry_run": True, "counts": {k: len(v) for k, v in per_park.items()}}
    payload = {**per_park, "date": time.strftime("%Y%m%d", time.localtime())}
    r = requests.post(
        SHOWS_INGEST,
        headers={"X-TDR-Token": TOKEN, "Content-Type": "application/json"},
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        timeout=15,
    )
    try:
        body = r.json()
    except Exception:
        body = {"raw": r.text[:300]}
    return {"endpoint": "shows", "status": r.status_code, **body}


def ingest_park_hours(hours: dict[str, dict[str, str]]) -> dict[str, Any]:
    if not hours:
        return {"endpoint": "park-hours", "skipped": "no hours parsed"}
    if DRY_RUN:
        return {"endpoint": "park-hours", "dry_run": True, "hours": hours}
    payload = {**hours, "date": time.strftime("%Y%m%d", time.localtime())}
    r = requests.post(
        PARK_HOURS_INGEST,
        headers={"X-TDR-Token": TOKEN, "Content-Type": "application/json"},
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        timeout=15,
    )
    try:
        body = r.json()
    except Exception:
        body = {"raw": r.text[:300]}
    return {"endpoint": "park-hours", "status": r.status_code, **body}


def ingest(source: str, items: list[dict]) -> dict[str, Any]:
    if not items:
        return {"source": source, "received": 0, "new": 0, "skipped": True}
    if DRY_RUN:
        return {"source": source, "dry_run": True, "count": len(items),
                "first_title": items[0].get("title", "")[:80]}
    payload = {"source": source, "items": items}
    r = requests.post(
        INGEST,
        headers={"X-TDR-Token": TOKEN, "Content-Type": "application/json"},
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        timeout=30,
    )
    try:
        body = r.json()
    except Exception:
        body = {"raw": r.text[:500]}
    return {"source": source, "status": r.status_code, **body}


def main() -> int:
    summary: dict[str, Any] = {}
    # 各ソースの items を1度貯めてから、最後に first-run-seed → 投稿ループ
    # （first-run時点で過去記事が大量に流れるのを防ぐ）
    items_by_source: dict[str, list[dict]] = {}

    with sync_playwright() as p:
        launch_kwargs = {
            "headless": True,
            "args": ["--disable-blink-features=AutomationControlled"],
        }
        channel = os.environ.get("PW_CHANNEL", "chrome")
        if channel:
            launch_kwargs["channel"] = channel
        try:
            browser = p.chromium.launch(**launch_kwargs)
        except Exception as e:
            print(f"[warn] chrome channel failed ({e}); falling back to bundled chromium", file=sys.stderr)
            launch_kwargs.pop("channel", None)
            browser = p.chromium.launch(**launch_kwargs)
        context = browser.new_context(
            user_agent=UA,
            locale="ja-JP",
            viewport={"width": 1280, "height": 900},
            extra_http_headers={"Accept-Language": "ja,en-US;q=0.9,en;q=0.8"},
        )
        page = context.new_page()
        if stealth_sync is not None:
            try:
                stealth_sync(page)
            except Exception as e:
                print(f"[warn] stealth_sync failed: {e}", file=sys.stderr)

        for key, (url, wait_sel) in SOURCES.items():
            print(f"[fetch] {key} {url}", file=sys.stderr)
            t0 = time.time()
            try:
                html = render_with_retry(page, url, wait_sel)
            except Exception as e:
                summary[key] = {"error": f"render: {e}"}
                continue
            dur = round(time.time() - t0, 1)
            print(f"[ok]    {key} len={len(html)} {dur}s", file=sys.stderr)
            try:
                items = PARSERS[key](html, url)
            except Exception as e:
                summary[key] = {"error": f"parse: {e}", "html_len": len(html)}
                continue
            seen_ids: set[str] = set()
            unique: list[dict] = []
            for it in items:
                if it["id"] in seen_ids:
                    continue
                seen_ids.add(it["id"])
                unique.append(it)
                if len(unique) >= 30:
                    break
            print(f"[parse] {key} items={len(unique)}", file=sys.stderr)
            res = ingest(key, unique)
            summary[key] = res
            # X 投稿は後回し（first-run-seed 後に一括処理）
            for it in unique:
                it["source"] = key
            items_by_source.setdefault(key, []).extend(unique)

        # Park calendar (today's TDL/TDS hours)
        try:
            html_cal = render_with_retry(page, PARK_CALENDAR_URL, "table.calendarTable")
        except Exception as e:
            summary["park_hours"] = {"error": f"render: {e}"}
        else:
            try:
                hours = parse_park_calendar(html_cal)
                print(f"[parse] park_hours = {hours}", file=sys.stderr)
                summary["park_hours"] = ingest_park_hours(hours)
            except Exception as e:
                summary["park_hours"] = {"error": f"parse: {e}"}

        # Show / parade schedules per park
        shows: dict[str, list[dict]] = {}
        for park, url in SHOW_SCHEDULE_URLS.items():
            try:
                html_show = render_with_retry(page, url, ".linkList33")
                shows[park] = parse_show_schedule(html_show)
                print(f"[parse] shows {park}: {len(shows[park])} items", file=sys.stderr)
            except Exception as e:
                print(f"[warn] shows {park}: {e}", file=sys.stderr)
                shows[park] = []
        try:
            summary["shows"] = ingest_show_schedule(shows)
        except Exception as e:
            summary["shows"] = {"error": f"ingest: {e}"}

        # Character greetings: 公式 JSON API (/_/realtime/<park>_greeting.json) を叩く。
        # HTML 版は待ち時間を埋め込まなくなったため使用不可（2026-04-27 確認）。
        greetings: dict[str, list[dict]] = {}
        for park in GREETING_PARENT.keys():
            try:
                data = fetch_greeting_json(page, park)
                greetings[park] = parse_greetings(data)
                op = sum(1 for r in greetings[park] if r["status"] == "operating")
                print(f"[parse] greetings {park}: {len(greetings[park])} items (operating={op})", file=sys.stderr)
            except Exception as e:
                print(f"[warn] greetings {park}: {e}", file=sys.stderr)
                greetings[park] = []
        try:
            summary["greetings"] = ingest_greetings(greetings)
        except Exception as e:
            summary["greetings"] = {"error": f"ingest: {e}"}

        # Realtime: 公式 JSON API (/_/realtime/<park>_attraction.json) を叩く。
        # HTML 版 /<park>/realtime/ は待ち時間を埋め込まなくなったため使用不可（2026-04-26 確認）。
        realtime: dict[str, list[dict]] = {}
        for park in REALTIME_PARENT.keys():
            try:
                items = fetch_realtime_json(page, park)
                realtime[park] = parse_realtime(items)
                with_w = sum(1 for r in realtime[park] if r["wait_min"] is not None)
                pp = sum(1 for r in realtime[park] if r["has_pp"])
                dpa = sum(1 for r in realtime[park] if r["has_dpa"])
                sbp = sum(1 for r in realtime[park] if r["has_sbp"])
                print(f"[parse] realtime {park}: {len(realtime[park])} items "
                      f"(with_wait={with_w} pp={pp} dpa={dpa} sbp={sbp})", file=sys.stderr)
            except Exception as e:
                print(f"[warn] realtime {park}: {e}", file=sys.stderr)
                realtime[park] = []
        try:
            summary["realtime"] = ingest_realtime(realtime)
        except Exception as e:
            summary["realtime"] = {"error": f"ingest: {e}"}

        browser.close()

    # PRTIMES via RDF feed (no browser needed — its HTML page is a client-rendered SPA)
    print(f"[fetch] prtimes {PRTIMES_RDF}", file=sys.stderr)
    t0 = time.time()
    try:
        items = fetch_prtimes_rdf()
    except Exception as e:
        summary["prtimes"] = {"error": f"rdf: {e}"}
    else:
        dur = round(time.time() - t0, 1)
        seen_ids: set[str] = set()
        unique: list[dict] = []
        for it in items:
            if it["id"] in seen_ids:
                continue
            seen_ids.add(it["id"])
            unique.append(it)
            if len(unique) >= 30:
                break
        print(f"[parse] prtimes items={len(unique)} ({dur}s)", file=sys.stderr)
        summary["prtimes"] = ingest("prtimes", unique)
        # X 投稿のために source 注入してプール
        for it in unique:
            it["source"] = "prtimes"
        items_by_source.setdefault("prtimes", []).extend(unique)

    # ── X 自動投稿（一括・after first-run seed）──
    # GHA の actions/cache が効いているか診断するため state を必ず log に出す。
    _x_state_pre = x_load_state()
    print(f"[x] state pre-run: seeded={_x_state_pre.get('seeded')} "
          f"posted_ids={len(_x_state_pre.get('posted_ids', []))} "
          f"by_date={_x_state_pre.get('by_date', {})} "
          f"state_file_exists={os.path.exists(X_STATE_FILE)}",
          file=sys.stderr)
    print(f"[x] enabled={X_ENABLED} hours=[{X_HOUR_START}-{X_HOUR_END}) "
          f"daily_limit={X_DAILY_LIMIT} "
          f"items_total={sum(len(v) for v in items_by_source.values())} "
          f"items_by_source={{{', '.join(f'{k}:{len(v)}' for k,v in items_by_source.items())}}}",
          file=sys.stderr)
    if X_ENABLED:
        seed = x_seed_if_first_run(items_by_source)
        if seed.get("seeded"):
            summary["x_seed"] = {"seeded_count": seed.get("seeded_count", 0),
                                  "note": "first-run: existing items recorded, no posts"}
            print(f"[x] first-run seeded {seed.get('seeded_count')} items, "
                  f"no posts this run", file=sys.stderr)
        else:
            x_results: list[dict] = []
            x_filtered: dict[str, int] = {}
            for src in ("urgent", "olc_tdr", "update", "prtimes"):  # 優先度順
                for it in items_by_source.get(src, []):
                    r = post_to_x(it)
                    if r.get("posted"):
                        x_results.append({
                            "source": src,
                            "title": r.get("title"),
                            "tweet_id": r.get("tweet_id"),
                        })
                        print(f"[x] posted {src}: {r.get('title')} → {r.get('tweet_id')}",
                              file=sys.stderr)
                    else:
                        reason = r.get("reason", "unknown")
                        x_filtered[reason] = x_filtered.get(reason, 0) + 1
            if x_results:
                summary["x_posts"] = x_results
            if x_filtered:
                summary["x_filtered"] = x_filtered
                print(f"[x] filtered: {x_filtered}", file=sys.stderr)
    else:
        missing = []
        if not X_CK: missing.append("X_CONSUMER_KEY")
        if not X_CS: missing.append("X_CONSUMER_SECRET")
        if not X_AT: missing.append("X_ACCESS_TOKEN")
        if not X_ATS: missing.append("X_ACCESS_TOKEN_SECRET")
        summary["x_disabled"] = {
            "missing_env": missing,
            "x_auto_post_env": os.environ.get("X_AUTO_POST", "1"),
        }

    # Trigger snapshot AFTER all data is ingested (so 15-min historical record uses fresh data).
    summary["snapshot"] = trigger_snapshot()

    print(json.dumps(summary, ensure_ascii=False, indent=2))
    failed = [k for k, v in summary.items() if "error" in v]
    total_sources = len(SOURCES) + 1  # +1 for prtimes
    return 1 if len(failed) == total_sources else 0


if __name__ == "__main__":
    sys.exit(main())
