# Setup journey execution

Status: complete · Date: 2026-10-10

Implemented additive settings identity/timestamps and a nonce/capability protected operator connection directory. Replaced the Setup task orchestration with a pure state mapper and separate credential, account, first-source and maintenance panels. Scoped setup CSS matches the reference rail/card geometry and colors. Preserved source modal and pollers, added unsaved-change guards and local directory retry/paging. Corrected activation to retain successful source history across later failures.

Owners: `src/Settings/SettingsRepository.php`, settings REST controllers, `src/Auth/GoogleConnectionDirectory.php`, `src/Sync/SourceRepository.php`; setup app, Google setup feature modules, settings API/types, `resources/css/components/setup-journey.css`; verification scripts named in the report.

Validation and limitations: [implementation report](reports/implementation-report.md). No deployment, commit or live Google consent/import performed. Browser fixtures were temporary and removed; WordPress 6.4 test tables were removed.

Rollback: revert these scoped source/UI changes and rebuild assets. The new credential timestamp is additive; rollback does not require removing posts or Google source metadata.
