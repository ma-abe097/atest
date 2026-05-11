# test

Claude API を使った文書要約システム。

## セットアップ

```bash
pip install -r requirements.txt
export ANTHROPIC_API_KEY="sk-ant-..."
```

## 使い方

```bash
# ファイルを要約
python summarize.py --file article.txt

# 直接テキストを渡す
python summarize.py --text "長いテキスト..."

# パイプ入力
cat doc.txt | python summarize.py

# 形式と分量を指定
python summarize.py --file doc.txt --style bullets --length short
```

### オプション

- `--style`: `paragraph`(段落), `bullets`(箇条書き), `outline`(アウトライン), `tldr`(極短)
- `--length`: `short`(約200字), `medium`(約500字), `long`(約1000字)

## 仕組み

- モデル: `claude-opus-4-7` + adaptive thinking
- 同じ文書を異なる形式・分量で繰り返し要約しても、文書部分は prompt caching によりキャッシュから読み込まれます
- ストリーミング出力
