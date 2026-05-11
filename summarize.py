"""Document summarization via the Claude API.

Usage:
    python summarize.py --file path/to/doc.txt
    python summarize.py --text "long text here..."
    echo "long text" | python summarize.py
    python summarize.py --file doc.txt --style bullets --length short
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

import anthropic

MODEL = "claude-opus-4-7"

STYLE_INSTRUCTIONS = {
    "paragraph": "段落形式の自然な文章で要約してください。",
    "bullets": "箇条書き(•)で重要なポイントを列挙してください。",
    "outline": "見出しと小項目を使った構造的なアウトラインで要約してください。",
    "tldr": "1〜2文の極めて簡潔なTL;DRを出力してください。",
}

LENGTH_INSTRUCTIONS = {
    "short": "全体で200字程度に収めてください。",
    "medium": "全体で500字程度にまとめてください。",
    "long": "重要な詳細を保持しつつ1000字程度で詳しく要約してください。",
}


def build_system_prompt(style: str, length: str) -> str:
    return (
        "あなたは熟練の編集者です。与えられた文書を読み、"
        "事実関係を歪めずに要約してください。\n\n"
        f"出力形式: {STYLE_INSTRUCTIONS[style]}\n"
        f"分量: {LENGTH_INSTRUCTIONS[length]}\n\n"
        "原文にない情報を補わないこと。固有名詞・数値はそのまま使うこと。"
    )


def read_input(args: argparse.Namespace) -> tuple[str, str]:
    if args.file:
        path = Path(args.file)
        return path.read_text(encoding="utf-8"), path.name
    if args.text:
        return args.text, "(--text)"
    if not sys.stdin.isatty():
        return sys.stdin.read(), "(stdin)"
    sys.exit("Error: provide --file, --text, or pipe text via stdin.")


def summarize(document: str, source: str, style: str, length: str) -> None:
    client = anthropic.Anthropic()
    system_prompt = build_system_prompt(style, length)

    # Cache the document so repeated summaries (different styles/lengths) are cheap.
    user_content = [
        {
            "type": "text",
            "text": f"<document source=\"{source}\">\n{document}\n</document>",
            "cache_control": {"type": "ephemeral"},
        },
        {
            "type": "text",
            "text": "上記の文書を指示通りに要約してください。",
        },
    ]

    with client.messages.stream(
        model=MODEL,
        max_tokens=4096,
        thinking={"type": "adaptive"},
        system=system_prompt,
        messages=[{"role": "user", "content": user_content}],
    ) as stream:
        for text in stream.text_stream:
            print(text, end="", flush=True)
        print()
        final = stream.get_final_message()

    usage = final.usage
    print(
        f"\n[tokens: in={usage.input_tokens} "
        f"cache_write={usage.cache_creation_input_tokens} "
        f"cache_read={usage.cache_read_input_tokens} "
        f"out={usage.output_tokens}]",
        file=sys.stderr,
    )


def main() -> None:
    parser = argparse.ArgumentParser(description="Claude 文書要約システム")
    src = parser.add_mutually_exclusive_group()
    src.add_argument("--file", "-f", help="要約するテキストファイルのパス")
    src.add_argument("--text", "-t", help="直接渡すテキスト")
    parser.add_argument(
        "--style",
        choices=STYLE_INSTRUCTIONS.keys(),
        default="paragraph",
        help="要約の形式 (default: paragraph)",
    )
    parser.add_argument(
        "--length",
        choices=LENGTH_INSTRUCTIONS.keys(),
        default="medium",
        help="要約の分量 (default: medium)",
    )
    args = parser.parse_args()

    document, source = read_input(args)
    if not document.strip():
        sys.exit("Error: input is empty.")

    summarize(document, source, args.style, args.length)


if __name__ == "__main__":
    main()
