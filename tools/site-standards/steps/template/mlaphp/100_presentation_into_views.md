---
title: Output lives in view files
status: idea
whose_move: agent
check: mlaphp_views
---
Chapter 10. Page scripts do not `echo` HTML or build output strings. All output
goes through a template or view file, and escaping happens there. JSON
responses go through one helper or response object rather than `echo
json_encode` in each script.

The check would count `echo`/`print` of markup in page scripts. No site has
done this yet.
