# Phase 02: Google OAuth and Drive Client

## Overview

Priority: P1  
Status: Completed  
Effort: 8h

Implement Google OAuth connection, token refresh, Drive metadata/export calls, and document id parsing.

## Key Decisions

- Use WordPress HTTP API instead of `google/apiclient` for MVP.
- Use authorization-code flow with offline access.
- Default scope: `https://www.googleapis.com/auth/drive.file`.
- Keep pasted link/id support, but return a clear access error if Google denies access.

## Files

Create:

- [/Volumes/500GB/Projects/DocSync-WP/src/Auth/GoogleOAuthService.php](/Volumes/500GB/Projects/DocSync-WP/src/Auth/GoogleOAuthService.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Google/DriveClient.php](/Volumes/500GB/Projects/DocSync-WP/src/Google/DriveClient.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Google/DocumentIdParser.php](/Volumes/500GB/Projects/DocSync-WP/src/Google/DocumentIdParser.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Rest/OAuthController.php](/Volumes/500GB/Projects/DocSync-WP/src/Rest/OAuthController.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Rest/DocumentController.php](/Volumes/500GB/Projects/DocSync-WP/src/Rest/DocumentController.php)

Modify:

- [/Volumes/500GB/Projects/DocSync-WP/src/Rest/RestServiceProvider.php](/Volumes/500GB/Projects/DocSync-WP/src/Rest/RestServiceProvider.php)

## REST Contracts

`GET /docsync-wp/v1/oauth/google/url`

- Requires logged-in user and nonce.
- Returns `authUrl`.
- Stores OAuth `state` transient/user meta.

`GET /docsync-wp/v1/oauth/google/callback`

- Verifies `state`.
- Exchanges code for tokens.
- Saves token for current WordPress user.
- Redirects back to DocSync admin.

`POST /docsync-wp/v1/documents/inspect`

Input:

```json
{
  "document": "https://docs.google.com/document/d/.../edit",
  "source": "picker|url|file_id"
}
```

Output:

```json
{
  "fileId": "...",
  "name": "Doc title",
  "mimeType": "application/vnd.google-apps.document",
  "modifiedTime": "...",
  "version": "...",
  "webViewLink": "..."
}
```

## Implementation Steps

1. Generate OAuth URL with `access_type=offline`, `include_granted_scopes=true`, and `state`.
2. Add callback handler and token exchange using `wp_remote_post()`.
3. Save encrypted refresh token in user meta.
4. Add token refresh flow for expired access tokens.
5. Add `DocumentIdParser`:
   - Extract `/document/d/{id}`.
   - Accept raw file id.
   - Reject non-Google Docs URLs.
6. Add Drive metadata request:
   - `files.get(fileId, fields=id,name,mimeType,modifiedTime,version,webViewLink)`.
7. Reject non-Docs MIME type.
8. Add Drive export request:
   - `files.export(fileId, text/markdown)`.
9. Normalize Google errors into WordPress REST errors:
   - not connected
   - invalid doc id
   - access denied
   - too large
   - transient Google failure

## Security

- OAuth `state` must bind to user id and expire quickly.
- Token endpoints must not reveal client secret, refresh token, or access token.
- REST callbacks must validate nonce/state and authenticated user.
- Broad Drive scopes are not enabled in this phase.

## Success Criteria

- User can connect Google account.
- User can inspect a Google Doc selected by Picker.
- User can paste a URL or file id and get metadata when scope allows.
- Access-denied response tells user to choose the file with Picker.

## Todo

- [x] Add OAuth URL endpoint.
- [x] Add OAuth callback.
- [x] Add token refresh.
- [x] Add Drive metadata request.
- [x] Add Drive export request.
- [x] Add document id parser.
- [x] Add typed REST errors.
