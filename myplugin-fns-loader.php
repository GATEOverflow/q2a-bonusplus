<?php

class BonusPlugin_FNSLoader
{
    public function plugins_loaded()
    {
        if (function_exists('pupi_fns')) {
			require_once __DIR__ . '/myplugin-fns-extension.php';
            pupi_fns()->getExtensionsManager()->register(new BonusPlus_FNSExtension());
        }
    }
}