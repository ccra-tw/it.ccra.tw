# -*- coding: utf-8 -*-
"""在 ITSrv01 暫存目錄跑資訊服務申請的伺服器端測試（不動正式站）。
把 repo 的 lib/ 與 site/ 複製到 /tmp/itccra-test/，把頁面裡的 /var/www/it-lib/ 改指向暫存 lib，
再以 www-data 身分執行 tests/svc_apply_test.php（測試資料表為 *_test schema）。
用法：python tests/run_svc_test.py"""
import os, subprocess, sys
sys.stdout.reconfigure(encoding="utf-8")
HOST = os.environ.get("ITCCRA_HOST", "itsu@itsrv01")
KEY = os.path.expanduser("~/.ssh/id_ed25519_itsu")
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
T = "/tmp/itccra-test"
ssh = ["ssh", "-i", KEY, "-o", "BatchMode=yes", HOST]
subprocess.run(ssh + [f"rm -rf {T} && mkdir -p {T}"], check=True)
subprocess.run(["tar", "-C", ROOT, "-cf", "-", "lib", "site", "tests"], stdout=open(os.path.join(os.environ.get("TEMP", "/tmp"), "itccra-test.tar"), "wb"), check=True)
subprocess.run(["scp", "-q", "-i", KEY, os.path.join(os.environ.get("TEMP", "/tmp"), "itccra-test.tar"), f"{HOST}:{T}/t.tar"], check=True)
cmd = (f"cd {T} && tar -xf t.tar && grep -rl /var/www/it-lib/ site | xargs sed -i 's#/var/www/it-lib/#{T}/lib/#g' && "
       f"chmod -R a+rX {T} && sudo -n -u www-data php {T}/tests/svc_apply_test.php {T}/site {T}/lib; rc=$?; sudo -n -u www-data php {T}/tests/push_prefs_test.php {T}/site {T}/lib || rc=1; sudo -n -u www-data php {T}/tests/survey_test.php {T}/site {T}/lib || rc=1; sudo -n -u www-data php {T}/tests/addr_test.php {T}/site {T}/lib || rc=1; sudo -n -u www-data php {T}/tests/devprojects_test.php {T}/site {T}/lib || rc=1; rm -rf {T}; exit $rc")
sys.exit(subprocess.run(ssh + [cmd]).returncode)
