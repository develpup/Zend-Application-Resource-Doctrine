<?php

// Define path to application directory
defined('APPLICATION_PATH')
    || define('APPLICATION_PATH', realpath(dirname(__FILE__) . '/../application'));

// Define application environment
defined('APPLICATION_ENV')
    || define('APPLICATION_ENV', (getenv('APPLICATION_ENV') ? getenv('APPLICATION_ENV') : 'development'));

// Ensure library/ is on include_path
set_include_path(implode(PATH_SEPARATOR, array(
    realpath(APPLICATION_PATH . '/../library'),
    get_include_path(),
)));

require_once 'Zend/Tool/Project/Provider/Abstract.php';
require_once 'Zend/Tool/Project/Provider/Exception.php';
require_once 'Zend/Application.php';
require_once 'Doctrine/Cli.php';

class DoctrineProvider extends Zend_Tool_Project_Provider_Abstract
{
    /**
     * The Doctrine CLI object
     * @var Doctrie_Cli
     */
    private $_doctrineCli = null;

    /**
     * The Doctrine configuration
     * @var array
     */
    private $_doctrineConfig = null;

    /**
     * Show the Doctrine CLI help
     */
    public function help()
    {
        $this->run('help');
    }

    /**
     * Show the list of Doctrine CLI tasks
     */
    public function showTasks()
    {
        $this->run();
    }

    /**
     * Show setting defaults
     */
    public function showDefaults()
    {
        $s = array(
            'dsn'                => '',
            'models_path'        => '',
            'yaml_schema_path'   => '',
            'migrations_path'    => '',
            'sql_path'           => '',
            'data_fixtures_path' => '',
            'manager' => array(
                'auto_accessor_override' => false,
                'autoload_table_classes' => false,
                'model_loading'          => 'model_loading_aggressive',
                'table_class'            => 'Doctrine_Table',
                'model_class_prefix'     => '',
            ),
            'connection' => array(
                'quote_identifier' => false,
                'use_native_enum'  => false
            ),
            'generate_models_options' => array(
                'packagesPrefix'       => 'Package',
                'packagesPath'         => '',
                'packagesFolderName'   => 'packages',
                'suffix'               => '.php',
                'generateBaseClasses'  => true,
                'generateTableClasses' => false,
                'generateAccessors'    => false,
                'baseClassPrefix'      => 'Base',
                'baseClassesDirectory' => 'generated',
                'baseClassName'        => 'Doctrine_Record'
            ),
            'dql_query' => ''
        );

        foreach ($s as $top => $top_val) {
            echo $top;
            if (is_array($top_val)) {
              echo ':'.PHP_EOL;
              foreach ($top_val as $key => $val) {
              if (is_string($val)) $val = "'$val'";
              if (is_bool($val)) $val = $val ? 'true' : 'false';
                echo "    $key = $val".PHP_EOL;
              }
            } else {
              if (is_string($top_val)) $top_val = "'$top_val'";
              if (is_bool($top_val)) $top_val = $top_val ? 'true' : 'false';
              echo " = $top_val".PHP_EOL;
            }
        }
    }

    /**
     * Runs Doctrine tasks
     */
    public function run($task = null)
    {
        $this->_bootstrapDoctrine();
        $args = func_get_args();
        $cmdline = array('zf run doctrine');
        $cmdline = (null === $task) ? $cmdline : array_merge($cmdline, $args);
        $this->_getDoctrineCli()->run($cmdline);
    }

    /**
     * Configures the Doctrine DSN
     */
    public function configure($key = null, $value = null, $env = 'production')
    {
        if (empty($key)) {
            $key = 'dsn';
        }

        if (null === $value) {
            $new_env = null;
            while (false === in_array($new_env, array('production','testing','staging','development'))) {
                $new_env = $this->_registry->getClient()->promptInteractiveInput('Enter environment:')->getContent();
                if (null === $new_env) $new_env = $env;
            }
            $env = $new_env;

            try {
                $this->_bootstrapDoctrine($env);
                $current = $this->_doctrineConfig[$key];
                if (is_bool($current)) {
                    $current = ($current ? 'true' : 'false');
                }
                $prompt = sprintf(
                    '%s: %s'.PHP_EOL.'Enter new value:',
                    $key,
                    $current
                );
            } catch (Exception $e) {
                $prompt = "Enter new value for [$key]:";
            }

            $value = $this->_registry->getClient()->promptInteractiveInput($prompt)->getContent();
            if (null === $value) return;
        } else {
            $this->_bootstrapDoctrine($env);
        }

        $this->_configSet($key, $value, $env);
    }

