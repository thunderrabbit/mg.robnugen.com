---
title: Classes come from an autoloader
position: 30
status: idea
whose_move: agent
check: mlaphp_autoloader
---
Chapter 3. Classes live under one directory, `classes/`, one class per file
with the file path following the namespace, and an autoloader registered with
`spl_autoload_register()` loads them. No script includes a class file by hand,
except a bootstrap that does it before its autoloader is registered (the
autoloader's own file, or a class the bootstrap needs first).

The check passes when `classes/` exists, some script registers an autoloader,
and every PHP file that includes a file under `classes/` also registers an
autoloader itself, which is what makes it a bootstrap. Tests are left out:
a suite has its own bootstrap and may load a fixture class by hand.
