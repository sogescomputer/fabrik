<?php
/**
 * Redirects the browser to paypal to perform payment
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.paypal
 * @copyright   Copyright (C) 2005-2016  Media A-Team, Inc. - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

use Joomla\Utilities\ArrayHelper;

// Require the abstract plugin class
require_once COM_FABRIK_FRONTEND . '/models/plugin-form.php';

/**
 * Redirects the browser to paypal to perform payment
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.paypal
 * @since       3.0
 */
class PlgFabrik_FormStripe extends PlgFabrik_Form
{
	/*
	 * J! Log
	 *
	 * @var  object
	 */
	private $log = null;

	/*
	 * Stripe charge object
	 */
	private $charge = null;

	/*
	 * Stripe customer object
	 */
	private $customer = null;

	/*
	 * Customer table name
	 */
	private $customerTableName = null;

	/**
	 * Attempt to run the Stripe payment, return false (abort save) if it fails
	 *
	 * @return    bool
	 */
	public function onBeforeStore()
	{
		$params     = $this->getParams();
		$formModel  = $this->getModel();
		$listModel  = $formModel->getListModel();
		$input      = $this->app->input;
		$this->data = $this->getProcessData();
		JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fabrik/tables');

		if (!$this->shouldProcess('stripe_conditon', null, $params))
		{
			return true;
		}

		$w      = new FabrikWorker;
		$userId = $this->user->get('id');

		$testMode = $params->get('stripe_test_mode', $input->get('stripe_testmode', false));

		if ($testMode)
		{
			$publicKey = trim($params->get('stripe_test_publishable_key', ''));
			$secretKey = trim($params->get('stripe_test_secret_key', ''));
		}
		else
		{
			$publicKey = trim($params->get('stripe_publishable_key', ''));
			$secretKey = trim($params->get('stripe_secret_key', ''));
		}

		$tokenId   = FArrayHelper::getValue($this->data, 'stripe_token_id', '');
		$tokenEmail = FArrayHelper::getValue($this->data, 'stripe_token_email', '');
		$tokenOpts = FArrayHelper::getValue($this->data, 'stripe_token_opts', '{}');
		$tokenOpts = json_decode($tokenOpts);

		$amount = $params->get('stripe_cost');
		$amount = $w->parseMessageForPlaceHolder($amount, $this->data);

		/**
		 * Adding eval option on cost field
		 * Useful if you use a cart system which will calculate on total shipping or tax fee and apply it. You can return it in the Cost field.
		 * Returning false will log an error and bang out with a runtime exception.
		 */

		if ($params->get('stripe_cost_eval_to_element', '0') === '1')
		{
			$amountKey = FabrikString::safeColNameToArrayKey($params->get('stripe_cost_element'));
			$amount    = FArrayHelper::getValue($this->data, $amountKey);
			$amount    = FArrayHelper::getValue($this->data, $amountKey . '_raw', $amount);

			if (is_array($amount))
			{
				$amount = array_shift($amount);
			}
		}
		else if ($params->get('stripe_cost_eval', 0) == 1)
		{
			$amount = @eval($amount);

			if ($amount === false)
			{
				$msgType   = 'fabrik.stripe.cost.eval';
				$msg       = new stdClass;
				$msg->data = $this->data;
				$msg->msg  = "Eval amount code returned false.";
				$msg       = json_encode($msg);
				$this->doLog($msgType, $msg);
				throw new RuntimeException(FText::_('PLG_FORM_STRIPE_COST_ELEMENT_ERROR'), 500);
			}
		}

		if (trim($amount) == '')
		{
			// Priority to raw data.
			$amountKey = FabrikString::safeColNameToArrayKey($params->get('stripe_cost_element'));
			$amount    = FArrayHelper::getValue($this->data, $amountKey);
			$amount    = FArrayHelper::getValue($this->data, $amountKey . '_raw', $amount);

			if (is_array($amount))
			{
				$amount = array_shift($amount);
			}
		}

		$costMultiplier = $params->get('stripe_currency_multiplier', '100');
		$amountMultiplied         = $amount * $costMultiplier;

		$item = $params->get('stripe_item');
		$item = $w->parseMessageForPlaceHolder($item, $this->data);

		if ($params->get('stripe_item_eval_to_element', '0') === '1')
		{
			$itemKey = FabrikString::safeColNameToArrayKey($params->get('stripe_item_element'));
			$item    = FArrayHelper::getValue($this->data, $itemKey);
			$item    = FArrayHelper::getValue($this->data, $itemKey . '_raw', $item);

			if (is_array($item))
			{
				$amount = array_shift($item);
			}
		}
		else if ($params->get('stripe_item_eval', 0) == 1)
		{
			$item = @eval($item);
		}

		$itemRaw = $item;

		if (trim($item) == '')
		{
			$itemRaw = FArrayHelper::getValue($this->data, FabrikString::safeColNameToArrayKey($params->get('paypal_item_element') . '_raw'));
			$item    = $this->data[FabrikString::safeColNameToArrayKey($params->get('stripe_item_element'))];

			if (is_array($item))
			{
				$item = array_shift($item);
			}

			if (is_array($itemRaw))
			{
				$itemRaw = array_shift($itemRaw);
			}
		}

		$currencyCode = $params->get('stripe_currency_code', 'USD');
		$currencyCode = $w->parseMessageForPlaceHolder($currencyCode, $this->data);
		$currencyCode = strtolower($currencyCode);

		$customerId = false;
		$customerTableName = $this->getCustomerTableName();
		$doCustomer = $customerTableName !== false && !empty($userId);

		if ($doCustomer)
		{
			$customerId = $this->getCustomerId($userId);
		}

		\Stripe\Stripe::setApiKey($secretKey);

		$logErrMsg = '';
		$logErrType = '';
		$chargeErrMsg = '';
		$customer = null;

		try
		{
			if ($doCustomer)
			{
				if (empty($customerId))
				{
					$this->customer = \Stripe\Customer::create(array(
						'source' => $tokenId,
						'email'  => $tokenEmail,
						'metadata' => array(
							'userid' => $userId
						)
					));

					$customerId = $this->customer->id;

					$this->updateCustomerId($userId, $customerId, $tokenOpts);
				}
				else
				{
					if ($params->get('stripe_customers_allow_update_cc', '0') === '1')
					{
						// if they used "update CC" button, we'll have a token
						if (!empty($tokenId))
						{
							$this->customer         = \Stripe\Customer::retrieve($customerId); // stored in your application
							$this->customer->source = $tokenId;
							$this->customer->save();

							$this->updateCustomerId($userId, $customerId, $tokenOpts);
						}
					}
				}

				$this->charge = \Stripe\Charge::create(array(
					"amount"      => $amountMultiplied,
					"currency"    => $currencyCode,
					"customer"    => $customerId,
					"description" => $item,
					"metadata"    => array(
						"listid" => (string) $listModel->getId(),
						"formid" => (string) $formModel->getId(),
						"rowid"  => (string) $this->data['rowid'],
						"userid" => (string) $userId
					)
				));
			}
			else
			{
				$this->charge = \Stripe\Charge::create(array(
					"amount"      => $amountMultiplied,
					"currency"    => $currencyCode,
					"source"      => $tokenId,
					"description" => $item,
					"metadata"    => array(
						"listid" => (string) $listModel->getId(),
						"formid" => (string) $formModel->getId(),
						"rowid"  => (string) $this->data['rowid'],
						"userid" => (string) $userId
					)
				));
			}
		}
		catch (\Stripe\Error\Card $e)
		{
			// Since it's a decline, \Stripe\Error\Card will be caught
			$body = $e->getJsonBody();
			$err  = $body['error'];
			$logErrMsg = json_encode($body);
			$logErrType = 'fabrik.form.stripe.charge.err.declined';
			$chargeErrMsg = FText::sprintf('PLG_FORM_STRIPE_ERROR_DECLINED', $err['message']);
		}
		catch (\Stripe\Error\RateLimit $e)
		{
			// Too many requests made to the API too quickly
			$logErrMsg = $e->getMessage();
			$logErrType = 'fabrik.form.stripe.charge.err.ratelimit';
			$chargeErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_RATE_LIMITED');
		}
		catch (\Stripe\Error\InvalidRequest $e)
		{
			// Invalid parameters were supplied to Stripe's API
			$logErrMsg = $e->getMessage();
			$logErrType = 'fabrik.form.stripe.charge.err.request';
			$chargeErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_INTERNAL');
		}
		catch (\Stripe\Error\Authentication $e)
		{
			// Authentication with Stripe's API failed
			// (maybe you changed API keys recently)
			$logErrMsg = $e->getMessage();
			$logErrType = 'fabrik.form.stripe.charge.err.authentication';
			$chargeErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_AUTHENTICATION');
		}
		catch (\Stripe\Error\ApiConnection $e)
		{
			// Network communication with Stripe failed
			$logErrMsg = $e->getMessage();
			$logErrType = 'fabrik.form.stripe.charge.err.connection';
			$chargeErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_NETWORK');
		}
		catch (\Stripe\Error\Base $e)
		{
			// Display a very generic error to the user, and maybe send
			// yourself an email
			$logErrMsg = $e->getMessage();
			$logErrType = 'fabrik.form.stripe.charge.err.base';
			$chargeErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_INTERNAL');
		}
		catch (Exception $e)
		{
			// Something else happened, completely unrelated to Stripe
			$logErrMsg = $e->getMessage();
			$logErrType = 'fabrik.form.stripe.charge.err.exception';
			$chargeErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_INTERNAL');
		}

		if (!empty($chargeErrMsg))
		{
			$formModel->setFormErrorMsg($chargeErrMsg);

			$opts = new stdClass;
			$opts->listid = $listModel->getId();
			$opts->formid = $formModel->getId();
			$opts->rowid = $this->data['rowid'];
			$opts->userid   = $userId;
			$opts->charge = $this->charge;
			$opts->customer = $customer;
			$opts->amount   = $amount;
			$opts->item     = $item;
			$msg       = new stdClass;
			$msg->opts  = $opts;
			$msg->data = $this->data;
			$msg->err  = $logErrMsg;
			$msg       = json_encode($msg);

			$this->doLog($logErrType, $msg);

			return false;
		}

		$chargeIdField = $this->getFieldName('stripe_charge_id_element', '');

		if (!empty($chargeIdField))
		{
			$formModel->updateFormData($chargeIdField, $this->charge->id, true, true);
		}

		$chargeEmailField = $this->getFieldName('stripe_charge_email_element', '');

		if (!empty($chargeEmailField))
		{
			$formModel->updateFormData($chargeEmailField, $tokenEmail, true, true);
		}

		$this->updateCustomerCustom($userId);

		$opts = new stdClass;
		$opts->listid = $listModel->getId();
		$opts->formid = $formModel->getId();
		$opts->rowid = $this->data['rowid'];
		$opts->userid   = $userId;
		$opts->charge = $this->charge;
		$opts->customer = $customer;
		$msgType   = 'fabrik.stripe.charge.success.prestore';
		$msg       = new stdClass;
		$msg->opts  = $opts;
		$msg->data = $this->data;
		$msg       = json_encode($msg);
		$this->doLog($msgType, $msg);

		return true;
	}

