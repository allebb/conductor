<?php

require_once 'MysqlPdo.php';

/**
 * Conductor
 *
 * Conductor is a CLI tool to aid provisioning and maintenance of PHP-based sites and applications.
 *
 * @author Bobby Allen <ballen@bobbyallen.me>
 * @license http://opensource.org/licenses/MIT
 * @link https://github.com/allebb/conductor
 * @link http://bobbyallen.me
 *
 */
class Conductor extends CliApplication
{

    /**
     * The main Conductor application version.
     */
    const CONDUCTOR_VERSION = "5.0.0";

    /**
     * The path to the core application configuration file.
     */
    const CONDUCTOR_CONF = "/etc/conductor.conf";

    /**
     * Number of spaces to use as indentation on the Nginx ENV block.
     */
    const SPACES_ENV_INDENT = 8;

    /**
     * CLI boolean value const.
     */
    const OPTION_YES = "y";
    const OPTION_NO = "n";
    const NGINX_CONFIG_ERROR_EXIT_CODE = 88;
    const GEOIP_DATABASE_FILENAME = "dbip-country-lite.mmdb";
    const DEFAULT_PROXY_TARGET = "http://localhost:9000";
    const AUTH_START_MARKER = "# -- C:Start HTTP Basic Auth Block -- #";
    const AUTH_END_MARKER = "# -- C:End HTTP Basic Auth Block -- #";
    const PROTECTION_START_MARKER = "# -- C:Start Fail2Ban Protection Block -- #";
    const PROTECTION_END_MARKER = "# -- C:End Fail2Ban Protection Block -- #";
    const WAF_START_MARKER = "# -- C:Start WAF Include Block -- #";
    const WAF_END_MARKER = "# -- C:End WAF Include Block -- #";
    const FAIL2BAN_WEBHOOK_ACTION_FILENAME = "conductor-webhook.conf";
    const LETSENCRYPT_WEBHOOK_CONFIG_FILENAME = "letsencrypt-webhook.conf";

    /**
     * The current application number.
     * @var string
     */
    private $appname = '';

    /**
     * The current application base directory.
     * @var string
     */
    private $appdir = '';

    /**
     * The conductor configuration object settings.
     * @var \stdClass
     */
    private $conf;

    /**
     * The MySQL PDO instance for database operations.
     * @var \Pdo
     */
    private $mysql;

    public function __construct($argv)
    {
        parent::__construct($argv);

        $this->enforceCli();

        if ($this->getCommand(1) == '__complete') {
            $this->conf = $this->conductorConfiguration(false);
            return;
        }

        if (!$this->isSuperUser()) {
            $this->writeln('You must be root to use this tool!');
            $this->endWithError();
        }

        $this->conf = $this->conductorConfiguration();
        $this->checkDependencies();
    }

    /**
     * Loads the Conductor configuration file.
     * @return stdClass
     */
    private function conductorConfiguration($required = true)
    {
        if (file_exists(self::CONDUCTOR_CONF)) {
            return json_decode(file_get_contents(self::CONDUCTOR_CONF));
        }

        if ($required) {
            $this->writeln('The conductor configuration file could not be found!');
            $this->endWithError();
        }

        return new stdClass();
    }

    /**
     * Returns the current version of Conductor.
     * @return string
     */
    public function version()
    {
        return self::CONDUCTOR_VERSION;
    }

    /**
     * Checks the Conductor CLI dependencies and will exit if not fully satistifed.
     * @return void
     */
    public function checkDependencies()
    {
        $depends = [
            'PDO' => 'The PHP PDO extention is required but is missing',
            'posix' => 'The PHP POSIX extention is required but is missing',
            'json' => 'The PHP JSON extention is required but is missing',
        ];

        if ($this->mysqlEnabled()) {
            $depends['pdo_mysql'] = 'The PHP MySQL PDO extension is required but is missing';
        }

        foreach ($depends as $function => $dependency) {
            if (!extension_loaded($function)) {
                $this->writeln($dependency);
                $this->endWithError();
            }
        }
    }

    /**
     * Connect to MySQL when a command first needs database access.
     * @return void
     */
    private function connectMySQL()
    {
        if (!$this->mysqlEnabled() || $this->mysql) {
            return;
        }

        $this->mysql = MysqlPdo::connect('information_schema', $this->conf->mysql->username,
            $this->conf->mysql->password, $this->conf->mysql->host);
    }

    /**
     * Checks whether Conductor should manage local MySQL databases.
     * @return boolean
     */
    private function mysqlEnabled()
    {
        if (!isset($this->conf->mysql->enabled)) {
            return true;
        }

        return (bool) $this->conf->mysql->enabled;
    }

    /**
     * Display versions for installed Conductor-managed components.
     * @return void
     */
    public function versions()
    {
        $this->writeln(sprintf('%-18s %s', 'Component', 'Version'));

        foreach ($this->versionComponents() as $component => $settings) {
            $this->writeln(sprintf('%-18s %s', $component, $this->componentVersion($settings)));
        }
    }

    /**
     * Components and configured binaries to display in conductor versions.
     * @return array
     */
    private function versionComponents()
    {
        return [
            'CertBot' => ['binary' => 'certbot', 'arguments' => ['--version']],
            'MySQL' => ['binary' => 'mysql', 'arguments' => ['--version']],
            'Redis' => ['binary' => 'redis', 'arguments' => ['--version']],
            'Supervisor' => ['binary' => 'supervisord', 'arguments' => ['--version']],
            'PHP7.4' => ['binary' => 'php7.4', 'arguments' => ['--version']],
            'PHP8.1' => ['binary' => 'php8.1', 'arguments' => ['--version']],
            'PHP8.4' => ['binary' => 'php8.4', 'arguments' => ['--version']],
            'PHP8.5' => ['binary' => 'php8.5', 'arguments' => ['--version']],
            'nftables' => ['binary' => 'nftables', 'arguments' => ['--version']],
            'Fail2Ban' => ['binary' => 'fail2ban', 'arguments' => ['--version']],
            'Crowdsec' => ['binary' => 'crowdsec', 'arguments' => ['-version']],
        ];
    }

