# Automations for Pterodactyl

[![Release](https://img.shields.io/github/v/release/CCBlueX/Pterodactyl-Extension-Automations?style=flat-square)](https://github.com/CCBlueX/Pterodactyl-Extension-Automations/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/CCBlueX/Pterodactyl-Extension-Automations/total?style=flat-square)](https://github.com/CCBlueX/Pterodactyl-Extension-Automations/releases)
[![Pterodactyl](https://img.shields.io/badge/Pterodactyl-2.0-0e4688?style=flat-square)](https://pterodactyl.io)
[![License](https://img.shields.io/github/license/CCBlueX/Pterodactyl-Extension-Automations?style=flat-square)](LICENSE)

Schedules that do more: restart with a countdown, only when nobody is online, then tell Discord the server is back.

![Automations](docs/screenshots/automations.png)

## Install

1. Download `automations-<version>.pteroext` from the [latest release](https://github.com/CCBlueX/Pterodactyl-Extension-Automations/releases/latest).
2. Install and enable it under **Admin → Extensions**.
3. Open a server's **Automations** tab and click **New automation**.

## Features

- **Steps**: console commands, power actions, backups, waits, "wait until running or offline", countdowns and Discord or webhook messages.
- **Conditions**: if/else on the server state, CPU or memory usage, or the number of players online (Minecraft).
- **Triggers in your time zone**: every few minutes, daily or on chosen weekdays, with a preview of the next runs.
- **Live progress and history**: see which step is running, cancel a run, and read a log of every step.
- **Safe**: steps need the same permissions as doing it by hand, and webhooks cannot reach private networks.

![Editor](docs/screenshots/editor.png)

![Run history](docs/screenshots/history.png)

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| Allow private webhook targets | Off | Lets notify steps post to private networks. |
| Player count host | Empty | Host used to reach Minecraft servers for player conditions, when the allocation address does not work from the panel. |

Subusers get an **Automations** group in the server's **Users** tab: see, manage and run automations.

---

Inspired by SchedulesPlus. The core **Schedules** page stays as it is and links here.
