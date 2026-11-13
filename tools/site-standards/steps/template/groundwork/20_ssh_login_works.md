---
title: ssh login works
status: proven
whose_move: rob
check: dh_ssh_login
---
Someone has logged in over ssh as the DreamHost user that serves each of the
site's domains, and the day it worked is recorded. An alias in the ssh config
is not enough: until a login is proven, nobody, person or AI, can deploy to
the site or look at what it is running.

## How to do it

1. Run the `recover-dh-user` skill for the DreamHost user. Where an alias
   already works this takes a minute and records the proof.
2. Where there is no way in, the skill walks Rob through regaining access.
   Resetting a password or adding a key is a panel change, so it is Rob's.
