# TDR Monitor Fetcher

GitHub Actions が定期的に TDR/OLC の WAF でブロックされるソースを取得し、
yasushi-duffy.com の `/wp-json/tdr-mon/v1/ingest` 系エンドポイントへ POST する。

| 機能 | データ源 | 更新間隔 |
|---|---|---|
| ニュース速報 (TDR/OLC/PRTIMES) | 各公式 + RDF | 30分 |
| 公式営業時間 | `/tdr/calendar.html` | 30分 |
| ショー・パレード時刻 | `/{tdl,tds}/daily/calendar.html` | 30分 |
| キャラクターグリーティング待ち時間 | `/{tdl,tds}/realtime/greeting.html` | 30分 |
| アトラクション待ち時間 | `/{tdl,tds}/realtime/` | **15分** |
| DPA / プライオリティパス / スタンバイパス バッジ | `/{tdl,tds}/realtime/` | **15分** |
| 待ち時間ヒートマップ履歴 | 上記を集約 | 15分 |
| X (@rin_disneyblog) 自動投稿 | ニュース速報を選別 | 即時 |

## セットアップ

### 1. GitHubリポジトリを作成

```
gh repo create tdr-monitor-fetcher --private --source=. --remote=origin --push
```

もしくは手動でGitHubで private リポジトリを作成してpush:

```
git init
git add .
git commit -m "init"
git branch -M main
git remote add origin git@github.com:YOUR_USERNAME/tdr-monitor-fetcher.git
git push -u origin main
```

### 2. Secrets を登録

リポジトリの **Settings → Secrets and variables → Actions** で2つ登録:

| 名前 | 値 |
|---|---|
| `WP_BASE` | `https://yasushi-duffy.com` |
| `WP_INGEST_TOKEN` | WP側で発行したトークン（ダッシュボードの緑バナーに表示） |

### 3. 動作確認

- **Actions タブ** → `TDR Monitor` → `Run workflow` で手動実行
- ログを見て各ソースの `new` 件数を確認
- 初回は seed になるので通知はスキップされる（WP側の `first_run` 判定）
- 2回目以降、新規アイテムが検出されるとDiscordへ通知される

## スケジュール (3つのワークフロー)

| ワークフロー | cron (UTC) | 役割 | 月消費(目安) |
|---|---|---|---|
| `monitor.yml` | `*/30 * * * *` | フルパス: ニュース + 公式営業時間 + ショー + グリーティング + リアルタイム + DPA + 待ち時間スナップショット | ~1,440min |
| `realtime.yml` | `15,45 23,0,...,11 * * *` | リアルタイムページのみ (JST 8-21h、15分間隔ずらし) → 待ち時間 + DPA バッジ更新 + スナップショット | ~780min |
| (削除済) `snapshot.yml` | — | realtime.yml に統合 | 0 |

**合計 ~2,220min/月** (無料枠 2,000min を ~220min 超 = 月 ~$1.80 課金)。

より細かい粒度が欲しい場合:
- `realtime.yml` の cron を `*/5 * * * *` に変更 → 5分粒度。~$22/月課金
- Public repo 化で全ての制約撤廃 (無料)
- Cloudflare Workers Cron へ移植 (無料枠 10万回/日)

## WP側ダッシュボード & メール通知

`wp-plugin/tdr-mon-dashboard.php` が、

1. ダッシュボード新着ウィジェット（共有ページへの1-tapリンク付き）
2. メール通知（新着検出時に admin_email へダイジェスト送信・Discord不要）

を提供する。**インストール2択:**

### A. 管理画面からZIPアップロード（推奨）

```
cd wp-plugin
zip -r tdr-mon-dashboard.zip tdr-mon-dashboard.php
```

→ WP管理画面 → **プラグイン → 新規追加 → プラグインのアップロード**
→ `tdr-mon-dashboard.zip` を選択 → 有効化。

### B. mu-plugins へ直接配置（SFTP利用者向け）

`wp-content/mu-plugins/tdr-mon-dashboard.php` へアップロード。自動有効化・削除不可。

## 「今日のディズニー」ハブ + 待ち時間ヒートマップ + DPA トラッカー

`wp-plugin/tdr-today/` (v1.3.1) は disney-wait-ranking + TDR Monitor + 公式リアルタイムページのデータを統合して、

