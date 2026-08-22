#!/usr/bin/env bash
set -Eeuo pipefail

wp_root="/opt/wordpress"

if ! wp --allow-root --path="${wp_root}" core is-installed >/dev/null 2>&1; then
  exit 0
fi

existing="$(wp --allow-root --path="${wp_root}" option get docsync_wp_folder_watches --format=json 2>/dev/null || echo '[]')"

if [[ "${existing}" != "[]" && -n "${existing}" ]]; then
  echo "Demo folder watches already seeded."
  exit 0
fi

php -r '
$watches = [
    [
        "id" => "watch-demo-client-a",
        "ownerUserId" => 1,
        "folderId" => "folder_client_a_001",
        "driveId" => "",
        "folderName" => "Client A — Blog Drafts",
        "webViewLink" => "https://drive.google.com/drive/folders/folder_client_a_001",
        "includeSubfolders" => true,
        "confirmRoot" => false,
        "postType" => "post",
        "postStatus" => "draft",
        "syncInterval" => "weekly",
        "layoutPreset" => "",
        "elementorSync" => false,
        "elementorPreset" => "",
        "status" => "watching",
        "pendingFileIds" => [],
        "excludedFileIds" => ["doc_excluded_001"],
        "failed" => [],
        "importedCount" => 12,
        "totalCount" => 12,
        "overflow" => false,
        "lastScanAt" => gmdate("c", time() - 3600),
        "lastError" => "",
        "createdAt" => gmdate("c", time() - 86400 * 7),
    ],
    [
        "id" => "watch-demo-agency-hub",
        "ownerUserId" => 1,
        "folderId" => "folder_agency_hub_002",
        "driveId" => "shared_drive_agency",
        "folderName" => "Agency Hub / Case Studies",
        "webViewLink" => "https://drive.google.com/drive/folders/folder_agency_hub_002",
        "includeSubfolders" => false,
        "confirmRoot" => false,
        "postType" => "page",
        "postStatus" => "publish",
        "syncInterval" => "weekly",
        "layoutPreset" => "",
        "elementorSync" => false,
        "elementorPreset" => "",
        "status" => "importing",
        "pendingFileIds" => ["doc_pending_001", "doc_pending_002", "doc_pending_003"],
        "excludedFileIds" => [],
        "failed" => [],
        "importedCount" => 5,
        "totalCount" => 8,
        "overflow" => false,
        "lastScanAt" => gmdate("c", time() - 1800),
        "lastError" => "",
        "createdAt" => gmdate("c", time() - 86400 * 3),
    ],
    [
        "id" => "watch-demo-newsletter",
        "ownerUserId" => 1,
        "folderId" => "folder_newsletter_003",
        "driveId" => "",
        "folderName" => "Newsletter Archive",
        "webViewLink" => "https://drive.google.com/drive/folders/folder_newsletter_003",
        "includeSubfolders" => true,
        "confirmRoot" => false,
        "postType" => "post",
        "postStatus" => "draft",
        "syncInterval" => "site",
        "layoutPreset" => "",
        "elementorSync" => false,
        "elementorPreset" => "",
        "status" => "attention",
        "pendingFileIds" => [],
        "excludedFileIds" => [],
        "failed" => [
            ["fileId" => "doc_failed_001", "fileName" => "Q3 Report.docx", "error" => "Permission denied"],
        ],
        "importedCount" => 20,
        "totalCount" => 21,
        "overflow" => false,
        "lastScanAt" => gmdate("c", time() - 7200),
        "lastError" => "1 import failed",
        "createdAt" => gmdate("c", time() - 86400 * 14),
    ],
];
file_put_contents("/tmp/demo-folder-watches.json", json_encode($watches));
'

wp --allow-root --path="${wp_root}" option update docsync_wp_folder_watches "$(cat /tmp/demo-folder-watches.json)" --format=json --quiet
wp --allow-root --path="${wp_root}" option update docsync_wp_last_cron_run_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --quiet
rm -f /tmp/demo-folder-watches.json

echo "Demo folder watches seeded."
