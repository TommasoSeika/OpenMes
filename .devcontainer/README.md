# Running OpenMES in GitHub Codespaces

This folder lets you run OpenMES in the browser, with nothing installed on your
computer. A codespace is a small cloud machine that GitHub starts for you; it
builds OpenMES from this repository and runs it there.

## Start it

1. On the repository page: **Code → Codespaces → Create codespace** (pick the
   branch that contains this folder).
2. Wait. The first start builds the application and takes roughly 10 to 15
   minutes. To watch it: Command Palette (`Ctrl/Cmd+Shift+P`) →
   **Codespaces: View Creation Log**.
3. Open the **Ports** tab at the bottom, find **OpenMES (8080)** and click the
   globe icon. If the application is still starting you get a page that reloads
   by itself.

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
- **Machine connectivity** (MQTT, Modbus, OPC UA) is not started. Those services
  are in the `connectivity` profile of `docker-compose.yml`.

## What is in this folder

| File | Purpose |
|---|---|
| `devcontainer.json` | Tells Codespaces which machine image to use and to run `start.sh` |
| `start.sh` | Writes `.env`, builds and starts the stack, prints the address and login |
| `docker-compose.codespaces.yml` | Swaps in the Caddyfile below when running in a codespace |
| `Caddyfile.codespaces` | Tells the application its public HTTPS address behind GitHub's port forwarding |

Nothing outside this folder is changed, so updates from the upstream OpenMES
repository merge without conflicts.