	public function onAfterProcess()
	{
		if (isset($this->charge))
		{
			$formModel = $this->getModel();
			$listModel = $formModel->getListModel();
			$userId    = JFactory::getUser()->get('id');
			$opts            = new stdClass;
			$opts->listid    = $this->getModel()->getListModel()->getId();
			$opts->formid    = (string) $this->getModel()->getId();
			$opts->rowid     = (string) $formModel->formData['rowid'];
			$opts->userid    = $userId;
			$opts->chargeId  = $this->charge->id;
			$opts->timestamp = time();
			$opts->date      = date('Y-m-d H:i:s');
			$opts->userid    = $userId;

			// if this was a new row, we need to update the metadata with the new rowid
			if (empty($this->data['rowid']))
			{
				try
				{
					$this->charge->metadata = array(
						"listid" => (string) $listModel->getId(),
						"formid" => (string) $formModel->getId(),
						"rowid"  => (string) $formModel->formData['rowid'],
						"userid" => (string) $userId
					);
					$this->charge->save();
				}
				catch (Exception $e)
				{
					// meh
					$this->app->enqueueMessage('Error updating metadata');
					$msgType         = 'fabrik.stripe.charge.success.err.metadata';
					$msg             = new stdClass;
					$msg->opts       = $opts;
					$msg             = json_encode($msg);
					$this->doLog($msgType, $msg);
				}
			}

			$msgType         = 'fabrik.stripe.charge.success.stored';
			$msg             = new stdClass;
			$msg->opts       = $opts;
			$msg             = json_encode($msg);
			$this->doLog($msgType, $msg);
		}
	}

