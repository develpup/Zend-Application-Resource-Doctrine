<?php
/**
 * Application resource for initializing and configuring Doctrine for use by
 * the Zend Framework.
 *
 * @package    Application
 * @subpackage Resource
 * @author     deVelpup
 */

require_once 'Doctrine/Core.php';

/**
 * Bootstraps Doctrine
 */
class Application_Resource_Doctrine extends Zend_Application_Resource_ResourceAbstract
{
    /**
     * The default attribute settings for the manager and connection
     * @var array
     */
    protected $_default_attributes = array(
      'manager'    => array(),
      'connection' => array()
    );

    /**
     * Creates and configures the Doctrine connection
     *
     * @return Doctrine_Connection
     */
    public function init()
    {
        $this->getBootstrap()
             ->getApplication()
             ->getAutoloader()
             ->pushAutoloader(array('Doctrine_Core', 'autoload'))
             ->pushAutoloader(array('Doctrine_Core', 'modelsAutoload'));

        $options = $this->getOptions();

        $manager = Doctrine_Manager::getInstance();

        if (array_key_exists('manager', $options)) {
            $this->_setDoctrineAttributes($manager, $options['manager']);
        }

        Doctrine_Core::loadModels($options['models_path']);

        $conn_opts = array();

        if (array_key_exists('connections', $options)) {
            $count = count($options['connections']);
            if (1 === $count) {
                $conn_opts = current($options['connections']);
            } else if ($count > 1) {
                $connections = array();
                foreach ($options['connections'] as $name => $opts) {
                    // crappy hack until Zend_Config_Yaml is better
                    $dsn = str_replace(array('"', "'"), '', $opts['dsn']);
                    unset($opts['dsn']);
                    $conn = Doctrine_Manager::connection($dsn, $name);
                    $this->_setDoctrineAttributes($conn, $opts);
                    $connections[$name] = $conn;
                }
                return $connections;
            }
        }

        $dsn = isset($conn_opts['dsn']) ? $conn_opts['dsn'] : $options['dsn'];
        // crappy hack until Zend_Config_Yaml is better
        $dsn = str_replace(array('"', "'"), '', $dsn);
        $connection = Doctrine_Manager::connection($dsn);
        $this->_setDoctrineAttributes($connection, $conn_opts);
        return $connection;
    }

    /**
     * Sets the attributes for a given Doctrine object
     *
     * @param Doctrine_Manager|Doctrine_Connection $obj
     * @param array $attributes
     */
    private function _setDoctrineAttributes($obj, array $attributes)
    {
        if (! $obj instanceof Doctrine_Manager &&
            ! $obj instanceof Doctrine_Connection) {
            throw new InvalidArgumentException(
                'Expected Doctrine_Manager or Doctrine_Connection object'
            );
        }

        foreach ($attributes as $attr => $value) {
            $attr_const = 'Doctrine_Core::ATTR_' . strtoupper($attr);
            $attr_id    = @constant($attr_const);
            if (null === $attr_id) {
                throw new Zend_Application_Resource_Exception(
                    "Invalid Doctrine constant: $attr_const"
                );
            }

            if (preg_match('/^' . $attr . '_[a-z][a-z_]*$/i', $value)) {
                $attr_val_const = 'Doctrine_Core::' . strtoupper($value);
                $attr_val       = @constant($attr_val_const);
                if (null === $attr_val) {
                    throw new Zend_Application_Resource_Exception(
                        "Invalid Doctrine constant: $attr_val_const"
                    );
                }
            }

            $obj->setAttribute($attr_id, $attr_val);
        }
    }
}

