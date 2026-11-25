# Running OpenMES in GitHub Codespaces

This folder lets you run OpenMES in the browser, with nothing installed on your
computer. A codespace is a small cloud machine that GitHub starts for you; it
builds OpenMES from this repository and runs it there.

## Start it

1. On the repository page: **Code → Codespaces → Create codespace** (pick the
   branch that contains this folder).
2. Wait. The first start builds the application and takes roughly 10 to 15
   minutes. The Terminal panel shows `Running postStartCommand...` while it
   works and prints the address and the login when it is done. For the full
   output: Command Palette (`Ctrl/Cmd+Shift+P`) →
   **Codespaces: View Creation Log**.
3. Open the **Ports** tab at the bottom, find **OpenMES (8080)** and click the
   globe icon.

Opened too early, the address shows `HTTP ERROR 401` (nothing is running on the
port yet) or an "OpenMES is starting" page that reloads by itself. Both mean the
build has not finished: wait and reload.

Open the application in a normal browser tab, not in the editor's built-in
preview: the login cookie is not sent inside the preview.

## Log in

- User: `admin`
- Password: generated on the first start and saved in the `.env` file at the
  repository root, on the `ADMIN_PASSWORD` line. Running
  `bash .devcontainer/start.sh` in the terminal prints the address and the
  login again.

`.env` is not committed (it is in `.gitignore`). The password is only applied
when the admin account is first created; after that, change it in the
application.

## Day to day

| I want to | Do this |
|---|---|
| See my code changes in the running application | `bash .devcontainer/start.sh` (rebuilds what changed) |
| See what the application is doing | `docker compose logs -f backend` |
| Stop the application | `docker compose down` |
| Start from an empty database | `docker compose down -v`, then `bash .devcontainer/start.sh` |
| Show it to someone else | Ports tab → right-click **OpenMES** → Port Visibility → Public |

A public port lets anyone with the link reach the login page. Set it back to
Private when you are done.

## The packing house module

The repository also contains a fruit packing house module (`backend/modules/Ortofrutta`)
with simulated data: traceability from a delivered order back to the producers
and from a raw lot forward to the customers, and crews organised production by
production. Once the application is up, one command enables it and loads the
data; the name is what the menu and the header of its screens show:

```text
docker compose exec backend sh modules/Ortofrutta/bin/attiva-demo.sh "Company name"
```

Then open the group with that name in the menu (`/admin/stabilimento`).
`backend/modules/Ortofrutta/README.md` has the demo script, and
`bash backend/modules/Ortofrutta/bin/prova-http.sh` checks every screen and action.

## Development mode

By default the codespace runs the application as built into the Docker image:
a code change shows after `bash .devcontainer/start.sh` has rebuilt the image.
Development mode mounts the source into the containers and rebuilds the frontend
on every save, so a change shows after a page reload:

```text
OPENMES_DEV=1 bash .devcontainer/start.sh     # on
OPENMES_DEV=0 bash .devcontainer/start.sh     # off
```

The choice is remembered in `.env`. PHP changes also need
`docker compose exec backend php artisan octane:reload`.

## Good to know

- **Cost.** A codespace uses your GitHub Codespaces quota while it runs. It
  stops by itself after 30 minutes without activity; you can also stop it from
  <https://github.com/codespaces>.
- **Data.** The database lives inside the codespace. It survives stopping and
  starting, and is gone when the codespace is deleted. Treat this as a demo and
  development environment, not as a production installation.
- **Address.** Always use the `…-8080.app.github.dev` address. The application
  builds its links for that address, so `localhost:8080` (for example from
  VS Code desktop) shows a broken page.
- **Defaults chosen here.** Anonymous usage reports to the OpenMES project are
  off (`OPENMES_TELEMETRY=false`) and the plant timezone is `Europe/Rome`
  (`APP_TIMEZONE`). Both can be changed in `.env` before the first start, or
  later in Settings → System.
- **Example companies.** Installing one (first sign-in, or Settings → Data)
  takes up to a minute here and shows no progress while it runs. Click once and
  wait. The server's 30-second request limit is raised to 5 minutes in a
  codespace (`octane.php`) so the install is not cut off partway.
- **Port 8443.** The Ports tab may list a second port, 8443. Nothing answers
  on it in a codespace (GitHub provides HTTPS on the 8080 address); ignore it.
- **Machine connectivity** (MQTT, Modbus, OPC UA) is not started. Those services
  are in the `connectivity` profile of `docker-compose.yml`.

## What is in this folder

| File | Purpose |
|---|---|
| `devcontainer.json` | Tells Codespaces which machine image to use and to run `start.sh` |
| `start.sh` | Writes `.env`, builds and starts the stack, prints the address and login |
| `docker-compose.codespaces.yml` | Swaps in the Caddyfile below when running in a codespace |
| `Caddyfile.codespaces` | Tells the application its public HTTPS address behind GitHub's port forwarding |
| `octane.php` | Raises the application server's request time limit from 30 seconds to 5 minutes |

Nothing outside this folder is changed, so updates from the upstream OpenMES
repository merge without conflicts.
