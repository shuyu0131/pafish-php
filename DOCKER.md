# Docker deployment

The Compose setup runs pafish with Apache/PHP and MySQL 8.4. Site files are
baked into the image; database and writable site data use named volumes.

## Start

Download [`docker-compose.yml`](https://raw.githubusercontent.com/shuyu0131/pafish-php/main/docker-compose.yml)
and [`.env.example`](https://raw.githubusercontent.com/shuyu0131/pafish-php/main/.env.example)
from the project repository, then copy `.env.example` to `.env`:

```sh
cp .env.example .env
```

Edit `.env` and set two different, long random passwords. If the GHCR image is
already available, start the site with:

```sh
docker compose up -d
```

Before the first public GHCR release, or when you want to run the checkout you
are currently editing, use the source-build command under
[Image publication](#image-publication) instead. The default Compose file
otherwise tries to pull `ghcr.io/shuyu0131/pafish-php:latest`.

Open <http://localhost:8080/install.php>. As with emlog, finish the setup in
the browser. Compose supplies the database connection automatically, so the
installer only asks for the public site URL and administrator details.

Set the public site URL to the URL readers will use, including `https://` when
TLS terminates at a reverse proxy. Keep `pretty_urls` enabled; Apache has
`mod_rewrite` enabled and the project `.htaccess` is active.

The web container listens on port `8080`. Compose binds it to `127.0.0.1` by
default, which is appropriate when a reverse proxy on the host serves the
public hostname. Set `HTTP_BIND=0.0.0.0` in `.env` only when the site should be
reachable directly from other machines. A proxy in another container should
share a Docker network with this Compose project; in that setup, expose port
8080 on the internal network rather than publishing it publicly.

For a reverse proxy, forward the original host and scheme, including
`X-Forwarded-Proto`. After installation, add only the proxy's actual IP or
CIDR to `trusted_proxies` in `runtime/config.php`; this setting controls
forwarded client-IP handling. Do not trust arbitrary client-supplied forwarding
headers. The canonical `site_url` should match the public HTTPS URL.

## Persistent data and updates

Compose creates named volumes for MySQL, runtime configuration, uploaded
media, backups, chunked uploads, plugins, and themes. They survive container
recreation and image updates. Back up the database volume and these site-data
volumes before moving a deployment to another host; copying only the image is
not a site backup.

To update the core, pull the new image and recreate the containers:

```sh
docker compose pull
docker compose up -d
```

The image is immutable at runtime for core files. Use image updates for core
releases; the application's in-place core updater cannot replace files baked
into the image. Manage installed plugins and themes through the admin UI; those
directories persist independently of the core image. On startup, the image
updates its bundled default theme, Sitemap, and AI Foundation components in
their named volumes when the image contains a higher manifest version. The
MySQL volume remains the source of truth for database content.

The `scheduler` service runs `cron.php` once per minute for scheduled posts.
Inspect service status and logs with:

```sh
docker compose ps
docker compose logs -f app scheduler db
```

Use `docker compose down` to stop the services. Do not add `-v` unless you
intend to delete the persistent database and site data.

## Image publication

The Compose file references the project image in GHCR as
`ghcr.io/shuyu0131/pafish-php`. The repository's release workflow publishes a
multi-architecture image for version tags. After the first publish, set the
GHCR package visibility to public so Docker can pull it without login. A
maintainer can also mirror it to a project-owned Docker Hub namespace. Project
images published to GHCR or a Docker Hub account are separate from Docker
Official Images, which are curated `library/` repositories maintained through
Docker's `docker-library/official-images` process.

Until the first GHCR release image is published, use the local checkout command
shown below or set `PAFISH_IMAGE` to an image you built and tagged yourself.

For comparison, Docker Hub currently marks `emlog/emlog` as a project-owned
image (`is_official: false` in the public Hub API). It can still be the
publisher's official emlog image in the everyday sense; it is simply not part
of Docker's `library/` Official Images program.

For a project-owned image, replace `your-account` with the Docker Hub
organization or account that owns the repository:

```sh
docker build -t your-account/pafish:1.2.7 -t your-account/pafish:latest .
docker push your-account/pafish:1.2.7
docker push your-account/pafish:latest
```

For a local checkout, build from source with the development override:

```sh
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
```

The override builds the `app` and `scheduler` services from the current
checkout; MySQL still uses the official `mysql:8.4` image. After changing
source files, repeat the command to rebuild the application image. Pin
`PAFISH_IMAGE` to a release tag in production instead of relying on `latest`.

Docker Official Images require an upstream source repository and maintainers
who commit to timely version and security updates, a repeatable build, Docker
best practices, and the review requirements in the Official Images project.
The current review checklist also asks whether the project has contacted the
upstream maintainers, uses an OSI-approved license, has a useful or reasonably
popular service, has documentation ready for Docker Hub, considers existing
official base images, passes current tests, and can support the image after it
is accepted. Two Official Images maintainers review the Dockerization. The
program also values multi-architecture support, although available
architectures depend on upstream and base-image support; tags must not be
continuous-integration builds that change without a release.

The project proposes a new image by adding a definition under `library/` and
opening a pull request to `docker-library/official-images`; Docker's
maintainers review and accept it. A matching image documentation contribution
to [`docker-library/docs`](https://github.com/docker-library/docs) should be
ready around the same time. See the current [Official Images review
guide](https://github.com/docker-library/official-images/blob/master/README.md)
and [new-image checklist](https://github.com/docker-library/official-images/blob/master/NEW-IMAGE-CHECKLIST.md)
before making that commitment. pafish's MIT license and upstream repository
make the licensing/source prerequisites straightforward; popularity,
maintainer commitment, documentation, reproducible builds, and multi-arch
coverage are the parts we still need to demonstrate before proposing it.
