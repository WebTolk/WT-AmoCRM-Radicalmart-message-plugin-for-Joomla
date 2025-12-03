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

namespace Joomla\Plugin\RadicalMart\Wtamocrmradicalmart\Extension;

use Exception;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\RadicalMart\Administrator\Helper\ParamsHelper as RadicalMartParamsHelper;
use Joomla\Component\RadicalMartExpress\Administrator\Helper\ParamsHelper as RadicalMartExpressParamsHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Webtolk\Amocrm\Amocrm;
use Webtolk\Amocrm\AmocrmClientException;
use function defined;

defined('_JEXEC') or die;

final class Wtamocrmradicalmart extends CMSPlugin implements SubscriberInterface
{

	use DatabaseAwareTrait;

	/**
	 * Enable on RadicalMart
	 *
	 * @var  bool
	 *
	 * @since  1.0.0
	 */
	public bool $radicalmart = true;
	/**
	 * Enable on RadicalMartExpress
	 *
	 * @var  bool
	 *
	 * @since  1.0.0
	 */
	public bool $radicalmart_express = true;
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    bool
	 *
	 * @since  1.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   1.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onRadicalMartSendMessage'        => 'sendDataToAmoCRM',
			'onRadicalMartPrepareForm'        => 'onGetOrderForm',
			'onRadicalMartExpressPrepareForm' => 'onGetOrderForm',
		];
	}

	/**
	 * Prepare RadicalMart & RadicalMart Express order form.
	 *
	 * @param   Form   $form  Order form object.
	 * @param   array  $data  Order  data.
	 *
	 * @since 2.0.0
	 */
	public function onGetOrderForm(Form $form, $data = [])
	{
		if ($form->getName() == 'com_radicalmart.order')
		{
			$form->loadFile(JPATH_PLUGINS . '/radicalmart/wtamocrmradicalmart/forms/wtamocrmradicalmart.xml');
		}
	}

	/**
	 * Method to send RadicalMart & RadicalMart Express messages.
	 *
	 * @param   string  $type  Message type.
	 * @param   mixed   $data  Message data.
	 *
	 * @throws /Exception
	 *
	 * @since  1.0.0
	 */
	public function sendDataToAmoCRM(string $type, $data = null)
	{
		// Check types
		if (!in_array($type, [
			'radicalmart.order.create',
			'radicalmart.order.change_status',
		]))
		{
			return;
		}

		$params = false;
		if (strpos($type, 'radicalmart.') !== false)
		{
			$params = RadicalMartParamsHelper::getComponentParams();
		}
		elseif (strpos($type, 'radicalmart_express.') !== false)
		{
			$params = RadicalMartExpressParamsHelper::getComponentParams();
		}

		if ($type == 'radicalmart.order.create')
		{
			$this->createLead($data, $params);
		}
		elseif ($type == 'radicalmart.order.change_status')
		{
			if (!empty($statuses = $this->params->get('statuses')) && in_array((int) $data->status->id, $statuses))
			{
				$this->updateLead($data, $params);
			}
		}
	}

	/**
	 * Create lead and contact in AmoCRM
	 * after order create in RadicalMart
	 *
	 * @param   mixed     $data    order data
	 * @param   Registry  $params  RadicalMart components params
	 *
	 *
	 * @throws AmocrmClientException
	 * @since 1.0.0
	 */
	private function createLead(mixed $data, Registry $params): void
	{
		if (empty($data) || !is_object($data))
		{
			return;
		}
		// Main order data
		$orderTotal = $data->total['final'] ?? 0;

		// Contact data
		$contacts  = $data->customer->contacts;
		$firstName = $contacts->get('first_name', '');
		$lastName  = $contacts->get('last_name', '');
		$email     = $contacts->get('email', '');
		$phone     = $contacts->get('phone', '');

		// Подготовка данных для сделки
		$lead_data = [
			'created_by'  => 0,
			'name'        => $data->title,
			'pipeline_id' => $this->params->get('pipeline_id'),
			'price'       => (int) $data->total['final'],
		];

		// Prepare contact name
		$fullName = $firstName . (!empty($lastName) ? ' ' . $lastName : '');
		$contact  = [
			'name' => $fullName,
		];

		if (!empty($firstName))
		{
			$contact['first_name'] = $firstName;
		}

		if (!empty($lastName))
		{
			$contact['last_name'] = $lastName;
		}

		// Phone
		if (!empty($phone))
		{
			$contact['custom_fields_values'][] = [
				'field_code' => 'PHONE',
				'values'     => [
					[
						'enum_code' => 'WORK',
						'value'     => $phone,
					],
				],
			];
		}

		// Email
		if (!empty($email))
		{
			$contact['custom_fields_values'][] = [
				'field_code' => 'EMAIL',
				'values'     => [
					[
						'enum_code' => 'WORK',
						'value'     => $email,
					],
				],
			];
		}

		$lead_data['_embedded']['contacts'][] = $contact;

		// Add lead tag
		if ($this->params->get('lead_tag_id', 0) > 0)
		{
			$lead_data['_embedded']['tags'][0]['id'] = (int) $this->params->get('lead_tag_id');
		}

		// Add UTM
		$lead_data = $this->checkUtms($lead_data);

		$leads[] = $lead_data;

		// Create lead
		$amocrm = new Amocrm();


		$result = $amocrm->leads()->createLeadsComplex($leads);
		$result = (array) $result;

		if (!isset($result['error_code']))
		{
			$lead_id = $result[0]->id;

			$this->saveRmOrderToAmocrmRelation((int) $data->id, (int) $lead_id);

			$notes = [];

			// Lead notes
			$notes[] = [
				'created_by' => 0,
				'note_type'  => 'common',
				'params'     => [
					'text' => Text::_('PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_ORDER_TOTAL_API_PREPEND') .
						$orderTotal .
						Text::_('PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_ORDER_TOTAL_API_APPEND') . PHP_EOL .
						Text::_('COM_RADICALMART_STATUS') . ': ' . $data->status->title . PHP_EOL,
				],
			];

			// Shipping & payment notes
			if ($shipping = $data->shipping)
			{
				$shippingInfo = Text::_('COM_RADICALMART_SHIPPING') . ': ';
				$shippingInfo .= ((!empty($shipping->order->title)) ? $shipping->order->title : $shipping->title) . PHP_EOL;

				if (!empty($shipping->notification))
				{
					foreach ($shipping->notification as $title => $text)
					{
						if (empty($text))
						{
							continue;
						}

						if (!is_numeric($title))
						{
							$shippingInfo .= Text::_($title) . ': ';
						}

						$shippingInfo .= $text . ' ';
					}
				}

				$notes[] = [
					'created_by' => 0,
					'note_type'  => 'common',
					'params'     => [
						'text' => $shippingInfo,
					],
				];
			}

			if (!empty($payment = $data->payment))
			{
				$payment_info = Text::_(
						'COM_RADICALMART_PAYMENT'
					) . ': ' . ((!empty($payment->order->title)) ? $payment->order->title : $payment->title) . PHP_EOL;

				if (!empty($payment->notification))
				{
					foreach ($payment->notification as $title => $text)
					{
						if (empty($text))
						{
							continue;
						}

						if (!is_numeric($title))
						{
							$payment_info .= Text::_($title) . ': ';
						}

						$payment_info .= $text . PHP_EOL;
					}
				}

				$notes[] = [
					'created_by' => 0,
					'note_type'  => 'common',
					'params'     => [
						'text' => $payment_info,
					],
				];
			}
			// Customer contacts
			if (!empty($contacts = $data->contacts))
			{
				$contacts_info = Text::_('COM_RADICALMART_CONTACTS') . PHP_EOL;
				foreach ($contacts as $key => $value)
				{
					if (!is_string($value) || empty(trim($value)))
					{
						continue;
					}

					if ($label = $params->get('fields_' . $key . '_label'))
					{
						$label = Text::_($label);
					}
					elseif ($this->getApplication()->getLanguage()->hasKey('COM_RADICALMART_' . $key))
					{
						$label = Text::_('COM_RADICALMART_' . $key);
					}
					else
					{
						$label = $key;
					}

					$contacts_info .= $label . ': ' . $value . PHP_EOL;
				}

				$notes[] = [
					'created_by' => 0,
					'note_type'  => 'common',
					'params'     => [
						'text' => $contacts_info,
					],
				];
			}

			// Products list
			if (!empty($products = $data->products) && $this->params->get('amocrm_note_order_items', false))
			{
				$products_for_comment = Text::sprintf(
						'PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_ORDER_TOTAL_QUANTITY',
						$data->total['quantity']
					) . PHP_EOL;
				$products_for_comment .= Text::sprintf(
						'PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_ORDER_TOTAL_PRODUCTS',
						$data->total['products']
					) . PHP_EOL;

				foreach ($products as $product)
				{
					$products_for_comment .= Text::_('PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_ORDER_ITEMS_PRODUCT') .
						($product->title ?? '') . ($product->code ? ' (' . $product->code . ')' : '') . PHP_EOL;
					$products_for_comment .= Text::_('PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_ORDER_ITEMS_QUANTITY') .
						($product->order['quantity'] ?? '') . PHP_EOL;
					$products_for_comment .= Text::_('PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_ORDER_ITEMS_PRICE') .
						($product->price['final_string'] ?? '') . PHP_EOL;

					$products_for_comment .= PHP_EOL . ' ==== ' . PHP_EOL . PHP_EOL;
				}

				$notes[] = [
					'created_by' => 0,
					'note_type'  => 'common',
					'params'     => [
						'text' => Text::_(
								'PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_ORDER_ITEMS'
							) . PHP_EOL . $products_for_comment,
					],
				];
			}

			// Additional info
			if (!empty($data->note))
			{
				$notes[] = [
					'created_by' => 0,
					'note_type'  => 'common',
					'params'     => [
						'text' => Text::_('PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_ORDER_NOTE') . $data->note,
					],
				];
			}
			$link_to_order = (new Uri(Uri::root()))->setPath('/administrator/index.php');
			$link_to_order->setQuery([
				'option' => 'com_radicalmart',
				'view'   => 'order',
				'layout' => 'edit',
				'id'     => $data->id,
			]);

			$notes[] = [
				'created_by' => 0,
				'note_type'  => 'common',
				'params'     => [
					'text' => Text::sprintf(
						'PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_LINK_TO_ORDER',
						$link_to_order->toString()
					),
				],
			];

			$notes_result = $amocrm->notes()->addNotes('leads', $lead_id, $notes);

		}
	}

	/**
	 * Function checks the utm marks and set its to array fields
	 *
	 * @param   array  $lead_data  AmoCRM lead array data
	 *
	 * @return  array    AmoCRM lead data with UTMs
	 * @since   1.0.0
	 */
	private function checkUtms(&$lead_data): array
	{
		$utms = [
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_content',
			'utm_term',
			'fbclid',
			'yclid',
			'gclid',
			'gclientid',
			'from',
			'openstat_source',
			'openstat_ad',
			'openstat_campaign',
			'openstat_service',
			'referrer',
			'roistat',
			'_ym_counter',
			'_ym_uid',
			'utm_referrer',
		];
		foreach ($utms as $key)
		{
			$utm      = $this->getApplication()->getInput()->cookie->get($key, '', 'raw');
			$utm      = urldecode($utm);
			$utm_name = strtoupper($key);
			if (!empty($utm))
			{
				$utm_array                           = [
					'field_code' => strtoupper($utm_name),
					'values'     => [
						[
							'value' => $utm,
						],
					],
				];
				$lead_data["custom_fields_values"][] = $utm_array;
			}
		}

		return $lead_data;
	}

	/**
	 * @param   int  $order_id  RadicalMart order ID
	 * @param   int  $lead_id   AmoCRM lead ID
	 *
	 *
	 * @since 1.0.0
	 */
	private function saveRmOrderToAmocrmRelation(int $order_id, int $lead_id): void
	{
		try
		{
			$db    = $this->getDatabase();
			$query = $db->createQuery();
			$query->insert($db->quoteName('#__plg_radicalmart_wtamocrmradicalmart'))
				->columns($db->quoteName(['radicalmart_order_id', 'amocrm_lead_id']))
				->values(implode(', ', [':orderid', ':amoleadid']))
				->bind(':orderid', $order_id, ParameterType::INTEGER)
				->bind(':amoleadid', $lead_id, ParameterType::INTEGER);
			$db->setQuery($query);
			$db->execute();
		}
		catch (Exception $e)
		{
			$this->saveToLog(
				'Error to save RadicalMart order to AmoCRM lead relation in ' . __METHOD__ . '. ' . $e->getMessage(),
				Log::ERROR
			);
		}
	}

	/**
	 * Function for to log plugin errors in plg_radicalmart_wtamocrmradicalmart.log.php in
	 * Joomla log path. Default Log category plg_radicalmart_wtamocrmradicalmart
	 *
	 * @param   string      $data      error message
	 * @param   Log::LEVEL  $priority  Joomla Log priority
	 *
	 * @return  void
	 * @since   1.3.0
	 */
	private function saveToLog(string $data, $priority): void
	{
		Log::addLogger(
			[
				// Sets file name
				'text_file' => 'plg_radicalmart_wtamocrmradicalmart.log.php',
			],
			// Sets all but DEBUG log level messages to be sent to the file
			Log::ALL & ~Log::DEBUG,
			['plg_radicalmart_wtamocrmradicalmart']
		);
		$priority = $priority ?? Log::INFO;
		Log::add($data, $priority, 'lib_webtolk_amo_crm');
	}

	/**
	 * Update AmoCRM lead after order status change
	 *
	 * @param   mixed     $data
	 * @param   Registry  $params
	 *
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	private function updateLead(mixed $data, Registry $params): bool
	{
		if (empty($data) || !is_object($data))
		{
			return false;
		}

		if ($lead_id = $this->getAmocrmLeadId((int) $data->id))
		{
			$notes[] = [
				'created_by' => 0,
				'note_type'  => 'common',
				'params'     => [
					'text' => Text::sprintf(
						'PLG_WTAMOCRMRADICALMART_AMOCRM_NOTE_UPDATE_LEAD_NOTE',
						$data->number,
						$data->status->title
					),
				],
			];

			$amocrm = new Amocrm();
			try
			{
				$notes_result = $amocrm->notes()->addNotes('leads', $lead_id, $notes);

				return true;
			}
			catch (AmocrmClientException $e)
			{
				$this->saveToLog(
					'Error to update AmoCRM lead by RadicalMart order id in ' . __METHOD__ . '. ' . $e->getMessage(),
					Log::ERROR
				);

				return false;
			}
		}

		return false;
	}

	/**
	 * @param   int  $id  RaicalMart order ID
	 *
	 * @return int AmoCRM lead ID
	 *
	 * @since 1.0.0
	 */
	private function getAmocrmLeadId(int $id): int
	{
		$db    = $this->getDatabase();
		$query = $db->createQuery();
		$query->select($db->quoteName('amocrm_lead_id'))
			->from($db->quoteName('#__plg_radicalmart_wtamocrmradicalmart'))
			->where($db->quoteName('radicalmart_order_id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		$db->setQuery($query);

		return (int) $db->loadResult();
	}
}