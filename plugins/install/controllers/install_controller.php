<?php
/**
 * Install Controller
 *
 * PHP version 5
 *
 * @category Controller
 * @package  Croogo
 * @version  1.0
 * @author   Fahad Ibnay Heylaal <contact@fahad19.com>
 * @license  http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link     http://www.croogo.org
 */
class InstallController extends InstallAppController {

/**
 * Controller name
 *
 * @var string
 * @access public
 */
    var $name = 'Install';

/**
 * No models required
 *
 * @var array
 * @access public
 */
    var $uses = null;

/**
 * No components required
 *
 * @var array
 * @access public
 */
    var $components = null;

/**
 * beforeFilter
 *
 * If the croogo bootstrap file exists - the app is already installed, don't do anything
 *
 * @return void
 * @access public
 */
    function beforeFilter() {
        parent::beforeFilter();

        $this->layout = 'install';
        App::import('Component', 'Session');
        $this->Session = new SessionComponent;
		if (file_exists(CONFIGS . 'croogo_bootstrap.php')) {
			$this->Session->setFlash('Already Installed');
			$this->redirect('/');
		}
    }

/**
 * Step 0: welcome
 *
 * A simple welcome message for the installer.
 *
 * @return void
 * @access public
 */
    function index() {
        $this->set('title_for_layout', __('Installation: Welcome', true));
    }

/**
 * Step 1: database
 *
 * Try to connect to the database and give a message if that's not possible so the user can check their
 * credentials or create the missing database
 * Create the database file and insert the submitted details
 *
 * @return void
 * @access public
 */
    function database() {
        $this->set('title_for_layout', __('Step 1: Database', true));
        if (empty($this->data)) {
			return;
		}
		if (!mysql_connect($this->data['Install']['host'], $this->data['Install']['login'], $this->data['Install']['password'])) {
			$this->Session->setFlash(__('Could not connect to database.', true));
			return;
		}
		if (!mysql_select_db($this->data['Install']['database'])) {
			$this->Session->setFlash(__('Could not select database.', true));
			return;
		}

		copy(CONFIGS.'database.php.install', CONFIGS.'database.php');

		App::import('Core', 'File');
		$file = new File(CONFIGS.'database.php', true);
		$content = $file->read();

		$content = str_replace('{default_host}', $this->data['Install']['host'], $content);
		$content = str_replace('{default_login}', $this->data['Install']['login'], $content);
		$content = str_replace('{default_password}', $this->data['Install']['password'], $content);
		$content = str_replace('{default_database}', $this->data['Install']['database'], $content);

		if($file->write($content) ) {
			return $this->redirect(array('action' => 'data'));
		} else {
			$this->Session->setFlash(__('Could not write database.php file.', true));
		}
    }

/**
 * Step 2: Run the initial sql scripts to create the db and seed it with data
 *
 * @return void
 * @access public
 */
    function data() {
        $this->set('title_for_layout', __('Step 2: Run SQL', true));
        if (isset($this->params['named']['run'])) {
            App::import('Core', 'File');
            App::import('Model', 'ConnectionManager');
            $db = ConnectionManager::getDataSource('default');

            if(!$db->isConnected()) {
                $this->Session->setFlash(__('Could not connect to database.', true));
            } else {
                $this->__executeSQLScript($db, CONFIGS.'sql'.DS.'croogo.sql');
                $this->__executeSQLScript($db, CONFIGS.'sql'.DS.'croogo_data.sql');

                $this->redirect(array('action' => 'finish'));
            }
        }
    }

/**
 * Step 3: finish
 *
 * Remind the user to delete 'install' plugin, move the bootstrap and settings.yml files into place
 *
 * @return void
 * @access public
 */
	function finish() {
		$this->set('title_for_layout', __('Installation completed successfully', true));
		if (isset($this->params['named']['delete'])) {
			App::import('Core', 'Folder');
			$this->folder = new Folder;
			if ($this->folder->delete(APP.'plugins'.DS.'install')) {
				$this->Session->setFlash(__('Installataion files deleted successfully.', true));
				$this->redirect('/');
			} else {
				$this->Session->setFlash(__('Could not delete installation files.', true));
			}
		}
		$this->_copyConfigFiles();
	}

/**
 * copyConfigFiles method
 *
 * By default, don't put files that are app specific in the repo.
 * Copy the croogo_bootstrap tempalte into place
 * Copy the settings.yml file into place
 * Copy the standard core.php file into place
 * 	give it a random salt and cipherSeed
 *
 * Update the admin users password if it's the same string as the value in the initial data dump
 *
 * @return bool
 * @access protected
 */
	function _copyConfigFiles() {
		copy(CONFIGS.'croogo_bootstrap.php.install', CONFIGS.'croogo_bootstrap.php');
		copy(CONFIGS.'settings.yml.install', CONFIGS.'settings.yml');

		copy(CAKE_CORE_INCLUDE_PATH.DS.'cake'.DS.'console'.DS.'templates'.DS.'skel'.DS.'config'.DS.'core.php', CONFIGS.'core.php');

		$File =& new File(CONFIGS . 'core.php');
		if (!class_exists('Security')) {
			require LIBS . 'security.php';
		}
		$salt = Security::generateAuthKey();
		$seed = mt_rand() . mt_rand();

		$contents = $File->read();
		$contents = preg_replace('/(?<=Configure::write\(\'Security.salt\', \')([^\' ]+)(?=\'\))/', $salt, $contents);
		$contents = preg_replace('/(?<=Configure::write\(\'Security.cipherSeed\', \')(\d+)(?=\'\))/', $seed, $contents);
		$contents = preg_replace('/\/\/(?=Configure::write\(\'Routing.admin\')/', '', $contents);

		if (!$File->write($contents)) {
			return false;
		}

		$User = ClassRegistry::init('User');
		$User->id = $User->field('id', array('password' => 'c054b152596745efa1d197b809fa7fc70ce586e5'));
		$User->saveField('password', Security::hash('password', null, $salt));
		return true;
	}

/**
 * Execute SQL file
 *
 * @link   http://cakebaker.42dh.com/2007/04/16/writing-an-installer-for-your-cakephp-application/
 * @param  object $db Database
 * @param  string $fileName sql file
 * @return void
 */
    function __executeSQLScript($db, $fileName) {
        $statements = file_get_contents($fileName);
        $statements = explode(';', $statements);

        foreach ($statements as $statement) {
            if (trim($statement) != '') {
                $db->query($statement);
            }
        }
    }
}
?>