	/**
	 * Sets up HTML to be injected into the form's bottom
	 *
	 * @return void
	 */
	public function getBottomContent()
	{
		$this->html = '';

		if ($this->app->input->get('format', 'html') === 'pdf')
		{
			return;
		}

		$params     = $this->getParams();
		$formModel  = $this->getModel();
		$input      = $this->app->input;
		$this->data = $formModel->data;

		JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fabrik/tables');

		if (!$this->shouldProcess('stripe_conditon', null, $params))
		{
			return true;
		}

		$opts = new stdClass();

		$opts->formid = $formModel->getId();

		$w      = new FabrikWorker;
		$userId = $this->user->get('id');

		if (!empty($userId))
		{
			$opts->email = $this->user->get('email');
		}

		$testMode = $params->get('stripe_test_mode', $input->get('stripe_testmode', false));

		if ($testMode)
		{
			$opts->publicKey = trim($params->get('stripe_test_publishable_key', ''));
			$secretKey = trim($params->get('stripe_test_secret_key', ''));
		}
		else
		{
			$opts->publicKey = trim($params->get('stripe_publishable_key', ''));
			$secretKey = trim($params->get('stripe_secret_key', ''));
		}

		$opts->name = FText::_($params->get('stripe_dialog_name', ''));
		$opts->panelLabel = FText::_($params->get('stripe_panel_label', 'PLG_FORM_STRIPE_PAY'));
		$opts->allowRememberMe = false;
		$opts->zipCode = $params->get('stripe_zipcode_check', '1') === '1';

		$currencyCode       = $params->get('stripe_currencycode', 'USD');
		$currencyCode       = $w->parseMessageForPlaceHolder($currencyCode, $this->data);
		$opts->currencyCode = $currencyCode;

		$amount = $params->get('stripe_cost');
		$amount = $w->parseMessageForPlaceHolder($amount, $this->data);

		if ($params->get('stripe_cost_eval', 0) == 1)
		{
			$amount = @eval($amount);

			if ($amount === false)
			{
				$msgType   = 'fabrik.stripe.onAfterProcess';
				$msg       = new stdClass;
				$msg->data = $this->data;
				$msg->msg  = "Eval amount code returned false.";
				$msg       = json_encode($msg);
				$this->doLog($msgType, $msg);
				throw new RuntimeException(FText::_('PLG_FORM_STRIPE_COST_ELEMENT_ERROR'), 500);
			}
		}

		if ($params->get('stripe_cost_eval_to_element', '0') === '1')
		{
			$amountKey = FabrikString::safeColNameToArrayKey($params->get('stripe_cost_element'));
			if (!empty($amountKey))
			{
				if (class_exists('NumberFormatter'))
				{
					$formatter = new NumberFormatter(JFactory::getLanguage()->getTag(), NumberFormatter::CURRENCY);
					$formModel->data[$amountKey] = $formatter->formatCurrency($amount, $currencyCode);
				}
				else
				{
					$formModel->data[$amountKey] = $amount;
				}
				$formModel->data[$amountKey . '_raw'] = $amount;
			}
		}
		else
		{
			if (trim($amount) == '')
			{
				// Priority to raw data.
				$amountKey = FabrikString::safeColNameToArrayKey($params->get('stripe_cost_element'));
				$amount    = FArrayHelper::getValue($this->data, $amountKey);
				$amount    = FArrayHelper::getValue($this->data, $amountKey . '_raw', $amount);

				if (is_array($amount))
				{
					$amount = array_shift($amount);
				}
			}
		}

		$costMultiplier = $params->get('stripe_currency_multiplier', '100');
		$amountMultiplied         = $amount * $costMultiplier;

		$opts->amount = $amountMultiplied;

		$item = $params->get('stripe_item');
		$item = $w->parseMessageForPlaceHolder($item, $this->data);

		if ($params->get('stripe_item_eval', 0) == 1)
		{
			$item = @eval($item);
		}

		if ($params->get('stripe_item_eval_to_element', '0') === '1')
		{
			$itemKey = FabrikString::safeColNameToArrayKey($params->get('stripe_item_element'));
			if (!empty($itemKey))
			{
				$formModel->data[$itemKey] = $item;
				$formModel->data[$itemKey . '_raw'] = $item;
			}
		}
		else
		{
			if (trim($item) == '')
			{
				$item    = $this->data[FabrikString::safeColNameToArrayKey($params->get('stripe_item_element'))];

				if (is_array($item))
				{
					$item = array_shift($item);
				}
			}
		}

		$opts->item = $item;

		$opts->billingAddress = $params->get('stripe_collect_billing_address', '0') === '1';

		$customerTableName = $this->getCustomerTableName();
		$doCustomer = $customerTableName !== false && !empty($userId);

		if ($doCustomer)
		{
			$customerId = $this->getCustomerId($userId);
		}

		$logErrMsg = '';
		$logErrType = '';
		$customerErrMsg = '';

		if (!empty($customerId))
		{
			$opts->useCheckout = false;

			\Stripe\Stripe::setApiKey($secretKey);
			try
			{
				$customer = \Stripe\Customer::retrieve($customerId);
				$card     = $customer->sources->retrieve($customer->default_source);
			}
			catch (\Stripe\Error\RateLimit $e)
			{
				// Too many requests made to the API too quickly
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.customer.err.ratelimit';
				$customerErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_RATE_LIMITED');
			}
			catch (\Stripe\Error\InvalidRequest $e)
			{
				// Invalid parameters were supplied to Stripe's API
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.customer.err.invalid';
				$body = $e->getJsonBody();
				$err  = $body['error'];
				$customerErrMsg = FText::sprintf('PLG_FORM_STRIPE_ERROR_CUSTOMER',$err['message'] );
			}
			catch (\Stripe\Error\Authentication $e)
			{
				// Authentication with Stripe's API failed
				// (maybe you changed API keys recently)
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.customer.err.authentication';
				$customerErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_AUTHENTICATION');
			}
			catch (\Stripe\Error\ApiConnection $e)
			{
				// Network communication with Stripe failed
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.customer.err.connection';
				$customerErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_NETWORK');
			}
			catch (\Stripe\Error\Base $e)
			{
				// Display a very generic error to the user, and maybe send
				// yourself an email
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.customer.err.base';
				$customerErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_INTERNAL');
			}
			catch (Exception $e)
			{
				// Something else happened, completely unrelated to Stripe
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.customer.err.exception';
				$customerErrMsg = JText::_('PLG_FORM_STRIPE_ERROR_INTERNAL');
			}


			if (!empty($customerErrMsg))
			{
				$this->app->enqueueMessage($customerErrMsg, 'message');

				$opts = new stdClass;
				$opts->listid = $formModel->getListModel()->getId();
				$opts->formid = $formModel->getId();
				$opts->rowid = $formModel->getRowId();
				$opts->cusomerid = $customerId;
				$opts->customer = $customer;
				$opts->amount   = $amount;
				$opts->item     = $item;
				$opts->userid   = $userId;
				$msg       = new stdClass;
				$msg->opts  = $opts;
				$msg->data = $this->data;
				$msg->err  = $logErrMsg;
				$msg       = json_encode($msg);

				$this->doLog($logErrType, $msg);

				return false;
			}

			$opts->useCheckout = false;

			if ($params->get('stripe_customers_allow_update_cc', '0') === '1')
			{
				$opts->updateCheckout = true;
				$opts->panelLabel = FText::_(
					$params->get('stripe_update_button_name', "PLG_FORM_STRIPE_CUSTOMERS_UPDATE_CC_BUTTON_NAME")
				);
				FabrikHelperHTML::script('https://checkout.stripe.com/checkout.js');
				JText::script('PLG_FORM_STRIPE_CUSTOMERS_UPDATE_CC_UPDATED');
			}
			else
			{
				$opts->updateCheckout = false;
			}

			$layout     = $this->getLayout('existing-customer');
			$layoutData = new stdClass();
			$layoutData->testMode = $testMode;
			$layoutData->useUpdateButton = $opts->updateCheckout;
			$layoutData->updateButtonName = FText::_($params->get('stripe_update_button_name', "PLG_FORM_STRIPE_CUSTOMERS_UPDATE_CC_BUTTON_NAME"));
			$layoutData->card = $card;
			$layoutData->amount = $amount;
			$layoutData->currencyCode = $currencyCode;
			$layoutData->langTag = JFactory::getLanguage()->getTag();
			$layoutData->item = $item;
			$layoutData->bottomText = FText::_($params->get('stripe_charge_bottom_text_existing', 'PLG_FORM_STRIPE_CHARGE_BOTTOM_TEXT_EXISTING'));
			$this->html = $layout->render($layoutData);
		}
		else
		{
			$opts->useCheckout = true;
			$layout     = $this->getLayout('checkout');
			$layoutData = new stdClass();
			$layoutData->testMode = $testMode;
			$layoutData->amount = $amount;
			$layoutData->currencyCode = $currencyCode;
			$layoutData->langTag = JFactory::getLanguage()->getTag();
			$layoutData->bottomText = FText::_($params->get('stripe_charge_bottom_text_new', 'PLG_FORM_STRIPE_CHARGE_BOTTOM_TEXT_NEW'));
			$layoutData->bottomText = $w->parseMessageForPlaceHolder($layoutData->bottomText, $this->data);
			$layoutData->item = $item;
			$this->html = $layout->render($layoutData);
			FabrikHelperHTML::script('https://checkout.stripe.com/checkout.js');
		}

		$opts = json_encode($opts);

		$this->formJavascriptClass($params, $formModel);
		$formModel->formPluginJS['Stripe' . $this->renderOrder] = 'var stripe = new Stripe(' . $opts . ');';

	}

