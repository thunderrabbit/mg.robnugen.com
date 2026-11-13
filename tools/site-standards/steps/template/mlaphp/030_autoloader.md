---
title: Classes come from an autoloader
status: proven
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

## How to do it

1. Move every class file under `classes/`, one class per file, the path
   following the namespace (`Auth\IsLoggedIn` in `classes/Auth/IsLoggedIn.php`).
2. In the bootstrap (`prepend.php`), require the autoloader's file by hand and
   register it with `spl_autoload_register()`. mg's is
   `classes/Mlaphp/Autoloader.php`, taken from the book.
3. Delete each `include`/`require` of a class file from page scripts. A
   script that runs without the bootstrap on purpose registers the autoloader
   itself.
4. Run the site's suite, then load a few pages: a missing class is a fatal
   error, so it shows at once.