    /**
     * Get a component version or N/A when the configured binary is unavailable.
     * @param array $settings
     * @return string
     */
    private function componentVersion($settings)
    {
        $binary = $this->configuredBinary($settings['binary']);
        if (!$binary || !is_executable($binary)) {
            return 'N/A';
        }

        $command = escapeshellarg($binary);
        foreach ($settings['arguments'] as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $output = [];
        exec($command . ' 2>&1', $output, $exit_code);
        if ($exit_code !== 0 || empty($output)) {
            return 'N/A';
        }

        return $this->extractVersionNumber(implode(' ', $output));
    }

    /**
     * Return a configured binary path if present.
     * @param string $name
     * @return string|null
     */
    private function configuredBinary($name)
    {
        if (!isset($this->conf->binaries->$name)) {
            return null;
        }

        return $this->conf->binaries->$name;
    }

    /**
     * Extract the first semantic-looking version number from command output.
     * @param string $output
     * @return string
     */
    private function extractVersionNumber($output)
    {
        if (preg_match('/\d+(?:\.\d+)+(?:[-+~][A-Za-z0-9.+~:-]+)?/', $output, $matches)) {
            return $matches[0];
        }

        return trim($output) ?: 'N/A';
    }

    /**
     * Display operating system, Nginx and network statistics.
     * @return void
     */
    public function stats()
    {
        $stats = $this->statsData();

        if (strtolower($this->getOption('format', 'text')) == 'json') {
            $this->writeln(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        $this->writeln('System');
        $this->writeln('  Operating system uptime: ' . $stats['system']['operating_system_uptime']);
        $this->writeln('  Nginx daemon uptime:     ' . $stats['system']['nginx_daemon_uptime']);

        $this->writeln();
        $this->writeln('Memory');
        $this->writeln('   Utilisation: ' . $stats['memory']['utilisation_percent'] . '%');
        $this->writeln('   Used:         ' . $stats['memory']['used_mb'] . 'MB');
        $this->writeln('   Available:   ' . $stats['memory']['available_mb'] . 'MB');

        $this->writeln();
        $this->writeln('Nginx status');
        foreach ($this->formatNginxStatusLines($stats['nginx_status']) as $line) {
            $this->writeln('  ' . $line);
        }

        $this->writeln();
        $this->writeln('Nginx configuration');
        $this->writeln('  Vhosts enabled:  ' . $stats['nginx_configuration']['virtual_hosts']['enabled']);
        $this->writeln('  Vhosts disabled: ' . $stats['nginx_configuration']['virtual_hosts']['disabled']);
        $this->writeln('  Streams enabled:        ' . $stats['nginx_configuration']['streams']['enabled']);
        $this->writeln('  Streams disabled:       ' . $stats['nginx_configuration']['streams']['disabled']);

        $this->writeln();
        $this->writeln('Configured IP addresses');
        foreach ($stats['configured_ip_addresses'] as $address) {
            $this->writeln('  * ' . $address);
        }

        $this->writeln();
        $this->writeln('Public (detected) IP: ' . $stats['public_detected_ip']);
    }

    /**
     * Display Prometheus textfile metrics for Conductor-managed services.
     * @return void
     */
    public function metrics()
    {
        $this->writeln($this->prometheusMetrics($this->statsData()));
    }

    /**
     * Collect the full stats payload used by text and JSON output.
     * @return array
     */
    private function statsData()
    {
        $operating_system_uptime_seconds = $this->operatingSystemUptimeSeconds();
        $nginx_daemon_uptime_seconds = $this->nginxDaemonUptimeSeconds();

        return [
            'system' => [
                'operating_system_uptime' => $this->formatDuration($operating_system_uptime_seconds),
                'operating_system_uptime_seconds' => $this->wholeSeconds($operating_system_uptime_seconds),
                'nginx_daemon_uptime' => $this->formatDuration($nginx_daemon_uptime_seconds),
                'nginx_daemon_uptime_seconds' => $this->wholeSeconds($nginx_daemon_uptime_seconds),
            ],
            'memory' => $this->memoryStats(),
            'nginx_status' => $this->nginxStatusData(),
            'nginx_configuration' => $this->nginxConfigurationStats(),
            'applications' => $this->applicationStats(),
            'configured_ip_addresses' => $this->configuredIpAddresses(),
            'public_detected_ip' => $this->publicDetectedIpAddress(),
        ];
    }

    /**
     * Return application inventory counts.
     * @return array
     */
    private function applicationStats()
    {
        $applications = [
            'total' => 0,
        ];

        if (!isset($this->conf->paths->apps) || !is_dir($this->conf->paths->apps)) {
            return $applications;
        }

        foreach (new DirectoryIterator($this->conf->paths->apps) as $application) {
            if ($application->isDir() && $application->getBasename()[0] != '.') {
                $applications['total']++;
            }
        }

        return $applications;
    }

    /**
     * Convert structured stats into Prometheus text exposition format.
     * @param array $stats
     * @return string
     */
    private function prometheusMetrics($stats)
    {
        $metrics = [];
        $this->addPrometheusMetric($metrics, 'conductor_up', 'Conductor metrics command completed successfully.', 'gauge', 1);
        $this->addPrometheusMetric($metrics, 'conductor_system_uptime_seconds', 'Operating system uptime in seconds.', 'gauge', $stats['system']['operating_system_uptime_seconds'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_uptime_seconds', 'Nginx master process uptime in seconds.', 'gauge', $stats['system']['nginx_daemon_uptime_seconds'] ?? null);

        $this->addPrometheusMetric($metrics, 'conductor_memory_utilisation_percent', 'Memory utilisation percentage.', 'gauge', $stats['memory']['utilisation_percent'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_memory_used_bytes', 'Used memory in bytes.', 'gauge', $this->megabytesToBytes($stats['memory']['used_mb'] ?? null));
        $this->addPrometheusMetric($metrics, 'conductor_memory_available_bytes', 'Available memory in bytes.', 'gauge', $this->megabytesToBytes($stats['memory']['available_mb'] ?? null));
        $this->addPrometheusMetric($metrics, 'conductor_memory_total_bytes', 'Total memory in bytes.', 'gauge', $this->megabytesToBytes($stats['memory']['total_mb'] ?? null));

        $this->addPrometheusMetric($metrics, 'conductor_nginx_virtual_hosts_enabled', 'Enabled Conductor Nginx virtual host configuration files.', 'gauge', $stats['nginx_configuration']['virtual_hosts']['enabled'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_virtual_hosts_disabled', 'Disabled Conductor Nginx virtual host configuration files.', 'gauge', $stats['nginx_configuration']['virtual_hosts']['disabled'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_streams_enabled', 'Enabled Conductor Nginx stream configuration files.', 'gauge', $stats['nginx_configuration']['streams']['enabled'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_streams_disabled', 'Disabled Conductor Nginx stream configuration files.', 'gauge', $stats['nginx_configuration']['streams']['disabled'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_applications_total', 'Conductor application directories.', 'gauge', $stats['applications']['total'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_configured_ip_addresses_total', 'Configured host IP addresses detected by Conductor.', 'gauge', $this->configuredIpAddressCount($stats['configured_ip_addresses'] ?? []));

        $nginx_status_available = !(isset($stats['nginx_status']['available']) && $stats['nginx_status']['available'] === false);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_status_available', 'Whether Nginx stub_status data was available.', 'gauge', $nginx_status_available ? 1 : 0);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_connections_active', 'Active Nginx connections.', 'gauge', $stats['nginx_status']['active_connections'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_connections_accepted_total', 'Accepted Nginx connections.', 'counter', $stats['nginx_status']['accepted_connections'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_connections_handled_total', 'Handled Nginx connections.', 'counter', $stats['nginx_status']['handled_connections'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_requests_total', 'Nginx requests.', 'counter', $stats['nginx_status']['requests'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_connections_reading', 'Nginx connections reading request headers.', 'gauge', $stats['nginx_status']['reading'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_connections_writing', 'Nginx connections writing responses.', 'gauge', $stats['nginx_status']['writing'] ?? null);
        $this->addPrometheusMetric($metrics, 'conductor_nginx_connections_waiting', 'Idle keepalive Nginx connections.', 'gauge', $stats['nginx_status']['waiting'] ?? null);

        return implode(PHP_EOL, $metrics) . PHP_EOL;
    }

    /**
     * Add one Prometheus metric when the value is numeric.
     * @param array $metrics
     * @param string $name
     * @param string $help
     * @param string $type
     * @param mixed $value
     * @return void
     */
    private function addPrometheusMetric(&$metrics, $name, $help, $type, $value)
    {
        if (!is_numeric($value)) {
            return;
        }

        $metrics[] = '# HELP ' . $name . ' ' . str_replace(["\r", "\n"], ' ', $help);
        $metrics[] = '# TYPE ' . $name . ' ' . $type;
        $metrics[] = $name . ' ' . $value;
    }

    /**
     * Convert MB values to bytes for Prometheus conventions.
     * @param mixed $megabytes
     * @return int|null
     */
    private function megabytesToBytes($megabytes)
    {
        if (!is_numeric($megabytes)) {
            return null;
        }

        return (int) $megabytes * 1024 * 1024;
    }

    /**
     * Count real configured IP address entries, excluding N/A placeholders.
     * @param array $addresses
     * @return int
     */
    private function configuredIpAddressCount($addresses)
    {
        $count = 0;
        foreach ($addresses as $address) {
            if (strpos($address, 'N/A') !== 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count enabled and disabled Nginx virtual host and stream configuration files.
     * @return array
     */
    private function nginxConfigurationStats()
    {
        return [
            'virtual_hosts' => $this->countConfigurationFiles(
                isset($this->conf->paths->appconfs) ? $this->conf->paths->appconfs : null,
                ['conf'],
                ['disabled']
            ),
            'streams' => $this->countConfigurationFiles(
                $this->streamsConfigurationDirectory(),
                ['conf'],
                ['disabled', 'conf.example']
            ),
        ];
    }

    /**
     * Return the configured stream directory, falling back to the documented default.
     * @return string
     */
    private function streamsConfigurationDirectory()
    {
        if (isset($this->conf->paths->streams)) {
            return rtrim($this->conf->paths->streams, '/');
        }

        return '/etc/conductor/streams';
    }

    /**
     * Count files by extension in a configuration directory.
     * @param string|null $directory
     * @param array $enabled_extensions
     * @param array $disabled_extensions
     * @return array
     */
    private function countConfigurationFiles($directory, $enabled_extensions, $disabled_extensions)
    {
        $counts = [
            'enabled' => 0,
            'disabled' => 0,
        ];

        if (!$directory || !is_dir($directory)) {
            return $counts;
        }

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_file(rtrim($directory, '/') . '/' . $entry)) {
                continue;
            }

            foreach ($enabled_extensions as $extension) {
                if (substr($entry, -strlen('.' . $extension)) === '.' . $extension) {
                    $counts['enabled']++;
                    continue 2;
                }
            }

            foreach ($disabled_extensions as $extension) {
                if (substr($entry, -strlen('.' . $extension)) === '.' . $extension) {
                    $counts['disabled']++;
                    continue 2;
                }
            }
        }

        return $counts;
    }

    /**
     * Read current memory utilisation from procfs.
     * @return array
     */
    private function memoryStats()
    {
        if (!is_readable('/proc/meminfo')) {
            return [
                'utilisation_percent' => 'N/A',
                'used_mb' => 'N/A',
                'available_mb' => 'N/A',
                'total_mb' => 'N/A',
            ];
        }

        return $this->parseMemoryStats(file_get_contents('/proc/meminfo'));
    }

    /**
     * Parse Linux meminfo data into whole MB values.
     * @param string $meminfo
     * @return array
     */
    private function parseMemoryStats($meminfo)
    {
        $values = [];
        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', trim($line), $matches)) {
                $values[$matches[1]] = (int) $matches[2];
            }
        }

        if (!isset($values['MemTotal']) || $values['MemTotal'] <= 0) {
            return [
                'utilisation_percent' => 'N/A',
                'used_mb' => 'N/A',
                'available_mb' => 'N/A',
                'total_mb' => 'N/A',
            ];
        }

        $total_kb = $values['MemTotal'];
        $available_kb = $values['MemAvailable'] ?? ($values['MemFree'] ?? 0);
        $used_kb = max(0, $total_kb - $available_kb);

        return [
            'utilisation_percent' => (int) round(($used_kb / $total_kb) * 100),
            'used_mb' => (int) round($used_kb / 1024),
            'available_mb' => (int) round($available_kb / 1024),
            'total_mb' => (int) round($total_kb / 1024),
        ];
    }

    /**
     * Format a duration in seconds as Xd Xh Xm.
     * @param int|float|null $seconds
     * @return string
     */
    private function formatDuration($seconds)
    {
        if ($seconds === null || $seconds < 0) {
            return 'N/A';
        }

        $minutes = (int) floor($seconds / 60);
        $days = (int) floor($minutes / 1440);
        $hours = (int) floor(($minutes % 1440) / 60);
        $remaining_minutes = $minutes % 60;

        return $days . 'd ' . $hours . 'h ' . $remaining_minutes . 'm';
    }

    /**
     * Convert a duration value to whole seconds for structured output.
     * @param int|float|null $seconds
     * @return int|null
     */
    private function wholeSeconds($seconds)
    {
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        return (int) floor($seconds);
    }

    /**
     * Read the operating system uptime from procfs.
     * @return float|null
     */
    private function operatingSystemUptimeSeconds()
    {
        if (!is_readable('/proc/uptime')) {
            return null;
        }

        $uptime = trim(file_get_contents('/proc/uptime'));
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)/', $uptime, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    /**
     * Calculate Nginx master process uptime from systemd/procfs details.
     * @return float|null
     */
    private function nginxDaemonUptimeSeconds()
    {
        $pid = $this->nginxMainPid();
        if (!$pid || !is_readable('/proc/' . $pid . '/stat')) {
            return $this->nginxServiceUptimeSeconds();
        }

        $stat = file_get_contents('/proc/' . $pid . '/stat');
        $end = strrpos($stat, ')');
        if ($end === false) {
            return $this->nginxServiceUptimeSeconds();
        }

        $parts = preg_split('/\s+/', trim(substr($stat, $end + 2)));
        if (!isset($parts[19])) {
            return $this->nginxServiceUptimeSeconds();
        }

        $clock_ticks = $this->clockTicksPerSecond();
        $uptime = $this->operatingSystemUptimeSeconds();
        if (!$clock_ticks || $uptime === null) {
            return $this->nginxServiceUptimeSeconds();
        }

        return $uptime - ((float) $parts[19] / $clock_ticks);
    }

    /**
     * Calculate Nginx uptime from systemd service metadata.
     * @return float|null
     */
    private function nginxServiceUptimeSeconds()
    {
        $output = [];
        exec('systemctl show nginx --property=ActiveEnterTimestamp --value 2>/dev/null', $output, $exit_code);
        if ($exit_code === 0 && isset($output[0])) {
            $uptime = $this->parseNginxActiveSinceUptimeSeconds($output[0]);
            if ($uptime !== null) {
                return $uptime;
            }
        }

        $output = [];
        exec('service nginx status 2>/dev/null', $output, $exit_code);
        if ($exit_code === 0 && $output) {
            return $this->parseNginxActiveSinceUptimeSeconds(implode("\n", $output));
        }

        return null;
    }

    /**
     * Parse a systemd "Active: ... since ..." timestamp into uptime seconds.
     * @param string $status
     * @param int|null $now
     * @return float|null
     */
    private function parseNginxActiveSinceUptimeSeconds($status, $now = null)
    {
        $status = trim($status);
        if ($status === '' || strtolower($status) === 'n/a') {
            return null;
        }

        if (preg_match('/\bsince\s+(.+?)(?:;|\n|$)/i', $status, $matches)) {
            $status = trim($matches[1]);
        }

        $started = strtotime($status);
        if ($started === false) {
            return null;
        }

        $now = $now ?? time();
        $seconds = $now - $started;

        return $seconds >= 0 ? (float) $seconds : null;
    }

    /**
     * Return the Nginx service main PID, falling back to the oldest nginx process.
     * @return int|null
     */
    private function nginxMainPid()
    {
        $output = [];
        exec('systemctl show nginx --property=MainPID --value 2>/dev/null', $output, $exit_code);
        if ($exit_code === 0 && isset($output[0]) && (int) $output[0] > 0) {
            return (int) $output[0];
        }

        $output = [];
        exec('pgrep -o nginx 2>/dev/null', $output, $exit_code);
        if ($exit_code === 0 && isset($output[0]) && (int) $output[0] > 0) {
            return (int) $output[0];
        }

        return null;
    }

    /**
     * Return the system clock ticks per second.
     * @return int|null
     */
    private function clockTicksPerSecond()
    {
        $output = [];
        exec('getconf CLK_TCK 2>/dev/null', $output, $exit_code);
        if ($exit_code === 0 && isset($output[0]) && (int) $output[0] > 0) {
            return (int) $output[0];
        }

        return null;
    }

    /**
     * Retrieve and parse the default vhost Nginx stub status endpoint.
     * @return array
     */
    private function nginxStatusData()
    {
        $body = $this->httpGet('http://127.0.0.1/nginx_status');
        if ($body === null) {
            $body = $this->httpGet('https://127.0.0.1/nginx_status', false);
        }

        if ($body === null) {
            return [
                'available' => false,
                'error' => 'Unable to read http(s)://127.0.0.1/nginx_status',
            ];
        }

        return $this->parseNginxStatusData($body);
    }

    /**
     * Format parsed Nginx stub_status data into readable lines.
     * @param array $status
     * @return array
     */
    private function formatNginxStatusLines($status)
    {
        if (isset($status['available']) && !$status['available']) {
            return ['N/A - ' . $status['error']];
        }

        if (!isset($status['active_connections'])) {
            return isset($status['raw']) && is_array($status['raw']) ? $status['raw'] : ['N/A'];
        }

        return [
            'Active connections:   ' . $status['active_connections'],
            'Accepted connections: ' . ($status['accepted_connections'] ?? 'N/A'),
            'Handled connections:  ' . ($status['handled_connections'] ?? 'N/A'),
            'Requests:             ' . ($status['requests'] ?? 'N/A'),
            'Reading:              ' . ($status['reading'] ?? 'N/A'),
            'Writing:              ' . ($status['writing'] ?? 'N/A'),
            'Waiting:              ' . ($status['waiting'] ?? 'N/A'),
        ];
    }

    /**
     * Parse Nginx stub_status output into readable lines.
     * @param string $body
     * @return array
     */
    private function parseNginxStatus($body)
    {
        return $this->formatNginxStatusLines($this->parseNginxStatusData($body));
    }

    /**
     * Parse Nginx stub_status output into structured data.
     * @param string $body
     * @return array
     */
    private function parseNginxStatusData($body)
    {
        $lines = preg_split('/\r?\n/', trim($body));
        $lines = array_values(array_filter(array_map('trim', $lines), 'strlen'));

        if (isset($lines[0]) && preg_match('/^Active connections:\s+(\d+)/i', $lines[0], $matches)) {
            $parsed = [
                'active_connections' => (int) $matches[1],
            ];

            if (isset($lines[2]) && preg_match('/^(\d+)\s+(\d+)\s+(\d+)$/', $lines[2], $matches)) {
                $parsed['accepted_connections'] = (int) $matches[1];
                $parsed['handled_connections'] = (int) $matches[2];
                $parsed['requests'] = (int) $matches[3];
            }

            if (isset($lines[3]) && preg_match('/Reading:\s+(\d+)\s+Writing:\s+(\d+)\s+Waiting:\s+(\d+)/i', $lines[3], $matches)) {
                $parsed['reading'] = (int) $matches[1];
                $parsed['writing'] = (int) $matches[2];
                $parsed['waiting'] = (int) $matches[3];
            }

            return $parsed;
        }

        return [
            'available' => false,
            'raw' => $lines ?: ['N/A'],
        ];
    }

    /**
     * Return all configured IP addresses grouped by interface.
     * @return array
     */
    private function configuredIpAddresses()
    {
        $output = [];
        exec('ip -o addr show 2>/dev/null', $output, $exit_code);
        if ($exit_code !== 0 || empty($output)) {
            return ['N/A - unable to run ip addr'];
        }

        $addresses = [];
        foreach ($output as $line) {
            if (preg_match('/^\d+:\s+([^ ]+)\s+inet6?\s+([^ ]+)/', $line, $matches)) {
                $addresses[] = $matches[1] . ' ' . $matches[2];
            }
        }

        return $addresses ?: ['N/A'];
    }

    /**
     * Detect the public IP address using HalliNet's IP endpoint.
     * @return string
     */
    private function publicDetectedIpAddress()
    {
        $body = $this->httpGet('https://ip.hallinet.com');
        if ($body === null) {
            $body = $this->httpGet('http://ip.hallinet.com');
        }

        if ($body === null) {
            return 'N/A';
        }

        foreach (preg_split('/\s+/', trim($body)) as $token) {
            $token = trim($token, " \t\n\r\0\x0B,;[]()");
            if (filter_var($token, FILTER_VALIDATE_IP)) {
                return $token;
            }
        }

        return trim($body) ?: 'N/A';
    }

    /**
     * Make a small HTTP request with a short timeout.
     * @param string $url
     * @param bool $verify_ssl
     * @return string|null
     */
    private function httpGet($url, $verify_ssl = true)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Conductor/" . self::CONDUCTOR_VERSION . "\r\n",
                'ignore_errors' => true,
                'timeout' => 5,
            ],
            'ssl' => [
                'verify_peer' => $verify_ssl,
                'verify_peer_name' => $verify_ssl,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        return $body;
    }

    /**
     * Download a URL. Kept overridable for tests.
     * @param string $url
     * @return string|null
     */
    protected function downloadUrl($url)
    {
        return $this->httpGet($url);
    }

    /**
     * Print shell completion candidates for the external completion script.
     * @return void
     */
    public function complete()
    {
        $args = $this->rawArgs();
        $words = array_slice($args, 3);
        $current_index = isset($args[2]) ? (int) $args[2] : 0;
        $current = isset($words[$current_index]) ? $words[$current_index] : '';
        $previous = $current_index > 0 && isset($words[$current_index - 1]) ? $words[$current_index - 1] : '';
        $command = isset($words[1]) ? $words[1] : '';

        foreach ($this->completionCandidates($words, $current_index, $current, $previous, $command) as $candidate) {
            $this->writeln($candidate);
        }
    }

    /**
     * Build completion candidates for commands, options and command arguments.
     * @param array $words
     * @param int $current_index
     * @param string $current
     * @param string $previous
     * @param string $command
     * @return array
     */
    private function completionCandidates($words, $current_index, $current, $previous, $command)
    {
        if ($current_index <= 1) {
            return $this->filterCompletionCandidates($this->completionCommands(), $current);
        }

        if (substr($current, 0, 2) == '--') {
            return $this->filterCompletionCandidates($this->completionOptions($command), $current);
        }

        if (substr($previous, 0, 2) == '--') {
            return [];
        }

        switch ($command) {
            case 'services':
                return $this->filterCompletionCandidates(['start', 'stop', 'status', 'restart', 'reload'], $current);
            case 'ban':
                return $this->filterCompletionCandidates(['list', 'purge'], $current);
            case 'waf':
                if ($current_index == 2) {
                    return $this->filterCompletionCandidates(array_merge(['rulesets', 'webhook'], $this->completionApplicationNames()), $current);
                }

                return [];
            case 'letsencrypt':
                if ($current_index == 2) {
                    return $this->filterCompletionCandidates(array_merge(['webhook'], $this->completionApplicationNames()), $current);
                }

                return [];
            case 'auth':
                if ($current_index == 3) {
                    return $this->filterCompletionCandidates(['set', 'delete'], $current);
                }

                return $this->filterCompletionCandidates($this->completionApplicationNames(), $current);
            case 'new':
                return [];
        }

        if (in_array($command, $this->completionApplicationCommands())) {
            return $this->filterCompletionCandidates($this->completionApplicationNames(), $current);
        }

        return [];
    }

    /**
     * Top-level commands available to the conductor CLI.
     * @return array
     */
    private function completionCommands()
    {
        return [
            'list',
            'versions',
            'stats',
            'metrics',
            'test',
            'geoipdb',
            'auth',
            'waf',
            'new',
            'edit',
            'dump',
            'load',
            'enable',
            'disable',
            'cron',
            'destroy',
            'update',
            'rollback',
            'envars',
            'backup',
            'restore',
            'letsencrypt',
            'genkey',
            'showkey',
            'delkey',
            'start',
            'stop',
            'services',
            'ban',
            'unban',
        ];
    }

    /**
     * Commands that accept an application name argument.
     * @return array
     */
    private function completionApplicationCommands()
    {
        return [
            'edit',
            'dump',
            'load',
            'enable',
            'disable',
            'cron',
            'destroy',
            'update',
            'rollback',
            'envars',
            'backup',
            'restore',
            'letsencrypt',
            'waf',
            'genkey',
            'showkey',
            'delkey',
            'start',
            'stop',
        ];
    }

    /**
     * Options available for a command.
     * @param string $command
     * @return array
     */
    private function completionOptions($command)
    {
        $global = ['--help', '--version'];
        $options = [
            'new' => [
                '--fqdn=',
                '--environment=',
                '--mysql-pass=',
                '--git-uri=',
                '--git-branch=',
                '--path=',
                '--template=',
                '--target=',
                '--auto-reload',
            ],
            'enable' => ['--auto-reload'],
            'disable' => ['--auto-reload'],
            'stats' => ['--format='],
            'test' => ['--auto-reload'],
            'letsencrypt' => ['--enable', '--disable', '--delete', '--force-renew', '--auto-reload', '--configure='],
            'update' => ['--down='],
            'geoipdb' => ['--update', '--url='],
            'auth' => ['--enable', '--disable', '--auto-reload'],
            'waf' => ['--enable', '--disable', '--auto-reload', '--update-community', '--configure='],
            'ban' => ['--debug'],
            'dump' => ['--waf'],
            'load' => ['--waf'],
        ];

        if (!isset($options[$command])) {
            return $global;
        }

        return array_values(array_unique(array_merge($global, $options[$command])));
    }

    /**
     * Find application names from configured application and vhost paths.
     * @return array
     */
    private function completionApplicationNames()
    {
        $names = [];
        $paths = [];

        if (isset($this->conf->paths->apps)) {
            $paths[] = ['type' => 'directory', 'path' => $this->conf->paths->apps];
        }

        if (isset($this->conf->paths->appconfs)) {
            $paths[] = ['type' => 'config', 'path' => $this->conf->paths->appconfs];
        }

        foreach ($paths as $path) {
            if (!is_dir($path['path']) || !is_readable($path['path'])) {
                continue;
            }

            foreach (scandir($path['path']) as $entry) {
                if ($entry == '.' || $entry == '..') {
                    continue;
                }

                if ($path['type'] == 'directory' && is_dir($path['path'] . '/' . $entry)) {
                    $names[] = $entry;
                }

                if ($path['type'] == 'config' && preg_match('/^(.+)\.(?:conf|disabled)$/', $entry, $matches)) {
                    $names[] = $matches[1];
                }
            }
        }

        sort($names);
        return array_values(array_unique($names));
    }

    /**
     * Filter completion candidates by the current shell word.
     * @param array $candidates
     * @param string $current
     * @return array
     */
    private function filterCompletionCandidates($candidates, $current)
    {
        return array_values(array_filter($candidates, function ($candidate) use ($current) {
            return $current === '' || strpos($candidate, $current) === 0;
        }));
    }

    /**
     * Validate and normalize a proxy upstream target.
     * @param string $target
     * @return string
     */
    private function validateProxyTarget($target)
    {
        $target = rtrim(trim($target), '/');
        $parts = parse_url($target);

        if ($parts === false
            || !isset($parts['scheme'], $parts['host'], $parts['port'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'])
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
        ) {
            $this->writeln('Proxy target must be an HTTP(S) URL with a host and port, eg. http://127.24.54.54:8000');
            $this->endWithError();
        }

        if (!$this->validProxyTargetHost($parts['host'])) {
            $this->writeln('Proxy target host must be a valid FQDN, localhost, IPv4 address, or IPv6 address.');
            $this->endWithError();
        }

        if ($parts['port'] < 1 || $parts['port'] > 65535) {
            $this->writeln('Proxy target port must be between 1 and 65535.');
            $this->endWithError();
        }

        return strtolower($parts['scheme']) . '://' . $parts['host'] . ':' . $parts['port'];
    }

    /**
     * Build placeholder values for proxy upstream configuration.
     * @param string $target
     * @return array
     */
    private function proxyTargetPlaceholders($target)
    {
        $parts = parse_url($target);

        return [
            '@@TARGET_SCHEME@@' => strtolower($parts['scheme']),
            '@@TARGET_HOST@@' => $parts['host'] . ':' . $parts['port'],
            '@@UPSTREAM@@' => $this->nginxUpstreamName($this->appname),
        ];
    }

    /**
     * Convert an application name into an Nginx upstream-safe identifier.
     * @param string $name
     * @return string
     */
    private function nginxUpstreamName($name)
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        $name = trim($name, '_');

        return $name !== '' ? $name : 'app';
    }

    /**
     * Validate a proxy target host.
     * @param string $host
     * @return bool
     */
    private function validProxyTargetHost($host)
    {
        $host = trim($host, '[]');

        if ($host == 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && strpos($host, '.') !== false;
    }

    /**
     * Manage HTTP Basic authentication for an application.
     * @return void
     */
    public function authControl()
    {
        $this->appNameRequired();

        if ($this->isFlagSet('enable')) {
            $this->updateApplicationAuthConfig(true);
            return;
        }

        if ($this->isFlagSet('disable')) {
            $this->updateApplicationAuthConfig(false);
            return;
        }

        $action = $this->getCommand(3);
        if ($action == 'set') {
            $username = $this->getCommand(4);
            $password = $this->getCommand(5);
            if (!$username || !$password) {
                $this->writeln('Usage: conductor auth {name} set {username} {password}');
                $this->endWithError();
            }

            $this->setAuthUser($username, $password);
            return;
        }

        if ($action == 'delete') {
            $username = $this->getCommand(4);
            if (!$username) {
                $this->writeln('Usage: conductor auth {name} delete {username}');
                $this->endWithError();
            }

            $this->deleteAuthUser($username);
            return;
        }

        $this->writeln('Usage: conductor auth {name} --enable|--disable');
        $this->writeln('       conductor auth {name} set {username} {password}');
        $this->writeln('       conductor auth {name} delete {username}');
        $this->endWithError();
    }

    /**
     * Manage WAF configuration and shared rulesets.
     * @return void
     */
    public function wafControl()
    {
        if ($this->getCommand(2) == 'webhook') {
            if ($this->getOption('configure')) {
                $this->configureFail2BanWebhook($this->getOption('configure'));
                return;
            }

            $this->writeln('Usage: conductor waf webhook --configure={endpoint}');
            $this->endWithError();
        }

        if ($this->getCommand(2) == 'rulesets') {
            if ($this->isFlagSet('update-community')) {
                $this->updateXcalerCommunityRulesets();
                return;
            }

            $this->writeln('Usage: conductor waf rulesets --update-community');
            $this->endWithError();
        }

        $this->appNameRequired();

        if ($this->isFlagSet('enable') && $this->isFlagSet('disable')) {
            $this->writeln('Usage: conductor waf {name} --enable|--disable [--auto-reload]');
            $this->writeln('       conductor waf rulesets --update-community');
            $this->writeln('       conductor waf webhook --configure={endpoint}');
            $this->endWithError();
        }

        if ($this->isFlagSet('enable')) {
            $this->updateApplicationWafConfig(true);
            return;
        }

        if ($this->isFlagSet('disable')) {
            $this->updateApplicationWafConfig(false);
            return;
        }

        $this->editApplicationWafConfig();
    }

    /**
     * Update the Fail2Ban webhook endpoint used by Conductor's webhook action.
     * @param string $endpoint
     * @return void
     */
    private function configureFail2BanWebhook($endpoint)
    {
        $endpoint = $this->validateWebhookEndpoint($endpoint);
        $config_path = $this->fail2BanWebhookActionPath();

        if (!file_exists($config_path)) {
            $this->writeln('Fail2Ban webhook action file was not found: ' . $config_path);
            $this->writeln('Run /etc/conductor/utils/install_fail2ban_nftables.sh to install Fail2Ban support first.');
            $this->endWithError();
        }

        $config = file_get_contents($config_path);
        $updated_config = preg_replace('/^url\s*=.*$/m', 'url = ' . $endpoint, $config, 1, $replacements);
        if ($replacements !== 1) {
            $this->writeln('Could not find the webhook url setting in: ' . $config_path);
            $this->endWithError();
        }

        if (file_put_contents($config_path, $updated_config) === false) {
            $this->writeln('Unable to write Fail2Ban webhook action file: ' . $config_path);
            $this->endWithError();
        }

        $this->writeln('Updated Fail2Ban webhook endpoint: ' . $endpoint);
        $this->restartFail2BanForWafChange();
    }

    /**
     * Validate a Fail2Ban webhook endpoint.
     * @param string $endpoint
     * @return string
     */
    private function validateWebhookEndpoint($endpoint)
    {
        $endpoint = trim((string) $endpoint);
        $parts = parse_url($endpoint);

        if ($endpoint === ''
            || preg_match('/\s|[\x00-\x1F\x7F]/', $endpoint)
            || $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'])
        ) {
            $this->writeln('Webhook endpoint must be an HTTP(S) URL, eg. https://n8n.yourdomain.com/webhook/8b4e7040-3746-4120-b317-50110f074a53');
            $this->endWithError();
        }

        return $endpoint;
    }

    /**
     * Return the installed Fail2Ban webhook action path.
     * @return string
     */
    private function fail2BanWebhookActionPath()
    {
        if (isset($this->conf->paths->fail2ban_actions)) {
            return rtrim($this->conf->paths->fail2ban_actions, '/') . '/' . self::FAIL2BAN_WEBHOOK_ACTION_FILENAME;
        }

        return '/etc/fail2ban/action.d/' . self::FAIL2BAN_WEBHOOK_ACTION_FILENAME;
    }

    /**
     * Download and replace Xcaler community rulesets.
     * @return void
     */
    private function updateXcalerCommunityRulesets()
    {
        $directory = $this->commonConfigurationDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            $this->writeln('Unable to create common configuration directory: ' . $directory);
            $this->endWithError();
        }

        $backups = [];
        $updated_any = false;

        foreach ($this->xcalerCommunityRulesetTypes() as $type) {
            $filename = 'xcaler_community_' . $type . '.conf';
            $url = 'https://lists.xcaler.com/xcaler_' . $type . '.list';
            $target = $directory . '/' . $filename;
            $backups[$target] = [
                'exists' => file_exists($target),
                'content' => file_exists($target) ? file_get_contents($target) : null,
            ];
            $body = $this->downloadUrl($url);
            $updated = false;

            if ($body !== null && trim($body) !== '') {
                $body = $this->stampXcalerCommunityRuleset($body);
                $updated = file_put_contents($target, $body) !== false;
            }

            if ($updated) {
                $updated_any = true;
            }

            $this->writeln($filename . ': ' . ($updated ? 'updated!' : 'failed!'));
        }

        if (!$updated_any) {
            return;
        }

        if (!$this->runNginxConfigurationTest()) {
            $this->restoreFilesFromBackups($backups);
            $this->writeln('Tests failed, reverting rulesets back to previous ruleset configuration!');
            return;
        }

        $this->restartFail2BanForWafChange();
        $this->reloadNginxGracefully();
    }

    /**
     * Replace the Xcaler community timestamp placeholder with the current time.
     * @param string $body
     * @return string
     */
    private function stampXcalerCommunityRuleset($body)
    {
        return str_replace('{DATETIME}', date('Y-m-d H:i:s T'), $body);
    }

    /**
     * Community ruleset type slugs.
     * @return array
     */
    private function xcalerCommunityRulesetTypes()
    {
        return [
            'search_engines',
            'ai_bots',
            'sql_injection',
            'path_traversal',
            'common_paths',
        ];
    }

    /**
     * Return the common configuration directory.
     * @return string
     */
    private function commonConfigurationDirectory()
    {
        if (isset($this->conf->paths->templates)) {
            return rtrim($this->conf->paths->templates, '/');
        }

        return '/etc/conductor/configs/common';
    }

    /**
     * Restore files captured before a batch update.
     * @param array $backups
     * @return void
     */
    private function restoreFilesFromBackups($backups)
    {
        foreach ($backups as $path => $backup) {
            if ($backup['exists']) {
                file_put_contents($path, $backup['content']);
                continue;
            }

            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Enable or disable the HTTP Basic auth block in an application vhost.
     * @param bool $enable
     * @return void
     */
    private function updateApplicationAuthConfig($enable)
    {
        $config_path = $this->applicationConfigPath();
        $config = file_get_contents($config_path);

        if ($enable && !file_exists($this->authFilePath())) {
            $this->writeAuthUsers([]);
        }

        foreach ([self::AUTH_START_MARKER, self::AUTH_END_MARKER] as $marker) {
            if (strpos($config, $marker) === false) {
                $this->writeln('Could not find required Conductor marker in virtualhost configuration: ' . $marker);
                $this->endWithError();
            }
        }

        $original_config = $config;
        $config = $this->replaceLinesBetweenMarkers(
            $config,
            self::AUTH_START_MARKER,
            self::AUTH_END_MARKER,
            $enable ? [$this, 'uncommentNginxConfigLine'] : [$this, 'commentNginxConfigLine']
        );
        file_put_contents($config_path, $config);

        $this->writeln('HTTP Basic authentication has been ' . ($enable ? 'enabled.' : 'disabled.'));
        if (!$this->runNginxConfigurationTest()) {
            file_put_contents($config_path, $original_config);
            $this->writeln('Nginx configuration test failed. The auth configuration has been returned to its previous state.');
            $this->endWithNginxConfigError();
        }

        $this->promptGracefulNginxReload('auth configuration change', $this->isFlagSet('auto-reload'));
    }

    /**
     * Enable or disable the optional conductor_security access log line.
     * @param bool $enable
     * @param bool $reload
     * @return void
     */
    private function updateApplicationProtectionConfig($enable, $reload = true)
    {
        $config_path = $this->applicationConfigPath();
        $config = file_get_contents($config_path);

        foreach ([self::PROTECTION_START_MARKER, self::PROTECTION_END_MARKER] as $marker) {
            if (strpos($config, $marker) === false) {
                $this->writeln('Could not find required Conductor marker in virtualhost configuration: ' . $marker);
                $this->endWithError();
            }
        }

        $original_config = $config;
        $config = $this->replaceLinesBetweenMarkers(
            $config,
            self::PROTECTION_START_MARKER,
            self::PROTECTION_END_MARKER,
            $enable ? [$this, 'uncommentNginxConfigLine'] : [$this, 'commentNginxConfigLine']
        );
        file_put_contents($config_path, $config);

        $this->writeln('Application protection has been ' . ($enable ? 'enabled.' : 'disabled.'));
        if (!$this->runNginxConfigurationTest()) {
            file_put_contents($config_path, $original_config);
            $this->writeln('Nginx configuration test failed. The protection configuration has been returned to its previous state.');
            $this->endWithNginxConfigError();
        }

        if ($enable) {
            $this->ensureFail2BanRunningForProtection();
        }

        if ($reload) {
            $this->promptGracefulNginxReload('protection configuration change', $this->isFlagSet('auto-reload'));
        }
    }

    /**
     * Ensure Fail2Ban is running when an application security log is enabled.
     * @return void
     */
    private function ensureFail2BanRunningForProtection()
    {
        $output = [];
        if ($this->callWithOutput('command -v fail2ban-client 2>/dev/null', $output) !== 0) {
            $this->writeln('Fail2Ban is not installed; run /etc/conductor/utils/install_fail2ban_nftables.sh to enable automatic bans.');
            return;
        }

        $output = [];
        if ($this->callWithOutput('fail2ban-client ping 2>&1', $output) === 0) {
            return;
        }

        $this->writeln('Fail2Ban is not running; attempting to start it...');

        $start_output = [];
        if ($this->startFail2BanService($start_output) !== 0) {
            $this->writeln('Unable to start Fail2Ban: ' . trim(implode(' ', $start_output)));
            $this->writeln('Run /etc/conductor/utils/install_fail2ban_nftables.sh and check /var/log/fail2ban.log.');
            return;
        }

        $verify_output = [];
        if ($this->callWithOutput('fail2ban-client ping 2>&1', $verify_output) !== 0) {
            $this->writeln('Fail2Ban start command completed, but the daemon is not responding: ' . trim(implode(' ', $verify_output)));
            $this->writeln('Check /var/log/fail2ban.log for details.');
            return;
        }

        $this->writeln('Fail2Ban has been started.');
    }

    /**
     * Start the Fail2Ban service using the available service manager.
     * @param array $output
     * @return int
     */
    private function startFail2BanService(&$output)
    {
        $systemctl = [];
        if ($this->callWithOutput('command -v systemctl 2>/dev/null', $systemctl) === 0) {
            return $this->callWithOutput('systemctl start fail2ban 2>&1', $output);
        }

        return $this->callWithOutput('service fail2ban start 2>&1', $output);
    }

    /**
     * Enable or disable the WAF include line in an application vhost.
     * @param bool $enable
     * @return void
     */
    private function updateApplicationWafConfig($enable)
    {
        $config_path = $this->applicationConfigPath();
        $this->ensureApplicationWafConfig();
        $config = file_get_contents($config_path);

        foreach ([self::WAF_START_MARKER, self::WAF_END_MARKER] as $marker) {
            if (strpos($config, $marker) === false) {
                $this->writeln('Could not find required Conductor marker in virtualhost configuration: ' . $marker);
                $this->endWithError();
            }
        }

        $original_config = $config;
        $config = $this->replaceLinesBetweenMarkers(
            $config,
            self::WAF_START_MARKER,
            self::WAF_END_MARKER,
            $enable ? [$this, 'uncommentNginxConfigLine'] : [$this, 'commentNginxConfigLine']
        );
        file_put_contents($config_path, $config);

        $this->writeln('Application WAF has been ' . ($enable ? 'enabled.' : 'disabled.'));
        if (!$this->runNginxConfigurationTest()) {
            file_put_contents($config_path, $original_config);
            $this->writeln('Nginx configuration test failed. The WAF configuration has been returned to its previous state.');
            $this->endWithNginxConfigError();
        }

        $this->updateApplicationProtectionConfig($enable, false);

        $this->restartFail2BanForWafChange();
        $this->promptGracefulNginxReload('WAF configuration change', $this->isFlagSet('auto-reload'));
    }

    /**
     * Open the application WAF config in the configured editor.
     * @return void
     */
    private function editApplicationWafConfig()
    {
        $this->applicationConfigPath();
        $config_path = $this->ensureApplicationWafConfig();

        system($this->conf->binaries->editor . ' ' . $config_path . ' > `tty`');
        $this->writeln('Checking WAF updates for Nginx configuration issues...');
        $this->writeln();
        if (!$this->runNginxConfigurationTest()) {
            $this->endWithNginxConfigError();
        }

        $this->restartFail2BanForWafChange();
        $this->promptGracefulNginxReload('WAF configuration change');
        $this->writeln();
    }

    /**
     * Write an application virtualhost or WAF config to STDOUT.
     * @return void
     */
    public function dumpApplicationConfig()
    {
        $this->appNameRequired();
        $config_path = $this->applicationCliConfigPath($this->isFlagSet('waf'));

        echo file_get_contents($config_path);
    }

    /**
     * Read an application virtualhost or WAF config from STDIN, validate Nginx, and reload on success.
     * @return void
     */
    public function loadApplicationConfig()
    {
        $this->appNameRequired();
        $is_waf = $this->isFlagSet('waf');
        $config_path = $this->applicationCliConfigPath($is_waf);
        $replacement_config = $this->readStdin();

        if ($replacement_config === '') {
            $this->writeln('No configuration content was provided on STDIN.');
            $this->endWithError();
        }

        $original_config = file_get_contents($config_path);
        file_put_contents($config_path, $replacement_config);

        if (!$this->runNginxConfigurationTest()) {
            file_put_contents($config_path, $original_config);
            $this->writeln();
            $this->writeln('Nginx configuration test failed; restored the previous ' . ($is_waf ? 'WAF' : 'virtualhost') . ' configuration.');
            $this->writeln();
            $this->endWithNginxConfigError();
        }

        $this->writeln('Updated ' . ($is_waf ? 'WAF' : 'virtualhost') . ' configuration: ' . $config_path);
        if ($is_waf) {
            $this->restartFail2BanForWafChange();
        }
        $this->promptGracefulNginxReload(($is_waf ? 'WAF' : 'virtualhost') . ' configuration change', true, false);
    }

    /**
     * Restart Fail2Ban after WAF or shared WAF ruleset changes.
     * @return void
     */
    private function restartFail2BanForWafChange()
    {
        $output = [];
        if ($this->callWithOutput('systemctl restart fail2ban 2>&1', $output) === 0) {
            $this->writeln('Restarted Fail2Ban to apply WAF-related filter changes.');
            return;
        }

        $this->writeln('Could not restart Fail2Ban automatically: ' . implode(' ', $output));
    }

    /**
     * Return the config path used by dump/load commands.
     * @param bool $waf
     * @return string
     */
    private function applicationCliConfigPath($waf)
    {
        if ($waf) {
            $this->applicationConfigPath();
            return $this->ensureApplicationWafConfig();
        }

        return $this->applicationConfigPath();
    }

    /**
     * Read all piped content from STDIN.
     * @return string
     */
    protected function readStdin()
    {
        return stream_get_contents(STDIN);
    }

    /**
     * Ensure the application WAF file exists and return its path.
     * @return string
     */
    private function ensureApplicationWafConfig()
    {
        $this->ensureSharedWafErrorPage();

        $directory = $this->wafDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            $this->writeln('Unable to create WAF configuration directory: ' . $directory);
            $this->endWithError();
        }

        $config_path = $this->applicationWafConfigPath();
        if (!file_exists($config_path)) {
            file_put_contents($config_path, $this->defaultWafConfigContent());
        }

        return $config_path;
    }

    /**
     * Returns the WAF configuration path for the selected application.
     * @return string
     */
    private function applicationWafConfigPath()
    {
        return $this->wafDirectory() . '/' . $this->appname . '.conf';
    }

    /**
     * Returns the configured WAF directory, with a fallback for older configs.
     * @return string
     */
    private function wafDirectory()
    {
        if (isset($this->conf->paths->wafs)) {
            return rtrim($this->conf->paths->wafs, '/');
        }

        return '/etc/conductor/wafs';
    }

    /**
     * Basic WAF file content used when an older app does not have one yet.
     * @return string
     */
    private function defaultWafConfigContent()
    {
        return implode(PHP_EOL, [
            '# Conductor managed (Xcaler) WAF configured for ' . $this->appname,
            '#',
            '# This file is included inside the application Nginx server{} block.',
            '# Add per-application WAF, access-control, and file-protection rules here.',
            '',
            'include /etc/conductor/configs/common/conductor_waf_error_pages.conf;',
            '',
        ]);
    }

    /**
     * Create the application WAF config from the matching template.
     * @param string $template
     * @param array $placeholders
     * @return void
     */
    private function createApplicationWafConfig($template, $placeholders)
    {
        $this->ensureSharedWafErrorPage();

        $directory = $this->wafDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            $this->writeln('Unable to create WAF configuration directory: ' . $directory);
            $this->endWithError();
        }

        $template_path = $this->conf->paths->templates . '/templates/waf_' . $template . '.tpl';
        $config_path = $this->applicationWafConfigPath();
        if (!file_exists($template_path)) {
            file_put_contents($config_path, $this->defaultWafConfigContent());
            return;
        }

        $config = file_get_contents($template_path);
        foreach ($placeholders as $placeholder => $value) {
            $config = str_replace($placeholder, $value, $config);
        }

        file_put_contents($config_path, $config);
    }

    /**
     * Create or reset an HTTP Basic auth user password.
     * @param string $username
     * @param string $password
     * @return void
     */
    private function setAuthUser($username, $password)
    {
        $this->validateAuthUsername($username);
        $users = $this->authUsers();
        $users[$username] = password_hash($password, PASSWORD_BCRYPT);
        $this->writeAuthUsers($users);
        $this->writeln('HTTP Basic auth password has been set for user: ' . $username);
    }

    /**
     * Delete an HTTP Basic auth user.
     * @param string $username
     * @return void
     */
    private function deleteAuthUser($username)
    {
        $this->validateAuthUsername($username);
        $users = $this->authUsers();

        if (!isset($users[$username])) {
            $this->writeln('HTTP Basic auth user was not found: ' . $username);
            return;
        }

        unset($users[$username]);
        $this->writeAuthUsers($users);
        $this->writeln('HTTP Basic auth user has been deleted: ' . $username);
    }

    /**
     * Read HTTP Basic auth users for the selected application.
     * @return array
     */
    private function authUsers()
    {
        $users = [];
        $path = $this->authFilePath();
        if (!file_exists($path)) {
            return $users;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) == 2) {
                $users[$parts[0]] = $parts[1];
            }
        }

        return $users;
    }

    /**
     * Write HTTP Basic auth users for the selected application.
     * @param array $users
     * @return void
     */
    private function writeAuthUsers($users)
    {
        $directory = $this->authDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            $this->writeln('Unable to create auth directory: ' . $directory);
            $this->endWithError();
        }

        $lines = [];
        foreach ($users as $username => $hash) {
            $lines[] = $username . ':' . $hash;
        }

        $path = $this->authFilePath();
        file_put_contents($path, implode(PHP_EOL, $lines) . (count($lines) > 0 ? PHP_EOL : ''));
        chmod($path, 0644);
    }

    /**
     * Validate an HTTP Basic auth username.
     * @param string $username
     * @return void
     */
    private function validateAuthUsername($username)
    {
        if ($username === '' || preg_match('/[:\r\n]/', $username)) {
            $this->writeln('Auth username cannot be empty or contain colons/newlines.');
            $this->endWithError();
        }
    }

    /**
     * Return the auth directory.
     * @return string
     */
    private function authDirectory()
    {
        if (isset($this->conf->paths->pwdbs)) {
            return rtrim($this->conf->paths->pwdbs, '/');
        }

        return '/etc/conductor/pwdbs';
    }

    /**
     * Return the auth file path for the selected application.
     * @return string
     */
    private function authFilePath()
    {
        return $this->authDirectory() . '/.htpasswd_' . $this->appname;
    }

    /**
     * Action GeoIP database management commands.
     */
    public function geoIpDbControl()
    {
        if ($this->isFlagSet('update')) {
            $this->updateGeoIpDatabase();
            return;
        }

        $this->writeln('No GeoIP database action was specified. Use --update to download/update the GeoIP country database.');
        $this->endWithError();
    }

    /**
     * Download/update the GeoIP country database used by Nginx GeoIP2 examples.
     * @return void
     */
    private function updateGeoIpDatabase()
    {
        if (!function_exists('gzdecode')) {
            $this->writeln('The PHP zlib extension is required to unpack the GeoIP database.');
            $this->endWithError();
        }

        $directory = $this->geoIpDatabaseDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            $this->writeln('Unable to create GeoIP database directory: ' . $directory);
            $this->endWithError();
        }

        $urls = $this->geoIpDatabaseDownloadUrls();
        foreach ($urls as $url) {
            $this->writeln('Downloading GeoIP database: ' . $url);
            $archive = $this->downloadGeoIpDatabaseArchive($url);
            if ($archive === false) {
                continue;
            }

            $database = gzdecode($archive);
            if ($database === false || strlen($database) < 1024) {
                continue;
            }

            $target = $directory . '/' . self::GEOIP_DATABASE_FILENAME;
            $temporary_target = tempnam($directory, 'geoip-');
            if ($temporary_target === false || file_put_contents($temporary_target, $database) === false) {
                $this->writeln('Unable to write GeoIP database to: ' . $directory);
                $this->endWithError();
            }

            if (!rename($temporary_target, $target)) {
                @unlink($temporary_target);
                $this->writeln('Unable to move GeoIP database into place: ' . $target);
                $this->endWithError();
            }

            file_put_contents($directory . '/source.txt', $url . PHP_EOL);
            $this->writeln('GeoIP database updated: ' . $target);
            return;
        }

        $this->writeln('Unable to download a GeoIP database from the configured source(s).');
        $this->endWithError();
    }

    /**
     * Return the configured GeoIP database directory.
     * @return string
     */
    private function geoIpDatabaseDirectory()
    {
        if (isset($this->conf->paths->geoip)) {
            return rtrim($this->conf->paths->geoip, '/');
        }

        return '/var/conductor/geoip';
    }

    /**
     * Build the ordered GeoIP download URL list.
     * @return array
     */
    private function geoIpDatabaseDownloadUrls()
    {
        $configured_url = $this->getOption('url');
        if ($configured_url) {
            return [$configured_url];
        }

        $urls = [];
        for ($months_ago = 0; $months_ago <= 1; $months_ago++) {
            $date = new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));
            if ($months_ago > 0) {
                $date = $date->modify('-' . $months_ago . ' month');
            }

            $urls[] = 'https://download.db-ip.com/free/dbip-country-lite-' . $date->format('Y-m') . '.mmdb.gz';
        }

        return $urls;
    }

    /**
     * Download a GeoIP database archive.
     * @param string $url
     * @return string|false
     */
    private function downloadGeoIpDatabaseArchive($url)
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_USERAGENT => 'Conductor/' . self::CONDUCTOR_VERSION,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/gzip, application/octet-stream, */*',
                ],
            ]);

            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($body !== false && $status >= 200 && $status < 300) {
                return $body;
            }

            $this->writeln('GeoIP download failed with HTTP status ' . ($status ?: 'unknown') . ($error ? ': ' . $error : ''));
            return false;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'user_agent' => 'Conductor/' . self::CONDUCTOR_VERSION,
                'header' => "Accept: application/gzip, application/octet-stream, */*\r\n",
            ],
        ]);

        return @file_get_contents($url, false, $context);
    }

    /**
     * Requests that the request can only be executed if the user has specified the application name.
     * @return void
     */
    private function appNameRequired()
    {
        $this->setAppName();
    }

    /**
     * Sets the application name to the second param
     * @return void
     */
    private function setAppName()
    {
        if (!$this->getCommand(2)) {
            $this->writeln('No application name was specified!');
            $this->endWithError();
        }
        $this->appname = $this->getCommand(2);
        $this->appdir = $this->conf->paths->apps . '/' . $this->appname;
    }

    /**
     * Action a Conductor service action.
     * @param string $action
     */
    public function serviceControl($action)
    {
        if (in_array($action, ['start', 'stop', 'status', 'restart', 'reload'])) {
            $this->call($this->conf->services->nginx->$action);
            $this->call($this->conf->services->php_fpm->$action);
        } else {
            $this->writeln('The requested action could be found!');
            $this->endWithError();
        }
    }

    /**
     * Test the active Nginx configuration and optionally reload on success.
     */
    public function testNginxConfiguration()
    {
        if (!$this->runNginxConfigurationTest()) {
            exit(1);
        }

        if ($this->shouldAutoReloadNginx($this->isFlagSet('auto-reload'))) {
            $this->reloadNginxGracefully();
        }
    }

    /**
     * Action Fail2Ban IP ban management commands.
     * @param string $actionOrIp
     */
    public function banControl($actionOrIp)
    {
        $this->ensureFail2BanClient();

        if ($actionOrIp == 'list') {
            $this->listBannedIps($this->isFlagSet('debug'));
            return;
        }

        if ($actionOrIp == 'purge') {
            $this->purgeBannedIps();
            return;
        }

        $this->banIpAddress($actionOrIp);
    }

    /**
     * Unban an IP address from every active Fail2Ban jail.
     * @param string $ip_address
     */
    public function unbanIpAddress($ip_address)
    {
        $this->ensureFail2BanClient();
        $this->validateIpAddress($ip_address);

        $removed = 0;
        foreach ($this->fail2BanJails() as $jail) {
            $output = [];
            $this->runFail2BanClient(['get', $jail, 'banip'], $output);
            if (!in_array($ip_address, $this->extractIpAddresses(implode(' ', $output)))) {
                continue;
            }

            $unban_output = [];
            if ($this->runFail2BanClient(['set', $jail, 'unbanip', $ip_address], $unban_output) === 0) {
                $removed++;
            }
        }

        if ($removed === 0) {
            $crowdsec_removed = $this->unbanLocalCrowdSecIpAddress($ip_address);
            if ($crowdsec_removed === null) {
                $this->writeln('The IP address was not banned in any active Fail2Ban jail.');
                return;
            }

            if ($crowdsec_removed === 0) {
                $this->writeln('The IP address was not banned in any active Fail2Ban jail or local CrowdSec decision.');
                return;
            }

            $this->writeln('Unbanned ' . $ip_address . ' from ' . $crowdsec_removed . ' local CrowdSec decision(s).');
            return;
        }

        $this->writeln('Unbanned ' . $ip_address . ' from ' . $removed . ' Fail2Ban jail(s).');
    }

    /**
     * Validate that Fail2Ban is available.
     */
    private function ensureFail2BanClient()
    {
        $output = [];
        exec('command -v fail2ban-client 2>/dev/null', $output, $exit_code);
        if ($exit_code !== 0) {
            $this->writeln('Fail2Ban is not installed, run: /etc/conductor/utils/install_fail2ban_nftables.sh to enable these features!');
            $this->endWithError();
        }
    }

    /**
     * Ban an IP address manually until it is explicitly unbanned or purged.
     * @param string $ip_address
     */
    private function banIpAddress($ip_address)
    {
        $this->validateIpAddress($ip_address);

        $jail = 'conductor-manual';
        if (!in_array($jail, $this->fail2BanJails())) {
            $this->writeln('The conductor-manual Fail2Ban jail is not active. Re-run /etc/conductor/utils/install_fail2ban_nftables.sh and restart Fail2Ban.');
            $this->endWithError();
        }

        $output = [];
        if ($this->runFail2BanClient(['set', $jail, 'banip', $ip_address], $output) !== 0) {
            $this->writeln('Unable to ban ' . $ip_address . ': ' . implode(' ', $output));
            $this->endWithError();
        }

        $this->writeln('Banned ' . $ip_address . ' in the ' . $jail . ' jail.');
    }

    /**
     * Show all IP addresses currently banned by Fail2Ban.
     */
    private function listBannedIps($debug = false)
    {
        $rows = [];
        $crowdsec_count = $this->localCrowdSecBanCount();

        foreach ($this->fail2BanJails() as $jail) {
            $output = [];
            if ($this->runFail2BanClient(['get', $jail, 'banip', '--with-time'], $output) !== 0) {
                $output = [];
                $this->runFail2BanClient(['get', $jail, 'banip'], $output);
            }

            if ($debug) {
                $this->writeln('Raw Fail2Ban banip output for ' . $jail . ':');
                if (empty($output)) {
                    $this->writeln('  (empty)');
                } else {
                    foreach ($output as $line) {
                        $this->writeln('  ' . $line);
                    }
                }
                $this->writeln();
            }

            $ips = $this->extractIpAddresses(implode(' ', $output));
            if (empty($ips)) {
                continue;
            }

            $ban_time = $this->fail2BanJailBanTime($jail);
            foreach ($ips as $ip_address) {
                $rows[] = [$ip_address, $jail, $ban_time];
            }
        }

        if (empty($rows)) {
            $this->writeln('No IP addresses are currently banned by Fail2Ban.');
        } else {
            $this->writeln(str_pad('IP address', 40) . str_pad('Jail', 30) . 'Ban time');
            $this->writeln(str_repeat('-', 80));
            foreach ($rows as $row) {
                $this->writeln(str_pad($row[0], 40) . str_pad($row[1], 30) . $row[2]);
            }
        }

        if ($crowdsec_count !== null) {
            $this->writeln('');
            $this->writeln('+' . $crowdsec_count . ' local CrowdSec IP ban' . ($crowdsec_count === 1 ? '' : 's') . ' enforced.');
            $this->writeln('CrowdSec global/community decisions are not shown here.');
        }
    }

    /**
     * Clear every IP address currently banned by Fail2Ban.
     */
    private function purgeBannedIps()
    {
        $removed = 0;
        foreach ($this->fail2BanJails() as $jail) {
            $output = [];
            $this->runFail2BanClient(['get', $jail, 'banip'], $output);
            foreach ($this->extractIpAddresses(implode(' ', $output)) as $ip_address) {
                $unban_output = [];
                if ($this->runFail2BanClient(['set', $jail, 'unbanip', $ip_address], $unban_output) === 0) {
                    $removed++;
                }
            }
        }

        $this->writeln('Purged ' . $removed . ' banned IP address entr' . ($removed === 1 ? 'y.' : 'ies.'));
    }

    /**
     * Get all active Fail2Ban jails.
     * @return array
     */
    private function fail2BanJails()
    {
        $output = [];
        if ($this->runFail2BanClient(['status'], $output) !== 0) {
            $this->writeln('Unable to read Fail2Ban status.');
            $this->endWithError();
        }

        if (!preg_match('/Jail list:\s*(.+)$/m', implode(PHP_EOL, $output), $matches)) {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $matches[1])));
    }

    /**
     * Get a human-readable configured ban time for a jail.
     * @param string $jail
     * @return string
     */
    private function fail2BanJailBanTime($jail)
    {
        $output = [];
        if ($this->runFail2BanClient(['get', $jail, 'bantime'], $output) !== 0 || empty($output)) {
            return 'unknown';
        }

        $seconds = (int) trim($output[0]);
        if ($seconds < 0) {
            return 'permanent';
        }

        if ($seconds >= 86400 && $seconds % 86400 === 0) {
            return ($seconds / 86400) . ' day(s)';
        }

        if ($seconds >= 3600 && $seconds % 3600 === 0) {
            return ($seconds / 3600) . ' hour(s)';
        }

        if ($seconds >= 60 && $seconds % 60 === 0) {
            return ($seconds / 60) . ' minute(s)';
        }

        return $seconds . ' second(s)';
    }

    /**
     * Execute fail2ban-client safely.
     * @param array $arguments
     * @param array $output
     * @return int
     */
    protected function runFail2BanClient($arguments, &$output)
    {
        $command = 'fail2ban-client';
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $output, $exit_code);
        return $exit_code;
    }

