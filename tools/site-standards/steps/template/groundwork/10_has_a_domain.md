---
title: Has a domain
status: proven
whose_move: rob
check: dh_has_domain
---
The site is served on at least one DreamHost domain, and that domain is linked
to the site here. Without a domain there is no server to reach, so nothing
that needs the server can be worked on. The code can still be checked from its
checkout.

## How to do it

1. Decide whether the site should be served at all. A skeleton that only
   exists to be forked can stay as it is, with this step open.
2. If it should, add the domain in the DreamHost panel. That is a panel change,
   so it is Rob's.
3. After the next sweep has seen the domain, link it:
   `tools/site-standards/site.sh site-link --shortcode=<shortcode> --domain=<domain>`
