-- Rob approves a change by deploying it, and what he deploys is whichever
-- branch is ready, not always master. So the branch that counts for a site is
-- the one deployed, and the column says so. The scanner checks a site's
-- checkout only when it is on this branch and clean.

ALTER TABLE std_sites RENAME COLUMN trunk_branch TO deployed_branch;