    /**
     * Count locally-generated CrowdSec IP bans without listing global/community decisions.
     * @return int|null
     */
    private function localCrowdSecBanCount()
    {
        $decisions = $this->crowdSecDecisions();
        if ($decisions === null) {
            return null;
        }

        $count = 0;
        foreach ($decisions as $decision) {
            if ($this->isLocalCrowdSecIpBan($decision, ['ip', 'range'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Remove local CrowdSec bans for an exact IP address.
     * @param string $ip_address
     * @return int|null
     */
    private function unbanLocalCrowdSecIpAddress($ip_address)
    {
        $decisions = $this->crowdSecDecisions();
        if ($decisions === null) {
            return null;
        }

        $removed = 0;
        foreach ($decisions as $decision) {
            if (!$this->isLocalCrowdSecIpBan($decision, ['ip'])) {
                continue;
            }

            $value = isset($decision->value) ? $decision->value : '';
            if ($value !== $ip_address) {
                continue;
            }

            $output = [];
            if (isset($decision->id) && $this->runCscli(['decisions', 'delete', '--id', $decision->id], $output) === 0) {
                $removed++;
                continue;
            }

            $output = [];
            if ($this->runCscli(['decisions', 'delete', '--ip', $ip_address], $output) === 0) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Return all CrowdSec decisions, or null if cscli is unavailable.
     * @return array|null
     */
    private function crowdSecDecisions()
    {
        if (!$this->crowdSecAvailable()) {
            return null;
        }

        $output = [];
        if ($this->runCscli(['decisions', 'list', '-o', 'json'], $output) !== 0 || empty($output)) {
            return null;
        }

        $decisions = json_decode(implode(PHP_EOL, $output));
        if (isset($decisions->decisions) && is_array($decisions->decisions)) {
            $decisions = $decisions->decisions;
        }

        return is_array($decisions) ? $decisions : null;
    }

    /**
     * Check whether CrowdSec and its CLI are available.
     * @return bool
     */
    private function crowdSecAvailable()
    {
        $output = [];
        exec('command -v crowdsec 2>/dev/null', $output, $exit_code);
        if ($exit_code !== 0) {
            return false;
        }

        $output = [];
        exec('command -v cscli 2>/dev/null', $output, $exit_code);
        return $exit_code === 0;
    }

    /**
     * Check whether a CrowdSec decision is a local IP/range ban.
     * @param object $decision
     * @param array $allowed_scopes
     * @return bool
     */
    private function isLocalCrowdSecIpBan($decision, $allowed_scopes)
    {
        $origin = isset($decision->origin) ? strtolower($decision->origin) : '';
        $scope = isset($decision->scope) ? strtolower($decision->scope) : '';
        $type = isset($decision->type) ? strtolower($decision->type) : '';

        if ($origin !== 'crowdsec') {
            return false;
        }

        if ($type !== '' && $type !== 'ban') {
            return false;
        }

        return in_array($scope, $allowed_scopes);
    }

    /**
     * Execute cscli safely.
     * @param array $arguments
     * @param array $output
     * @return int
     */
    private function runCscli($arguments, &$output)
    {
        $command = 'cscli';
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $output, $exit_code);
        return $exit_code;
    }

    /**
     * Extract valid IPv4 and IPv6 addresses from command output.
     * @param string $text
     * @return array
     */
    private function extractIpAddresses($text)
    {
        preg_match_all('/(?:\d{1,3}\.){3}\d{1,3}|(?:[a-f0-9]{0,4}:){2,}[a-f0-9]{0,4}/i', $text, $matches);

        $ips = [];
        foreach ($matches[0] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ips[] = $candidate;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Validate a CLI-provided IP address.
     * @param string $ip_address
     */
    private function validateIpAddress($ip_address)
    {
        if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
            $this->writeln('Invalid IP address: ' . $ip_address);
            $this->endWithError();
        }
    }

    /**
     * Reloadsof the Nginx configuration for environment variables to take affect.
     */
    public function reloadEnvVars()
    {
        $this->call($this->conf->services->nginx->reload);
    }

    /**
     * Exit with the code reserved for invalid Nginx configuration.
     */
    private function endWithNginxConfigError()
    {
        exit(self::NGINX_CONFIG_ERROR_EXIT_CODE);
    }

    /**
     * Run nginx -t while suppressing Nginx's noisy success output.
     * @param bool $show_success
     * @return bool
     */
    private function runNginxConfigurationTest($show_success = true)
    {
        $output = [];
        $exit_code = $this->callWithOutput($this->conf->binaries->nginx . ' -t 2>&1', $output);

        if ($exit_code !== 0) {
            foreach ($output as $line) {
                $this->writeln($line);
            }

            return false;
        }

        if ($show_success) {
            $this->writeln('Nginx configuration test successful!');
        }

        return true;
    }

    /**
     * Validate Nginx and optionally reload it gracefully.
     * @param string $change_description
     * @param bool $auto_reload
     * @param bool $test_configuration
     */
    private function promptGracefulNginxReload($change_description = 'change', $auto_reload = false, $test_configuration = true)
    {
        if ($test_configuration && !$this->runNginxConfigurationTest()) {
            $this->endWithNginxConfigError();
        }

        if ($this->shouldAutoReloadNginx($auto_reload)) {
            $this->reloadNginxGracefully();
            return;
        }

        $reload_nginx = $this->input('Gracefully restart (reload) Nginx now for the ' . $change_description . ' to take effect?',
            self::OPTION_YES, [self::OPTION_YES, self::OPTION_NO]);

        if (strtolower($reload_nginx) == self::OPTION_YES) {
            $this->reloadNginxGracefully();
        } else {
            $this->writeln('Remember to gracefully restart (reload) Nginx before the ' . $change_description . ' will take effect.');
        }
    }

    /**
     * Determine whether successful Nginx configuration tests should reload automatically.
     * @param bool $explicit_auto_reload
     * @return bool
     */
    private function shouldAutoReloadNginx($explicit_auto_reload = false)
    {
        return $explicit_auto_reload || $this->autoReloadNginxEnabled();
    }

    /**
     * Read the global Nginx auto-reload setting. Missing values default to enabled.
     * @return bool
     */
    private function autoReloadNginxEnabled()
    {
        if (!isset($this->conf) || !is_object($this->conf) || !property_exists($this->conf, 'auto-reload-nginx')) {
            return true;
        }

        $value = $this->conf->{'auto-reload-nginx'};
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Gracefully reload Nginx using the configured service command.
     * @return void
     */
    private function reloadNginxGracefully()
    {
        $this->writeln('Gracefully restarting (reloading) Nginx...');
        $this->call($this->conf->services->nginx->reload);
    }

    /**
     * Executes a backup the entire web application including it's database.
     * @param string $filename The filename to use when creating the backup.
     * @return void
     */
    private function backupApplication($filename)
    {
        $this->appNameRequired();
        $this->call('cp -R ' . $this->appdir . ' ' . $this->conf->paths->temp . '/' . $this->appname);
        $this->connectMySQL();
        if ($this->mysqlEnabled() && $this->mysql->query('SHOW DATABASES LIKE \'db_' . $this->appname . '\';')->fetchObject()) {
            $this->writeln('Detected a MySQL database, backing it up...');
            $this->call($this->conf->binaries->mysqldump . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' --no-create-db db_' . $this->appname . ' | ' . $this->conf->binaries->gzip . ' -c | cat > ' . $this->conf->paths->temp . '/' . $this->appname . '/appdb.sql.gz');
        }
        $crontab = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
        if (file_exists($crontab)) {
            $this->writeln('Backing up crontab file...');
            $this->call('cp ' . $crontab . ' ' . $this->conf->paths->temp . '/' . $this->appname . '/');
        }
        $appconf = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';
        if (file_exists($appconf)) {
            $this->writeln('Backing up Nginx virtualhost config...');
            $this->call('cp ' . $appconf . ' ' . $this->conf->paths->temp . '/' . $this->appname . '/');
        }
        $this->writeln('Compressing backup archive...');
        $this->call('tar -zcf ' . $this->conf->paths->temp . '/' . $filename . ' -C ' . $this->conf->paths->temp . '/' . $this->appname . '/ .');
        $this->writeln('Cleaning up...');
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/' . $this->appname);
        $this->call('mv ' . $this->conf->paths->temp . '/' . $filename . ' ' . $this->conf->paths->backups . '/' . $filename);
    }

    /**
     * Creates a new MySQL user and database.
     * @param string $db_pass The password of which to use for the user account.
     * @return void
     */
    private function createMySQL($db_pass)
    {
        $this->appNameRequired();
        if (!$this->mysqlEnabled()) {
            $this->writeln('MySQL management is disabled in /etc/conductor.conf; skipping database provisioning.');
            return;
        }
        $this->connectMySQL();

        // Creating the user and granting privileges in separate statements (rather than the legacy
        // combined `GRANT ... IDENTIFIED BY`) is required since MySQL 8.0 removed that syntax entirely;
        // this form still works fine on MariaDB too.
        $this->mysql->exec('CREATE DATABASE if NOT EXISTS `db_' . $this->appname . '`;');
        $this->mysql->exec('CREATE USER IF NOT EXISTS \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\' IDENTIFIED BY \'' . $db_pass . '\';');
        $this->mysql->exec('GRANT ALL ON `db_' . $this->appname . '`.* TO \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\';');
        $this->mysql->exec('FLUSH PRIVILEGES;');

        $this->writeln();
        $this->writeln('MySQL Database and User Details:');
        $this->writeln();
        $this->writeln(' DB Name:      db_' . $this->appname);
        $this->writeln(' DB Host:      ' . $this->conf->mysql->host);
        $this->writeln(' DB Username:  ' . $this->appname);
        $this->writeln(' DB Password:  ' . $db_pass);
        $this->writeln();

        // For convenience we'll add these DB params to the ENV vars with the benefit of using default Laravel ENV var names .
        $this->call('/usr/bin/conductor envars ' . $this->appname . ' --DB_HOST="' . $this->conf->mysql->host . '" --DB_DATABASE="db_' . $this->appname . '" --DB_USERNAME="' . $this->appname . '"  --DB_PASSWORD="' . $db_pass . '"');
    }

    /**
     * Write application metadata needed for future automated restores.
     * @param string $environment
     * @param string $root_path
     * @param string $fqdn
     * @param string $mysql_req
     * @param string|null $mysql_password
     * @return void
     */
    private function writeApplicationConductorConfig($environment, $root_path, $fqdn, $mysql_req, $mysql_password = null)
    {
        $conductor_directory = rtrim($this->appdir, '/') . '/.conductor';
        if (!is_dir($conductor_directory) && !mkdir($conductor_directory, 0755, true)) {
            $this->writeln('Unable to create Conductor application config directory: ' . $conductor_directory);
            $this->endWithError();
        }

        $env_vars = [];
        $env_conf = $this->conf->paths->appconfs . '/' . $this->appname . '_envars.json';
        if (file_exists($env_conf)) {
            $decoded_env = json_decode(file_get_contents($env_conf), true);
            if (is_array($decoded_env)) {
                $env_vars = $decoded_env;
            }
        }

        $has_mysql = strtolower($mysql_req) == self::OPTION_YES;
        $config = [
            'appname' => $this->appname,
            'environment_type' => $environment,
            'root_path' => $root_path,
            'mysql_db_name' => $has_mysql ? 'db_' . $this->appname : null,
            'mysql_db_user' => $has_mysql ? $this->appname : null,
            'mysql_db_pass' => $has_mysql ? $mysql_password : null,
            'mysql_db_host' => $has_mysql && isset($this->conf->mysql->host) ? $this->conf->mysql->host : null,
            'fqdn' => $fqdn,
            'env' => $env_vars,
        ];

        file_put_contents($conductor_directory . '/config.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * Destroys the database and user for the current application.
     * @return void
     */
    private function destroyMySQL()
    {
        $this->appNameRequired();
        if (!$this->mysqlEnabled()) {
            return;
        }
        $this->connectMySQL();

        if ($this->mysql->query('SHOW DATABASES LIKE \'db_' . $this->appname . '\';')->fetchObject()) {
            $this->writeln('Detected a Application MySQL user and database...');
            $this->mysql->exec('DROP DATABASE IF EXISTS `db_' . $this->appname . '`;');
            $this->mysql->exec('DROP USER \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\';');
            $this->mysql->exec('FLUSH PRIVILEGES;');
        }
    }

    /**
     * If detected as a Laravel application will attempt to migrate it based on it's framework version number.
     * @param string $environment The environment of which to execute the Laravel commands under.
     * @return void
     */
    private function migrateLaravel($environment = 'production')
    {
        if (!$this->isLaravelApplication()) {
            return;
        }

        if (!file_exists($this->appdir . '/.env')) {
            $this->writeln('No .env file found, skipping migrations...');
            return;
        }

        $this->call($this->conf->binaries->php . ' ' . escapeshellarg($this->appdir . '/artisan') . ' migrate --force --env=' . escapeshellarg($environment));
        $this->call($this->conf->binaries->php . ' ' . escapeshellarg($this->appdir . '/artisan') . ' cache:clear --env=' . escapeshellarg($environment));
        $this->call($this->composerForApplication('dump-autoload -o'));
    }

    /**
     * Pull the latest version of the application from Git and reset the local copy as appropriate.
     * @return void
     */
    private function gitPull()
    {
        $this->call($this->gitWithDeploymentKey($this->conf->binaries->git . ' fetch --all', $this->appdir));
        $this->call($this->gitWithDeploymentKey($this->conf->binaries->git . ' reset --hard @{u}', $this->appdir));
    }

    /**
     * Build a Git command that uses the application's deployment key as the configured web user.
     * @param string $git_command
     * @param string $working_directory
     * @return string
     */
    private function gitWithDeploymentKey($git_command, $working_directory)
    {
        $deploy_key_path = $this->conf->paths->deploykeys . '/' . $this->appname . '.deploykey';
        $web_user = isset($this->conf->permissions->webuser) ? $this->conf->permissions->webuser : 'www-data';
        $script = 'cd ' . escapeshellarg($working_directory) . '; ssh-add ' . escapeshellarg($deploy_key_path) . '; ' . $git_command;

        return 'sudo -u ' . escapeshellarg($web_user) . ' ssh-agent bash -c ' . escapeshellarg($script);
    }

    /**
     * Execute a command and stop if it fails.
     * @param string $command
     * @param string $error_message
     * @return void
     */
    private function callOrFail($command, $error_message)
    {
        if ($this->callWithExitCode($command) !== 0) {
            $this->writeln($error_message);
            $this->endWithError();
        }
    }

    /**
     * Build a command that runs as the configured web user.
     * @param string $command
     * @return string
     */
    private function asWebUser($command)
    {
        $web_user = isset($this->conf->permissions->webuser) ? $this->conf->permissions->webuser : 'www-data';

        return 'sudo -u ' . escapeshellarg($web_user) . ' ' . $command;
    }

    /**
     * Build a Composer command for the current application as the configured web user.
     * @param string $arguments
     * @return string
     */
    private function composerForApplication($arguments)
    {
        return $this->asWebUser($this->conf->binaries->composer . ' ' . $arguments . ' --working-dir=' . escapeshellarg($this->appdir));
    }

    /**
     * Check whether the current application appears to be Laravel-based.
     * @return bool
     */
    private function isLaravelApplication()
    {
        return file_exists($this->appdir . '/artisan');
    }

    /**
     * Print a consistent message when a Laravel-only operation is not applicable.
     * @return void
     */
    private function writeNotLaravelMessage()
    {
        $this->writeln('The application does not appear to be a Laravel-based application, skipping this operation!');
    }

    /**
     * Check whether a directory has no visible or hidden entries.
     * @param string $directory
     * @return bool
     */
    private function isDirectoryEmpty($directory)
    {
        foreach (new DirectoryIterator($directory) as $entry) {
            if (!$entry->isDot()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update the environmental configuration for the application.
     * @return void
     */
    public function updateEnvVars()
    {
        $this->appNameRequired();
        if (!file_exists($this->conf->paths->apps . '/' . $this->appname)) {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }
        $env_conf = $this->conf->paths->appconfs . '/' . $this->appname . '_envars.json';
        if (!file_exists($env_conf)) {
            file_put_contents($env_conf, json_encode(['APP_ENV' => 'production']));
            $this->reloadEnvVars();
        }

        $env_handler = new EnvHandler($env_conf);
        $env_handler->load();

        if ((strtolower($this->getCommand(1)) == 'envars') and (count($this->options()) > 0)) {
            if (!$this->isFlagSet('d')) {
                foreach ($this->options() as $key => $value) {
                    $env_handler->push($key, $value);
                }
            } else {
                foreach ($this->options() as $key => $value) {
                    $env_handler->remove($key);
                }
            }
            // Lets now write the changes to the file...
            $env_handler->save();

            // Apply them to the application configuration...
            $ammended_vhost_conf = $this->replaceBetweenSections('# START APPLICATION ENV VARIABLES',
                '# END APPLICATION ENV VARIABLES',
                file_get_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf'),
                $this->envConfigurationBlock($env_handler));
            file_put_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf', $ammended_vhost_conf);
            file_put_contents($this->conf->paths->apps . '/' . $this->appname . '/.env',
                $this->envFileLaravelConfiguration($env_handler));
            $this->reloadEnvVars();
        } else {
            $this->writeln();
            foreach ($env_handler->all() as $key => $value) {
                $this->writeln(sprintf(' %s=%s', $key, $value));
            }
            $this->writeln();
        }
    }

    /**
     * Format the environment variables for the virtual host configuration.
     * @param EnvHandler $envars The environmental variables.
     * @return string
     */
    private function envConfigurationBlock(EnvHandler $envars)
    {
        $block = PHP_EOL . "";
        if (count($envars->all()) > 0) {
            foreach ($envars->all() as $key => $value) {
                $block .= sprintf(str_repeat(' ', self::SPACES_ENV_INDENT) . "fastcgi_param    %s    %s;" . PHP_EOL,
                    $key, $value);
            }
        }
        return $block;
    }

    /**
     * Formats the environment variables for Laravel 5 based applications.
     * @param EnvHandler $envars The environmental variables.
     * @return string
     */
    private function envFileLaravelConfiguration(EnvHandler $envars)
    {
        $block = "";
        if (count($envars->all()) > 0) {
            foreach ($envars->all() as $key => $value) {
                $block .= $key . "=" . $value . PHP_EOL;
            }
        }
        return $block;
    }

    /**
     * Replaces the text/content between two points.
     * @param string $needle_start
     * @param string $needle_end
     * @param string $file
     * @param string $replacement
     * @return string
     */
    public function replaceBetweenSections($needle_start, $needle_end, $file, $replacement)
    {
        $pos = strpos($file, $needle_start);
        $start = $pos === false ? 0 : $pos + strlen($needle_start);
        $pos = strpos($file, $needle_end, $start);
        $end = $pos === false ? strlen($file) : $pos;
        return substr_replace($file, $replacement, $start, $end - $start);
    }

    /**
     * Replaces the complete lines between two marker lines, preserving the markers themselves.
     * @param string $content
     * @param string $start_marker
     * @param string $end_marker
     * @param callable $line_handler
     * @return string
     */
    private function replaceLinesBetweenMarkers($content, $start_marker, $end_marker, $line_handler)
    {
        $lines = preg_split('/(\r\n|\n|\r)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $in_section = false;

        for ($i = 0; $i < count($lines); $i += 2) {
            if (strpos($lines[$i], $start_marker) !== false) {
                $in_section = true;
                continue;
            }

            if (strpos($lines[$i], $end_marker) !== false) {
                $in_section = false;
                continue;
            }

            if ($in_section) {
                $lines[$i] = call_user_func($line_handler, $lines[$i]);
            }
        }

        return implode('', $lines);
    }

    /**
     * Comments a Nginx configuration line unless it is already commented or blank.
     * @param string $line
     * @return string
     */
    private function commentNginxConfigLine($line)
    {
        if (trim($line) === '' || strpos($line, '# -- C:') !== false) {
            return $line;
        }

        if (preg_match('/^(\s*)#\s*(\S.*)$/', $line, $matches)) {
            return $matches[1] . '#' . $matches[2];
        }

        return preg_replace('/^(\s*)/', '$1#', $line, 1);
    }

    /**
     * Uncomments a Nginx configuration line unless it is a Conductor marker comment.
     * @param string $line
     * @return string
     */
    private function uncommentNginxConfigLine($line)
    {
        if (strpos($line, '# -- C:') !== false) {
            return $line;
        }

        return preg_replace('/^(\s*)#\s*/', '$1', $line, 1);
    }

    /**
     * Enables the SSL listener/certificate section in an application virtualhost configuration.
     * @param string $config_path
     * @return void
     */
    private function enableApplicationSslConfig($config_path)
    {
        $config = file_get_contents($config_path);

        foreach ([
            '# -- C:Start Default (HTTP) Main Block -- #',
            '# -- C:End Default (HTTP) Main Block -- #',
            '# -- C:Start Auto-LetsEncrypt Main Block -- #',
            '# -- C:End Auto-LetsEncrypt Main Block -- #',
        ] as $marker) {
            if (strpos($config, $marker) === false) {
                $this->writeln('Could not find required Conductor marker in virtualhost configuration: ' . $marker);
                $this->endWithError();
            }
        }

        $config = $this->replaceLinesBetweenMarkers(
            $config,
            '# -- C:Start Default (HTTP) Main Block -- #',
            '# -- C:End Default (HTTP) Main Block -- #',
            [$this, 'commentNginxConfigLine']
        );

        $config = $this->replaceLinesBetweenMarkers(
            $config,
            '# -- C:Start Auto-LetsEncrypt Main Block -- #',
            '# -- C:End Auto-LetsEncrypt Main Block -- #',
            [$this, 'uncommentNginxConfigLine']
        );

        file_put_contents($config_path, $config);
    }

    /**
     * Disables the SSL listener/certificate section in an application virtualhost configuration.
     * @param string $config_path
     * @return void
     */
    private function disableApplicationSslConfig($config_path)
    {
        $config = file_get_contents($config_path);

        foreach ([
            '# -- C:Start Default (HTTP) Main Block -- #',
            '# -- C:End Default (HTTP) Main Block -- #',
            '# -- C:Start Auto-LetsEncrypt Main Block -- #',
            '# -- C:End Auto-LetsEncrypt Main Block -- #',
        ] as $marker) {
            if (strpos($config, $marker) === false) {
                $this->writeln('Could not find required Conductor marker in virtualhost configuration: ' . $marker);
                $this->endWithError();
            }
        }

        $config = $this->replaceLinesBetweenMarkers(
            $config,
            '# -- C:Start Default (HTTP) Main Block -- #',
            '# -- C:End Default (HTTP) Main Block -- #',
            [$this, 'uncommentNginxConfigLine']
        );

        $config = $this->replaceLinesBetweenMarkers(
            $config,
            '# -- C:Start Auto-LetsEncrypt Main Block -- #',
            '# -- C:End Auto-LetsEncrypt Main Block -- #',
            [$this, 'commentNginxConfigLine']
        );

        file_put_contents($config_path, $config);
    }

    /**
     * Apply an SSL vhost configuration change, validate Nginx, and optionally restart it.
     * @param string $config_path
     * @param string $action
     * @return void
     */
    private function updateApplicationSslConfig($config_path, $action)
    {
        $original_conf_content = file_get_contents($config_path);

        if ($action == 'enable') {
            $this->enableApplicationSslConfig($config_path);
        } elseif ($action == 'disable') {
            $this->disableApplicationSslConfig($config_path);
        } else {
            $this->writeln('Unknown SSL configuration action: ' . $action);
            $this->endWithError();
        }

        $this->writeln('Updated virtualhost configuration: ' . $config_path);
        if (!$this->runNginxConfigurationTest()) {
            file_put_contents($config_path, $original_conf_content);
            $this->writeln();
            $this->writeln('Nginx configuration test failed; restored the previous virtualhost configuration.');
            $this->writeln();
            $this->endWithNginxConfigError();
        }

        if ($action == 'enable') {
            $this->writeln('SSL configuration has been enabled.');
        } else {
            $this->writeln('SSL configuration has been reset.');
        }

        if ($this->shouldAutoReloadNginx($this->isFlagSet('auto-reload'))) {
            $this->reloadNginxGracefully();
            return;
        }

        $reload_nginx = $this->input('Gracefully restart (reload) Nginx now?', self::OPTION_YES,
            [self::OPTION_YES, self::OPTION_NO]);

        if (strtolower($reload_nginx) == self::OPTION_YES) {
            $this->reloadNginxGracefully();
        } else {
            $this->writeln('Remember to gracefully restart (reload) Nginx before the SSL configuration change will take effect.');
        }
    }

    /**
     * Returns the virtualhost configuration path for the selected application.
     * @return string
     */
    private function applicationConfigPath()
    {
        $conf_path = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';

        if (!file_exists($conf_path)) {
            $this->writeln('Configuration file not found at: ' . $conf_path);
            $this->endWithError();
        }

        return $conf_path;
    }

    /**
     * Generates an SSH deployment key (PPK) for the specified application.
     */
    public function createDeploymentKey()
    {
        $this->appNameRequired();
        $deploy_key_path = $this->conf->paths->deploykeys . '/' . $this->appname . '.deploykey';
        $cmd_replacements = [
            '__PATH__' => $deploy_key_path,
            '__COMMENT__' => 'deploy@' . $this->appname . '.' . gethostname(),
        ];

        if (file_exists($deploy_key_path)) {
            $this->writeln('Private key already exists at: ' . $deploy_key_path);
            $this->writeln('Use \'conductor delkey {name}\' to remove it first or you can re-use it.');
            $this->writeln();
            $this->endWithError();
        }

        $this->call(str_replace(array_keys($cmd_replacements), array_values($cmd_replacements),
            $this->conf->cmdtpls->sshkeygen));

        if (!file_exists($deploy_key_path)) {
            $this->writeln('An error occurred and the deployment key could not be generated!');
            $this->endWithError();
        }

        foreach ([$deploy_key_path, $deploy_key_path . '.pub'] as $keyfile) {
            $this->call('/usr/bin/chown ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $keyfile);
            $this->call('/usr/bin/chmod 0700 ' . $keyfile);
        }

        $this->writeln('Deployment key has been generated successfully!');
        $this->printDeploymentPublicKey();
    }

    /**
     * Display the SSH deployment public key for a specific application.
     * @return void
     */
    public function showDeploymentKey()
    {
        $this->appNameRequired();
        $this->printDeploymentPublicKey();
    }

    /**
     * Print the deployment public key for the current application.
     * @return void
     */
    private function printDeploymentPublicKey()
    {
        $deploy_key_path = $this->conf->paths->deploykeys . '/' . $this->appname . '.deploykey';
        $public_key_path = $deploy_key_path . '.pub';

        if (!file_exists($public_key_path)) {
            $this->writeln('No public key found at: ' . $public_key_path);
            $this->endWithError();
        }

        $this->writeln();
        $this->writeln(file_get_contents($public_key_path));
        $this->writeln();
        $this->writeln('Copy and paste the above public key content to your remote service(s) as a read-only deployment key.');
        $this->writeln('We also highly recommend adding /.conductor to your repository .gitignore before cloning, so future Conductor-managed settings are not tracked or reset during deployments.');
        $this->writeln();
    }

    /**
     * Deletes an SSH deployment key (PPK) for a specific application.
     */
    public function deleteDeploymentKey()
    {
        $this->appNameRequired();
        $this->deleteDeploymentKeyFiles();
    }

    /**
     * Delete deployment key files for the current application.
     * @return void
     */
    private function deleteDeploymentKeyFiles()
    {
        $deploy_key_path = $this->conf->paths->deploykeys . '/' . $this->appname . '.deploykey';
        if (file_exists($deploy_key_path)) {
            foreach ([$deploy_key_path, $deploy_key_path . '.pub'] as $keyfile) {
                @unlink($keyfile);
            }
            $this->writeln('Deleted the deployment key: ' . $deploy_key_path);
        } else {
            $this->writeln('No private key found at: ' . $deploy_key_path);
        }
    }

    /**
     * Prompt whether to delete deployment key files for the current application.
     * @return void
     */
    private function promptDeleteDeploymentKeyFiles()
    {
        $delete_key = $this->input('Delete the SSH deployment key pair for this application too?', self::OPTION_YES,
            [self::OPTION_YES, self::OPTION_NO]);

        if (strtolower($delete_key) == self::OPTION_YES) {
            $this->deleteDeploymentKeyFiles();
            return;
        }

        $this->writeln('Deployment key files have been kept.');
    }

    /**
     * Request the provision or renewal of LetsEncrypt SSL certificates for a specific application.
     */
    public function generateLetsEncryptCertificate()
    {
        if ($this->getCommand(2) == 'webhook') {
            if ($this->getOption('configure')) {
                $this->configureLetsEncryptWebhook($this->getOption('configure'));
                return;
            }

            $this->writeln('Usage: conductor letsencrypt webhook --configure={endpoint}');
            $this->endWithError();
        }

        $this->appNameRequired();

        if ($this->isFlagSet('enable')) {
            $this->updateApplicationSslConfig($this->applicationConfigPath(), 'enable');
            $this->writeln();
            $this->endWithSuccess();
        }

        if ($this->isFlagSet('disable')) {
            $this->updateApplicationSslConfig($this->applicationConfigPath(), 'disable');
            $this->writeln();
            $this->endWithSuccess();
        }

        if ($this->isFlagSet('delete')) {
            $conf_path = $this->applicationConfigPath();

            $cmd_replacements = [
                '__APP__' => $this->appname,
            ];

            $exit_code = $this->callWithExitCode(str_replace(array_keys($cmd_replacements), array_values($cmd_replacements),
                $this->conf->cmdtpls->letsencryptdel));

            if ($exit_code !== 0) {
                $this->writeln();
                $this->writeln('The LetsEncrypt certificate delete request failed; leaving the virtualhost configuration unchanged.');
                $this->writeln();
                $this->endWithError();
            }

            $this->updateApplicationSslConfig($conf_path, 'disable');
            $this->writeln('SSL configuration has been disabled.');
            $this->writeln();
            $this->endWithSuccess();
        }

        if ($this->isFlagSet('force-renew')) {
            $cmd_replacements = [
                '__APP__' => $this->appname,
                '__NGINX_RELOAD_CMD__' => $this->letsEncryptDeployHook($this->appname),
                '__LETSENCRYPT_DEPLOY_HOOK__' => $this->letsEncryptDeployHook($this->appname),
            ];
            $this->call(str_replace(array_keys($cmd_replacements), array_values($cmd_replacements),
                $this->conf->cmdtpls->letsencryptforcerenew));
            $this->writeln();
            $this->endWithSuccess();
        }

        $conf_path = $this->applicationConfigPath();
        $conf_content = file_get_contents($conf_path);
        $managed_domains = null;
        if (preg_match('/:: Managed domains: \[(.*?)\]/', $conf_content, $match) == 1) {
            $managed_domains = $match[1];
        }

        if (!$managed_domains) {
            $this->writeln('No managed domains found!');
            $this->endWithError();
        }
        $domains = rtrim(str_replace(' ', ',', $managed_domains), ',');

        $cmd_replacements = [
            '__APP__' => $this->appname,
            '__NGINX_RELOAD_CMD__' => $this->letsEncryptDeployHook($this->appname),
            '__LETSENCRYPT_DEPLOY_HOOK__' => $this->letsEncryptDeployHook($this->appname),
            '__DOMAINS__' => $domains,
            '__EMAIL__' => $this->conf->admin->email,
        ];

        $exit_code = $this->callWithExitCode(str_replace(array_keys($cmd_replacements), array_values($cmd_replacements),
            $this->conf->cmdtpls->letsencryptgen));

        if ($exit_code !== 0) {
            $this->writeln();
            $this->writeln('The LetsEncrypt certificate request failed; leaving the virtualhost configuration unchanged.');
            $this->writeln();
            $this->endWithError();
        }

        $enable_ssl = $this->input('LetsEncrypt certificate request successful. Enable SSL configuration now?', self::OPTION_YES,
            [self::OPTION_YES, self::OPTION_NO]);

        if (strtolower($enable_ssl) == self::OPTION_YES) {
            $this->updateApplicationSslConfig($conf_path, 'enable');
        }

        $this->writeln();
        $this->writeln('If you wish to delete this certificate in future you can run:');
        $this->writeln('   conductor letsencrypt ' . $this->appname . ' --delete');
        $this->writeln();
        $this->endWithSuccess();
    }

    /**
     * Configure the JSON POST endpoint used by the LetsEncrypt webhook helper.
     * @param string $endpoint
     * @return void
     */
    private function configureLetsEncryptWebhook($endpoint)
    {
        $endpoint = $this->validateWebhookEndpoint($endpoint);
        $config_path = $this->letsEncryptWebhookConfigPath();
        $directory = dirname($config_path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            $this->writeln('Unable to create LetsEncrypt webhook configuration directory: ' . $directory);
            $this->endWithError();
        }

        if (file_put_contents($config_path, "url = " . $endpoint . PHP_EOL) === false) {
            $this->writeln('Unable to write LetsEncrypt webhook configuration file: ' . $config_path);
            $this->endWithError();
        }

        $this->writeln('Updated LetsEncrypt webhook endpoint: ' . $endpoint);
    }

    /**
     * Build the command Certbot runs after a certificate is deployed.
     * @param string $appname
     * @return string
     */
    private function letsEncryptDeployHook($appname)
    {
        $hook = $this->conf->services->nginx->reload . ' && /etc/conductor/utils/letsencrypt_webhook.sh deploy';
        if (preg_match('/^[A-Za-z0-9._-]+$/', $appname)) {
            $hook .= ' ' . $appname;
        }

        return $hook;
    }

    /**
     * Return the LetsEncrypt webhook configuration path.
     * @return string
     */
    private function letsEncryptWebhookConfigPath()
    {
        if (isset($this->conf->paths->letsencrypt_webhook)) {
            return $this->conf->paths->letsencrypt_webhook;
        }

        return $this->commonConfigurationDirectory() . '/' . self::LETSENCRYPT_WEBHOOK_CONFIG_FILENAME;
    }

    /**
     * Reloads the Crontab service
     * return @void
     */
    private function reloadCronJobs()
    {
        $this->call($this->conf->services->cron->reload);
        $this->writeln('Reloaded the system crons.');
    }

    /**
     * Creates a new application hosting container (and deploys as required)
     */
    public function newApplication()
    {
        $this->appNameRequired();
        if (file_exists($this->appdir)) {
            $this->writeln('Cannot create new application as it already exists on this server!');
            $this->endWithError();
        }

        if (strlen($this->appname) > 14) {
            $this->writeln('Application name cannot exceed 14 characters!');
            $this->endWithError();
        }

        $option_yes_no_set = [self::OPTION_YES, self::OPTION_NO];
        $generate_keys = self::OPTION_YES;
        $deployment_key_generated = false;

        $vhost_template = $this->getOption('template', $this->conf->admin->default_template);
        $is_proxy_template = strtolower($vhost_template) == 'proxy';
        if (!$is_proxy_template && $this->getOption('target')) {
            $this->writeln('The --target option can only be used with --template=proxy.');
            $this->endWithError();
        }
        $proxy_target = $is_proxy_template
            ? $this->validateProxyTarget($this->getOption('target', self::DEFAULT_PROXY_TARGET))
            : '';
        if(!file_exists($tmpl = $this->conf->paths->templates.'/templates/vhost_' . strtolower($vhost_template).'.tpl')){
            $this->writeln('The configuration template was not found!');
            $this->endWithError();
        }

        $gitbranch = trim($this->getOption('git-branch', 'main'));
        if (!$gitbranch) {
            $gitbranch = 'main';
        }
        if (!$this->getOption('fqdn')) {
            // Entering interactive mode...
            $domain = $this->input('Domains (FQDN\'s) to map this application to:');
            $apppath = $is_proxy_template ? '' : $this->input('Hosted directory:', '/public');
            if ($is_proxy_template && !$this->getOption('target')) {
                $proxy_target = $this->validateProxyTarget($this->input('Target address:', self::DEFAULT_PROXY_TARGET));
            }
            $environment = $this->input('Environment type:', 'production');
            $mysql_req = $this->mysqlEnabled()
                ? $this->input('Provision a MySQL database?', self::OPTION_NO, $option_yes_no_set)
                : self::OPTION_NO;
            $deploy_git = $this->input('Deploy application with Git now?', self::OPTION_NO, $option_yes_no_set);
        } else {
            // FQDN is set, entering non-interactive mode!
            $domain = $this->getOption('fqdn');
            $environment = $this->getOption('environment', 'production');
            $apppath = $is_proxy_template ? '' : $this->getOption('path', '/public');
            $mysql_req = self::OPTION_NO; // Disable this by default.
            $deploy_git = self::OPTION_NO; // Disable this by default.

            if ($this->mysqlEnabled() && $this->getOption('mysql-pass')) {
                $mysql_req = self::OPTION_YES;
                $password = $this->getOption('mysql-pass');
            }

            if ($this->getOption('git-uri')) {
                $deploy_git = self::OPTION_YES;
                $gitrepo = trim($this->getOption('git-uri'));
            }
        }

        // Trim any trailing slash from the $path variable...
        $apppath = rtrim($apppath, '/');

        if (strtolower($deploy_git) == self::OPTION_YES) {
            if (strtolower($generate_keys) == self::OPTION_YES) {
                $this->writeln('Generating a deployment (SSH) key pair...');
                $this->createDeploymentKey();
                $deployment_key_generated = true;
            }

            if (!isset($gitrepo)) {
                $this->writeln();
                $gitrepo = trim($this->input('Git repository URI (eg. git@github.com:user/repo.git):'));
                $gitbranch = trim($this->input('Git branch [main]: '));
                if (!$gitbranch) {
                    $gitbranch = 'main';
                }
                $this->writeln();
            }
        }

        // Copy the virtualhost configuration file to our application configuration directory.
        copy($tmpl, $this->conf->paths->appconfs . '/' . $this->appname . '.conf');

        $domains = explode(' ', $domain);

        $placeholders = [
            '@@DOMAIN@@' => $domain,
            '@@DOMAIN_FIRST@@' => $domains[0],
            '@@APPNAME@@' => $this->appname,
            '@@APPPATH@@' => $this->appname . $apppath,
            '@@HLOGS@@' => $this->conf->paths->applogs . '/' . $this->appname . '/',
            '@@ENVIROMENT@@' => $environment,
            '@@SOCKET@@' => $this->conf->paths->fpmsocket,
            '@@FASTCGIPARAMS@@' => $this->conf->paths->fastcgiparams,
            '@@VERSION@@' => $this->version(),
            '@@CREATED_AT@@' => date('c'),
        ];
        if ($is_proxy_template) {
            $placeholders = array_merge($placeholders, $this->proxyTargetPlaceholders($proxy_target));
        }
        $config = file_get_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf');
        foreach ($placeholders as $placeholder => $value) {
            $config = str_replace($placeholder, $value, $config);
        }
        file_put_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf', $config);

        $this->createApplicationWafConfig(strtolower($vhost_template), $placeholders);

        mkdir($this->appdir, 0755);
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir);
        mkdir($this->conf->paths->applogs . '/' . $this->appname, 0755);
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->conf->paths->applogs . '/' . $this->appname);
        $this->call('/usr/bin/chmod 755 ' .$this->conf->paths->appconfs . '/' . $this->appname . '.conf');

        $cron_file = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
        copy($this->conf->paths->templates . '/cron_template.tpl',
            $this->conf->paths->crontabs . '/conductor_' . $this->appname);
        $placeholders = [
            '@@APPNAME@@' => $this->appname,
            '@@APPPATH@@' => $this->appname . $apppath,
        ];
        $cron_config = file_get_contents($cron_file);
        foreach ($placeholders as $placeholder => $value) {
            $cron_config = str_replace($placeholder, $value, $cron_config);
        }
        file_put_contents($cron_file, $cron_config);
        $this->call('chown -R root:root ' . $cron_file);
        $this->call('chmod 744 ' . $cron_file);

        // Enable the site by reloading Nginx.
        //$this->call($this->conf->services->nginx->reload);

        if (strtolower($deploy_git) == self::OPTION_YES) {
            $this->writeln('We\'ll now deploy your application using Git...');
            if (!$this->isDirectoryEmpty($this->appdir)) {
                $this->writeln('Cannot clone repository because the application directory is not empty: ' . $this->appdir);
                $this->endWithError();
            }

            $this->callOrFail(
                $this->gitWithDeploymentKey($this->conf->binaries->git . ' clone --branch ' . escapeshellarg($gitbranch) . ' ' . escapeshellarg($gitrepo) . ' .', $this->appdir),
                'Git clone failed; check the repository URI and branch, then try again.'
            );
            $this->call('/usr/bin/conductor envars ' . $this->appname . ' APP_ENV="' . $environment . '"');
            if (file_exists($this->appdir . '/vendor')) {
                $this->writeln('Skipping dependencies are the \'vendor\' directory exists!');
            } else {
                $this->writeln('Downloading dependencies...');
                $this->call($this->composerForApplication('install --no-dev --optimize-autoloader'));
            }
        } else {
            $this->call('/usr/bin/conductor envars ' . $this->appname . ' APP_ENV="' . $environment . '"');
            $this->writeln('To deploy your application, manually copy the files to:');
            $this->writeln();
            $this->writeln($this->appdir . '/');
            $this->writeln();
            $this->writeln('Alternatively if you are migrating from another server, use \'conductor restore ' . $this->appname . '\' to restore now!');
        }

        if ($is_proxy_template) {
            foreach ([502, 503, 504] as $status_code) {
                $this->createApplicationErrorPage($status_code, $this->appdir);
            }
            $error_page_root = $this->appdir;
        } else {
            $conductor_page_template = $this->conf->paths->templates . '/templates/conductor.html.tpl';
            if (!file_exists($conductor_page_template)) {
                $this->writeln('The Conductor placeholder page template was not found: ' . $conductor_page_template);
                $this->endWithError();
            }

            $document_root = rtrim($this->appdir . $apppath, '/');
            if (!file_exists($document_root)) {
                mkdir($document_root, 0755, true);
            }

            $conductor_page_path = $document_root . '/conductor.html';
            copy($conductor_page_template, $conductor_page_path);
            $conductor_page = file_get_contents($conductor_page_path);
            $conductor_page = str_replace('@@APPNAME@@', $this->appname, $conductor_page);
            file_put_contents($conductor_page_path, $conductor_page);

            $error_page_root = $document_root;
        }

        foreach ([401, 403, 404, 500] as $status_code) {
            $this->createApplicationErrorPage($status_code, $error_page_root);
        }

        if (strtolower($mysql_req) == self::OPTION_YES) {
            $this->writeln();
            if (!isset($password)) {
                $password = $this->input('Please enter a password for the MySQL database:');
            }
            $this->createMySQL($password);
        }

        $this->writeApplicationConductorConfig($environment, $this->appdir, $domain, $mysql_req,
            isset($password) ? $password : null);

        $this->writeln('Setting ownership permissions on application files...');
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir);

        if (strtolower($generate_keys) == self::OPTION_YES && !$deployment_key_generated) {
            $this->writeln('Generating a deployment (SSH) key pair...');
            $this->createDeploymentKey();
        }

        $this->migrateLaravel($environment);
        $this->promptGracefulNginxReload('new application', $this->isFlagSet('auto-reload'));
    }

    /**
     * Create an application-specific error page from the bundled template.
     * @param int $status_code
     * @param string $document_root
     * @return void
     */
    private function createApplicationErrorPage($status_code, $document_root)
    {
        $this->ensureSharedErrorPage($status_code);

        $error_template = $this->conf->paths->templates . '/templates/' . $status_code . '.html.tpl';
        if (!file_exists($error_template)) {
            $this->writeln('The application error page template was not found: ' . $error_template);
            $this->endWithError();
        }

        $error_page_directory = rtrim($document_root, '/') . '/.conductor/error_pages';
        if (!is_dir($error_page_directory) && !mkdir($error_page_directory, 0755, true)) {
            $this->writeln('Unable to create local error page directory: ' . $error_page_directory);
            $this->endWithError();
        }

        $error_page_path = $error_page_directory . '/' . $status_code . '.html';
        copy($error_template, $error_page_path);
        $error_page = file_get_contents($error_page_path);
        $error_page = str_replace('@@APPNAME@@', $this->appname, $error_page);
        file_put_contents($error_page_path, $error_page);
    }

    /**
     * Ensure the shared WAF rejection page exists outside application directories.
     * @return void
     */
    private function ensureSharedWafErrorPage()
    {
        $this->ensureSharedErrorPage(406);
    }

    /**
     * Ensure a shared error page exists outside application directories.
     * @param int $status_code
     * @return void
     */
    private function ensureSharedErrorPage($status_code)
    {
        $shared_directory = $this->sharedWafErrorPageDirectory();
        if (!is_dir($shared_directory) && !mkdir($shared_directory, 0755, true)) {
            $this->writeln('Unable to create shared error page directory: ' . $shared_directory);
            $this->endWithError();
        }

        $shared_page = $shared_directory . '/' . $status_code . '.html';
        if (file_exists($shared_page)) {
            return;
        }

        $error_template = $this->conf->paths->templates . '/templates/' . $status_code . '.html.tpl';
        if (!file_exists($error_template)) {
            $this->writeln('The shared error page template was not found: ' . $error_template);
            $this->endWithError();
        }

        copy($error_template, $shared_page);
        @chown($shared_page, $this->conf->permissions->webuser);
        @chgrp($shared_page, $this->conf->permissions->webgroup);
    }

    /**
     * Return the shared error page directory.
     * @return string
     */
    private function sharedWafErrorPageDirectory()
    {
        if (isset($this->conf->paths->errorpages)) {
            return rtrim($this->conf->paths->errorpages, '/');
        }

        return '/var/conductor/error-pages';
    }

    /**
     * Opens the configured text editor to edit the Nginx configuration file for a specific app.
     */
    public function editApplicationConfig()
    {
        $this->appNameRequired();

        $config_path = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';
        if (!file_exists($config_path)) {
            $this->writeln('Virtual host configuration not found at: ' . $config_path);
        }
        system($this->conf->binaries->editor . ' ' . $config_path . ' > `tty`');
        $this->writeln('Checking file updates for Nginx configuration issues...');
        $this->writeln();
        $this->promptGracefulNginxReload('configuration change');
        $this->writeln();
    }

    /**
     * Enables the Nginx virtualhost configuration for a specific application.
     */
    public function enableApplication()
    {
        $this->appNameRequired();
        $this->toggleApplicationConfig(true);
    }

    /**
     * Disables the Nginx virtualhost configuration for a specific application.
     */
    public function disableApplication()
    {
        $this->appNameRequired();
        $this->toggleApplicationConfig(false);
    }

    /**
     * Rename an application config between active and disabled states.
     * @param bool $enable
     */
    private function toggleApplicationConfig($enable)
    {
        $enabled_path = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';
        $disabled_path = $this->conf->paths->appconfs . '/' . $this->appname . '.disabled';
        $from = $enable ? $disabled_path : $enabled_path;
        $to = $enable ? $enabled_path : $disabled_path;

        if (!file_exists($this->appdir)) {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }

        if (file_exists($to)) {
            $this->writeln('Application is already ' . ($enable ? 'enabled.' : 'disabled.'));
            return;
        }

        if (!file_exists($from)) {
            $this->writeln('Virtual host configuration not found at: ' . $from);
            $this->endWithError();
        }

        if (!rename($from, $to)) {
            $this->writeln('Unable to rename virtual host configuration.');
            $this->endWithError();
        }

        $this->writeln('Application ' . $this->appname . ' has been ' . ($enable ? 'enabled.' : 'disabled.'));

        if (!$this->runNginxConfigurationTest()) {
            rename($to, $from);
            $this->writeln('Nginx configuration test failed. The application has been returned to its previous state.');
            $this->endWithNginxConfigError();
        }

        if ($this->shouldAutoReloadNginx($this->isFlagSet('auto-reload'))) {
            $this->reloadNginxGracefully();
            return;
        }

        $reload_nginx = $this->input('Gracefully restart (reload) Nginx now for the change to take effect?', self::OPTION_YES,
            [self::OPTION_YES, self::OPTION_NO]);

        if (strtolower($reload_nginx) == self::OPTION_YES) {
            $this->reloadNginxGracefully();
        } else {
            $this->writeln('Remember to gracefully restart (reload) Nginx before the change will take effect.');
        }
    }

    /**
     * Opens the application managed cron file.
     */
    public function editApplicationCron()
    {
        $this->appNameRequired();

        $cron_path = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
        if (!file_exists($cron_path)) {
            $this->writeln('Application cron not found at: ' . $cron_path);
        }
        system($this->conf->binaries->editor . ' ' . $cron_path . ' > `tty`');
        $this->writeln();
    }

    /**
     * Updates the code and executes migrations on an existing database.
     */
    public function updateApplication()
    {
        $this->appNameRequired();

        // Get the current environment type to execute the Laravel migrations with.
        $env_handler = new EnvHandler($this->conf->paths->appconfs . '/' . $this->appname . '_envars.json');
        $env_handler->load();
        $environment = $env_handler->get('APP_ENV', 'production');

        // Checks for CLI options to suppress the 'stop' application user input.
        if ($this->getOption('down', false)) {
            if ($this->getOption('down') == "true") {
                $stopapp = self::OPTION_YES;
            } else {
                $stopapp = self::OPTION_NO;
            }
        }

        if (!file_exists($this->appdir)) {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }

        if (!isset($stopapp)) {
            $stopapp = $this->input('Do you wish to \'stop\' the application before upgrading?', self::OPTION_YES,
                [self::OPTION_YES, self::OPTION_NO]);
        }

        if (strtolower($stopapp) == self::OPTION_YES) {
            $this->stopLaravelApplication();
        }

        if (file_exists($this->conf->paths->backups . '/rollback_' . $this->appname . '.tag.gz')) {
            unlink($this->conf->paths->backups . '/rollback_' . $this->appname . '.tag.gz');
        }
        $this->backupApplication('rollback_' . $this->appname . '.tar.gz');
        $this->writeln('Starting application upgrade...');
        if (file_exists($this->appdir . '/.git')) {
            $this->writeln('Pulling latest code from Git...');
            $this->gitPull();
            $this->writeln('Downloading Composer dependencies...');
            $this->call($this->composerForApplication('install --no-dev --optimize-autoloader'));
        }
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir);
        $this->migrateLaravel($environment);
        $this->writeln('...finished!');
        if (strtolower($stopapp) == self::OPTION_YES) {
            $this->startLaravelApplication();
        }

        $this->endWithSuccess();
    }

    /**
     * List all the hosted applications on the server.
     */
    public function listApplications()
    {
        $applications = new DirectoryIterator($this->conf->paths->apps);
        $this->writeln();
        $this->writeln(str_pad('Status', 8) . 'Application');
        $this->writeln(str_repeat('-', 32));

        $application_names = [];
        foreach ($applications as $application) {
            if ($application->isDir() and ($application->getBasename()[0] != '.')) {
                $application_names[] = $application->getBasename();
            }
        }

        sort($application_names);
        foreach ($application_names as $application_name) {
            $this->writeln(str_pad($this->applicationEnabledMarker($application_name), 8) . $application_name);
        }

        $this->writeln();
    }

    /**
     * Return an enabled/disabled marker for listApplications().
     * @param string $application_name
     * @return string
     */
    private function applicationEnabledMarker($application_name)
    {
        $enabled_path = $this->conf->paths->appconfs . '/' . $application_name . '.conf';
        $disabled_path = $this->conf->paths->appconfs . '/' . $application_name . '.disabled';

        if (file_exists($enabled_path)) {
            return '[/]';
        }

        if (file_exists($disabled_path)) {
            return '[x]';
        }

        return '[?]';
    }

    /**
     * Initiates an application backup.
     */
    public function backup()
    {
        $this->appNameRequired();
        $archive_filename = $this->appname . '-' . date('Y-m-d-H-i') . '.tar.gz';

        if (file_exists($this->appdir)) {
            $this->writeln('Starting application backup...');
            $this->backupApplication($archive_filename);
            $this->writeln('...finished!');
            $this->writeln();
            $this->writeln('Backup successfully created: ' . $this->conf->paths->backups . '/' . $archive_filename);
            $this->writeln();
            $this->endWithSuccess();
        } else {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }
    }

    /**
     * Restores an application from a backup.
     */
    public function restore()
    {
        $this->appNameRequired();
        $this->writeln('Tell us which archive you wish to restore (eg. /var/conductor/backups/myapp_2013-10-26-0900.tar.gz)');
        $archive = $this->input('Backup archive:');

        if (!file_exists($archive)) {
            $this->writeln('The backup archive could not be found!');
            $this->endWithError();
        }

        mkdir($this->appdir, 0755);
        mkdir($this->conf->paths->applogs . '/' . $this->appname, 0755);
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->conf->paths->applogs . '/' . $this->appname);

        mkdir($this->conf->paths->temp . '/restore_' . $this->appname, 755);
        $this->call('tar -zxf ' . $archive . ' -C ' . $this->conf->paths->temp . '/restore_' . $this->appname);

        $crontab = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
        if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname)) {
            @unlink($crontab); // Delete existing crontab file if it exists!
            $this->call('mv ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname . ' ' . $crontab);
            $this->call('chmod 744 ' . $crontab);
            $this->call('chown root:root ' . $crontab);
            $this->writeln('Finished importing the application crontab!');
            $this->call($this->conf->services->cron->reload);
            $this->writeln('Reloaded the system crons.');
        } else {
            $this->writeln('No Conductor crontab was found, skipping cron import!');
        }

        if ($this->mysqlEnabled() && file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz')) {
            $this->writeln('Importing application MySQL database...');
            $this->call('gunzip < ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz | mysql -h' . $this->conf->mysql->host . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' db_' . $this->appname . '');
            $this->writeln('Finished importing the MySQL database!');
            @unlink($this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz');
        } elseif (!$this->mysqlEnabled()) {
            $this->writeln('MySQL management is disabled, skipping DB import!');
        } else {
            $this->writeln('No Conductor database archive was found, skipping DB import!');
        }

        $appconf = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';
        if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/' . $this->appname . '.conf')) {
            @unlink($appconf); // Delete existing nginx config file if it exists!
            $this->call('mv ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/' . $this->appname . '.conf' . ' ' . $appconf);
            $this->call('chmod 744 ' . $appconf);
            $this->call('chown root:root ' . $crontab);
            $this->writeln('Finished importing the application (nginx) configuration!');
        } else {
            $this->writeln('No application (nginx) configuration was found, skipping virtualhost config import!');
        }

        $this->call('rm -Rf ' . $this->appdir);
        $this->call('cp -Rf ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/ ' . $this->appdir . '/');
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir);
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/restore_' . $this->appname);
        $this->writeln('Restarting Nginx...');
        $this->call($this->conf->services->nginx->restart);
        $this->writeln('...finished!');
        $this->startLaravelApplication();
        $this->endWithSuccess();
    }

    /**
     * Rollback the current application to it's state prior to the upgrade.
     */
    public function rollback()
    {
        $this->appNameRequired();
        if (!file_exists($this->conf->paths->backups . '/rollback_' . $this->appname . '.tar.gz')) {
            $this->writeln('There is no available rollback snapshot to restore to!');
            $this->endWithError();
        }

        mkdir($this->conf->paths->temp . '/rollback_' . $this->appname, 755);
        $this->writeln('Extracting the rollback image...');
        $this->call('tar -zxf ' . $this->conf->paths->backups . '/rollback_' . $this->appname . '.tar.gz -C ' . $this->conf->paths->temp . '/rollback_' . $this->appname);

        $crontab = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
        if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname)) {
            @unlink($crontab); // Delete existing crontab file if it exists!
            $this->call('mv ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname . ' ' . $crontab);
            $this->call('chmod 744 ' .$crontab);
            $this->call('chown root:root ' . $crontab);
            $this->writeln('Finished importing the application crontab!');
            $this->call($this->conf->services->cron->reload);
            $this->writeln('Reloaded the system crons.');
        } else {
            $this->writeln('No Conductor crontab was found, skipping cron import!');
        }

        if ($this->mysqlEnabled() && file_exists($this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz')) {
            $this->writeln('Importing application MySQL database...');
            $this->call('gunzip < ' . $this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz | mysql -h' . $this->conf->mysql->host . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' db_' . $this->appname . '');
            $this->writeln('Finished importing the MySQL database!');
            unlink($this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz');
        } elseif (!$this->mysqlEnabled()) {
            $this->writeln('MySQL management is disabled, skipping DB import!');
        } else {
            $this->writeln('No Conductor database archive was found, skipping DB import!');
        }

        $appconf = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';
        if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/' . $this->appname . '.conf')) {
            @unlink($appconf); // Delete existing nginx config file if it exists!
            $this->call('mv ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/' . $this->appname . '.conf' . ' ' . $appconf);
            $this->call('chmod 744 ' . $appconf);
            $this->call('chown root:root ' . $crontab);
            $this->writeln('Finished importing the application (nginx) configuration!');
        } else {
            $this->writeln('No application (nginx) configuration was found, skipping virtualhost config import!');
        }

        $this->call('rm -Rf ' . $this->appdir);
        $this->call('cp -Rf ' . $this->conf->paths->temp . '/rollback_' . $this->appname . '/ ' . $this->appdir . '/');
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir);
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/rollback_' . $this->appname);
        $this->writeln('...finished!');
        $this->writeln('Forcing application start...');
        $this->startLaravelApplication();
        $this->endWithSuccess();
    }

    /**
     * Delete an application and associated data.
     */
    public function destroy()
    {
        $this->appNameRequired();
        if (file_exists($this->appdir)) {
            $this->writeln('Running a quick snapshot as you can never be too careful...');
            $this->backupApplication('priordestroy_' . $this->appname . '.tar.gz');
            $this->writeln('Removing application crontab...');
            $this->call('rm ' . $this->conf->paths->crontabs . '/conductor_' . $this->appname);
            $this->writeln('Destroying application...');
            $this->call('rm ' . $this->conf->paths->appconfs . '/' . $this->appname . '*');
            $this->writeln('Removing application WAF configuration...');
            $this->call('rm -f ' . $this->applicationWafConfigPath());
            $this->promptGracefulNginxReload('deleted application');
            if ($this->mysqlEnabled()) {
                $this->writeln('Destroying MySQL database and associated users...');
                $this->destroyMySQL();
            }
            $this->writeln('Destroying app directory and log files...');
            $this->call('rm -Rf ' . $this->appdir);
            $this->call('rm -Rf ' . $this->conf->paths->applogs . '/' . $this->appname);
            $this->writeln('Removing optional security log files...');
            $this->call('rm -f /tmp/conductor_' . $this->appname . '.seclog*');
            $this->writeln();
            $this->promptDeleteDeploymentKeyFiles();
            $this->writeln();
            $this->writeln('...finished!');
            $this->endWithSuccess();
        } else {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }
    }

    /**
     * Start a specific Laravel application.
     */
    public function startLaravelApplication()
    {
        $this->appNameRequired();
        $this->writeln('Attempting to start the Laravel Application');
        if (!$this->isLaravelApplication()) {
            $this->writeNotLaravelMessage();
            return;
        }

        $this->call($this->conf->binaries->php . ' ' . escapeshellarg($this->appdir . '/artisan') . ' up');
    }

    /**
     * Stop a specific Laravel application.
     * @return void
     */
    public function stopLaravelApplication()
    {
        $this->appNameRequired();
        $this->writeln('Attempting to stop the Laravel Application');
        if (!$this->isLaravelApplication()) {
            $this->writeNotLaravelMessage();
            return;
        }

        $this->call($this->conf->binaries->php . ' ' . escapeshellarg($this->appdir . '/artisan') . ' down');
    }
}
