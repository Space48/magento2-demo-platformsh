<?php

namespace Platformsh\Magento;

class Platformsh
{
    const MAGIC_ROUTE = '{default}';
    
    const PREFIX_SECURE = 'https://';
    const PREFIX_UNSECURE = 'http://';
    
    const GIT_MASTER_BRANCH = 'master';
    
    const MAGENTO_PRODUCTION_MODE = 'production';
    const MAGENTO_DEVELOPER_MODE = 'developer';
    
    protected $debugMode = false;
    
    protected $magentoRootDir = 'magento';
    
    protected $platformReadWriteDirs = ['generated/code', 'generated/metadata', 'app/etc'];
    
    protected $serviceDatabase = 'mysql';
    protected $serviceRedis = 'redis';
    
    protected $urls = ['unsecure' => [], 'secure' => []];
    
    protected $defaultCurrency = 'USD';
    
    protected $dbHost;
    protected $dbName;
    protected $dbUser;
    protected $dbPassword;
    
    protected $adminUsername;
    protected $adminFirstname;
    protected $adminLastname;
    protected $adminEmail;
    protected $adminPassword;
    protected $adminUrl;
    
    protected $redisHost;
    protected $redisScheme;
    protected $redisPort;
    
    protected $isMasterBranch = null;
    protected $desiredApplicationMode;
    
    /**
     * Parse Platform.sh routes to more readable format.
     */
    public function initRoutes()
    {
        $this->log('Initializing routes.');
        
        $routes = $this->getRoutes();
        
        foreach($routes as $key => $val) {
            if ($val['type'] !== 'upstream') {
                continue;
            }
            
            $urlParts = parse_url($val['original_url']);
            $originalUrl = str_replace(self::MAGIC_ROUTE, '', $urlParts['host']);
            
            if(strpos($key, self::PREFIX_UNSECURE) === 0) {
                $this->urls['unsecure'][$originalUrl] = $key;
                continue;
            }
            
            if(strpos($key, self::PREFIX_SECURE) === 0) {
                $this->urls['secure'][$originalUrl] = $key;
                continue;
            }
        }
        
        if (!count($this->urls['secure'])) {
            $this->urls['secure'] = $this->urls['unsecure'];
        }
        
        $this->log(sprintf('Routes: %s', var_export($this->urls, true)));
    }
    
    /**
     * Build application: clear temp directory and move writable directories content to temp.
     *
     * @throws \RuntimeException
     */
    public function build()
    {
        $this->log('Start build.');
        
        $this->clearTemp();
        
        $this->compile();
        
        $this->log('Copying read/write directories to temp directory.');
        
        foreach ($this->platformReadWriteDirs as $dir) {
            $magentoDir = $this->getMagentoFilePath($dir);
            
            $this->execute(sprintf('mkdir -p ./init/%s', $dir));
            $this->log(sprintf('Copying %s to ./init/%s', $magentoDir, $dir));
            $this->execute(sprintf('/bin/bash -c "shopt -s dotglob; cp -R %s/* ./init/%s/"', $magentoDir, $dir));
            $this->execute(sprintf('rm -rf %s', $magentoDir));
            $this->execute(sprintf('mkdir %s', $magentoDir));
        }
    }
    
    /**
     * Compile the generated files.
     *
     * @throws \RuntimeException
     */
    public function compile()
    {
        $this->log('Enable all modules.');
        
        $this->executeMagentoCommand('module:enable', ['all']);
        
        $this->log('Compiling generated files.');
    
        $this->executeMagentoCommand('setup:di:compile');
    }
    
    /**
     * Deploy application: copy writable directories back, install or update Magento data.
     *
     * @throws \RuntimeException
     */
    public function deploy()
    {
        $this->log('Start deploy.');
        
        $this->_init();
        
        $this->log('Copying read/write directories back.');
        
        foreach ($this->platformReadWriteDirs as $dir) {
            $magentoDir = $this->getMagentoFilePath($dir);
            
            $this->execute(sprintf('mkdir -p %s', $magentoDir));
            $this->log(sprintf('Copying back ./init/%s to %s', $dir, $magentoDir));
            $this->execute(sprintf('/bin/bash -c "shopt -s dotglob; cp -R ./init/%s/* %s/ || true"', $dir, $magentoDir));
            $this->log(sprintf('Copied directory: %s', $magentoDir));
        }
        
        if (!$this->isMagentoInstalled()) {
            $this->installMagento();
        } else {
            $this->updateMagento();
        }
        $this->processMagentoMode();
        
        $this->executeMagentoCommand('setup:static-content:deploy');
    }
    