	/**
	 * Inject custom html into the bottom of the form
	 *
	 * @param   int $c plugin counter
	 *
	 * @return  string  html
	 */
	public function getBottomContent_result($c)
	{
		return $this->html;
	}

	/**
	 * Log a message
	 *
	 * @param  string $msgType The dotted message type
	 * @param  string $msg     The log message
	 */
	private function doLog($msgType, $msg)
	{
		if ($this->log === null)
		{
			$this->log                = FabTable::getInstance('log', 'FabrikTable');
			$this->log->referring_url = $this->app->input->server->getString('REQUEST_URI');
		}
		$this->log->message_type = $msgType;
		$this->log->message      = $msg;
		$this->log->id           = '';
		$this->log->store();
	}

	/**
	 * Get the Customer table name
	 *
	 * @return  string  db table name
	 */
	protected function getCustomerTableName()
	{
		if (isset($this->customerTableName))
		{
			return $this->customerTableName;
		}

		$params = $this->getParams();
		$customerTable = (int) $params->get('stripe_customers_table', '');

		if (empty($customerTable))
		{
			$this->customerTableName = false;

			return false;
		}

		$db = FabrikWorker::getDbo();
		$query = $db->getQuery(true);
		$query->select('db_table_name')->from('#__{package}_lists')->where('id = ' . (int) $params->get('stripe_customers_table'));
		$db->setQuery($query);
		$db_table_name = $db->loadResult();

		if (!isset($db_table_name))
		{
			$this->customerTableName = false;

			return false;
		}

		$this->customerTableName = $db_table_name;

		return $this->customerTableName;
	}

