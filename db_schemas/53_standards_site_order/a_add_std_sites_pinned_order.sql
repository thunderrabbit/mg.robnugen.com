-- Rob orders the standards board by hand, by dragging: a site he is busy on
-- goes near the top even if it has one step left, and how far behind a site
-- is says nothing about how much he cares. A pinned site is listed above the
-- host headings, whatever its host. sort_order is the site's place inside its
-- block (pinned, or its host's); NULL sorts after every number, by shortcode,
-- so the board looks as it did until something is dragged. Set only from the
-- page (wwwroot/admin/dh/standards_order.php).

ALTER TABLE std_sites
    ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER autonomy,
    ADD COLUMN sort_order INT NULL AFTER pinned;