    /**
     * Prepare data needed to install Magento
     */
    protected function _init()
    {
        $this->log('Preparing environment specific data.');
        
        $this->initRoutes();
        
        $relationships = $this->getRelationships();
        $var = $this->getVariables();
        
        $this->dbHost = $relationships[$this->serviceDatabase][0]['host'];
        $this->dbName = $relationships[$this->serviceDatabase][0]['path'];
        $this->dbUser = $relationships[$this->serviceDatabase][0]['username'];
        $this->dbPassword = $relationships[$this->serviceDatabase][0]['password'];
        
        $this->adminUsername = isset($var['ADMIN_USERNAME']) ? $var['ADMIN_USERNAME'] : 'admin';
        $this->adminFirstname = isset($var['ADMIN_FIRSTNAME']) ? $var['ADMIN_FIRSTNAME'] : 'John';
        $this->adminLastname = isset($var['ADMIN_LASTNAME']) ? $var['ADMIN_LASTNAME'] : 'Doe';
        $this->adminEmail = isset($var['ADMIN_EMAIL']) ? $var['ADMIN_EMAIL'] : 'john@example.com';
        $this->adminPassword = isset($var['ADMIN_PASSWORD']) ? $var['ADMIN_PASSWORD'] : 'admin12';
        $this->adminUrl = isset($var['ADMIN_URL']) ? $var['ADMIN_URL'] : 'admin';
        
        $this->desiredApplicationMode = isset($var['APPLICATION_MODE']) ? $var['APPLICATION_MODE'] : false;
        $this->desiredApplicationMode =
            in_array($this->desiredApplicationMode, array(self::MAGENTO_DEVELOPER_MODE, self::MAGENTO_PRODUCTION_MODE), true)
                ? $this->desiredApplicationMode
                : false;
        
        $this->redisHost = $relationships[$this->serviceRedis][0]['host'];
        $this->redisScheme = $relationships[$this->serviceRedis][0]['scheme'];
        $this->redisPort = $relationships[$this->serviceRedis][0]['port'];
    }
    
    /**
     * Get routes information from Platform.sh environment variable.
     *
     * @return mixed
     */
    protected function getRoutes()
    {
        return json_decode(base64_decode($_ENV['PLATFORM_ROUTES']), true);
    }
    
    /**
     * Get relationships information from Platform.sh environment variable.
     *
     * @return mixed
     */
    protected function getRelationships()
    {
        return json_decode(base64_decode($_ENV['PLATFORM_RELATIONSHIPS']), true);
    }
    
    /**
     * Get custom variables from Platform.sh environment variable.
     *
     * @return mixed
     */
    protected function getVariables()
    {
        return json_decode(base64_decode($_ENV['PLATFORM_VARIABLES']), true);
    }
    
    /**
     * Run Magento installation
     *
     * @throws \RuntimeException
     */
    protected function installMagento()
    {
        $this->log('Installing Magento.');
        
        $urlUnsecure = $this->urls['unsecure'][''];
        $urlSecure = $this->urls['secure'][''];
        
        $installArgs = [
            'session-save' => 'db',
            'cleanup-database',
            'currency' => $this->defaultCurrency,
            'base-url' => $urlUnsecure,
            'base-url-secure' => $urlSecure,
            'use-rewrites' => '1',
            'language' => 'en_US',
            'timezone' => 'America/Los_Angeles',
            'db-host' => $this->dbHost,
            'db-name' => $this->dbName,
            'db-user' => $this->dbUser,
            'backend-frontname' => $this->adminUrl,
            'admin-user' => $this->adminUsername,
            'admin-firstname' => $this->adminFirstname,
            'admin-lastname' => $this->adminLastname,
            'admin-email' => $this->adminEmail,
            'admin-password' => $this->adminPassword
        ];
    
        if (strlen($this->dbPassword) > 0) {
            $installArgs['db-password'] = $this->dbPassword;
        }
    
        $this->executeMagentoCommand('setup:install', $installArgs);
    }
    
    /**
     * Update Magento configuration
     *
     * @throws \RuntimeException
     */
    protected function updateMagento()
    {
        $this->log('Updating Magento');
        
        $this->updateConfiguration();
        $this->updateAdminCredentials();
        $this->updateUrls();
        $this->executeMagentoCommand('setup:upgrade', ['keep-generated']);
        $this->executeMagentoCommand('cache:flush');
    }
    
