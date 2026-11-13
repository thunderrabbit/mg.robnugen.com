---
title: Has a domain
status: proven
whose_move: rob
check: dh_has_domain
---
The WordPress install is served on a DreamHost domain, at the domain's root or
under a path such as `/wp`, and that domain is linked to the site here.
Without a domain there is no server to reach.

## How to do it

1. Add or confirm the domain in the DreamHost panel. That is a panel change,
   so it is Rob's.
2. After the next sweep has seen it, link it, with the path when the install
   is not at the root:
   `tools/site-standards/site.sh site-link --shortcode=<shortcode> --domain=<domain> --path=/wp`