    /**
     * Set the value of a configuration setting
     *
     * @param string $key
     * @param string $value
     * @param string $env
     */
    protected function _configSet($key, $value, $env = 'production')
    {
        require_once 'Doctrine/Parser/sfYaml/sfYaml.php';
        require_once 'Zend/Config.php';

        $parts = explode('.', $key);

        if ('manager' === $parts[0] || 'connection' === $parts[0]) {
            $attr = $parts[1];
            $attr_const = 'Doctrine_Core::ATTR_' . strtoupper($attr);
            $attr_id = @constant($attr_const);
            if (null === $attr_id) {
                require_once 'Zend/Tool/Project/Provider/Exception.php';
                throw new Zend_Tool_Project_Provider_Exception(
                    "Invalid Doctrine constant: $attr_const"
                );
            }
            if (preg_match('/^' . $attr . '_[a-z][a-z_]*$/i', $value)) {
                $attr_val_const = 'Doctrine_Core::' . strtoupper($value);
                $attr_val = @constant($attr_val_const);
                if (null === $attr_val) {
                    require_once 'Zend/Tool/Project/Provider/Exception.php';
                    throw new Zend_Tool_Project_Provider_Exception(
                        "Invalid Doctrine constant: $attr_val_const"
                    );
                }
            }
        }

        $last = $parts[ count($parts) - 1 ];

        $cfg_file = APPLICATION_PATH . '/configs/doctrine.yaml';
        $cfg = new Zend_Config(sfYaml::load($cfg_file), true);

        if ( ! isset($cfg->{$env})) {
            $cfg->{$env} = new Zend_Config(array(), true);
            if ('production' !== $env) {
                $cfg->{$env}->_extends = 'production';
            }
        }

        if ( ! isset($cfg->{$env}->resources)) {
            $cfg->{$env}->resources = new Zend_Config(array(), true);
        }

        if ( ! isset($cfg->{$env}->resources->doctrine)) {
            $cfg->{$env}->resources->doctrine = new Zend_Config(array(), true);
        }

        $dig = $cfg->{$env}->resources->doctrine;

        foreach ($parts as $part) {
          if ($part === $last) break;
          $dig = $dig->{$part};
        }

        if (preg_match('/^([Tt]rue|[Ff]alse|TRUE|FALSE)$/', $value)) {
            $value = ('true' === strtolower($value));
        }

        $dig->{$last} = $value;

        $yaml = '---'.PHP_EOL.sfYaml::dump($cfg->toArray(), 1000);
        $yaml = str_replace(PHP_EOL.'development:'.PHP_EOL, PHP_EOL.PHP_EOL.'development:'.PHP_EOL, $yaml);
        $yaml = str_replace(PHP_EOL.'staging:'.PHP_EOL, PHP_EOL.PHP_EOL.'staging:'.PHP_EOL, $yaml);
        $yaml = str_replace(PHP_EOL.'testing:'.PHP_EOL, PHP_EOL.PHP_EOL.'testing:'.PHP_EOL, $yaml);

        file_put_contents($cfg_file, $yaml.PHP_EOL);
    }

    /**
     * Get the Doctrine configuration
     *
     * @return array
     */
    protected function _bootstrapDoctrine($env = null)
    {
        static $bootstrapped_env = null;

        $env = ($env ? $env : APPLICATION_ENV);

        if ($bootstrapped_env === $env) return;

        // Create application and bootstrap the 'doctrine' resource
        $application = new Zend_Application(
            $env,
            APPLICATION_PATH . '/configs/application.ini'
        );
        $application->getBootstrap()->bootstrap('doctrine');
        $resources = $application->getOption('resources');
        $this->_doctrineConfig = $resources['doctrine'];
        $bootstrapped_env = $env;
    }

    /**
     * Get the Doctrine_Cli object
     *
     * @return Doctrine_Cli
     */
    protected function _getDoctrineCli()
    {
        if (null === $this->_doctrineCli) {
            $this->_doctrineCli = new Doctrine_Cli( $this->_doctrineConfig );
        }
        return $this->_doctrineCli;
    }

    /**
     * Gets the list of tasks registered with the Doctrine CLI
     *
     * @return array
     */
    protected function _getDoctrineTasks()
    {
        return $this->_getDoctrineCli()->getRegisteredTasks();
    }
}