    /**
     * Update admin credentials
     */
    protected function updateAdminCredentials()
    {
        $this->log('Updating admin credentials.');
        
        $this->executeDbQuery("update admin_user set firstname = '$this->adminFirstname', lastname = '$this->adminLastname', email = '$this->adminEmail', username = '$this->adminUsername', password='{$this->generatePassword($this->adminPassword)}' where user_id = '1';");
    }
    
    /**
     * Update secure and unsecure URLs
     */
    protected function updateUrls()
    {
        $this->log('Updating secure and unsecure URLs.');
        
        foreach ($this->urls as $urlType => $urls) {
            foreach ($urls as $route => $url) {
                $prefix = 'unsecure' === $urlType ? self::PREFIX_UNSECURE : self::PREFIX_SECURE;
                if (!strlen($route)) {
                    $this->executeDbQuery("update core_config_data set value = '$url' where path = 'web/$urlType/base_url' and scope_id = '0';");
                    continue;
                }
                $likeKey = $prefix . $route . '%';
                $likeKeyParsed = $prefix . str_replace('.', '---', $route) . '%';
                $this->executeDbQuery("update core_config_data set value = '$url' where path = 'web/$urlType/base_url' and (value like '$likeKey' or value like '$likeKeyParsed');");
            }
        }
    }
    
    /**
     * Clear content of temp directory
     */
    protected function clearTemp()
    {
        $this->log('Clearing temporary directory.');
        
        $this->execute('rm -rf ../init/*');
    }
    
    /**
     * Update env.php file content
     */
    protected function updateConfiguration()
    {
        $this->log('Updating env.php with new configuration from environment.');
        
        $configFileName = $this->getMagentoFilePath('app/etc/env.php');
        
        $config = include $configFileName;
        
        $config['db']['connection']['default']['username'] = $this->dbUser;
        $config['db']['connection']['default']['host'] = $this->dbHost;
        $config['db']['connection']['default']['dbname'] = $this->dbName;
        $config['db']['connection']['default']['password'] = $this->dbPassword;
        
        $config['db']['connection']['indexer']['username'] = $this->dbUser;
        $config['db']['connection']['indexer']['host'] = $this->dbHost;
        $config['db']['connection']['indexer']['dbname'] = $this->dbName;
        $config['db']['connection']['indexer']['password'] = $this->dbPassword;
        
        if (
            isset($config['cache']['frontend']['default']['backend']) &&
            isset($config['cache']['frontend']['default']['backend_options']) &&
            'Cm_Cache_Backend_Redis' == $config['cache']['frontend']['default']['backend']
        ) {
            $this->log('Updating env.php Redis cache configuration.');
            
            $config['cache']['frontend']['default']['backend_options']['server'] = $this->redisHost;
            $config['cache']['frontend']['default']['backend_options']['port'] = $this->redisPort;
        }
        
        if (
            isset($config['cache']['frontend']['page_cache']['backend']) &&
            isset($config['cache']['frontend']['page_cache']['backend_options']) &&
            'Cm_Cache_Backend_Redis' == $config['cache']['frontend']['page_cache']['backend']
        ) {
            $this->log('Updating env.php Redis page cache configuration.');
            
            $config['cache']['frontend']['page_cache']['backend_options']['server'] = $this->redisHost;
            $config['cache']['frontend']['page_cache']['backend_options']['port'] = $this->redisPort;
        }
        $config['backend']['frontName'] = $this->adminUrl;
        
        $updatedConfig = '<?php'  . "\n" . 'return ' . var_export($config, true) . ';';
        
        file_put_contents($configFileName, $updatedConfig);
    }
    
    protected function log($message)
    {
        echo sprintf('[%s] %s', date('Y-m-d H:i:s'), $message) . PHP_EOL;
    }
    
    /**
     * Run the ./bin/magento command with optional arguments.
     *
     * @param       $command
     * @param array $args
     * @throws \RuntimeException
     */
    private function executeMagentoCommand($command, array $args = [])
    {
        $cliFlags = [];
        
        /*
         * Depending on whether it's a --key=value or a --value format the argument for passing to bin/magento.
         */
        foreach ($args as $pairKey => $pairValue) {
            $isArgWithValue = is_string($pairKey);
            
            if ($isArgWithValue) {
                $cliFlags[] = sprintf('--%s=%s', $pairKey, $pairValue);
            } else {
                $cliFlags[] = sprintf('--%s', $pairValue);
            }
        }
    
        $cliFlagsString = implode(' ', $cliFlags);
        
        $this->log(sprintf('Running bin/magento %s %s', $command, $cliFlagsString));
        
        $this->execute(
            implode(' ', [
                $this->getMagentoFilePath('bin/magento'), $command, $cliFlagsString
            ])
        );
    }
    
