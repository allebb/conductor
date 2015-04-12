<?php

class CliApplication
{

    private $commands = [];
    private $options = [];
    private $flags = [];
    private $arguments = [];

    public function __construct($argv)
    {
        $this->paramBuilder($argv);
    }

    /**
     * Enforces that the script must be run at the console.
     * @return void
     */
    public function enforceCli()
    {
        if (php_sapi_name() != "cli") {
            die('CLI excution only!');
        }
    }

    /**
     * Checks that the user is root!
     * @return boolean
     */
    public function isSuperUser()
    {
        if (posix_getuid() == 0) {
            return true;
        }
        return false;
    }

    /**
     * Return CLI arguments
     * @return array
     */
    public function arguments()
    {
        return $this->arguments;
    }

    /**
     * Return the CLI commands eg. (new)
     * @return array
     */
    public function commands()
    {
        return $this->commands;
    }

    /**
     * Return the CLI flags eg. (-f or --force)
     * @return array
     */
    public function flags()
    {
        return $this->flags;
    }

    /**
     * Return the CLI options as a key'd array (--option="Hello")
     * @return array
     */
    public function options()
    {
        return $this->options;
    }

    /**
     * Checks to see if a flag is set on the CLI
     * @param string $flag The flag name/character
     * @return boolean
     */
    public function isFlagSet($flag)
    {
        if (in_array($flag, $this->flags)) {
            return true;
        }
        return false;
    }

    /**
     * Executes a system command.
     * @param string $command
     * @return string
     */
    public function call($command)
    {
        return system($command);
    }

    /**
     * Retrieve the value of an option
     * @param string $name The name of the option to return
     * @param type $default An optional default value if its not set.
     * @return mixed
     */
    public function getOption($name, $default = false)
    {
        if (isset($this->options[$name])) {
            return $this->options[$name];
        } else {
            return $default;
        }
    }

    /**
     * Returns a command from the command index.
     * @param int $part Will return the Nth command. eg 1 for "./conductor new myapp" will return 'new'
     * @param string $default An optioanl default value if the command index is not set.
     */
    public function getCommand($part, $default = false)
    {
        if (isset($this->commands[$part])) {
            return $this->commands[$part];
        } else {
            return $default;
        }
    }

    /**
     * Write out a line to the CLI.
     * @param string $line
     */
    public function writeln($line = '')
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    /**
     * Request user input
     * @param string $question The input question/text
     * @param string $default A default value if one is not selected.
     * @param array $options Valid options that are acceptable.
     * @return string
     */
    public function input($question, $default = '', $options = [])
    {
        fwrite(STDOUT, $question . ' ');

        $answer = rtrim(fgets(STDIN), PHP_EOL);

        $valid = rtrim(implode(',', $options), ',');

        // Should we validate the input?
        if (count($options) > 0) {
            if (!in_array($answer, $options)) {
                $this->writeln('Invalid selection, valid options are: ' . $valid);
                return $this->input($question, $default, $options);
            }
            return $answer;
        }

        if (empty($answer)) {
            return $default;
        } else {
            return $answer;
        }
    }

    /**
     * Writes an array to the CLI output
     */
    public function writearr($array = [])
    {
        print_r($array);
    }

    /**
     * Exits the CLI with code (0)
     */
    public function endWithSuccess()
    {
        exit(0);
    }

    /**
     * Exits the CLI with code (1)
     */
    public function endWithError()
    {
        exit(1);
    }

    /**
     * Sort the CLI params into their type and assign to the object properties.
     * @return void
     */
    private function paramBuilder($args)
    {
        $endofoptions = false;

        $ret = [
            'commands' => [],
            'options' => [],
            'flags' => [],
            'arguments' => [],
        ];

        while ($arg = array_shift($args)) {

            if ($endofoptions) {
                $ret['arguments'][] = $arg;
                continue;
            }

            // Is it a command? (prefixed with --)
            if (substr($arg, 0, 2) === '--') {

                // Is it a long flag type? eg . --help?
                if (!strpos($arg, '=')) {
                    $ret['flags'][] = ltrim($arg, '--');
                    continue;
                }

                $value = "";
                $com = substr($arg, 2);

                // Is it the syntax '--option=argument'?
                if (strpos($com, '=')) {
                    list($com, $value) = split("=", $com, 2);
                    // Is the option not followed by another option but by arguments
                } elseif (strpos($args[0], '-') !== 0) {
                    while (strpos($args[0], '-') !== 0)
                        $value .= array_shift($args) . ' ';
                    $value = rtrim($value, ' ');
                }

                $ret['options'][$com] = !empty($value) ? $value : true;
                continue;
            }

            // Is it a flag or a serial of flags? (prefixed with -)
            if (substr($arg, 0, 1) === '-') {
                for ($i = 1; isset($arg[$i]); $i++)
                    $ret['flags'][] = $arg[$i];
                continue;
            }

            $ret['commands'][] = $arg;
            continue;
        }

        // Set the object property values.
        $this->arguments = $ret['arguments'];
        $this->commands = $ret['commands'];
        $this->flags = $ret['flags'];
        $this->options = $ret['options'];
    }
}
