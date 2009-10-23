<?php
/**
 * @author        Toni Uebernickel <toni@uebernickel.info>
 * @link          http://toni.uebernickel.info/
 *
 * @package       wspXCSSPlugin
 * @subpackage    actions.xCSS.modules
 * @version       $Id$
 * @link          $HeadURL$
 */

/**
 * Currently only one file is supported.
 *
 * @version 0.1
 */
class processAction extends sfAction
{
  public function preExecute()
  {
    $this->setLayout(false);
    $this->createCacheDirectory();
    $this->getResponse()->setContentType('text/css');
  }

  /**
   * The xCSS processor itself.
   *
   * @param sfWebRequest $request
   *
   * @return string
   */
  public function execute($request)
  {
    // we do not care, whether this is a valid CSS file here
    $requestedCSSFile = $request->getParameter('file');
    $targetFile = $this->getOutputDirectory() . $requestedCSSFile;

    // because we cache the generated files, we checke for an existing one
    if (file_exists($targetFile) === false)
    {
      $config = $this->getConfiguration();

      $config['xCSS_files'] = array(
        $requestedCSSFile => $targetFile,
      );

      $xCSS = new xCSS($config);
      $xCSS->compile();
    }

    $this->xcss = file_get_contents($targetFile);
  }

  /**
   * Parses the given configuration from the app.yml into an xCSS config array.
   *
   * @param bool $force If set, loads the configuration again, otherwise returns cached config.
   *
   * @return array
   */
  protected function getConfiguration($force = false)
  {
    static $xCSSConfiguration = array();

    if ($force || empty($xCSSConfiguration))
    {
      $configPrefix = 'app_wsp_xcss_plugin_';
      foreach (sfConfig::getAll() as $entry => $value)
      {
        if (strstr($entry, $configPrefix) !== false)
        {
          $xCSSConfiguration[str_replace($configPrefix, '', $entry)] = $value;
        }
      }

      // set default config items, if not set by user
      $xCSSConfiguration = array_merge($this->getDefaultConfiguration(), $xCSSConfiguration);
    }

    return $xCSSConfiguration;
  }

  /**
   * Returns the default configuration for xCSS on this plugin.
   *
   * @return array
   */
  protected function getDefaultConfiguration()
  {
    return array(
      'path_to_css_dir' => sfConfig::get('sf_web_dir') . '/css/',
      'master_file' => false,
      'master_filename' => null,
      'reset_files' => null,
      'hook_files' => null,
      'construct_name' => 'self',
      'compress' => true,
      'debugmode' => false,
      'disable_xcss' => false,
      'minify_output' => true,
    );
  }

  /**
   * Returns the directory in which xCSS generated files will be put into.
   *
   * @return string
   */
  private function getOutputDirectory()
  {
    static $dir = '';

    if ($dir === '')
    {
      $dir = sfConfig::get('sf_cache_dir') . '/' . sfContext::getInstance()->getConfiguration()->getApplication() . '/' . sfContext::getInstance()->getConfiguration()->getEnvironment() . '/xcss/';
    }

    return $dir;
  }

  /**
   * Creates the cache directory for generated xCSS files.
   * It creates a directory 'xcss' in the cache/app/env/ folder, if this does not exist.
   *
   * @return bool
   */
  private function createCacheDirectory()
  {
    if (!file_exists($this->getOutputDirectory()))
    {
      $result = mkdir($this->getOutputDirectory());

      if ($result)
      {
        // make symfony cc work
        chmod($this->getOutputDirectory(), 0777);
      }

      return $result;
    }
    else
    {
      return true;
    }
  }
}