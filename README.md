# MQTT Bridge (pull)

Poll Kostal solar inverters and Dimplex/Weishaupt heat pumps and publish their
readings to MQTT. Requires Linux, PHP 7.3+ with curl and DOM; pcntl is recommended
for graceful service shutdown. The heat pump requires the network module and a
custom `/http/mqtt/adapter.html` endpoint.

## Configuration

Copy `config.properties.example` and set the broker and devices. Extractors are
numbered continuously from 1:

```properties
extractor1=home/solarinverter1/#,picoSolar,inverterhost
extractor2=home/heatpump/#,weishauptWP,heatpumphost,hpuser,hppass
```

Available classes: `picoSolar`, `picoSolarLegacy`, `weishauptWP`.
`broker_tls=` selects plaintext; `tls` enables TLS 1.2/1.3 and `tlsv1.2` pins TLS
1.2. Set `cafile` to your system CA bundle (Debian/Raspberry Pi OS:
`/etc/ssl/certs/ca-certificates.crt`).

## systemd service

Place this repository at `/opt/mqtt-bridge-pull` and install the service:

```sh
sudo useradd --system --user-group --no-create-home --shell /usr/sbin/nologin mqtt-bridge-pull
sudo install -d -m 0750 -o root -g mqtt-bridge-pull /etc/mqtt-bridge-pull
sudo install -m 0640 -o root -g mqtt-bridge-pull /opt/mqtt-bridge-pull/config.properties.example /etc/mqtt-bridge-pull/config.properties
sudoedit /etc/mqtt-bridge-pull/config.properties
sudo install -m 0644 /opt/mqtt-bridge-pull/mqtt-bridge-pull.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now mqtt-bridge-pull.service
```

An existing configuration can be copied instead of the example. Ensure the
service user can read the repository, configuration and CA bundle. Remove the
old cron entry and let an existing cron run finish before starting the service.
The service uses a runtime lock in `/run/mqtt-bridge-pull`; manual single runs
use `/tmp/pollpush.lock` unless `POLLPUSH_LOCK` is set to the same runtime path.

`poll_interval=120` sets the interval in seconds between poll starts. Polls never
overlap. If a poll overruns, the service waits at least one second before the
next cycle. Device failures skip that device; broker failures end the cycle and
retry on the next cycle. MQTT connections are closed after each cycle.
Configuration changes take effect after `systemctl restart mqtt-bridge-pull`.
SIGTERM/SIGINT stop the loop; in-progress network operations have timeouts.

## Journal logging

```sh
journalctl -u mqtt-bridge-pull.service -f
journalctl -u mqtt-bridge-pull.service -p warning --since today
systemctl status mqtt-bridge-pull.service
```

No application log files are written or rotated. Journald manages timestamps,
retention and persistence according to the host configuration. Normal logging
contains startup/shutdown, one summary per cycle and errors. Set
`log_level=debug` to additionally log individual published topics and values.
Credentials and raw device responses are not logged. Existing `/tmp/pollpush.log*`
files are left untouched and may be removed manually after checking them.

## Manual use and checks

```sh
php pollpush.php --config=/path/to/config.properties
php pollpush.php --loop --interval=120 --config=/path/to/config.properties
php test/legacy-regression.php
python3 test/service-regression.py
```

Without `--config`, the previous path
`$HOME/.config/phpMQTTbridge/config.properties` is used. Without `--loop`, one
cycle runs, returning a nonzero exit status on device or broker failure.
Missing configuration is reported as an error; no placeholder file is created.
