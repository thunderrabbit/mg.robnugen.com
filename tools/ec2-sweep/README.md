# EC2 sweep

The facts the standards board needs about sites served from EC2, gathered
over ssh. Nothing here touches AWS, and nothing schedules it: Rob runs it.

    tools/ec2-sweep/install.sh <alias>          # once per server, and after changing deets.sh
    tools/ec2-sweep/sweep.sh --dry-run [alias]  # see what an import would do
    tools/ec2-sweep/sweep.sh [alias ...]        # import

`deets.sh` runs **on the server**, by name, as `~/.local/bin/sweep_ec2_deets_for_mg.sh`,
with `hostname=directory` pairs that mg supplied (`site.sh ec2-servers`). It
reads the web PHP of each hostname by asking the server itself for
`/_php_version.php` (see `tools/site-standards/NAMES.md`) over loopback,
through the real vhost: first `https://127.0.0.1` with `--resolve`, then,
because TLS may end at a load balancer, plain HTTP on port 80 with `Host:` and
`X-Forwarded-Proto: https`. It also reports whether the directory is a git
checkout, what HEAD is, and when the directory path itself last changed. It
writes nothing on the server. `sweep.sh` adds the alias and streams the JSON to
`tools/import_ec2_sweep.php` on mg, which opens and closes `ec2_domain_states`
the way the DreamHost importer does and proves the server's ssh login on the
first successful sweep.

A site whose bootstrap runs on every request must let `/_php_version.php`
through, or the probe answers with a page and the sweep records no version.
