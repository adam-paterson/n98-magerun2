<?php

namespace N98\Util\Console\Helper;

use N98\Util\String;
use Symfony\Component\Console\Helper\Helper as AbstractHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputAwareInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class MagentoHelper extends AbstractHelper
{
    /**
     * @var string
     */
    protected $_magentoRootFolder = null;

    /**
     * @var string
     */
    protected $_magentoMajorVersion = \N98\Magento\Application::MAGENTO_MAJOR_VERSION_1;

    /**
     * @var bool
     */
    protected $_magentoEnterprise = false;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var array
     */
    protected $baseConfig;

    /**
     * Returns the canonical name of this helper.
     *
     * @return string The canonical name
     *
     * @api
     */
    public function getName()
    {
        return 'magento';
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function __construct(InputInterface $input = null, OutputInterface $output = null)
    {
        if (null === $input) {
            $input = new ArgvInput();
        }

        if (null === $output) {
            $output = new ConsoleOutput();
        }

        $this->input = $input;
        $this->output = $output;
    }

    /**
     * Start Magento detection
     *
     * @param string $folder
     * @param array $subFolders Sub-folders to check
     */
    public function detect($folder, $subFolders = array())
    {
        $folders = $this->splitPathFolders($folder);
        $folders = $this->checkMagerunFile($folders);
        $folders = $this->checkModman($folders);
        $folders = array_merge($folders, $subFolders);

        foreach (array_reverse($folders) as $searchFolder) {
            if (!is_dir($searchFolder) || !is_readable($searchFolder)) {
                continue;
            }
            if ($this->_search($searchFolder)) {
                break;
            }
        }
    }


    /**
     * @return string
     */
    public function getRootFolder()
    {
        return $this->_magentoRootFolder;
    }

    public function getEdition()
    {
        return $this->_magentoMajorVersion;
    }

    /**
     * @return bool
     */
    public function isEnterpriseEdition()
    {
        return $this->_magentoEnterprise;
    }

    /**
     * @return mixed
     */
    public function getMajorVersion()
    {
        return $this->_magentoMajorVersion;
    }

    /**
     * @param string $folder
     *
     * @return array
     */
    protected function splitPathFolders($folder)
    {
        $folders = array();

        $folderParts = explode(DIRECTORY_SEPARATOR, $folder);
        foreach ($folderParts as $key => $part) {
            $explodedFolder = implode(DIRECTORY_SEPARATOR, array_slice($folderParts, 0, $key + 1));
            if ($explodedFolder !== '') {
                $folders[] = $explodedFolder;
            }
        }

        return $folders;
    }

    /**
     * Check for modman file and .basedir
     *
     * @param array $folders
     *
     * @return array
     */
    protected function checkModman($folders)
    {
        foreach (array_reverse($folders) as $searchFolder) {
            if (!is_readable($searchFolder)) {
                if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
                    $this->output->writeln('<debug>Folder <info>' . $searchFolder . '</info> is not readable. Skip.</debug>');
                }
                continue;
            }

            $finder = Finder::create();
            $finder
                ->files()
                ->ignoreUnreadableDirs(true)
                ->depth(0)
                ->followLinks()
                ->ignoreDotFiles(false)
                ->name('.basedir')
                ->in($searchFolder);

            $count = $finder->count();
            if ($count > 0) {
                $baseFolderContent = trim(file_get_contents($searchFolder . DIRECTORY_SEPARATOR . '.basedir'));
                if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
                    $this->output->writeln('<debug>Found modman .basedir file with content <info>' . $baseFolderContent . '</info></debug>');
                }

                if (!empty($baseFolderContent)) {
                    $modmanBaseFolder = $searchFolder
                                      . DIRECTORY_SEPARATOR
                                      . '..'
                                      . DIRECTORY_SEPARATOR
                                      . $baseFolderContent;
                    array_push($folders, $modmanBaseFolder);
                }
            }
        }

        return $folders;
    }

    /**
     * Check for .n98-magerun file
     *
     * @param array $folders
     *
     * @return array
     */
    protected function checkMagerunFile($folders)
    {
        foreach (array_reverse($folders) as $searchFolder) {
            if (!is_readable($searchFolder)) {
                if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
                    $this->output->writeln('<debug>Folder <info>' . $searchFolder . '</info> is not readable. Skip.</debug>');
                }
                continue;
            }
            $finder = Finder::create();
            $finder
                ->files()
                ->ignoreUnreadableDirs(true)
                ->depth(0)
                ->followLinks()
                ->ignoreDotFiles(false)
                ->name('.n98-magerun')
                ->in($searchFolder);

            $count = $finder->count();
            if ($count > 0) {
                $magerunFileContent = trim(file_get_contents($searchFolder . DIRECTORY_SEPARATOR . '.n98-magerun'));
                if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
                    $this->output->writeln('<debug>Found .n98-magerun file with content <info>' . $magerunFileContent . '</info></debug>');
                }

                $modmanBaseFolder = $searchFolder
                    . DIRECTORY_SEPARATOR
                    . $magerunFileContent;
                array_push($folders, $modmanBaseFolder);
            }
        }

        return $folders;
    }

    /**
     * @param string $searchFolder
     *
     * @return bool
     */
    protected function _search($searchFolder)
    {
        if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
            $this->output->writeln('<debug>Search for Magento in folder <info>' . $searchFolder . '</info></debug>');
        }

        if (!is_dir($searchFolder . '/app')) {
            return false;
        }

        $finder = Finder::create();
        $finder
            ->ignoreUnreadableDirs(true)
            ->depth(0)
            ->followLinks()
            ->name('Mage.php')
            ->name('bootstrap.php')
            ->name('autoload.php')
            ->in($searchFolder . '/app');

        if ($finder->count() > 0) {
            $files = iterator_to_array($finder, false);
            /* @var $file \SplFileInfo */

            if (count($files) == 2) {
                // Magento 2 has bootstrap.php and autoload.php in app folder
                $this->_magentoMajorVersion = \N98\Magento\Application::MAGENTO_MAJOR_VERSION_2;
            }

            $this->_magentoRootFolder = $searchFolder;

            if (is_callable(array('\Mage', 'getEdition'))) {
                $this->_magentoEnterprise = (\Mage::getEdition() == 'Enterprise');
            } else {
                $this->_magentoEnterprise = is_dir($this->_magentoRootFolder . '/app/code/core/Enterprise');
            }

            if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
                $this->output->writeln('<debug>Found Magento in folder <info>' . $this->_magentoRootFolder . '</info></debug>');
            }

            return true;
        }

        return false;
    }

    /**
     * @return array
     * @throws \ErrorException
     * @throws \Exception
     */
    public function getBaseConfig()
    {
        if (!$this->baseConfig) {
            $command = $this->getHelperSet()->getCommand();
            if ($command == null) {
                $application = new Application();
            } else {
                $application = $command->getApplication(); /* @var $application Application */
            }
            $application->detectMagento();

            $configFile = $application->getMagentoRootFolder() . '/app/etc/config.php';

            if (!is_readable($configFile)) {
                throw new \Exception('app/etc/config.php is not readable');
            }

            $config = @include $configFile;

            if (!is_array($config)) {
                throw new \ErrorException('app/etc/config.php is corrupted. Please check it.');
            }

            $this->baseConfig = $config;
        }

        return $this->baseConfig;
    }
}
