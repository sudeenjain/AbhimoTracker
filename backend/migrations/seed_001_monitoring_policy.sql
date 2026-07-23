-- Seed: default monitoring_policy row, version 1.
-- Placeholder text -- edit `content` directly in the monitoring_policy table
-- (or via an admin CMS endpoint in a later phase) before going live.

INSERT INTO monitoring_policy (version, content, effective_date)
SELECT 1,
'PLACEHOLDER MONITORING POLICY (v1) -- replace this text before production use.

This company uses a workplace activity monitoring tool that records, during
your scheduled working hours and only after you explicitly accept this
policy:
  1. The title of the application/window you have active.
  2. Whether your keyboard/mouse has been idle, and for how long.

This tool does NOT take screenshots, does NOT log individual keystrokes,
and does NOT access your camera or microphone.

By accepting this policy you consent to the collection described above.
You may withdraw consent at any time by contacting HR, which will stop
future data collection (see company handbook for details).',
CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM monitoring_policy WHERE version = 1);