- `[tdr_today_hub park="tdl|tds"]` — 今日の運営時間・天気・混雑度・待ち時間TOP10・キャラクターグリーティング待ち時間・休止施設・ショースケジュールを1画面に集約
- `[tdr_today_heatmap park="tdl|tds"]` — 今日のアトラクション待ち時間を30分間隔のヒートマップで表示
- `[tdr_today_heatmap park="tdl|tds" type="greet"]` — グリーティング待ち時間ヒートマップ
- `[tdr_pass_today park="tdl|tds"]` — DPA / プライオリティパス / スタンバイパスの本日の発券タイムライン (開始時刻・終了時刻・🟢発券中/🔴終了)

を提供する。15分間隔で待ち時間データを `wp_tdr_wait_history` テーブルに蓄積、パス発券イベントを `wp_tdr_pass_events` テーブルに記録。

### 待ち時間データソースの優先順

DWRプラグインの擬似cronが止まっても動くようにフォールバックチェーンを実装:

```
1. tdrt_waits_<park>     ← /tdr-today/v1/realtime が15分毎に更新 (我々の取得)
2. dwr_latest_<park>     ← DWRプラグインが書く (動いていれば)
3. dwr_prev_<park>       ← DWRの前回値 (latest が空の時の救済)
```

### インストール

```
cd wp-plugin
zip -r tdr-today.zip tdr-today/
```

→ WP管理画面 → **プラグイン → 新規追加 → プラグインのアップロード** → `tdr-today.zip` → 有効化。

### 固定ページに配置

```
[tdr_today_hub park="tdl"]

<h2>今日のアトラクション待ち時間推移</h2>
[tdr_today_heatmap park="tdl"]

<h2>今日のキャラクターグリーティング待ち時間推移</h2>
[tdr_today_heatmap park="tdl" type="greet"]

<h2>本日のDPA / プライオリティパス発券状況</h2>
[tdr_pass_today park="tdl"]
```

スラッグ `today-tdl` / `today-tds` で公開すると、shortcode のタブ切替リンクと整合する。

### 蓄積データ

- テーブル `{$wpdb->prefix}tdr_wait_history` に [park, attr_id, wait_min, status, recorded_at, slot_key] を 15分間隔で記録
  - park='tdl'/'tds' = アトラクション、'tdl_g'/'tds_g' = キャラクターグリーティング
- テーブル `{$wpdb->prefix}tdr_pass_events` に [park, attr_id, pass_type, event, event_date, event_time]
  - pass_type: 'dpa' (プレミアアクセス) / 'pp' (プライオリティパス) / 'sbp' (スタンバイパス)
  - event: 'start' (発券開始検出) / 'end' (発券終了検出)
  - UNIQUE(park, attr_id, pass_type, event, event_date) で1日1イベントに正規化
- 30〜70 行/15分 = 約 100KB/日 = 35MB/年
- 1週間溜まると「過去の同じ曜日の待ち時間グラフ」、1ヶ月で「混雑予想モデル」が作れる

## X (Twitter) 自動投稿

`@rin_disneyblog` へ TDR ニュース速報を自動ツイートする。

### 必須 GitHub Secrets

| 名前 | 値 |
|---|---|
| `X_CONSUMER_KEY` | X Developer Portal の Consumer Key |
| `X_CONSUMER_SECRET` | 同 Secret |
| `X_ACCESS_TOKEN` | OAuth 1.0a Access Token |
| `X_ACCESS_TOKEN_SECRET` | 同 Secret |

### 安全制約

- `X_DAILY_LIMIT=15` (1日最大15ツイート)
- `X_HOUR_START=7` `X_HOUR_END=23` (JST 7:00-22:59 のみ)
- 投稿対象ソース: `prtimes`, `update`, `urgent`, `olc_tdr` (休止お知らせは除外)
- 状態は `.x_state.json` を GHA Actions Cache で run 間永続化
- **初回実行は seed のみ** (過去ニュース全部を `posted_ids` に記録、ツイート0)

### デバッグ

GHA Actions のログで:
```
[x] enabled=True hours=[7-23) daily_limit=15 items_total=87
[x] first-run seeded 87 items, no posts this run
[x] posted urgent: ... → 1234567890
[x] filtered: {"already_posted": 12, "out_of_hours": 0, "daily_limit": 0}
```

`x_filtered` に reason 別件数が出るので、無投稿時の原因が分かる。

## 通知先メールアドレス変更

デフォルトは `admin_email`。変更したければWPコンソールで:

```sql
UPDATE wp_options SET option_value='you@example.com' WHERE option_name='tdr_mon_mu_email_to';
```

もしくは `update_option('tdr_mon_mu_email_to', 'you@example.com')` を一度実行。
