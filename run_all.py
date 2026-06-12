"""
作業リスト履歴(FileMaker) → 逆引きDB(サイト) 一括取り込みパイプライン
============================================================================
ワンクリックで以下を順に実行する:
  STEP1  共有上の .fmp12 を開き、fmp:// でスクリプト『media-db』を実行
         （= デスクトップに 逆引き.csv が書き出される想定）
  STEP2  逆引き.csv が「新しく書き出される（更新される）」のを待つ
  STEP3  サイトに ID/パスワードでログインし、register.php と同じ規則で取り込み

使い方:
  python run_all.py              # 全工程（FileMaker → 待機 → 取り込み）
  python run_all.py --import-only   # FileMakerを飛ばし、今あるCSVを取り込むだけ
  python run_all.py --fm-only       # FileMakerで media-db 実行まで（取り込みなし）

必要ライブラリ:
  python -m pip install requests
"""
import os
import sys
import csv
import io
import re
import time
import random
import datetime as dt
from urllib.parse import quote

import requests

# ========== 設定（ここだけ書き換える） ==========
# --- STEP1: FileMaker ---
UNC_DIR      = r"\\192.168.61.42\marketing\00_マーケティング準備室\集計\02_リスト運用状況\日報"
FM_FILE      = "202606_作業リスト履歴.fmp12"
SCRIPT_NAME  = "media-db"
FM_OPEN_WAIT = 15          # ファイルが開ききるまでの待機秒（遅い/重い場合は増やす）

# --- STEP3: 取り込み先サイト ---
BASE     = "https://s-benri.heteml.net/atest/media-db"
LOGIN_ID = "bot"           # 「アカウント管理」で作ったログインID
PASSWORD = "bot0923"       # そのパスワード

# --- CSV（media-db が書き出す先） ---
CSV_PATH         = r"C:\Users\smn0226\Desktop\逆引き.csv"
CSV_WAIT_TIMEOUT = 180     # CSVが更新されるのを待つ最大秒
# ================================================

FM_PATH   = os.path.join(UNC_DIR, FM_FILE)
DB_NAME   = os.path.splitext(FM_FILE)[0]   # 拡張子を除いた名前 = fmp:// で使うDB名
HEADER_RE = re.compile(r"NyoiBow|シリアル|顧客名|会社名|作業日|リストカテゴリー", re.I)


# ----------------------------------------------------------------------
# STEP1: FileMakerを開いて media-db を実行
# ----------------------------------------------------------------------
def run_filemaker():
    if not os.path.exists(FM_PATH):
        raise SystemExit(
            f"❌ FileMakerファイルにアクセスできません:\n   {FM_PATH}\n"
            f"   共有(\\\\192.168.61.42\\marketing)に接続できているか、"
            f"一度エクスプローラーで開いて認証が通るか確認してください。"
        )
    print(f"▶ STEP1: FileMakerを開きます: {FM_FILE}")
    os.startfile(FM_PATH)
    print(f"  └ 起動待機 ({FM_OPEN_WAIT}秒)... ※ログイン画面が出たらログインしてください")
    time.sleep(FM_OPEN_WAIT)
    url = f"fmp://$/{quote(DB_NAME)}?script={quote(SCRIPT_NAME)}"
    print(f"  └ スクリプト『{SCRIPT_NAME}』を実行します...")
    os.startfile(url)


# ----------------------------------------------------------------------
# STEP2: CSVが新しく書き出されるのを待つ
# ----------------------------------------------------------------------
def wait_for_fresh_csv(baseline_mtime):
    """CSVが baseline より新しく更新され、サイズが安定する（書き込み完了）まで待つ。"""
    print(f"▶ STEP2: 逆引き.csv の書き出しを待機中（最大{CSV_WAIT_TIMEOUT}秒）...")
    end = time.time() + CSV_WAIT_TIMEOUT
    last_size, stable = -1, 0
    while time.time() < end:
        time.sleep(2)
        if not os.path.exists(CSV_PATH):
            continue
        if os.path.getmtime(CSV_PATH) <= baseline_mtime:
            continue                          # まだ更新されていない（古いまま）
        size = os.path.getsize(CSV_PATH)
        if size > 0 and size == last_size:
            stable += 1
            if stable >= 2:                   # 2回連続でサイズ変化なし=書き込み完了
                print("  └ CSVの更新を確認しました。")
                return True
        else:
            stable, last_size = 0, size
    return False


# ----------------------------------------------------------------------
# STEP3: CSVをサイトへ取り込み
# ----------------------------------------------------------------------
def read_csv_rows(path):
    """UTF-8(BOM可)→ダメならShift_JISで読み、空行を除いた行×列を返す。"""
    with open(path, "rb") as f:
        raw = f.read()
    for enc in ("utf-8-sig", "cp932"):
        try:
            text = raw.decode(enc); break
        except UnicodeDecodeError:
            continue
    else:
        text = raw.decode("utf-8", errors="replace")
    rows = csv.reader(io.StringIO(text))
    return [[c.strip() for c in r] for r in rows if any(c.strip() for c in r)]


