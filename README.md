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

`cron: '*/30 * * * *'` (30分間隔)

private repo の GHA 無料枠 (2,000分/月) に収まるよう30分間隔。
約 1,440分/月の消費で課金ゼロ。

より速い速報性が必要なら:
- Public repo 化で3分cron無料
- Cloudflare Workers Cron へ移植 (無料枠10万回/日)

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

### 通知先メールアドレス変更

デフォルトは `admin_email`。変更したければWPコンソールで:

```sql
UPDATE wp_options SET option_value='you@example.com' WHERE option_name='tdr_mon_mu_email_to';
```

もしくは `update_option('tdr_mon_mu_email_to', 'you@example.com')` を一度実行。
