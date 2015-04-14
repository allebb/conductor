<?php

class EnvHandler
{

    /**
     * The configuration file name and path.
     * @var type 
     */
    private $file;

    /**
     * Object storage for environmental variables.
     * @var type 
     */
    private $vars = [];

    /**
     * Initiates the Environment Variable class.
     * @param string $conf_file The name of the configuration file to read/write.
     */
    public function __construct($conf_file)
    {
        $this->file = $conf_file;
    }

    /**
     * Load the current configuration file into memory.
     * @throws RuntimeException
     */
    public function load()
    {
        if (!file_exists($this->file)) {
            throw new RuntimeException(sprintf('Configuration file does not exsist in %s', $this->file));
        }
        $conf_data = json_decode(file_get_contents($this->file), true);
        if (empty($conf_data)) {
            throw new RuntimeException('Invalid JSON data format, the configuration could not be loaded!');
        }
        $this->vars = $conf_data;
    }

    /**
     * Save the current configuration to file.
     * @throws RuntimeException
     */
    public function save()
    {
        if (!file_put_contents($this->file, json_encode($this->vars))) {
            throw new RuntimeException(sprinf('The configuration file could not be saved in %s', $this->file));
        }
    }

    /**
     * Adds a new environmental variable to the configuration or updates an existing one.
     * @param string $key The ENV variable to update.
     * @param string $value The value to add or update.
     */
    public function push($key, $value)
    {
        $this->vars[$key] = $value;
    }

    /**
     * Remove a configuration item from the environmental variables.
     * @param string $key The key to remove.
     */
    public function remove($key)
    {
        if ($this->get($key, true)) {
            unset($key);
        }
    }

    /**
     * Return all the current environment settings.
     * @return array
     */
    public function all()
    {
        return $this->vars;
    }

    /**
     * Retrieves a value of the specified ENV setting.
     * @param string $key The name of the environmental variable.
     * @param mixed $default Optional default return value if not found.
     * @return string
     */
    public function get($key, $default = null)
    {
        if (isset($this->vars[$key])) {
            return $this->vars[$key];
        } else {
            return $default;
        }
    }
}
