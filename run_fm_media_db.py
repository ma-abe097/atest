"""
FileMaker: 指定ファイルを開いてスクリプト『media-db』を実行する（CSV取り込みの前段）
============================================================================
  1) ネットワーク共有上の FileMaker ファイル(.fmp12) を開く
  2) FileMaker URL（fmp://）で、開いたファイルのスクリプト『media-db』を実行する

前提:
  - このPCに FileMaker Pro が入っていて、.fmp12 と fmp:// が関連付けられていること
  - 対象ファイルの権限設定で、拡張アクセス権
    「FileMaker URL からのアクセス(fmurl)」が有効であること
    （無効だと fmp:// 経由でスクリプトを実行できません）

追加インストール不要（標準ライブラリのみ）。
"""
import os
import time
from urllib.parse import quote

# ========== 設定 ==========
UNC_DIR     = r"\\192.168.61.42\marketing\00_マーケティング準備室\集計\02_リスト運用状況\日報"
FM_FILE     = "202606_作業リスト履歴.fmp12"
SCRIPT_NAME = "media-db"
OPEN_WAIT   = 15      # ファイルが開ききるまでの待機秒（遅い/重い場合は増やす）
# ==========================

FM_PATH = os.path.join(UNC_DIR, FM_FILE)
DB_NAME = os.path.splitext(FM_FILE)[0]   # 拡張子を除いた名前 = fmp:// で使うDB名


def main():
    # 1) 共有にアクセスできるか確認
    if not os.path.exists(FM_PATH):
        raise SystemExit(
            f"❌ ファイルにアクセスできません:\n   {FM_PATH}\n"
            f"   ・ネットワーク共有(\\\\192.168.61.42\\marketing)に接続できているか\n"
            f"   ・一度エクスプローラーでそのフォルダを開いて認証が通るか\n"
            f"   を確認してください。"
        )

    # 2) FileMaker でファイルを開く
    print(f"▶ FileMakerファイルを開きます: {FM_FILE}")
    os.startfile(FM_PATH)
    print(f"  └ 起動を待機中 ({OPEN_WAIT}秒)... ※ログイン画面が出たらここでログインしてください")
    time.sleep(OPEN_WAIT)

    # 3) fmp:// で、開いているファイルのスクリプトを実行（$ = ローカルで開いているFileMaker）
    url = f"fmp://$/{quote(DB_NAME)}?script={quote(SCRIPT_NAME)}"
    print(f"▶ スクリプト『{SCRIPT_NAME}』を実行します...")
    os.startfile(url)
    time.sleep(3)
    print("✓ スクリプト実行リクエストを送信しました。FileMaker側の処理を確認してください。")


if __name__ == "__main__":
    main()
