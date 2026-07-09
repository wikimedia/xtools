XTools development with Docker
======

A docker-compose stack runs the whole app locally: an Apache front on port
3001 proxying over FastCGI to two php-fpm pools (the app and the `/api`), plus
a MariaDB for XTools' own tables. The front-end port (3001) matches production;
inside the stack the FastCGI hop runs over TCP, where production uses a local
Unix socket.

```
docker compose up --build
```

The site comes up at http://localhost:3001. First boot installs dependencies
and runs migrations, so give it a minute; assets rebuild on change. You need
neither PHP nor Node installed on the host.

Containers run as UID 1000 so files they write back (vendor, node_modules) stay
yours. If your host user isn't 1000, export `DOCKER_UID` and `DOCKER_GID`
before `up`.

### Wiki replica access

Most tools query the Wikimedia replicas, which can't run in a container. Reach
them through a Toolforge SSH tunnel on the host. Put your replica credentials
(from `~/replica.my.cnf` on Toolforge) in `.env.local`, which is gitignored:

```
DATABASE_REPLICA_USER=sXXXXX
DATABASE_REPLICA_PASSWORD=...
```

Then open the tunnel, bound to `0.0.0.0` so the containers can reach it:

```
ssh -N \
  -L 0.0.0.0:4711:s1.web.db.svc.wikimedia.cloud:3306 \
  -L 0.0.0.0:4712:s2.web.db.svc.wikimedia.cloud:3306 \
  -L 0.0.0.0:4713:s3.web.db.svc.wikimedia.cloud:3306 \
  -L 0.0.0.0:4714:s4.web.db.svc.wikimedia.cloud:3306 \
  -L 0.0.0.0:4715:s5.web.db.svc.wikimedia.cloud:3306 \
  -L 0.0.0.0:4716:s6.web.db.svc.wikimedia.cloud:3306 \
  -L 0.0.0.0:4717:s7.web.db.svc.wikimedia.cloud:3306 \
  -L 0.0.0.0:4718:s8.web.db.svc.wikimedia.cloud:3306 \
  -L 0.0.0.0:4720:tools.db.svc.eqiad.wmflabs:3306 \
  <username>@login.toolforge.org
```

The `0.0.0.0` prefix matters: `host.docker.internal` resolves to the docker
bridge gateway, not host loopback, so a default loopback-only tunnel isn't
reachable from the containers. You need at least the sections for the wikis you
test, plus s7 (the meta lookup most pages depend on).
