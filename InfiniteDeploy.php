<?php

use phpseclib3\Net\SFTP;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class InfiniteDeploy
{
    public $build   = true;
    public $deploy  = true;
    public $gate    = true;
    public $verbose = false;
    public $quiet   = false;

    public $config;
    public $selectedTargets = [];

    private $remoteUsername;
    private $remotePassword;
    private $twig;

    public function __construct(array $argv, array $config)
    {
        $this->config = $config;
        $this->validateConfig();

        foreach (array_slice($argv, 1) as $argument) {
            if ($argument == '--no-build')  { $this->build   = false; continue; }
            if ($argument == '--no-deploy') { $this->deploy  = false; continue; }
            if ($argument == '--no-gate')   { $this->gate    = false; continue; }
            if ($argument == '--verbose')   { $this->verbose = true;  continue; }
            if ($argument == '--quiet')     { $this->quiet   = true;  continue; }

            if ($argument == '--all') {
                $this->selectedTargets = $config['targets'];
                continue;
            }

            if (array_key_exists($argument, $config['targets'])) {
                $this->selectedTargets[$argument] = $config['targets'][$argument];
                continue;
            }

            $this->showUsageAndExit();
        }

        if (!$this->selectedTargets) {
            $this->showUsageAndExit();
        }

        // Get a username/password for remote deploys.
        if ($this->deploy) {
            echo 'Remote username: ';
            $this->remoteUsername = rtrim(fgets(STDIN), "\r\n");
            echo 'Remote password: ';
            $ph = popen('stty -echo; read REPLY; echo $REPLY', 'r');
            $this->remotePassword = rtrim(fgets($ph), "\r\n");
            pclose($ph);
            echo "\n";

            // Confirm that this username/password combo works on every target, so that we can fail early if they're wrong
            foreach ($this->selectedTargets as $target => $meta) {
                if (!($session = $this->connect($target))) {
                    $this->error("Username/password failed on $target");
                }
                $session->disconnect();
            }
        }

        $this->twig = new Environment(new ArrayLoader);
    }

    /**
     * Unless --no-gate was passed, print the given $title and return true.
     */
    public function gate(string $title): bool
    {
        if ($this->gate && !$this->quiet) {
            echo "$title\n";
        }
        return $this->gate;
    }

    /**
     * Unless --no-build was passed, print the given $title and return true.
     */
    public function build(string $title): bool
    {
        if ($this->build && !$this->quiet) {
            echo "$title\n";
        }
        return $this->build;
    }

    /**
     * Unless --no-deploy was passed, print the given $title and return true.
     */
    public function deploy(string $title): bool
    {
        if ($this->deploy && !$this->quiet) {
            echo "$title\n";
        }
        return $this->deploy;
    }

    public function runScripts(string $key, array $substitutions = []): void
    {
        if (!isset($this->config['scripts'][$key])) {
            throw new \RuntimeException("$key scripts not defined");
        }

        foreach ($this->config['scripts'][$key] as $line) {
            $template = $this->twig->createTemplate($line);
            $line = $template->render($substitutions);
            $this->runCmd($line);
        }
    }

    public function runCmd(string $command): void
    {
        if ($this->verbose) {
            echo "$command\n";
            passthru($command, $code);
            if ($code) { exit($code); }
        } else {
            $ph = popen($command . ' 2>&1', 'r');
            $output = stream_get_contents($ph);
            $code = pclose($ph);
            if ($code) {
                $this->error("$command\n$output", $code);
            }
        }
    }

    public function connect($target): ?SFTP
    {
        if (!isset($this->config['targets'][$target]['remote'])) {
            throw new \InvalidArgumentException('No such remote target: '.$target);
        }
        list($server, $port) = $this->config['targets'][$target]['remote'];

        $session = new SFTP($server, $port);
        if (!$session->login($this->remoteUsername, $this->remotePassword)) {
            $session->disconnect();
            return null;
        }
        return $session;
    }

    public function remoteSudo(SFTP $session, $script): void
    {
        $command = sprintf('echo %s | sudo -kS bash -c %s', escapeshellarg($this->remotePassword), escapeshellarg($script));
        $output = $session->exec($command, $this->verbose ? function ($line) { echo $line; } : null);
        if ($session->getExitStatus()) {
            if (!$this->verbose) { fwrite(STDERR, $output); }
            $session->disconnect();
            exit($session->getExitStatus());
        }
    }

    private function showUsageAndExit(): void
    {
        global $argv;
        fwrite(STDERR, "Usage: $argv[0] [target1] [target2] ...
Flags:
    --all:       Deploy to all targets
    --no-build:  Don't build the package (deploy existing package only)
    --no-deploy: Skip the deploy phase (build package only)
    --no-gate:   Skip the validation phase
    --verbose:   Print more output
    --quiet:     Print less output
Targets:
");
        foreach ($this->config['targets'] as $target => $meta) {
            fwrite(STDERR, "    $target: $meta[domain]\n");
        }
        exit(1);
    }

    private function validateConfig(): void
    {
        if (!isset($this->config['targets']))    { $this->error("config.targets must exist"); }
        if (!is_array($this->config['targets'])) { $this->error("config.targets must be an array"); }
        if (!$this->config['targets'])           { $this->error("config.targets must not be empty"); }

        foreach ($this->config['targets'] as $target => $meta) {
            if ($target === '') { $this->error("config.targets must not have an empty key"); }
            if (!is_array($meta)) { $this->error("config.targets[$target] must be an array"); }

            if (!isset($meta['remote'])) { $this->error("config.targets[$target].remote must exist"); }
            if (!is_array($meta['remote']) || count($meta['remote']) !== 2 || !is_string($meta['remote'][0]) || !is_int($meta['remote'][1])) {
                $this->error("config.targets[$target].remote must be an array with two elements (IP address and port number)");
            }

            if (!isset($meta['domain'])) { $this->error("config.targets[$target].domain must exist"); }
            if (!is_string($meta['domain'])) { $this->error("config.targets[$target].domain must be a string"); }
        }

        if (isset($this->config['scripts'])) {
            if (!is_array($this->config['scripts'])) { $this->error("config.scripts must be an array"); }

            foreach ($this->config['scripts'] as $key => $scripts) {
                if ($key === '') { $this->error("config.scripts must not have an empty key"); }

                if (!is_array($scripts)) { $this->error("config.scripts[$key] must be an array"); }

                foreach ($scripts as $index => $line) {
                    if (!is_string($line)) {
                        $this->error("config.scripts[$key][$index] must be a string");
                    }
                }
            }
        }
    }

    private function error($message, $code = 1)
    {
        fprintf(STDERR, "%s\n", $message);
        exit($code);
    }
}
