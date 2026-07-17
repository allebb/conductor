# Grafana Integration

Conductor exposes machine-readable operational data through:

```shell
sudo conductor stats --format=json
sudo conductor metrics
```

Use ``stats --format=json`` for scripts or API glue. Use ``metrics`` for Prometheus/Grafana; it prints Prometheus text exposition format to STDOUT.

## Recommended Architecture

For a typical Conductor server, use:

* Prometheus ``node_exporter`` for host metrics.
* ``node_exporter`` textfile collector for Conductor-specific metrics.
* Grafana dashboards backed by Prometheus.
* Optional Loki/Promtail for Conductor, Nginx, and security logs.
* Optional exporters for MySQL/MariaDB, Redis, Fail2Ban, and Nginx if you want deeper component metrics.

Conductor does not need to run as a daemon for metrics. A timer or cron job can periodically write ``conductor metrics`` output into node_exporter's textfile collector directory.

## Conductor Prometheus Metrics

Run:

```shell
sudo conductor metrics
```

Example output:

```text
# HELP conductor_up Conductor metrics command completed successfully.
# TYPE conductor_up gauge
conductor_up 1
# HELP conductor_nginx_virtual_hosts_enabled Enabled Conductor Nginx virtual host configuration files.
# TYPE conductor_nginx_virtual_hosts_enabled gauge
conductor_nginx_virtual_hosts_enabled 3
```

Metrics currently include:

* ``conductor_up``
* ``conductor_system_uptime_seconds``
* ``conductor_nginx_uptime_seconds``
* ``conductor_memory_utilisation_percent``
* ``conductor_memory_used_bytes``
* ``conductor_memory_available_bytes``
* ``conductor_memory_total_bytes``
* ``conductor_nginx_virtual_hosts_enabled``
* ``conductor_nginx_virtual_hosts_disabled``
* ``conductor_nginx_streams_enabled``
* ``conductor_nginx_streams_disabled``
* ``conductor_applications_total``
* ``conductor_configured_ip_addresses_total``
* ``conductor_nginx_status_available``
* ``conductor_nginx_connections_active``
* ``conductor_nginx_connections_accepted_total``
* ``conductor_nginx_connections_handled_total``
* ``conductor_nginx_requests_total``
* ``conductor_nginx_connections_reading``
* ``conductor_nginx_connections_writing``
* ``conductor_nginx_connections_waiting``

## node_exporter Textfile Collector

Install node_exporter and enable the textfile collector. The exact packaging varies by distribution, but the important parts are:

```shell
sudo mkdir -p /var/lib/node_exporter/textfile_collector
```

Start node_exporter with:

```shell
--collector.textfile.directory=/var/lib/node_exporter/textfile_collector
```

Create a small metrics writer:

```shell
sudo tee /usr/local/sbin/conductor-prometheus-textfile >/dev/null <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

OUT="/var/lib/node_exporter/textfile_collector/conductor.prom"
TMP="$(mktemp "${OUT}.XXXXXX")"

/usr/bin/conductor metrics > "${TMP}"
chmod 0644 "${TMP}"
mv "${TMP}" "${OUT}"
EOF
sudo chmod 0755 /usr/local/sbin/conductor-prometheus-textfile
```

Run it manually once:

```shell
sudo /usr/local/sbin/conductor-prometheus-textfile
```

Then schedule it with cron:

```shell
* * * * * root /usr/local/sbin/conductor-prometheus-textfile
```

Or with a systemd timer if you prefer service-managed scheduling.

## Prometheus Scrape Config

Point Prometheus at node_exporter:

```yaml
scrape_configs:
  - job_name: conductor-node
    static_configs:
      - targets:
          - your-server.example.com:9100
```

The Conductor metrics will appear alongside the normal node_exporter metrics because they are loaded from the textfile collector.

## Useful Grafana Panels

Good first panels:

* Conductor applications total.
* Enabled and disabled virtual hosts.
* Enabled and disabled stream configs.
* Nginx active connections.
* Nginx request rate from ``rate(conductor_nginx_requests_total[5m])``.
* Nginx status availability.
* Memory utilisation.
* OS and Nginx uptime.

Good alert ideas:

```promql
conductor_nginx_status_available == 0
```

```promql
conductor_nginx_virtual_hosts_enabled == 0
```

```promql
rate(conductor_nginx_requests_total[5m]) > 100
```

Tune request thresholds to your own traffic.

## Logs With Loki

For log dashboards, ship these paths with Promtail or another Loki agent:

```text
/var/conductor/logs/*/access.log
/var/conductor/logs/*/error.log
/tmp/conductor_*.seclog
/var/log/fail2ban.log
/var/log/nginx/error.log
```

The ``/tmp/conductor_*.seclog`` files are especially useful when optional Fail2Ban/CrowdSec protection is enabled. They contain lean security-focused request logs suitable for panels showing scanner probes, 401/403 spikes, WAF rejections, GeoIP blocks, abusive IPs, and per-application security activity.

## Related Commands

```shell
sudo conductor stats
sudo conductor stats --format=json
sudo conductor metrics
sudo conductor ban list
```
