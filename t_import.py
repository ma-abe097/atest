"""
受注逆引きDB CSV 自動取り込み（テスト用 / ブラウザもGoogleも使わない）
============================================================================
★この t_import.py は「ブラウザを一切起動しない」新方式です。
  以前のChromeを開く版とは全くの別物なので、ファイルごと丸ごと差し替えてください。

  処理の流れ:
   1) ID/パスワードでログイン（ログイン画面の「ID・パスワードでログイン(管理者用)」と同じPOST）
   2) GET api.php で現在のデータを取得
   3) CSVを読み、register.php の取り込み処理（processImport）と同じ規則で顧客を作成
   4) POST api.php で保存

事前準備（最初の1回だけ・済んでいればOK）:
   サイトにGoogleでログイン →「アカウント管理」で
   ID/パスワードのアカウント（bot / bot0923）を作成しておく。

必要ライブラリ:
   python -m pip install requests
"""
import os
import csv
import io
import re
import time
import random
import datetime as dt

import requests

# ========== 設定（ここだけ書き換える） ==========
BASE     = "https://s-benri.heteml.net/atest/media-db"
LOGIN_ID = "bot"            # ←「アカウント管理」で作ったログインID
PASSWORD = "bot0923"        # ← そのパスワード
CSV_PATH = r"C:\Users\smn0226\Desktop\逆引き.csv"   # 取り込むCSV（場所が違う場合はここを修正）
# ================================================

HEADER_RE = re.compile(r"NyoiBow|シリアル|顧客名|会社名|作業日|リストカテゴリー", re.I)


def read_csv_rows(path):
    """UTF-8(BOM可)→ダメならShift_JISで読み、空行を除いた行×列を返す。"""
    with open(path, "rb") as f:
        raw = f.read()
    for enc in ("utf-8-sig", "cp932"):
        try:
            text = raw.decode(enc)
            break
        except UnicodeDecodeError:
            continue
    else:
        text = raw.decode("utf-8", errors="replace")
    rows = csv.reader(io.StringIO(text))
    return [[c.strip() for c in r] for r in rows if any(c.strip() for c in r)]


def clean_media_name(s):
    """「○○ 申込日：...」の注記を落とす（register.php と同じ）。"""
    return re.sub(r"[\s　]*申込日[：:].*$", "", s or "").strip()


def previous_business_day():
    """前営業日（土日を飛ばす）。※祝日はテストでは簡略化のため未対応。"""
    d = dt.date.today()
    while True:
        d -= dt.timedelta(days=1)
        if d.weekday() < 5:        # 月=0 … 金=4
            return d.isoformat()


def gen_id(prefix):
    return f"{prefix}{int(time.time() * 1000)}{random.randint(0, 99999)}"


def find_or_create_media(media, name):
    """媒体名から既存IDを返す。無ければ新規作成してIDを返す（register.php と同じ）。"""
    n = (name or "").strip()
    if not n:
        return ""
    for m in media:
        if str(m.get("name", "")).lower() == n.lower():
            return m["id"]
    mid = gen_id("m")
    media.append({"id": mid, "name": n, "domain": "-"})
    return mid


def main():
    if not os.path.exists(CSV_PATH):
        raise SystemExit(
            f"❌ CSVが見つかりません: {CSV_PATH}\n"
            f"   デスクトップにファイルがあるか、CSV_PATH の場所が正しいか確認してください。\n"
            f"   （OneDrive等でデスクトップの場所が変わっている場合は実際のパスに直してください）"
        )

    s = requests.Session()
    s.headers.update({"User-Agent": "mediadb-import-test/1.0"})

    # 1) ログイン画面を開いて CSRF とセッションCookieを取得
    r = s.get(f"{BASE}/index.php", timeout=15)
    r.raise_for_status()
    m = re.search(r'name="csrf"\s+value="([^"]+)"', r.text)
    if not m:
        raise SystemExit("❌ CSRFトークンが取得できません。URL（BASE）を確認してください。")
    csrf = m.group(1)

    # 2) ID/パスワードでログイン
    r = s.post(f"{BASE}/index.php",
               data={"csrf": csrf, "loginId": LOGIN_ID, "password": PASSWORD},
               timeout=15)
    r.raise_for_status()
    if "action=logout" not in r.text:
        raise SystemExit("❌ ログイン失敗。LOGIN_ID / PASSWORD と、アカウント管理で作ったアカウントを確認してください。")
    print(f"✓ ログイン成功（{LOGIN_ID}）")

    # 3) 現在のデータを取得（保存時は“全件”を送り返す必要があるため）
    data = s.get(f"{BASE}/api.php", timeout=15).json()
    media   = data.get("media", [])
    clients = data.get("clients", [])
    before  = len(clients)

    # 4) CSV を register.php の取り込みと同じ規則で顧客化
    #    列順: ①NyoiBowシリアル ②作業日(無視) ③顧客名 ④住所 ⑤業種 ⑥リストカテゴリー
    order_date = previous_business_day()
    added = skipped = 0
    for idx, cols in enumerate(read_csv_rows(CSV_PATH)):
        cols = cols + [""] * (6 - len(cols))           # 列不足を補う
        if idx == 0 and HEADER_RE.search(",".join(cols)):
            continue                                    # 見出し行はスキップ
        name = cols[2].strip()
        if not name:
            skipped += 1
            continue
        src = clean_media_name(cols[5])
        clients.append({
            "id":            gen_id("c"),
            "serial":        cols[0].strip(),
            "name":          name,
            "industry":      cols[4].strip(),
            "orderDate":     order_date,
            "address":       cols[3].strip(),
            "sourceMediaId": find_or_create_media(media, src) if src else "",
            "usedMediaIds":  [],
        })
        added += 1

    if added == 0:
        raise SystemExit("❌ 有効なデータがありません。CSVの列の順番を確認してください。")

    # 5) 保存（users を省くとアカウントが消えるので“取得した全件”を送り返す）
    payload = {
        "users":          data.get("users", []),
        "media":          media,
        "clients":        clients,
        "excludeDomains": data.get("excludeDomains", []),
        "csrf":           csrf,
    }
    r = s.post(f"{BASE}/api.php", json=payload,
               headers={"X-CSRF-Token": csrf}, timeout=30)
    r.raise_for_status()
    if r.json().get("status") == "success":
        print(f"🎉 取り込み完了！ {added}件追加（スキップ {skipped}件）／顧客 {before}→{len(clients)}件")
    else:
        raise SystemExit(f"❌ 保存失敗: {r.json()}")


if __name__ == "__main__":
    main()
