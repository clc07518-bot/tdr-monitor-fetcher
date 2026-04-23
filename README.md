# TDR Monitor Fetcher

GitHub Actions が3分おきに TDR/OLC の WAF でブロックされるソースを取得し、
yasushi-duffy.com の `/wp-json/tdr-mon/v1/ingest` へPOSTする。

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

## スケジュール

`cron: '*/3 * * * *'` (3分間隔)

GitHub Actions の cron は混雑時には遅延することがある（〜数分）。
速報性が足りなければ Cloudflare Workers Cron へ移植可。
