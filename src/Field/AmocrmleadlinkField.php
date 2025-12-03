<?php
/**
 * @package       WT AmoCRM - RadicalMart
 * @version       1.0.2
 * @Author        Sergey Tolkachyov
 * @copyright     Copyright (c) 2018 - 2025 Sergey Tolkachyov. All rights reserved.
 * @license       GNU/GPL3 http://www.gnu.org/licenses/gpl-3.0.html
 * @link       https://web-tolk.ru
 * @since         1.0.0
 */

namespace Joomla\Plugin\RadicalMart\Wtamocrmradicalmart\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;

use Joomla\CMS\HTML\HTMLHelper;

use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Uri\Uri;
use Webtolk\Amocrm\Amocrm;
use Webtolk\Amocrm\AmocrmClientException;

use function defined;

defined('_JEXEC') or die;

class AmocrmleadlinkField extends FormField
{

	protected $type = 'Amocrmleadlink';


	/**
	 * Method to get the field input markup for a spacer.
	 * The spacer does not have accept input.
	 *
	 * @return  string  The field input markup.
	 *
	 * @since   1.7.0
	 */
	protected function getInput()
	{

		$layoutData = $this->collectLayoutData();
		$html = '';
		if(array_key_exists('amocrm_lead_id', $layoutData) && !empty($amocrm_lead_id = $layoutData['amocrm_lead_id'])) {
			$amocrm = new Amocrm();
			$amocrm->getRequest()->canDoRequest();
			$amocrm_domain = $amocrm->getRequest()->getAmoCRMHost()->toString();
			if(!empty($amocrm_domain)) {
				$link = (new Uri($amocrm_domain))->setPath('/leads/detail/'.$amocrm_lead_id);
				$link->setScheme('https');
				$html = HTMLHelper::link($link->toString(), Text::sprintf('PLG_WTAMOCRMRADICALMART_FIELD_AMOCRMLEADLINK', $amocrm_lead_id), ['target' => '_blank']);
			}

		}

		return $html;
	}

	/**
	 * Method to get the field title.
	 *
	 * @return  string  The field title.
	 *
	 * @since   1.7.0
	 */
	protected function getTitle()
	{
		return $this->getLabel();
	}

	/**
	 * @return  string  The field label markup.
	 *
	 * @since   1.7.0
	 */
	protected function getLabel()
	{
		return '';
	}

	/**
	 * Method to get the data to be passed to the layout for rendering.
	 *
	 * @return  array
	 *
	 * @throws  AmocrmClientException
	 * @since   1.3.0
	 */
	protected function getLayoutData(): array
	{
		$layoutData = parent::getLayoutData();

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$rm_order_id = Factory::getApplication()->getInput()->getInt('id');
		$query = $db->createQuery();
		$query->select($db->quoteName('amocrm_lead_id'))
			->from($db->quoteName('#__plg_radicalmart_wtamocrmradicalmart'))
			->where($db->quoteName('radicalmart_order_id') . ' = ' . $db->quote($rm_order_id));
		$amocrm_lead_id = $db->setQuery($query)->loadResult();
		$layoutData['amocrm_lead_id'] = $amocrm_lead_id;

		return $layoutData;
	}
}