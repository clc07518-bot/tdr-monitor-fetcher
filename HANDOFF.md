# セッション引き継ぎ — TDR Monitor + ディズニー研究所ブログ

**最終更新**: 2026-04-26
**最後のコミット**: `ce321e3` (v1.3.1)
**プラグインバージョン**: 1.3.1（ローカル / GitHub）／ live WP は未アップロード（ユーザー作業待ち）

---

## 🚨 ユーザーが今すぐやること（最優先）

1. **WP管理画面 → プラグイン → 新規追加 → プラグインのアップロード** で
   `/Users/Yusuke_1/Desktop/tdr-monitor-fetcher/wp-plugin/tdr-today.zip` を選択
   → 既存の `TDR Today` を上書き有効化（v1.2.x → v1.3.1）

   このアップロードがないと：
   - 新しい REST エンドポイント `/realtime` `/snapshot` `/greetings` が叩けない
   - `wp_tdr_pass_events` テーブルが作成されない
   - DPA タイムライン `[tdr_pass_today]` ショートコードが使えない
   - 待ち時間データ源切替（`tdrt_waits_*`）が機能せず**ヒートマップが引き続きフリーズ**

2. （任意）`today-tdl` / `today-tds` 固定ページに以下を追記：
   ```
   <h2>キャラクターグリーティング待ち時間推移</h2>
   [tdr_today_heatmap park="tdl" type="greet"]

   <h2>本日のDPA / プライオリティパス発券状況</h2>
   [tdr_pass_today park="tdl"]
   ```

---

## プロジェクト全体図

### リポジトリ
- **GitHub**: `git@github.com:clc07518-bot/tdr-monitor-fetcher.git` (private)
- **ローカル**: `/Users/Yusuke_1/Desktop/tdr-monitor-fetcher/`
- **WP本番**: `https://yasushi-duffy.com/`

### 構成
```
fetcher.py            … メインスクレーパ (Playwright + 各種 parser)
realtime_poll.py      … 軽量リアルタイム専用ポーラー
.github/workflows/
  ├── monitor.yml     … */30 * * * * フル取得（ニュース+各種データ）
  └── realtime.yml    … 15,45 23,0,...,11 * * * リアルタイムのみ
wp-plugin/
  ├── tdr-mon-dashboard.php  … ダッシュボード+メール通知 (mu-plugin型)
  └── tdr-today/
      └── tdr-today.php       … 「今日のディズニー」ハブ（v1.3.1）
README.md             … セットアップ手順
HANDOFF.md            … このファイル
```

### データフロー (v1.3.1)
```
[GHA monitor.yml @ 0,30]
  ├→ TDR ニュース → /tdr-mon/v1/ingest
  ├→ 公式営業時間 → /tdr-today/v1/park-hours
  ├→ ショー時刻 → /tdr-today/v1/shows
  ├→ グリーティング → /tdr-today/v1/greetings
  ├→ リアルタイム → /tdr-today/v1/realtime  ★待ち時間+DPA
  ├→ X 自動投稿 (post_to_x via tweepy)
  └→ /tdr-today/v1/snapshot (履歴に転記)

[GHA realtime.yml @ 15,45 (JST 8-21h)]
  ├→ リアルタイム → /tdr-today/v1/realtime
  └→ /tdr-today/v1/snapshot

WP プラグイン側:
  /realtime endpoint
    ├→ option `tdrt_realtime_<park>` (バッジ状態)
    ├→ option `tdrt_waits_<park>` ★新設・rows+ts (DWR代替の待ち時間源)
    └→ table wp_tdr_pass_events (発券 start/end イベント)

  tdrt_wait_data_raw($park) 優先順:
    1. tdrt_waits_<park>     (60分以内)
    2. dwr_latest_<park>     (DWRプラグイン)
    3. dwr_prev_<park>       (DWR前回値)
```

---

## 直近セッションでの主な変更（v1.2.4 → v1.3.1）

### v1.2.4
- アトラクション正式名称マップ `tdrt_fix_name()` 追加
  - 魅惑のチキルーム full name、スター・ツアーズ、ジャングルクルーズ、美女と野獣、モンスターズ・インク、ソアリン等
- `/tdr-today/v1/snapshot` REST エンドポイント追加（外部cronトリガ用）
- `/tdr-today/v1/greetings` REST エンドポイント追加 + `tdrt_greet_*` option
- ハブに「🤝 キャラクターグリーティング 待ち時間」セクション追加
- ヒートマップに `type="greet"` パラメタ追加
- fetcher.py に `parse_greetings()` + `ingest_greetings()` + `trigger_snapshot()`

### v1.3.0
- DPA / プライオリティパス / スタンバイパス トラッキング
  - 新テーブル `wp_tdr_pass_events` (UNIQUE per park+attr+type+event+date)
  - REST `/tdr-today/v1/realtime` でバッジ状態の差分検出 → イベント記録
  - 新ショートコード `[tdr_pass_today park="tdl"]`
- fetcher.py に `parse_realtime()` + `ingest_realtime()`
- 軽量ポーラー `realtime_poll.py` + ワークフロー `realtime.yml`

