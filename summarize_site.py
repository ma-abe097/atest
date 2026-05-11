"""Website project summarizer via the Claude API.

Reads a folder containing a website/web app and produces a summary of what
the site is and how it's built.

Usage:
    python summarize_site.py path/to/site
    python summarize_site.py path/to/site --focus tech
    python summarize_site.py path/to/site --max-file-kb 64
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

import anthropic

MODEL = "claude-opus-4-7"

# Directories never worth reading
EXCLUDE_DIRS = {
    ".git", "node_modules", ".next", ".nuxt", "dist", "build", "out",
    ".cache", ".parcel-cache", ".turbo", ".vercel", ".netlify",
    "__pycache__", ".venv", "venv", ".idea", ".vscode", "coverage",
    ".pytest_cache", ".mypy_cache", ".ruff_cache",
}

# Files to skip outright
EXCLUDE_FILES = {
    "package-lock.json", "yarn.lock", "pnpm-lock.yaml", "bun.lockb",
    "poetry.lock", "Pipfile.lock", "composer.lock", "Gemfile.lock",
    ".DS_Store", "Thumbs.db",
}

# Extensions we consider "text we want to read"
TEXT_EXTENSIONS = {
    # Web
    ".html", ".htm", ".css", ".scss", ".sass", ".less",
    ".js", ".jsx", ".mjs", ".cjs", ".ts", ".tsx",
    ".vue", ".svelte", ".astro",
    # Backend / scripts
    ".py", ".rb", ".php", ".go", ".rs", ".java", ".kt",
    # Config / docs
    ".json", ".yaml", ".yml", ".toml", ".ini", ".env.example",
    ".md", ".mdx", ".txt", ".xml", ".svg",
    # Templates
    ".ejs", ".pug", ".hbs", ".njk", ".liquid",
}

# Files without extensions worth reading
NO_EXT_INCLUDE = {
    "README", "LICENSE", "Dockerfile", "Makefile", "Procfile",
    ".gitignore", ".env.example", ".nvmrc",
}

FOCUS_PROMPTS = {
    "overview": (
        "このウェブサイト/ウェブアプリの全体像をまとめてください。以下の観点で:\n"
        "1. **目的・用途**: 何のためのサイトか、誰向けか\n"
        "2. **主な機能・ページ**: どんなページや機能があるか\n"
        "3. **技術スタック**: 使われているフレームワーク・ライブラリ・ツール\n"
        "4. **構成**: ディレクトリ構成から読み取れる設計の特徴\n"
        "5. **気づいた点**: 開発状況、未完成な部分、注意点など"
    ),
    "tech": (
        "技術面に絞って詳しくまとめてください:\n"
        "- 言語・フレームワーク・主要ライブラリとバージョン\n"
        "- ビルド/開発ツール (バンドラ、リンタ、テスト等)\n"
        "- アーキテクチャの特徴 (SPA/SSR/SSG、状態管理、ルーティング等)\n"
        "- 外部サービス連携 (API、認証、DB、ホスティング等)\n"
        "- 設定ファイルから読み取れる挙動"
    ),
    "features": (
        "ユーザー視点でこのサイトが「何ができるか」をまとめてください:\n"
        "- 提供されるページ/画面の一覧と役割\n"
        "- 主要なユーザー操作とフロー\n"
        "- フォーム、ボタン、リンクなど対話的要素\n"
        "- コンテンツの種類\n"
        "技術的な詳細より、ユーザーが触って何が起きるかを中心に。"
    ),
    "spec": (
        "このサイトの仕様書を再構築してください。以下の形式で:\n\n"
        "## 概要\n(1段落でサイトの目的)\n\n"
        "## 機能一覧\n(箇条書きで機能を列挙)\n\n"
        "## ページ構成\n(ページ/ルートと役割)\n\n"
        "## 技術仕様\n(スタック、依存関係、設定)\n\n"
        "## データ/API\n(扱うデータ、外部API、内部エンドポイント)\n\n"
        "実際のコードから読み取れる事実のみ書くこと。推測する場合は「推測:」と明示。"
    ),
}


def should_include(path: Path) -> bool:
    if path.name in EXCLUDE_FILES:
        return False
    if path.suffix.lower() in TEXT_EXTENSIONS:
        return True
    if path.name in NO_EXT_INCLUDE:
        return True
    # Files starting with a known stem like "README.something"
    if path.stem in NO_EXT_INCLUDE:
        return True
    return False


def walk_site(root: Path, max_file_bytes: int) -> list[tuple[Path, str]]:
    files: list[tuple[Path, str]] = []
    skipped_large: list[Path] = []

    for path in sorted(root.rglob("*")):
        if path.is_dir():
            continue
        # Skip if any parent dir is excluded
        if any(part in EXCLUDE_DIRS for part in path.relative_to(root).parts):
            continue
        if not should_include(path):
            continue

        try:
            size = path.stat().st_size
        except OSError:
            continue

        if size > max_file_bytes:
            skipped_large.append(path)
            continue

        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue

        files.append((path.relative_to(root), text))

    if skipped_large:
        print(
            f"[info] {len(skipped_large)} files skipped (over --max-file-kb):",
            file=sys.stderr,
        )
        for p in skipped_large[:5]:
            print(f"  {p.relative_to(root)}", file=sys.stderr)
        if len(skipped_large) > 5:
            print(f"  ... and {len(skipped_large) - 5} more", file=sys.stderr)

    return files


def render_codebase(files: list[tuple[Path, str]]) -> str:
    parts = ["=== ファイル一覧 ===\n"]
    parts.extend(f"- {rel}" for rel, _ in files)
    parts.append("\n\n=== ファイル内容 ===\n")
    for rel, text in files:
        parts.append(f"\n----- {rel} -----\n{text}\n")
    return "".join(parts)


def summarize_site(root: Path, focus: str, max_file_bytes: int) -> None:
    files = walk_site(root, max_file_bytes)
    if not files:
        sys.exit(f"Error: no readable text files found under {root}")

    codebase = render_codebase(files)
    total_chars = sum(len(t) for _, t in files)
    print(
        f"[info] reading {len(files)} files ({total_chars:,} chars) from {root}",
        file=sys.stderr,
    )

    client = anthropic.Anthropic()

    system_prompt = (
        "あなたはウェブ開発に精通したエンジニアです。与えられたウェブサイト"
        "プロジェクトのソースコードを読み、その内容を日本語で正確にまとめてください。"
        "推測ではなく、コード・設定・READMEから読み取れる事実を優先してください。"
    )

    user_content = [
        {
            "type": "text",
            "text": (
                f"以下はディレクトリ `{root.name}` 配下のウェブサイトプロジェクトの"
                f"全ファイル内容です。\n\n{codebase}"
            ),
            "cache_control": {"type": "ephemeral"},
        },
        {
            "type": "text",
            "text": FOCUS_PROMPTS[focus],
        },
    ]

    with client.messages.stream(
        model=MODEL,
        max_tokens=8192,
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
    parser = argparse.ArgumentParser(
        description="Claude API でウェブサイトプロジェクトのフォルダを要約",
    )
    parser.add_argument("path", help="要約するウェブサイトのフォルダパス")
    parser.add_argument(
        "--focus",
        choices=FOCUS_PROMPTS.keys(),
        default="overview",
        help="要約の観点 (default: overview)",
    )
    parser.add_argument(
        "--max-file-kb",
        type=int,
        default=128,
        help="1ファイルあたりの最大サイズ(KB)。これを超えるファイルはスキップ (default: 128)",
    )
    args = parser.parse_args()

    root = Path(args.path).resolve()
    if not root.is_dir():
        sys.exit(f"Error: {root} is not a directory.")

    summarize_site(root, args.focus, args.max_file_kb * 1024)


if __name__ == "__main__":
    main()
