---
title: WordPress core on the current release
position: 10
status: idea
whose_move: agent
check: wp_core_current
---
The install runs the current WordPress release, and its plugins and theme are
current and support the newest PHP that release allows. Nothing checks this
yet: the check would read the version from the install over ssh (WP-CLI or
`wp-includes/version.php`) and compare it with the release WordPress.org
announces. The first site to do this writes the how-to and the check.
