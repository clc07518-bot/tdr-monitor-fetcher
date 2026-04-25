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

    print(json.dumps(summary, ensure_ascii=False, indent=2))
    failed = [k for k, v in summary.items() if "error" in v]
    total_sources = len(SOURCES) + 1  # +1 for prtimes
    return 1 if len(failed) == total_sources else 0


if __name__ == "__main__":
    sys.exit(main())
