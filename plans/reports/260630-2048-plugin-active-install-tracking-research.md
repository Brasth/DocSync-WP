---
type: report
created: 2026-06-30 20:48 Asia/Ho_Chi_Minh
topic: Plugin active install tracking
plugin: brasth-document-sync-for-google-docs
---

# Plugin Active Install Tracking Research

## Summary

Current public answer: WordPress.org reports **Active installations: fewer than 10** for `brasth-document-sync-for-google-docs`.

The WordPress.org Plugin API check returned `active_installs: 0` for the same slug during this research. Treat that as a bucketed public signal, not an exact customer analytics number.

Do not add silent telemetry. If you need exact site counts later, add explicit opt-in usage reporting with clear privacy copy, a disable control, minimal payload, and server-side aggregation.

## Findings

| Question | Answer |
|---|---|
| Can we know how many sites use the plugin now? | Approx only from WordPress.org. Current public bucket is fewer than 10. |
| Is there exact built-in count for every install? | No. WordPress.org exposes public active install stats for directory-hosted plugins, but not exact per-site identity. Manual ZIP/GitHub installs outside WordPress.org are not reliably visible. |
| Current API result | `active_installs: 0` from `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=brasth-document-sync-for-google-docs&request%5Bfields%5D%5Bactive_installs%5D=1` |
| Best next step | Use WordPress.org public count for now. Add opt-in telemetry only when there is a real product need. |

## Architecture Analysis

There are two different metrics:

1. **Active installs**: approximate number of WordPress sites with the plugin installed and active. WordPress.org can estimate this for directory installs.
2. **Active users**: people using the plugin features. This requires in-plugin event tracking and is more sensitive.

For this plugin, the user asked "how many site are using the plugin", so the correct metric is **active installs**, not user behavior analytics.

## Design Recommendations

Keep it simple:

- For public distribution: rely on WordPress.org active install count.
- For release/download interest: track WordPress.org downloads and GitHub release asset downloads separately.
- For exact live site count: add optional telemetry only after consent.

Minimum safe telemetry payload if needed later:

```json
{
  "site_hash": "sha256(home_url + plugin_salt)",
  "plugin_version": "1.1.1",
  "wp_version": "7.0",
  "php_version": "8.3",
  "enabled_post_types_count": 2,
  "timestamp": "2026-06-30T13:48:00Z"
}
```

Avoid collecting:

- Site URL
- Admin email
- Google account email
- OAuth client ID or secret
- Google file IDs, titles, folder IDs, search terms, tokens
- Post titles/content
- User IDs

## Technology Guidance

Preferred options:

| Option | Use when | Pros | Cons |
|---|---|---|---|
| WordPress.org active installs | Need current public adoption count | Free, already available, no privacy work | Bucketed/approx, no exact site list |
| WordPress.org download stats | Need interest/release momentum | Useful for launch tracking | Downloads are not active sites |
| Opt-in telemetry endpoint | Need exact active site count | Accurate trend data | Privacy, policy, infrastructure, support burden |
| License/update server check-ins | Commercial plugin only | Natural check-in path | Not applicable unless product has licensing |

## Implementation Strategy

No implementation needed for the current question.

If exact tracking becomes necessary:

1. Add a Setup toggle: `Share anonymous usage diagnostics with Brasth`.
2. Default it off unless user explicitly accepts.
3. Add privacy policy text via existing `wp_add_privacy_policy_content()` path.
4. Add an external service disclosure in `readme.txt`.
5. Send a weekly WP-Cron check-in, not per-action events.
6. Aggregate counts server-side by anonymized site hash and plugin version.
7. Provide a delete/disable path.

## Sources

- WordPress.org plugin listing: https://wordpress.org/plugins/brasth-document-sync-for-google-docs/
- WordPress.org Plugin API endpoint checked: https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=brasth-document-sync-for-google-docs&request%5Bfields%5D%5Bactive_installs%5D=1
- WordPress `plugins_api()` reference: https://developer.wordpress.org/reference/functions/plugins_api/
- WordPress plugin guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- WordPress privacy policy content guidance: https://developer.wordpress.org/plugins/privacy/suggesting-text-for-the-site-privacy-policy/

## Next Actions

1. Use **fewer than 10 active installations** as the public site count today.
2. Re-check the Plugin API after the next WordPress.org stats refresh.
3. Do not build telemetry unless you need exact product analytics.

## Unresolved Questions

- Do you want exact telemetry later, or is WordPress.org public active install count enough?
- Should GitHub release asset downloads be tracked separately from WordPress.org installs?
