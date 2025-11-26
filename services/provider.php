<?php
/**
 * @package       WT AmoCRM - RadicalMart
 * @author     Sergey Tolkachyov
 * @copyright   Copyright (C) Sergey Tolkachyov, 2025. All rights reserved.
 * @version     1.0.0
 * @license     GNU General Public License version 3 or later. Only for *.php files!
 * @link       https://web-tolk.ru
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\RadicalMart\Wtamocrmradicalmart\Extension\Wtamocrmradicalmart;

return new class implements ServiceProviderInterface {

    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @since   1.0.0
     */
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = PluginHelper::getPlugin('radicalmart', 'wtamocrmradicalmart');
                $subject = $container->get(DispatcherInterface::class);

                $plugin = new Wtamocrmradicalmart($subject, (array)$plugin);
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase(Factory::getContainer()->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};