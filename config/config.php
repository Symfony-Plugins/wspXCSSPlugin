<?php

if (sfConfig::get('app_wsp_xcss_plugin_routes_register', true) && in_array('xcss', sfConfig::get('sf_enabled_modules', array())))
{
  $this->dispatcher->connect('routing.load_configuration', array('wspXCSSRouting', 'listenToRoutingLoadConfigurationEvent'));
}