def clean_media_name(s):
    return re.sub(r"[\s　]*申込日[：:].*$", "", s or "").strip()


def previous_business_day():
    """前営業日（土日を飛ばす）。※祝日はテストでは簡略化のため未対応。"""
    d = dt.date.today()
    while True:
        d -= dt.timedelta(days=1)
        if d.weekday() < 5:
            return d.isoformat()


def gen_id(prefix):
    return f"{prefix}{int(time.time()*1000)}{random.randint(0,99999)}"


def find_or_create_media(media, name):
    n = (name or "").strip()
    if not n:
        return ""
    for m in media:
        if str(m.get("name", "")).lower() == n.lower():
            return m["id"]
    mid = gen_id("m")
    media.append({"id": mid, "name": n, "domain": "-"})
    return mid


def import_csv():
    if not os.path.exists(CSV_PATH):
        raise SystemExit(f"❌ CSVが見つかりません: {CSV_PATH}")
    print("▶ STEP3: サイトへ取り込みます...")
    s = requests.Session()
    s.headers.update({"User-Agent": "mediadb-import-test/1.0"})

    # ログイン画面で CSRF とセッションCookieを取得
    r = s.get(f"{BASE}/index.php", timeout=15); r.raise_for_status()
    m = re.search(r'name="csrf"\s+value="([^"]+)"', r.text)
    if not m:
        raise SystemExit("❌ CSRFトークンが取得できません。BASE を確認してください。")
    csrf = m.group(1)

    # ID/パスワードでログイン
    r = s.post(f"{BASE}/index.php",
               data={"csrf": csrf, "loginId": LOGIN_ID, "password": PASSWORD}, timeout=15)
    r.raise_for_status()
    if "action=logout" not in r.text:
        raise SystemExit("❌ ログイン失敗。LOGIN_ID/PASSWORD とアカウント管理の内容を確認してください。")
    print(f"  └ ログイン成功（{LOGIN_ID}）")

    # 現在のデータ取得 → CSVを足して全件を送り返す
    data = s.get(f"{BASE}/api.php", timeout=15).json()
    media, clients = data.get("media", []), data.get("clients", [])
    before = len(clients)

    order_date = previous_business_day()
    added = skipped = 0
    for idx, cols in enumerate(read_csv_rows(CSV_PATH)):
        cols = cols + [""] * (6 - len(cols))
        if idx == 0 and HEADER_RE.search(",".join(cols)):
            continue
        name = cols[2].strip()
        if not name:
            skipped += 1; continue
        src = clean_media_name(cols[5])
        clients.append({
            "id": gen_id("c"), "serial": cols[0].strip(), "name": name,
            "industry": cols[4].strip(), "orderDate": order_date,
            "address": cols[3].strip(),
            "sourceMediaId": find_or_create_media(media, src) if src else "",
            "usedMediaIds": [],
        })
        added += 1

    if added == 0:
        raise SystemExit("❌ 有効なデータがありません。CSVの列順を確認してください。")

    payload = {"users": data.get("users", []), "media": media, "clients": clients,
               "excludeDomains": data.get("excludeDomains", []), "csrf": csrf}
    r = s.post(f"{BASE}/api.php", json=payload, headers={"X-CSRF-Token": csrf}, timeout=30)
    r.raise_for_status()
    if r.json().get("status") == "success":
        print(f"🎉 取り込み完了！ {added}件追加（スキップ {skipped}件）／顧客 {before}→{len(clients)}件")
    else:
        raise SystemExit(f"❌ 保存失敗: {r.json()}")


# ----------------------------------------------------------------------
# オーケストレーション
# ----------------------------------------------------------------------
def main():
    mode = sys.argv[1] if len(sys.argv) > 1 else ""

    if mode == "--import-only":
        import_csv(); return
    if mode == "--fm-only":
        run_filemaker(); print("✓ media-db 実行リクエストを送信しました。"); return

    # 全工程
    baseline = os.path.getmtime(CSV_PATH) if os.path.exists(CSV_PATH) else 0.0
    run_filemaker()
    if not wait_for_fresh_csv(baseline):
        raise SystemExit(
            "❌ 制限時間内に 逆引き.csv が更新されませんでした。\n"
            "   ・media-db が実行されたか（fmurl 拡張アクセス権が有効か）\n"
            f"   ・書き出し先が {CSV_PATH} になっているか\n"
            "   ・FileMakerの起動が遅い場合は FM_OPEN_WAIT を増やす\n"
            "   を確認してください。--import-only で手動CSVだけ取り込むことも可能です。"
        )
    import_csv()


if __name__ == "__main__":
    main()
