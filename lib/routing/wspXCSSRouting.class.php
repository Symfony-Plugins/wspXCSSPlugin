<?php
/**
 * @author        Toni Uebernickel <toni@uebernickel.info>
 * @link          http://toni.uebernickel.info/
 *
 * @package       wspXCSSPlugin
 * @subpackage    routing.lib
 * @version       $Id$
 * @link          $HeadURL$
 */

class wspXCSSRouting
{
  /**
   * Listens to the routing.load_configuration event.
   *
   * @param sfEvent An sfEvent instance
   */
  static public function listenToRoutingLoadConfigurationEvent(sfEvent $event)
  {
    $r = $event->getSubject();
    $r->prependRoute('wsp_xcss_process', new sfRoute('/xcss/:file', array('module' => 'xcss', 'action' => 'process'), array('file' => '\w.+')));
  }
}