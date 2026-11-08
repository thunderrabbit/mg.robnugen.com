# EC2 sweep

The facts the standards board needs about sites served from EC2, gathered
over ssh. Nothing here touches AWS, and nothing schedules it: Rob runs it.

    tools/ec2-sweep/install.sh <alias>          # once per server, and after changing deets.sh
    tools/ec2-sweep/sweep.sh --dry-run [alias]  # see what an import would do
    tools/ec2-sweep/sweep.sh [alias ...]        # import

`deets.sh` runs **on the server**, by name, as `~/.local/bin/sweep_ec2_deets_for_mg.sh`,
with `hostname=directory` pairs that mg supplied (`site.sh ec2-servers`). It
reads the web PHP from the box: the `php*.load` module enabled in
`/etc/apache2/mods-enabled` names it, and `dpkg-query` gives the installed
release of `libapache2-mod-php<X.Y>`. One read covers every hostname, since they
share the web server, and it needs nothing from the sites themselves. A
server that runs PHP some other way (php-fpm) gets no version and a problem
line. It also reports whether the directory is a git checkout, what HEAD is
(read with `-c safe.directory='*'`, because a production directory may belong
to the web user), and when the directory path itself last changed. It writes
nothing on the server. `sweep.sh` adds the alias and streams the JSON to
`tools/import_ec2_sweep.php` on mg, which opens and closes `ec2_domain_states`
the way the DreamHost importer does and proves the server's ssh login on the
first successful sweep.
