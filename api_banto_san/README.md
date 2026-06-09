# API番人さん (api_banto_san)

APIキーの棚卸し・共有ツール。外部API（OpenAI / Stripe / Google Maps 等）の
**キー本体・使用箇所・コスト**を、**Googleログイン + グループ権限**つきのダッシュボードで一元管理します。
heteml 等の素の PHP 共有ホスティングで単体動作する（外部ライブラリ・Composer 不要）。

## 機能

- **Googleログイン**（OAuth 2.0 / OpenID Connect）。パスワードは保持しない。
- **グループによるデータ分離**。APIは必ずいずれかのグループに属し、所属グループのものだけ閲覧・編集できる
  （**サーバ側で必ず権限チェック**）。
- **ロール**（owner / admin / member / viewer）。

  | ロール | 閲覧 | 編集 | メンバー/ロール管理 | グループ削除 |
  |--------|:----:|:----:|:----:|:----:|
  | owner  | ✓ | ✓ | ✓ | ✓ |
  | admin  | ✓ | ✓ | ✓ | - |
  | member | ✓ | ✓ | - | - |
  | viewer | ✓ | - | - | - |

- **API一覧（フロント画面）**: 各APIをカードで一覧。1つのフォームに
  「名前・提供元・**APIキー本体**・鍵の在りか・月額・状態・担当・メモ」をまとめて登録できる。入力箇所が散らばらない。
- **APIキーの保管庫**: キー本体を **AES-256-GCM で暗号化**して保存。一覧・詳細から「表示」「コピー」。
- **使用箇所（1キー → 複数箇所）**: 各APIに「場所の名前・URL・メモ」を**手動で何個でも**登録。
  1つのキーを複数の場所で使っていても、差し替え時の影響範囲がひと目で分かる。
- **使い方ガイド（ツアー）**: 初回に自動表示される吹き出しツアー。`次へ / 戻る / スキップ / 今後表示しない`
  （ブラウザに記憶）に対応。ヘッダの「使い方」からいつでも再表示。
- **コスト軸ビュー**（左メニュー「コスト」）: 月額合計 / 通貨別小計 / プロダクト別ドーナツ / 前月比。
  OpenAI / Anthropic / Twilio / DataForSEO / Vonage / SerpApi / Google Cloud(BigQuery) は
  「コスト取得キー」を登録すると月額をAPI連携で**自動取得**できる。
- **ID/パスワード管理**: 共有の「アカウント管理」と、本人だけが見られる「個人アカウント」。パスワードは暗号化保存。
- **キーの取得ガイド**: プロバイダごとの「必要なもの・取得場所」。

## ファイル構成

| ファイル | 役割 |
|----------|------|
| `index.php`      | API一覧 / API詳細 / コスト / 管理 / アカウント等の各画面 + 認証ルーティング |
| `groups.php`     | グループ作成・メンバー招待・ロール変更・削除 |
| `bootstrap.php`  | 設定 / DB / セッション / 認証・認可 / 暗号化 / コスト取得 / Google OAuth |
| `config.local.php.example` | 設定ファイルのサンプル |
| `logo.svg` / `favicon.svg` / `duck.svg` / `duck2.png` | ロゴ・マスコット素材 |
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
    // APIキー本体・パスワードを暗号化保存するためのマスター鍵（base64の32バイト）。
    //   php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
    // ★この鍵を変更・紛失すると保存済みキーは復号できなくなります。大切に保管を。
    'APP_ENCRYPTION_KEY'   => '...',
    'APP_DEV_LOGIN'        => false, // 本番は必ず false
];
```

> 🔒 `APP_ENCRYPTION_KEY` を設定するとキー本体・パスワードを暗号化保存できます。
> 未設定でも「鍵の在りか・メモ・使用箇所・コスト」は使えますが、キー本体の保存はできません。

### 3. 動作要件

- PHP 8.0+（PDO SQLite、cURL 推奨。cURL が無い環境では stream にフォールバック）
- `api_banto_san/` ディレクトリに書き込み権限（`data.sqlite` 生成のため）

## ローカル検証

Google なしで UI・権限ロジックを確認したい場合は `APP_DEV_LOGIN=1` で簡易ログインを有効化:

```bash
cd api_banto_san
APP_DEV_LOGIN=1 APP_ENCRYPTION_KEY="$(php -r 'echo base64_encode(random_bytes(32));')" php -S 127.0.0.1:8000
# ブラウザで http://127.0.0.1:8000/ → メールアドレスで（DEV）ログイン
```

> ⚠ `APP_DEV_LOGIN` は認証を回避する開発専用機能です。本番では必ず無効化してください。

## 使い方の流れ

1. **API一覧**（トップ）で「＋ APIを追加」。名前・提供元・**キー本体**などを1つのフォームで登録。
2. 一覧のカードからキーを「表示」「コピー」。「詳細」を開く。
3. **詳細画面**で、そのキーを使っている場所（**複数可**）を「場所の名前・URL・メモ」で追加。
4. 月額やグラフを見たいときは左メニューの **コスト**。コスト自動取得は「コスト取得キー」を登録。
5. 迷ったらヘッダの **使い方** で吹き出しガイドを再生。
