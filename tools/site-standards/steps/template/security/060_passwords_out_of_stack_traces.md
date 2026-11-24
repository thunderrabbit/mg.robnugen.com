---
title: Passwords stay out of stack traces
status: idea
whose_move: agent
check: security_sensitive_parameters
model: sonnet
---
A stack trace prints the arguments of every function on the stack. A password
parameter marked `#[\SensitiveParameter]` is printed as
`Object(SensitiveParameterValue)` instead, wherever the trace ends up.

The check reads tracked PHP. Every typed password parameter, such as
`string $password` or `?string $new_password`, must carry the attribute on
its own line. A name with "hash" in it is the stored hash and is not counted.
A site with no such parameter passes.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; this is its commit
`Keep passwords out of stack traces` (d782525). One commit.

1. Put `#[\SensitiveParameter]` in front of every parameter that receives a
   plain password, in every function it is handed to, private ones included.
2. Do the same for the raw login cookie where it is a parameter. The check
   cannot see those, so find them by reading the login class.
3. Run the site's gates, then this step's check.