### v1.3.1（重大なバグ修正）
- **ユーザー報告：「11:00 と 11:30 全アトラクション同じ待ち時間」**
- **原因**：DWRプラグインの擬似cronが死んでて `dwr_latest_<park>` がフリーズ
- **修正**：`/realtime` エンドポイントが `tdrt_waits_<park>` も書く → `tdrt_wait_data_raw()` がそれを最優先で使う
- ワークフロー整理：`snapshot.yml` 削除、`realtime.yml` を 15,45 にずらして統合
- README全面書き直し

### Xバグ修正（commit `0bb35d8`）
- **ユーザー報告：「X何もつぶやかれてない」**
- **原因**：`post_to_x()` が `item.get("source")` でフィルタしてるが、parser が source を入れてなかった → 全件 `source_filtered:None` で却下
- **追加バグ**：PRTIMES が X 投稿ループに含まれてなかった
- **修正**：
  - 各 item に `source` 注入
  - 全ソース統一の X 投稿ループを main() 末尾に
  - 初回シード機能 `x_seed_if_first_run()` で過去記事爆撃防止
  - フィルタ理由を `summary["x_filtered"]` に集計してデバッグ可視化

---

## GHA 消費見積もり

| ワークフロー | cron (UTC) | 月消費 |
|---|---|---|
| monitor.yml | `*/30 * * * *` | ~1,440 min |
| realtime.yml | `15,45 23,0,...,11 * * *` | ~780 min |
| **合計** | | **~2,220 min/月** |

無料枠 2,000 min を ~220 min 超 → **月 ~$1.80 課金**。

5分粒度が欲しい場合は `realtime.yml` の cron を `*/5 * * * *` に → ~$22/月課金、または public repo 化。

---

## 残タスク / 次セッションでの可能性

### ユーザー作業待ち
- [ ] `tdr-today.zip` を WP にアップロード（最重要）
- [ ] 動作確認：1日後にヒートマップが時刻ごと違う値になっているか
- [ ] DPA タイムラインショートコードを固定ページに配置
- [ ] X 自動投稿の動作確認（first-run seed → 翌日以降 posts）

### 既知の懸念事項
- **DWRプラグインの状態が不明** … 死んでるなら無効化推奨。`tdrt_waits_*` で完全に置き換え可能
- **realtime.yml の cron `0,10,20...`書式** … `0,30` の方が安定。現状は `15,45 23,0,1,2,3,4,5,6,7,8,9,10,11 * * *` で OK
- **GHA cache (`.x_state.json`)** … restore-keys の挙動でセッション間引き継がれているはず。要監視

### 想定される今後のリクエスト
1. **5分粒度に上げたい** → realtime.yml cron 変更 + 課金 or public repo 化
2. **過去データ閲覧（曜日別グラフ等）** → 新ショートコード `[tdr_history park="tdl" weekday="wed"]`
3. **混雑予想モデル** → 蓄積データから ML or ヒューリスティック
4. **DPA 完売予測** → `wp_tdr_pass_events` の過去データから「○月○日のスプラッシュは何時に完売」予測
5. **アクセス解析の組み込み** → GA4 / Search Console データを WP に取り込み

### 次回着手しやすいトピック
- TDS 公式アトラクション名の正式リスト取得（v1.2.4 で WebFetch タイムアウトしたまま放置）
  → `fetcher.py` の Playwright で `/tds/attraction.html` を取得して、`tdrt_fix_name()` のマップに追加
- 「過去の同じ曜日の待ち時間グラフ」ショートコード
- DPA 完売時刻の曜日別集計表示

---

## 重要な秘密情報の場所

| 用途 | 保管場所 |
|---|---|
| WP_INGEST_TOKEN | GHA Secrets + WP option `tdr_mon_ingest_token` |
| WP_BASE | GHA Secrets (= `https://yasushi-duffy.com`) |
| X API keys (4つ) | GHA Secrets (`X_CONSUMER_KEY` 等) |
| GitHub access | `clc07518-bot` の SSH 鍵 |
| WP管理ログイン | ユーザー本人のみ |

---

## 動作確認コマンド

### 待ち時間データが取得できているか
```bash
# WP 側 option 確認（管理画面 → ツール → サイトヘルス → 情報 → データベース）
# tdrt_waits_tdl の ts が 1時間以内なら正常
```

### snapshot エンドポイントが叩けるか
```bash
curl -X POST "https://yasushi-duffy.com/wp-json/tdr-today/v1/snapshot" \
  -H "X-TDR-Token: $WP_INGEST_TOKEN" \
  -H "Content-Type: application/json" -d '{}'
```

### GHA ログで X の状態確認
- Actions → 直近の `TDR Monitor` 実行 → "Fetch & ingest" ステップ → JSON サマリ末尾
- `[x] enabled=True items_total=87 ...` が出ていれば認証OK
- `summary.x_seed` が出てれば first-run、`x_posts` か `x_filtered` で挙動が分かる

---

## 連絡事項

- ブログ：副業の「ディズニー研究所」(`yasushi-duffy.com`)
- X アカウント：`@rin_disneyblog`
- 関連メモ：`~/.claude/projects/-/memory/MEMORY.md` 内に
  - `blog_disney_lab.md` … ブログ全般
  - `x_operation_strategy.md` … X 運用戦略