	private function getCustomerId($userId)
	{
		$params = $this->getParams();
		$cDb = FabrikWorker::getDbo(false, $params->get('stripe_customers_connection'));
		$cQuery = $cDb->getQuery(true);
		$cUserIdField = FabrikString::shortColName($params->get('stripe_customers_userid'));
		$cStripeIdField = FabrikString::shortColName($params->get('stripe_customers_stripeid'));

		if (empty($cUserIdField) || empty($cStripeIdField))
		{
			throw new RuntimeException('Stripe plugin is not configured correctly');
		}

		$cQuery
			->select($cDb->quoteName($cStripeIdField))
			->from($cDb->quoteName($this->getCustomerTableName()))
			->where($cDb->quoteName($cUserIdField) . ' = ' . $cDb->quote($userId));
		$cDb->setQuery($cQuery);

		return $cDb->loadResult();
	}

	private function updateCustomerCustom($userId)
	{
		$params       = $this->getParams();
		$cDb          = FabrikWorker::getDbo(false, $params->get('stripe_customers_connection'));
		$cQuery       = $cDb->getQuery(true);
		$cUserIdField = FabrikString::shortColName($params->get('stripe_customers_userid'));
		$customField  = FabrikString::shortColName($params->get('stripe_customers_custom_field'));
		$customValue  = $params->get('stripe_customers_custom_value', '');

		if (empty($cUserIdField) || empty($customField) || empty($customValue))
		{
			return;
		}

		$w           = new FabrikWorker;
		$customValue = $w->parseMessageForPlaceHolder($customValue, $this->data);

		$cQuery
			->clear()
			->update($cDb->quoteName($this->getCustomerTableName()))
			->set($cDb->quoteName($customField) . ' = ' . $cDb->quote($customValue))
			->where($cDb->quoteName($cUserIdField) . ' = ' . $cDb->quote($userId));
		$cDb->setQuery($cQuery);
		$cDb->execute();
	}

