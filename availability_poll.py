#!/usr/bin/env python3
"""Collect time-sensitive official inventory independently of news/closure work."""
from __future__ import annotations
import json, os, time
from playwright.sync_api import sync_playwright
from fetcher import (UA, fetch_ticket_sale_snapshot, fetch_realtime_json,
                     parse_realtime, ingest_ticket_price, ingest_realtime,
                     failed_sources, TICKET_TYPE_MAP)

def ticket_payload(snapshot):
    types={}
    for item in snapshot['items']:
        key=TICKET_TYPE_MAP.get(item['name'])
        if key:types[key]=item['prices']
    return {'sales_snapshot':snapshot,'types':types,**types.get('general',{})}

def main():
    summary={}
    with sync_playwright() as p:
        browser=p.chromium.launch(channel=os.getenv('PW_CHANNEL','chrome'),headless=True)
        context=browser.new_context(user_agent=UA,locale='ja-JP',viewport={'width':1280,'height':900})
        for park in ('tdl','tds'):
            # A failure in one dataset/park must not block successful independent data.
            page=context.new_page()
            try:
                date=time.strftime('%Y%m%d',time.localtime())
                snap=fetch_ticket_sale_snapshot(page,park,date)
                result=ingest_ticket_price({park:ticket_payload(snap)})
                if not result.get('dry_run') and result.get('stored',{}).get(park,{}).get('sales_saved') is not True:
                    result['error']='ticket_sales_not_persisted'
                summary['tickets_'+park]=result
            except Exception as e:
                summary['tickets_'+park]={'error':type(e).__name__+': '+str(e)}
            try:
                rows=parse_realtime(fetch_realtime_json(page,park))
                if not rows:raise ValueError('empty official realtime snapshot')
                result=ingest_realtime({park:rows})
                if not result.get('dry_run') and result.get('stored',{}).get(park)!=len(rows):
                    result['error']='realtime_not_persisted'
                summary['passes_'+park]=result
            except Exception as e:
                summary['passes_'+park]={'error':type(e).__name__+': '+str(e)}
            page.close()
        browser.close()
    print(json.dumps(summary,ensure_ascii=False,indent=2))
    return 1 if failed_sources(summary) else 0
if __name__=='__main__':raise SystemExit(main())
