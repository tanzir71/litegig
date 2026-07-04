# Decision 0001: Keep LiteGig on Path A

Status: accepted production direction

LiteGig keeps the self-hosted PHP 8 + SQLite architecture from the build plan. This is the production direction, not a temporary demo path. The app remains deployable on shared hosting with `litegig.php` as the entrypoint, modular code under `app/`, private uploads outside web root, and static public pages kept separate.

Why:
- Preserves the product promise: a local-gig operations app that runs on cheap PHP hosting.
- Works on old and inexpensive infrastructure common to cPanel/shared-host deployments.
- Keeps startup cost low because SQLite needs no managed database.
- Fits emerging markets, small operators, and simple backup workflows.
- Keeps payment/SMS providers pluggable behind disabled-by-default adapters.
- Makes `npm run deploy:shared` the user-facing production package path.

Revisit when:
- SQLite write contention becomes visible in production.
- A deployment owner explicitly funds and accepts a native port to another runtime or managed database.
- Multi-tenant marketplace billing becomes in scope.
