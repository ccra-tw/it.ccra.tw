# it.ccra.tw（CCRA 資訊服務站）

部署流程、目錄結構見 README.md。版本號規則：site/changelog/versions.json（X.Y.Z＝升級服務．大修．小修）。

**本 repo 公開，Issues 全世界看得到**（熊哥 2026-10-14：「公開 issues 可以避開內部資訊，搭配 Planner 處理」）。
Issue 只寫功能、需求與技術決定；**不寫**：主機 IP、內部網段、帳號密碼與金鑰、同工或捐贈者姓名與個資、內部流程爭議。
這些細節放 CCRA Planner「資訊部共同事務」對應的票，Issue 內只寫「細節見 Planner 票 <前 8 碼>」。

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues (ccra-tw/it.ccra.tw), via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five-role vocabulary (needs-triage, needs-info, ready-for-agent, ready-for-human, wontfix). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `GLOSSARY.md` + `docs/adr/` at the repo root, created lazily. See `docs/agents/domain.md`.
