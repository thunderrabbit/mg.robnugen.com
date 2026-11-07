-- NAh, "Not Applicable here": a terminal status for an issue that records why
-- something does not fit this one site. On the standards page, a site note in
-- this status makes its step count as done for that site.

INSERT INTO issue_statuses (slug, label, sort_order, is_terminal, is_default)
VALUES ('nah', 'NAh', 80, 1, 0);