    protected function execute($command)
    {
        if ($this->debugMode) {
            $this->log('Command:'.$command);
        }
        
        exec(
            $command,
            $output,
            $status
        );
        
        if ($this->debugMode) {
            $this->log('Status:'.var_export($status, true));
            $this->log('Output:'.var_export($output, true));
        }
        
        if ($status != 0) {
            throw new \RuntimeException("Command $command returned code $status", $status);
        }
        
        return $output;
    }
    
    
    /**
     * Generates admin password using default Magento settings
     */
    protected function generatePassword($password)
    {
        $saltLenght = 32;
        $charsLowers = 'abcdefghijklmnopqrstuvwxyz';
        $charsUppers = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charsDigits = '0123456789';
        $randomStr = '';
        $chars = $charsLowers . $charsUppers . $charsDigits;
        
        // use openssl lib
        for ($i = 0, $lc = strlen($chars) - 1; $i < $saltLenght; $i++) {
            $bytes = openssl_random_pseudo_bytes(PHP_INT_SIZE);
            $hex = bin2hex($bytes); // hex() doubles the length of the string
            $rand = abs(hexdec($hex) % $lc); // random integer from 0 to $lc
            $randomStr .= $chars[$rand]; // random character in $chars
        }
        $salt = $randomStr;
        $version = 1;
        $hash = hash('sha256', $salt . $password);
        
        return implode(
            ':',
            [
                $hash,
                $salt,
                $version
            ]
        );
    }
    
    /**
     * If current deploy is about master branch
     *
     * @return boolean
     */
    protected function isMasterBranch()
    {
        if (is_null($this->isMasterBranch)) {
            if (isset($_ENV['PLATFORM_ENVIRONMENT']) && $_ENV['PLATFORM_ENVIRONMENT'] == self::GIT_MASTER_BRANCH) {
                $this->isMasterBranch = true;
            } else {
                $this->isMasterBranch = false;
            }
        }
        return $this->isMasterBranch;
    }
    
    /**
     * Executes database query.
     *
     * @param string $query
     * @return mixed
     * @throws \RuntimeException
     */
    protected function executeDbQuery($query)
    {
        $password = '' !== $this->dbPassword ? sprintf('-p%s', $this->dbPassword) : '';
    
        /**
         * Quick function to determine whether the string ends with a character.
         *
         * @link https://stackoverflow.com/a/834355/283844
         * @param $haystack
         * @param $needle
         * @return bool
         */
        $endsWith = function ($haystack, $needle) {
            $length = strlen($needle);
    
            return $length === 0 ||
                (substr($haystack, -$length) === $needle);
        };
        
        if (!$endsWith($query, ';')) {
            $query .= ';';
        }
        
        return $this->execute("mysql -u $this->dbUser -h $this->dbHost -e \"$query\" $password $this->dbName");
    }
    
    /**
     * Based on variable APPLICATION_MODE. Production mode by default
     *
     * @throws \RuntimeException
     */
    protected function processMagentoMode()
    {
        $mode = ($this->desiredApplicationMode) ? $this->desiredApplicationMode : self::MAGENTO_PRODUCTION_MODE;
        
        $this->log(sprintf('Setting application mode to: %s', $mode));
        $this->executeMagentoCommand('deploy:mode:set ' . $mode, ['skip-compliation']);
    }
    
    /**
     * Checks that Magento is installed.
     *
     * This follows the same logic as found in Magento\Framework\App\DeploymentConfig::isAvailable().
     *proces
     * @return bool
     */
    private function isMagentoInstalled()
    {
        $envFile = $this->getMagentoFilePath('app/etc/env.php');
        
        if (!file_exists($envFile)) {
            return false;
        }
        
        $envValues = require $envFile;
        
        return isset($envValues['install']['date']);
    }
    
    /**
     * Get a file path relative to the Magento root.
     *
     * @param $path
     * @return string
     */
    private function getMagentoFilePath($path)
    {
        return $this->magentoRootDir . '/' . $path;
    }
}
