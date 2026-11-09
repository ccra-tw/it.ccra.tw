# it.ccra.tw（CCRA 資訊服務站）

部署流程、目錄結構見 README.md。版本號規則：site/changelog/versions.json（X.Y.Z＝升級服務．大修．小修）。

**本 repo 公開，Issues 全世界看得到**（熊哥 2026-10-14：「公開 issues 可以避開內部資訊，搭配 Planner 處理」）。
Issue 只寫功能、需求與技術決定；**不寫**：主機 IP、內部網段、帳號密碼與金鑰、同工或捐贈者姓名與個資、內部流程爭議。
這些細節放 CCRA Planner「資訊部共同事務」對應的票，Issue 內只寫「細節見 Planner 票 <前 8 碼>」。

## 開發專案流程（資訊主任 2026-11-10 定）

新功能、新服務、別的部門提出的開發請求，一律照這個順序，**主任同意前不答應、不承諾日期、不動手**：

1. **收到需求**：回覆「收到，整理需求與建議給主任確認」。
2. **評估，第一題必問：會不會給辦公室以外的人使用？**
   - 只有同工用 → 內部主機，可走 Zero Trust／協會帳號登入。
   - 有外部人員 → 正式版放外部主機（獨立網址、獨立資料庫），不走 Zero Trust，要自建登入、資料隔離與保存期限；內部只放測試版。
   - 一併列出：使用人數與裝置、是否涉及個資或位置資料、離線需求、時程、費用（含第三方 API 額度）、可用的現成工具。
3. **送主任判斷**：需求＋評估＋建議做法，主任同意（並採納他的建議）才開工。
4. **開工**：開 Planner 票並寫明「檢查人」（新服務兩位），Issue 只寫功能與技術決定。
5. **上線前**：檢查人驗收通過、留下驗收紀錄，才給使用者網址。

理由：這一題沒有在一開始問清楚，事後才發現要給外部人員用，就得補登入、換主機、改架構。

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues (ccra-tw/it.ccra.tw), via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five-role vocabulary (needs-triage, needs-info, ready-for-agent, ready-for-human, wontfix). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `GLOSSARY.md` + `docs/adr/` at the repo root, created lazily. See `docs/agents/domain.md`.
