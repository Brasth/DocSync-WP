CREATE TABLE IF NOT EXISTS sites (
  site_hash TEXT PRIMARY KEY,
  plugin_slug TEXT NOT NULL,
  plugin_version TEXT NOT NULL,
  wp_version TEXT NOT NULL,
  php_version TEXT NOT NULL,
  consent_version TEXT NOT NULL,
  first_seen TEXT NOT NULL,
  last_seen TEXT NOT NULL,
  checkin_count INTEGER NOT NULL DEFAULT 1
);

CREATE INDEX IF NOT EXISTS idx_sites_last_seen ON sites (last_seen);
