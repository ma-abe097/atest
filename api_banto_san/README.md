# API番人さん (api_banto_san)

API棚卸しツール。外部API（Stripe / OpenAI / Google Maps 等）を「どこで使い・いくらかかるか」
コスト軸で一元管理する、**Googleログイン + グループ権限**つきダッシュボード。
heteml 等の素の PHP 共有ホスティングで単体動作する（外部ライブラリ・Composer 不要）。

## 機能

- **Googleログイン**（OAuth 2.0 / OpenID Connect）。パスワードは保持しない。
- **グループによるデータ分離**。APIカタログは必ずいずれかのグループに属し、
  所属グループのカタログのみ閲覧・編集できる（**サーバ側で必ず権限チェック**）。
- **ロール**（owner / admin / member / viewer）。

  | ロール | カタログ閲覧 | カタログ編集 | メンバー/ロール管理 | グループ削除 |
  |--------|:----:|:----:|:----:|:----:|
  | owner  | ✓ | ✓ | ✓ | ✓ |
  | admin  | ✓ | ✓ | ✓ | - |
  | member | ✓ | ✓ | - | - |
  | viewer | ✓ | - | - | - |

- **メール招待**: 招待されたメールアドレスで Google ログインすると自動で参加。
- **コスト軸ビュー**: 月額降順 / 通貨別小計 / 未設定の明示 / 使用箇所ドリルダウン / 絞り込み。
- **制約**: APIキー本体は保存せず、鍵の在りか（`env: OPENAI_API_KEY` 等）のみ記録。

## ファイル構成

| ファイル | 役割 |
|----------|------|
| `index.php`      | ダッシュボード本体 + 認証ルーティング |
| `groups.php`     | グループ作成・メンバー招待・ロール変更・削除 |
| `bootstrap.php`  | 設定 / DB / セッション / 認証・認可 / Google OAuth |
| `config.local.php.example` | 設定ファイルのサンプル |
| `data.sqlite`    | SQLite データベース（自動生成・gitignore済み） |

## セットアップ

### 1. Google OAuth クライアントを作成

1. [Google Cloud Console](https://console.cloud.google.com/) →「APIとサービス」→「認証情報」
2. 「OAuth クライアント ID を作成」→ 種類「ウェブアプリケーション」
3. 「承認済みのリダイレクト URI」に、デプロイ先の URL を **完全一致**で登録:
   ```
   https://<あなたのドメイン>/atest/api_banto_san/index.php?route=oauth2callback
   ```
4. OAuth 同意画面でスコープ `openid` / `email` / `profile` を許可。

### 2. 設定ファイル

`config.local.php.example` を `config.local.php` にコピーして値を設定（gitignore済み）。
または環境変数（`.htaccess` の `SetEnv` 等）で渡す。優先順は **環境変数 > config.local.php > 既定値**。

```php
return [
    'GOOGLE_CLIENT_ID'     => '...apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => '...',
    'GOOGLE_REDIRECT_URI'  => 'https://<ドメイン>/atest/api_banto_san/index.php?route=oauth2callback',
    'APP_BASE_URL'         => 'https://<ドメイン>/atest/api_banto_san', // 任意（未指定なら自動推定）
    'APP_DEV_LOGIN'        => false, // 本番は必ず false
];
```

### 3. 動作要件

- PHP 8.0+（PDO SQLite、cURL 推奨。cURL が無い環境では stream にフォールバック）
- `api_banto_san/` ディレクトリに書き込み権限（`data.sqlite` 生成のため）

## ローカル検証

Google なしで UI・権限ロジックを確認したい場合は `APP_DEV_LOGIN=1` で簡易ログインを有効化:

```bash
cd api_banto_san
APP_DEV_LOGIN=1 php -S 127.0.0.1:8000
# ブラウザで http://127.0.0.1:8000/ → メールアドレスで（DEV）ログイン
```

> ⚠ `APP_DEV_LOGIN` は認証を回避する開発専用機能です。本番では必ず無効化してください。

## 将来フェーズ（未実装）

- スキャナCLI 連携（`scan` / `push`、再プッシュ時の手動フィールドのマージ保持、CLI個人用トークン）
- 各社 billing/usage API 連携による `monthly_cost` の半自動更新（Stripe / OpenAI / AWS Cost Explorer 等）
