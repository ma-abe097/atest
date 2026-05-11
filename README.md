# test

Claude API を使ったウェブサイトプロジェクト要約ツール。
指定したフォルダ配下のソースコードを読み込み、そのサイトが何なのか・どう作られているかを日本語でまとめます。

## セットアップ

```bash
pip install -r requirements.txt
export ANTHROPIC_API_KEY="sk-ant-..."
```

## 使い方

```bash
# サイトのフォルダを丸ごと要約
python summarize_site.py ./my-website

# 観点を変える
python summarize_site.py ./my-website --focus tech       # 技術スタック中心
python summarize_site.py ./my-website --focus features   # ユーザー目線の機能
python summarize_site.py ./my-website --focus spec       # 仕様書形式
python summarize_site.py ./my-website --focus overview   # 全体像 (default)

# 大きなファイルを許容したい時
python summarize_site.py ./my-website --max-file-kb 256
```

## 仕組み

- フォルダを再帰的に読み、HTML/CSS/JS/TS/Vue/Svelte/設定ファイル/README 等を収集
- `node_modules`, `.git`, `dist`, `build`, `.next`, ロックファイル等は自動で除外
- バイナリと巨大ファイルはスキップ
- モデル: `claude-opus-4-7` + adaptive thinking + ストリーミング出力
- コードベース部分に `cache_control` を付けているので、同じフォルダを別の `--focus` で要約し直す時は prompt cache が効きます
