#!/usr/bin/env python3
"""Lightweight realtime poller for DPA/PP/SBP badge tracking.

Reuses parse_realtime() and ingest_realtime() from fetcher.py without
running the full news-fetching path. Targets 30-second runtime per invocation.
"""
from __future__ import annotations
import os
import sys
import time

# Force JST so timestamps line up with WP-side current_time()
os.environ.setdefault("TZ", "Asia/Tokyo")
try:
    time.tzset()
except AttributeError:
    pass

import json
from typing import Any

from playwright.sync_api import sync_playwright

# Reuse helpers
from fetcher import (
    fetch_realtime_json,
    parse_realtime,
    ingest_realtime,
    trigger_snapshot,
    REALTIME_PARENT,
    UA,
)

try:
    from playwright_stealth import stealth_sync
except ImportError:
    stealth_sync = None


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
            print(f"[warn] chrome channel failed ({e}); falling back to chromium",
                  file=sys.stderr)
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

        realtime: dict[str, list[dict]] = {}
        for park in REALTIME_PARENT.keys():
            try:
                items = fetch_realtime_json(page, park)
                realtime[park] = parse_realtime(items)
                with_w = sum(1 for r in realtime[park] if r["wait_min"] is not None)
                pp = sum(1 for r in realtime[park] if r["has_pp"])
                dpa = sum(1 for r in realtime[park] if r["has_dpa"])
                sbp = sum(1 for r in realtime[park] if r["has_sbp"])
                print(f"[parse] {park}: {len(realtime[park])} items "
                      f"(with_wait={with_w} pp={pp} dpa={dpa} sbp={sbp})", file=sys.stderr)
            except Exception as e:
                print(f"[warn] {park}: {e}", file=sys.stderr)
                realtime[park] = []
        try:
            summary["realtime"] = ingest_realtime(realtime)
        except Exception as e:
            summary["realtime"] = {"error": f"ingest: {e}"}

        browser.close()

    # 待ち時間履歴ヒートマップに反映するためスナップショットを直後にトリガ。
    # /realtime エンドポイントが tdrt_waits_<park> を上書きしたあとに走らないと
    # 同じ値が記録され続けてしまう（DWR が止まっている本番環境で観測された問題）。
    summary["snapshot"] = trigger_snapshot()

    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0 if "error" not in summary.get("realtime", {}) else 1


if __name__ == "__main__":
    sys.exit(main())