	private function updateCustomerId($userId, $customerId, $opts)
	{
		$params = $this->getParams();
		$cDb = FabrikWorker::getDbo(false, $params->get('stripe_customers_connection'));
		$cQuery = $cDb->getQuery(true);
		$cUserIdField = FabrikString::shortColName($params->get('stripe_customers_userid'));
		$cStripeIdField = FabrikString::shortColName($params->get('stripe_customers_stripeid'));
		$done = false;

		if (empty($cUserIdField) || empty($cStripeIdField))
		{
			throw new RuntimeException('Stripe plugin not configured correctly');
		}

		$stripeAddressFields = array(
			"name",
			"address_country",
			"address_country_code",
			"address_zip",
			"address_line1",
			"address_city",
			"address_state"
		);

		$setList = array();

		if ($params->get('stripe_collect_billing_address', '0') === '1')
		{
			foreach ($stripeAddressFields as $field)
			{
				$paramName = 'stripe_customers_billing_' . $field;
				$fieldName = FabrikString::shortColName($params->get($paramName));

				if (!empty($fieldName))
				{
					$optName = 'billing_' . $field;

					if (isset($opts->$optName))
					{
						$setList[] = $cDb->quoteName($fieldName) . ' = ' . $cDb->quote($opts->$optName);
					}
				}

			}
		}

		if ($params->get('stripe_collect_shipping_address', '0') === '1')
		{
			foreach ($stripeAddressFields as $field)
			{
				$paramName = 'stripe_customers_shipping_' . $field;
				$fieldName = FabrikString::shortColName($params->get($paramName));

				if (!empty($fieldName))
				{
					$optName = 'shipping_' . $field;

					if (isset($opts->$optName))
					{
						$setList[] = $cDb->quoteName($fieldName) . ' = ' . $cDb->quote($opts->$optName);
					}
				}

			}
		}

		try
		{
			if ($params->get('stripe_customers_insert', '') === '1')
			{
				$cQuery
					->select('*')
					->from($cDb->quoteName($this->getCustomerTableName()))
					->where($cDb->quoteName($cUserIdField) . ' = ' . $cDb->quote($userId));
				$cDb->setQuery($cQuery);
				$result = $cDb->loadObject();

				if (empty($result))
				{
					$cQuery
						->clear()
						->insert($cDb->quoteName($this->getCustomerTableName()))
						->set($cDb->quoteName($cStripeIdField) . ' = ' . $cDb->quote($customerId))
						->set($cDb->quoteName($cUserIdField) . ' = ' . $cDb->quote($userId));

					foreach ($setList as $set)
					{
						$cQuery->set($set);
					}

					$cDb->setQuery($cQuery);
					$cDb->execute();
					$done = true;
				}
			}

			if (!$done)
			{
				$cQuery
					->clear()
					->update($cDb->quoteName($this->getCustomerTableName()))
					->set($cDb->quoteName($cStripeIdField) . ' = ' . $cDb->quote($customerId))
					->where($cDb->quoteName($cUserIdField) . ' = ' . $cDb->quote($userId));

				foreach ($setList as $set)
				{
					$cQuery->set($set);
				}

				$cDb->setQuery($cQuery);
				$cDb->execute();
			}
		}
		catch (Exception $e)
		{
			$this->doLog('fabrik.form.stripe.customer.error', $e->getMessage());
			$this->app->enqueueMessage($e->getMessage(), 'error');

			return false;
		}

		return true;
	}
}
